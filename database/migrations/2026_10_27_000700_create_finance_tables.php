<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_payouts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 20)->unique();
            $table->foreignId('organizer_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40)->nullable();
            $table->string('provider_transfer_id')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('status', 20)->default('scheduled');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('scheduled_for')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['organizer_id', 'status']);
            $table->unique(['provider', 'provider_transfer_id']);
        });

        // Append-only. Signed amounts from the organiser's point of view:
        // positive increases what Tickora owes the organiser, negative
        // decreases it. Balance = SUM(amount_minor) per organiser and currency.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('organizer_payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('entry_type', 30);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('description')->nullable();
            // Provider transaction, refund or transfer the entry reconciles to.
            $table->string('external_reference')->nullable();
            // Deterministic per business event (e.g. "order:42:sale") so a
            // replayed webhook can never write the same entry twice.
            $table->string('idempotency_key')->unique();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['organizer_id', 'currency', 'created_at']);
            $table->index('order_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE organizer_payouts ADD CONSTRAINT organizer_payouts_status_check CHECK (status IN ('scheduled','processing','paid','failed','cancelled'))");
            DB::statement('ALTER TABLE organizer_payouts ADD CONSTRAINT organizer_payouts_amount_check CHECK (amount_minor > 0)');
            DB::statement('ALTER TABLE organizer_payouts ADD CONSTRAINT organizer_payouts_period_check CHECK (period_end >= period_start)');

            DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check CHECK (entry_type IN ('ticket_sale','platform_fee','refund','platform_fee_reversal','chargeback','payout','adjustment'))");
            DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_nonzero_check CHECK (amount_minor <> 0)');

            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION ledger_entries_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'ledger_entries is append-only; record a correcting entry instead';
                END;
                $$ LANGUAGE plpgsql
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER ledger_entries_no_update_or_delete
                BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_entries_append_only()
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS ledger_entries_no_update_or_delete ON ledger_entries');
            DB::statement('DROP FUNCTION IF EXISTS ledger_entries_append_only()');
        }

        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('organizer_payouts');
    }
};
