<?php

namespace Tests\Feature\Sync;

use App\Enums\EventType;
use App\Models\DomainEvent;
use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncTablesCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Time freeze: cutoff math depends on `now()` returning a stable value
        // between insert and command execution; otherwise the boundary test is
        // racy.
        Carbon::setTestNow(Carbon::create(2026, 5, 8, 12, 0, 0));

        // Shim until SYN-2 migration (create_pusher_event_acks_table) merges in.
        // The cleanup command only reads `received_at`, so a minimal schema is
        // sufficient. Once the real migration lands, the guard short-circuits.
        if (!Schema::hasTable('pusher_event_acks')) {
            Schema::create('pusher_event_acks', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->dateTime('received_at')->nullable();
            });
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_deletes_pusher_event_acks_older_than_30_days(): void
    {
        DB::table('pusher_event_acks')->insert([
            ['received_at' => now()->subDays(31)],
            ['received_at' => now()->subDays(60)],
            ['received_at' => now()->subDays(7)],
            ['received_at' => now()->subDays(29)],
        ]);

        $this->artisan('foodking:sync:cleanup')->assertExitCode(0);

        $this->assertSame(2, DB::table('pusher_event_acks')->count());
        $this->assertSame(
            0,
            DB::table('pusher_event_acks')
                ->where('received_at', '<', now()->subDays(30))
                ->count(),
            'No row older than 30d may remain.'
        );
    }

    public function test_deletes_dispatched_domain_events_older_than_30_days(): void
    {
        $oldDispatchedId = $this->createDomainEvent([
            'aggregate_id' => 1,
            'dispatched_at' => now()->subDays(45),
        ]);
        $recentDispatchedId = $this->createDomainEvent([
            'aggregate_id' => 2,
            'dispatched_at' => now()->subDays(5),
        ]);

        $this->artisan('foodking:sync:cleanup')->assertExitCode(0);

        $this->assertDatabaseMissing('domain_events', ['id' => $oldDispatchedId]);
        $this->assertDatabaseHas('domain_events', ['id' => $recentDispatchedId]);
    }

    public function test_preserves_undispatched_domain_events_regardless_of_age(): void
    {
        // Ancient pending event (NULL dispatched_at) — must NEVER be deleted by
        // the retention job. Losing it would silently drop an outbox row that
        // OutboxRescue is supposed to retry.
        $ancientUndispatchedId = $this->createDomainEvent([
            'aggregate_id' => 99,
            'dispatched_at' => null,
            'occurred_at' => now()->subDays(120),
            'created_at' => now()->subDays(120),
            'updated_at' => now()->subDays(120),
        ]);

        $this->artisan('foodking:sync:cleanup')->assertExitCode(0);

        $this->assertDatabaseHas('domain_events', [
            'id' => $ancientUndispatchedId,
            'dispatched_at' => null,
        ]);
    }

    public function test_strict_cutoff_preserves_row_at_exact_boundary(): void
    {
        // Strict `<` boundary: a row at *exactly* cutoff (now - 30d) must be
        // KEPT. Anything one second past must be deleted.
        $kept = $this->createDomainEvent([
            'aggregate_id' => 1001,
            'dispatched_at' => now()->subDays(30),
        ]);
        $deleted = $this->createDomainEvent([
            'aggregate_id' => 1002,
            'dispatched_at' => now()->subDays(30)->subSecond(),
        ]);

        DB::table('pusher_event_acks')->insert([
            ['id' => 1001, 'received_at' => now()->subDays(30)],
            ['id' => 1002, 'received_at' => now()->subDays(30)->subSecond()],
        ]);

        $this->artisan('foodking:sync:cleanup')->assertExitCode(0);

        $this->assertDatabaseHas('domain_events', ['id' => $kept]);
        $this->assertDatabaseMissing('domain_events', ['id' => $deleted]);

        $this->assertSame(1, DB::table('pusher_event_acks')->where('id', 1001)->count());
        $this->assertSame(0, DB::table('pusher_event_acks')->where('id', 1002)->count());
    }

    public function test_runs_cleanly_on_empty_tables(): void
    {
        // Self-sufficient empty state: don't rely on RefreshDatabase baseline,
        // which can carry seeded rows on MySQL but not on SQLite. The point of
        // this test is "exit 0 with nothing to delete," not "verify migrations
        // produce an empty domain_events."
        DB::table('pusher_event_acks')->delete();
        DB::table('domain_events')->delete();

        $this->artisan('foodking:sync:cleanup')->assertExitCode(0);

        $this->assertSame(0, DB::table('pusher_event_acks')->count());
        $this->assertSame(0, DB::table('domain_events')->count());
    }

    public function test_days_option_overrides_default_window(): void
    {
        $this->createDomainEvent([
            'aggregate_id' => 7001,
            'dispatched_at' => now()->subDays(8),
        ]);

        $this->artisan('foodking:sync:cleanup', ['--days' => 7])->assertExitCode(0);

        $this->assertDatabaseMissing('domain_events', ['aggregate_id' => 7001]);
    }

    private function createDomainEvent(array $overrides): int
    {
        $base = [
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 1,
            'branch_id' => 1,
            'payload' => ['order_id' => 1],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'test-' . bin2hex(random_bytes(4)),
            'occurred_at' => now(),
        ];

        $event = DomainEvent::query()->create(array_merge($base, $overrides));

        if (array_key_exists('created_at', $overrides)) {
            $event->forceFill([
                'created_at' => $overrides['created_at'],
                'updated_at' => $overrides['updated_at'] ?? $overrides['created_at'],
            ])->save();
        }

        return $event->id;
    }
}
