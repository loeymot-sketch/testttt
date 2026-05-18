<?php

namespace Tests\Feature\Settings;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\SettingsUpdated;
use App\Listeners\PersistSettingsUpdatedToOutbox;
use App\Models\Branch;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [Wave 5G R9 heal 2026-05-17] Sentinel for RED-team R9:
 *   "Admin currency/tax/site change doesn't propagate to POS/Kiosk until manual reload"
 *
 * Asserts:
 *   1. SettingsUpdated event is properly dispatchable.
 *   2. PersistSettingsUpdatedToOutbox handler persists one outbox row per
 *      active branch when $branchId is null (global fan-out).
 *   3. Outbox row has correct event_type / broadcast_as / channel shape so
 *      POS/Kiosk subscribers on `private-branch.{id}` receive the broadcast.
 *   4. Idempotent on duplicate dispatch (same correlation -> single row).
 */
class SettingsUpdatedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_is_dispatched_with_changed_keys(): void
    {
        Event::fake([SettingsUpdated::class]);

        SettingsUpdated::dispatchNow(['currency']);

        Event::assertDispatched(SettingsUpdated::class, function (SettingsUpdated $e): bool {
            return $e->changedKeys === ['currency'] && $e->branchId === null;
        });
    }

    public function test_listener_fans_out_to_all_active_branches_when_branch_id_is_null(): void
    {
        // Two active branches + one inactive (status filter must exclude it).
        $active1   = Branch::factory()->create(['status' => Status::ACTIVE]);
        $active2   = Branch::factory()->create(['status' => Status::ACTIVE]);
        $inactive  = Branch::factory()->create(['status' => Status::INACTIVE]);

        $listener = app(PersistSettingsUpdatedToOutbox::class);
        $listener->handle(new SettingsUpdated(['currency']));

        $rows = DomainEvent::query()
            ->where('event_type', EventType::SETTINGS_UPDATED)
            ->get();

        $this->assertCount(2, $rows, 'Expected one outbox row per ACTIVE branch.');
        $this->assertEqualsCanonicalizing(
            [$active1->id, $active2->id],
            $rows->pluck('branch_id')->map(fn ($v): int => (int) $v)->all()
        );

        $first = $rows->firstWhere('branch_id', $active1->id);
        $this->assertSame('SettingsUpdated', $first->broadcast_as);
        $this->assertSame('settings', $first->aggregate_type);
        $this->assertStringContainsString('private-branch.' . $active1->id, $first->channel);
    }

    public function test_listener_targets_only_provided_branch_when_branch_id_is_set(): void
    {
        $b1 = Branch::factory()->create(['status' => Status::ACTIVE]);
        $b2 = Branch::factory()->create(['status' => Status::ACTIVE]);

        $listener = app(PersistSettingsUpdatedToOutbox::class);
        $listener->handle(new SettingsUpdated(['site'], $b1->id));

        $rows = DomainEvent::query()
            ->where('event_type', EventType::SETTINGS_UPDATED)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($b1->id, (int) $rows->first()->branch_id);
    }

    public function test_listener_is_idempotent_on_replay(): void
    {
        Branch::factory()->create(['status' => Status::ACTIVE]);

        // Stable correlation id across two fires.
        request()->headers->set('X-Correlation-ID', 'stable-correlation-r9-test');

        $listener = app(PersistSettingsUpdatedToOutbox::class);
        $listener->handle(new SettingsUpdated(['tax']));
        $listener->handle(new SettingsUpdated(['tax']));

        $this->assertSame(
            1,
            DomainEvent::query()
                ->where('event_type', EventType::SETTINGS_UPDATED)
                ->count(),
            'Duplicate dispatch with identical correlation must collapse to a single outbox row.'
        );
    }

    public function test_idempotency_key_is_stable_regardless_of_changed_keys_order(): void
    {
        Branch::factory()->create(['status' => Status::ACTIVE]);
        request()->headers->set('X-Correlation-ID', 'stable-correlation-r9-order');

        $listener = app(PersistSettingsUpdatedToOutbox::class);
        $listener->handle(new SettingsUpdated(['currency', 'tax']));
        $listener->handle(new SettingsUpdated(['tax', 'currency']));

        $this->assertSame(
            1,
            DomainEvent::query()
                ->where('event_type', EventType::SETTINGS_UPDATED)
                ->count(),
            'Sorting changedKeys before hashing must collapse both calls to one row.'
        );
    }
}
