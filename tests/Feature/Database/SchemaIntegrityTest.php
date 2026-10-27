<?php

namespace Tests\Feature\Database;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\TicketType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * The database enforces the invariants that matter most, so a bug in
 * application code fails loudly instead of corrupting money or inventory.
 */
class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_types_cannot_be_oversold(): void
    {
        $type = TicketType::factory()->create(['quantity_total' => 10]);

        $this->assertDatabaseRejects(fn () => DB::table('ticket_types')
            ->where('id', $type->id)
            ->update(['quantity_sold' => 11]));
    }

    public function test_prices_cannot_be_negative(): void
    {
        $type = TicketType::factory()->create();

        $this->assertDatabaseRejects(fn () => DB::table('ticket_types')
            ->where('id', $type->id)
            ->update(['unit_amount_minor' => -1]));
    }

    public function test_refunds_cannot_exceed_the_order_total(): void
    {
        $order = Order::factory()->create(['total_minor' => 1000, 'subtotal_minor' => 1000, 'platform_fee_minor' => 0]);

        $this->assertDatabaseRejects(fn () => DB::table('orders')
            ->where('id', $order->id)
            ->update(['refunded_minor' => 1001]));
    }

    public function test_unknown_statuses_are_rejected(): void
    {
        $order = Order::factory()->create();

        $this->assertDatabaseRejects(fn () => DB::table('orders')
            ->where('id', $order->id)
            ->update(['status' => 'paid_maybe']));
    }

    public function test_the_ledger_is_append_only_at_the_database_level(): void
    {
        $entry = $this->ledgerEntry();

        $this->assertDatabaseRejects(fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['amount_minor' => 1]));
        $this->assertDatabaseRejects(fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete());
    }

    public function test_the_ledger_model_refuses_edits_before_reaching_the_database(): void
    {
        $entry = $this->ledgerEntry();

        $this->expectException(LogicException::class);

        $entry->description = 'changed';
        $entry->save();
    }

    public function test_ledger_entries_are_written_once_per_idempotency_key(): void
    {
        $this->ledgerEntry('order:1:sale');

        $this->assertDatabaseRejects(fn () => $this->ledgerEntry('order:1:sale'));
    }

    public function test_webhook_events_are_unique_per_provider(): void
    {
        $row = [
            'provider' => 'fake',
            'provider_event_id' => 'evt_123',
            'event_type' => 'payment.succeeded',
            'signature_valid' => true,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('payment_webhook_events')->insert($row);
        DB::table('payment_webhook_events')->insert([...$row, 'provider' => 'other']);

        $this->assertDatabaseRejects(fn () => DB::table('payment_webhook_events')->insert($row));
    }

    private function ledgerEntry(string $key = 'order:42:sale'): LedgerEntry
    {
        $order = Order::factory()->create();

        $entry = new LedgerEntry;
        $entry->organizer_id = $order->organizer_id;
        $entry->order_id = $order->id;
        $entry->entry_type = LedgerEntryType::TicketSale;
        $entry->currency = $order->currency;
        $entry->amount_minor = $order->subtotal_minor;
        $entry->idempotency_key = $key;
        $entry->save();

        return $entry;
    }

    /**
     * Run a statement that must violate a constraint, inside a savepoint so
     * the surrounding test transaction stays usable afterwards.
     */
    private function assertDatabaseRejects(callable $statement): void
    {
        try {
            DB::transaction(function () use ($statement): void {
                $statement();
            });
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The database accepted a write that violates an integrity constraint.');
    }
}
