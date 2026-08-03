<?php

namespace Tests\Feature\Outbox;

use App\Enums\EventType;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [Outbox dead-letter fix — 2026-07-07]
 *
 * `PruneOutboxCommand` previously purged only:
 *   (A) dispatched_at NOT NULL & old, OR
 *   (B) attempts >= 6 & old.
 *
 * Contract-violation rows (DispatchDomainEventsJob short-circuits
 * PayloadMismatchException via $this->fail() on the FIRST failure — see
 * app/Jobs/DispatchDomainEventsJob.php:168-187) freeze at dispatched_at=NULL
 * with a LOW attempts count (2-4). They match NEITHER clause and lived
 * forever — 17 such legacy rows were observed immortal since 2026-06-17.
 *
 * This test pins the new clause (C): terminal contract violations past the
 * retention cutoff ARE purged, while genuinely-pending rows and still-recent
 * contract violations are PRESERVED.
 */
class PruneOutboxContractViolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_old_contract_violation_poison_rows(): void
    {
        // Poison row: contract violation, undispatched, attempts=3, 100 days old.
        $poison = $this->makeEvent(
            attempts: 3,
            lastError: 'contract_violation: envelope missing field order_id',
            dispatchedAt: null,
            ageDays: 100,
        );

        Artisan::call('foodking:outbox:prune', ['--older-than-days' => 90]);

        $this->assertDatabaseMissing('domain_events', ['id' => $poison->id]);
    }

    public function test_prune_preserves_recent_contract_violation_rows(): void
    {
        // Same poison shape but WITHIN the retention window (1 day old).
        $recent = $this->makeEvent(
            attempts: 2,
            lastError: 'contract_violation: bad payload',
            dispatchedAt: null,
            ageDays: 1,
        );

        Artisan::call('foodking:outbox:prune', ['--older-than-days' => 90]);

        $this->assertDatabaseHas('domain_events', ['id' => $recent->id]);
    }

    public function test_prune_does_not_touch_genuinely_pending_rows(): void
    {
        // Undispatched, NO error, old — a legitimately pending event (e.g. worker
        // was down). It is NOT terminal and must survive prune (regression guard
        // against an over-broad clause C).
        $pending = $this->makeEvent(
            attempts: 1,
            lastError: null,
            dispatchedAt: null,
            ageDays: 100,
        );

        // A transient runtime failure that is still being retried (attempts < 6,
        // non-contract error) must ALSO survive.
        $retrying = $this->makeEvent(
            attempts: 4,
            lastError: 'Pusher unreachable',
            dispatchedAt: null,
            ageDays: 100,
        );

        Artisan::call('foodking:outbox:prune', ['--older-than-days' => 90]);

        $this->assertDatabaseHas('domain_events', ['id' => $pending->id]);
        $this->assertDatabaseHas('domain_events', ['id' => $retrying->id]);
    }

    public function test_dry_run_counts_contract_violation_rows_as_eligible(): void
    {
        $this->makeEvent(
            attempts: 3,
            lastError: 'contract_violation: x',
            dispatchedAt: null,
            ageDays: 100,
        );

        $this->artisan('foodking:outbox:prune', ['--older-than-days' => 90, '--dry-run' => true])
            ->expectsOutputToContain('1 domain_events row(s) eligible')
            ->assertExitCode(0);

        // Dry-run must NOT delete.
        $this->assertSame(1, DB::table('domain_events')->count());
    }

    private function makeEvent(int $attempts, ?string $lastError, ?string $dispatchedAt, int $ageDays): DomainEvent
    {
        $ts = now()->subDays($ageDays);
        static $seq = 0;
        $seq++;

        DomainEvent::query()->getQuery()->insert([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => 'Order',
            'aggregate_id' => 8000 + $seq,
            'branch_id' => 1,
            'payload' => json_encode(['order_id' => 8000 + $seq]),
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'prune-cv-' . $seq,
            'occurred_at' => $ts,
            'dispatched_at' => $dispatchedAt,
            'attempts' => $attempts,
            'last_error' => $lastError,
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);

        return DomainEvent::query()->where('correlation_id', 'prune-cv-' . $seq)->firstOrFail();
    }
}
