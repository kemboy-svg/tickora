<?php

namespace Tests\Feature\Checkout;

use App\Actions\Checkout\CreateCheckout;
use App\Models\Event;
use App\Models\InventoryReservation;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;
use Throwable;

/**
 * Proves the inventory lock holds under real concurrency: separate OS
 * processes, each with its own PostgreSQL connection, race for the last
 * tickets at the same instant.
 *
 * Transactions cannot be shared across processes, so this test commits real
 * rows instead of using RefreshDatabase, and truncates every table before
 * and after so the rest of the suite still starts from an empty database.
 */
#[Group('postgres')]
class ConcurrentCheckoutTest extends TestCase
{
    use CreatesTenants;

    private string $results;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            if (getenv('CI') !== false) {
                $this->fail('The concurrency test needs the pcntl and posix extensions in CI.');
            }

            $this->markTestSkipped('pcntl and posix are required to run checkouts in parallel processes.');
        }

        if (! RefreshDatabaseState::$migrated) {
            Artisan::call('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }

        $this->truncateAll();
        $this->results = sys_get_temp_dir().'/tickora-race-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->results);
    }

    protected function tearDown(): void
    {
        if (isset($this->results) && is_dir($this->results)) {
            array_map('unlink', glob($this->results.'/*') ?: []);
            rmdir($this->results);
        }

        if (RefreshDatabaseState::$migrated) {
            $this->truncateAll();
        }

        parent::tearDown();
    }

    public function test_parallel_buyers_can_never_oversell_a_ticket_type(): void
    {
        $event = $this->publishedEventFor($this->organizer());
        $type = TicketType::factory()->create([
            'event_id' => $event->id,
            'quantity_total' => 25,
            'quantity_sold' => 18, // 7 left
            'max_per_order' => 10,
        ]);

        // 12 buyers want 2 tickets each: 24 asked for, only 7 exist.
        $buyers = User::factory()->count(12)->create();

        $outcomes = $this->race($buyers->all(), $event, [$type->public_id => 2]);

        $held = (int) InventoryReservation::query()->where('ticket_type_id', $type->id)->holding()->sum('quantity');

        $this->assertSame(3, $outcomes['ok'], 'exactly three buyers get two tickets each: '.json_encode($outcomes));
        $this->assertSame(9, $outcomes['rejected'], json_encode($outcomes));
        $this->assertSame(0, $outcomes['error'], json_encode($outcomes));
        $this->assertSame(6, $held);
        $this->assertLessThanOrEqual($type->quantity_total - $type->quantity_sold, $held);
    }

    public function test_parallel_baskets_across_several_types_do_not_deadlock_or_oversell(): void
    {
        $event = $this->publishedEventFor($this->organizer());
        $a = TicketType::factory()->create(['event_id' => $event->id, 'name' => 'A', 'quantity_total' => 5]);
        $b = TicketType::factory()->create(['event_id' => $event->id, 'name' => 'B', 'quantity_total' => 5, 'sort_order' => 1]);

        $buyers = User::factory()->count(10)->create();

        $outcomes = $this->race($buyers->all(), $event, [$b->public_id => 1, $a->public_id => 1]);

        foreach ([$a, $b] as $type) {
            $held = (int) InventoryReservation::query()->where('ticket_type_id', $type->id)->holding()->sum('quantity');
            $this->assertLessThanOrEqual(5, $held, "{$type->name} oversold");
        }

        $this->assertSame(5, $outcomes['ok'], json_encode($outcomes));
        $this->assertSame(0, $outcomes['error'], 'no deadlocks or other failures: '.json_encode($outcomes));
    }

    /**
     * Fork one process per buyer, release them all at the same moment, and
     * collect how each attempt ended.
     *
     * @param  list<User>  $buyers
     * @param  array<string, int>  $items
     * @return array{ok: int, rejected: int, error: int, errors: list<string>}
     */
    private function race(array $buyers, Event $event, array $items): array
    {
        $event->load('organizer');

        // Children must not share the parent's socket; each reconnects lazily.
        DB::disconnect();

        $startAt = microtime(true) + 1.5;
        $pids = [];

        foreach ($buyers as $i => $buyer) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork.');
            }

            if ($pid === 0) {
                $this->runChild($i, $buyer, $event, $items, $startAt);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $outcomes = ['ok' => 0, 'rejected' => 0, 'error' => 0, 'errors' => []];

        foreach (glob($this->results.'/*') ?: [] as $file) {
            $line = (string) file_get_contents($file);
            [$kind, $message] = array_pad(explode(':', $line, 2), 2, '');

            if (isset($outcomes[$kind]) && $kind !== 'errors') {
                $outcomes[$kind]++;
            }

            if ($kind === 'error') {
                $outcomes['errors'][] = $message;
            }
        }

        $missing = count($buyers) - ($outcomes['ok'] + $outcomes['rejected'] + $outcomes['error']);
        $this->assertSame(0, $missing, 'every child process reported back');

        return $outcomes;
    }

    /**
     * @param  array<string, int>  $items
     */
    private function runChild(int $i, User $buyer, Event $event, array $items, float $startAt): never
    {
        $result = 'error:unknown';

        try {
            DB::reconnect();

            $wait = $startAt - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }

            app(CreateCheckout::class)->handle($buyer, $event, $items);
            $result = 'ok:';
        } catch (ValidationException $e) {
            $result = 'rejected:'.$e->getMessage();
        } catch (Throwable $e) {
            $result = 'error:'.get_class($e).' '.$e->getMessage();
        }

        file_put_contents($this->results.'/'.$i, $result);

        // Exit without running PHPUnit's or Laravel's shutdown handlers,
        // which belong to the parent process.
        posix_kill(getmypid(), SIGKILL);

        exit(1);
    }

    private function truncateAll(): void
    {
        $tables = collect(DB::select("select tablename from pg_tables where schemaname = current_schema() and tablename <> 'migrations'"))
            ->map(fn (object $row): string => '"'.$row->tablename.'"')
            ->implode(', ');

        if ($tables !== '') {
            DB::statement("TRUNCATE {$tables} RESTART IDENTITY CASCADE");
        }
    }
}
