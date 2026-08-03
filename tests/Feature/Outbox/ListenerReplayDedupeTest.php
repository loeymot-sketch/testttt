<?php

namespace Tests\Feature\Outbox;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\CatalogChanged;
use App\Events\CouponChanged;
use App\Events\ItemAvailabilityChanged;
use App\Events\ItemExtraAvailabilityChanged;
use App\Events\ItemVariationAvailabilityChanged;
use App\Events\OrderTableChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Listeners\PersistCatalogChangedToOutbox;
use App\Listeners\PersistCouponChangedToOutbox;
use App\Listeners\PersistItemAvailabilityChangedToOutbox;
use App\Listeners\PersistItemExtraAvailabilityChangedToOutbox;
use App\Listeners\PersistItemVariationAvailabilityChangedToOutbox;
use App\Listeners\PersistOrderTableChangedToOutbox;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [iter15-P1b — ref iter14 SPECIALIST-2 pattern]
 *
 * Verifies that the 6 listeners refactored in iter15 P1b
 * (PersistOrderTableChangedToOutbox, PersistCatalogChangedToOutbox,
 *  PersistCouponChangedToOutbox, PersistItemAvailabilityChangedToOutbox,
 *  PersistItemExtraAvailabilityChangedToOutbox,
 *  PersistItemVariationAvailabilityChangedToOutbox)
 * use a deterministic `idempotency_key` + UNIQUE-index dedupe (firstOrCreate)
 * so a duplicate listener fire within the same request collapses to a single
 * `domain_events` row.
 *
 * Pre-iter15: PersistOrderTableChangedToOutbox / Item*Availability listeners
 * used `DomainEvent::create()` (no dedupe), and PersistCatalogChangedToOutbox /
 * PersistCouponChangedToOutbox used `alreadyPersisted()` exists() probes that
 * are race-non-atomic under concurrent listener fires (TOCTOU between exists()
 * and create()).
 *
 * Note about the "concurrent" coverage:
 *   SQLite (CI test backend) treats `lockForUpdate()` as a no-op and does not
 *   expose true row-level concurrency. The UNIQUE index on `idempotency_key`
 *   IS however enforced at insert time in SQLite, so a sequential replay of
 *   `handle()` with the same key collapses to one row — that is the same
 *   contract the production race-safe path relies on. The
 *   `test_concurrent_listener_fires_atomic_via_unique_constraint` case below
 *   pins this contract honestly (sequential replay, not real concurrency) and
 *   asserts no `QueryException` leaks from the second insert.
 */
class ListenerReplayDedupeTest extends TestCase
{
    use RefreshDatabase;

    private const FIXED_CORRELATION = 'corr-iter15-p1b-replay-test-fixture-uuid';

    protected function setUp(): void
    {
        parent::setUp();

        // Pin correlation_id so each handle() call within the same test sees the
        // same correlation. Without this, resolveCorrelationId() falls back to
        // a fresh Str::uuid() per invocation and the "replay" assertion turns
        // into "two distinct events" — a false green.
        Log::shareContext(['correlation_id' => self::FIXED_CORRELATION]);

        // Don't actually queue any DispatchDomainEventsJob — we only assert the
        // outbox row count + idempotency contract.
        Bus::fake([DispatchDomainEventsJob::class]);
    }

    // ---------------------------------------------------------------------
    // 1. PersistOrderTableChangedToOutbox
    // ---------------------------------------------------------------------

    public function test_persist_order_table_changed_replay_yields_one_row(): void
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'queue_number' => 'Q-42',
        ]);

        $event = new OrderTableChanged(
            order: $order,
            previousTableId: 1,
            newTableId: 2,
            action: 'transfer'
        );

        $listener = new PersistOrderTableChangedToOutbox();
        $listener->handle($event);
        $listener->handle($event); // replay — same correlation, same tuple

        $this->assertSame(
            1,
            DomainEvent::query()->where('event_type', EventType::ORDER_TABLE_CHANGED)->count(),
            'OrderTableChanged listener replay must collapse to a single outbox row.'
        );
    }

    // ---------------------------------------------------------------------
    // 2. PersistCatalogChangedToOutbox
    // ---------------------------------------------------------------------

    public function test_persist_catalog_changed_replay_yields_one_row(): void
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        $event = new CatalogChanged(
            entityType: 'item',
            entityId: 7,
            changeType: 'updated',
            branchId: (int) $branch->id,
            payloadDiff: ['name' => 'Cheeseburger']
        );

        $listener = new PersistCatalogChangedToOutbox(app(MenuSnapshot::class));
        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(
            1,
            DomainEvent::query()
                ->where('event_type', EventType::CATALOG_CHANGED)
                ->where('aggregate_id', 7)
                ->where('branch_id', $branch->id)
                ->count(),
            'CatalogChanged listener replay must collapse to a single outbox row per branch.'
        );
    }

    public function test_persist_catalog_changed_global_fan_out_keeps_one_row_per_branch_on_replay(): void
    {
        // Three active branches — global emission (branchId = null) fans out
        // to three rows. Replay must keep exactly three rows.
        Branch::factory()->count(3)->create(['status' => Status::ACTIVE]);

        $event = new CatalogChanged(
            entityType: 'item',
            entityId: 11,
            changeType: 'updated',
            branchId: null,
            payloadDiff: []
        );

        $listener = new PersistCatalogChangedToOutbox(app(MenuSnapshot::class));
        $listener->handle($event);
        $listener->handle($event); // replay

        $this->assertSame(
            3,
            DomainEvent::query()
                ->where('event_type', EventType::CATALOG_CHANGED)
                ->where('aggregate_id', 11)
                ->count(),
            'Catalog fan-out replay must keep exactly one row per active branch (not collapse all branches into one).'
        );
    }

    // ---------------------------------------------------------------------
    // 3. PersistCouponChangedToOutbox
    // ---------------------------------------------------------------------

    public function test_persist_coupon_changed_replay_yields_one_row(): void
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        $event = new CouponChanged(
            couponId: 99,
            changeType: CouponChanged::CHANGE_UPDATED,
            code: 'PROMO-X',
            status: 1,
            branchScope: [(int) $branch->id],
            surfaces: ['pos'],
            payloadDiff: ['discount' => 10],
        );

        $listener = new PersistCouponChangedToOutbox();
        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(
            1,
            DomainEvent::query()
                ->where('event_type', EventType::COUPON_CHANGED)
                ->where('aggregate_id', 99)
                ->where('branch_id', $branch->id)
                ->count(),
            'CouponChanged listener replay must collapse to a single outbox row per branch.'
        );
    }

    public function test_persist_coupon_changed_distinct_change_types_keep_two_rows_in_same_request(): void
    {
        // A created→updated sequence for the same coupon in the same request
        // is two legitimate distinct events: the dedupe key MUST distinguish
        // them via change_type, otherwise the second event silently disappears.
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        $listener = new PersistCouponChangedToOutbox();

        $listener->handle(new CouponChanged(
            couponId: 100,
            changeType: CouponChanged::CHANGE_CREATED,
            code: 'PROMO-Y',
            status: 1,
            branchScope: [(int) $branch->id],
        ));
        $listener->handle(new CouponChanged(
            couponId: 100,
            changeType: CouponChanged::CHANGE_UPDATED,
            code: 'PROMO-Y',
            status: 1,
            branchScope: [(int) $branch->id],
        ));

        $this->assertSame(
            2,
            DomainEvent::query()
                ->where('event_type', EventType::COUPON_CHANGED)
                ->where('aggregate_id', 100)
                ->count(),
            'Distinct change_type values must yield distinct outbox rows.'
        );
    }

    // ---------------------------------------------------------------------
    // 4. PersistItemAvailabilityChangedToOutbox
    // ---------------------------------------------------------------------

    public function test_persist_item_availability_changed_replay_yields_one_row(): void
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        $event = ItemAvailabilityChanged::forBranch(
            itemId: 5,
            branchId: (int) $branch->id,
            isAvailable: false,
            reason: 'manual_86',
            price: 9.5,
        );

        $listener = new PersistItemAvailabilityChangedToOutbox();
        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(
            1,
            DomainEvent::query()
                ->where('event_type', EventType::MENU_ITEM_AVAILABILITY_CHANGED)
                ->where('aggregate_id', 5)
                ->where('branch_id', $branch->id)
                ->count(),
            'ItemAvailabilityChanged branch-scoped replay must collapse to one row.'
        );
    }

    public function test_persist_item_availability_changed_global_and_branch_scoped_coexist(): void
    {
        // Sanity check: a global emission (branchId=null) followed by a
        // branch-scoped emission for the same item in the same request must
        // keep BOTH rows — they target different downstream channels.
        Branch::factory()->count(2)->create(['status' => Status::ACTIVE]);
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        $listener = new PersistItemAvailabilityChangedToOutbox();

        $listener->handle(new ItemAvailabilityChanged(
            itemId: 8, status: 1, price: 12.0, type: 'status'
        ));
        $listener->handle(ItemAvailabilityChanged::forBranch(
            itemId: 8,
            branchId: (int) $branch->id,
            isAvailable: false,
            reason: null,
            price: 12.0,
        ));

        $this->assertSame(
            2,
            DomainEvent::query()
                ->where('event_type', EventType::MENU_ITEM_AVAILABILITY_CHANGED)
                ->where('aggregate_id', 8)
                ->count(),
            'Global emission and branch-scoped emission must coexist (distinct rows).'
        );
    }

    // ---------------------------------------------------------------------
    // 5. PersistItemExtraAvailabilityChangedToOutbox
    // ---------------------------------------------------------------------

    public function test_persist_item_extra_availability_changed_replay_yields_one_row(): void
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        $event = new ItemExtraAvailabilityChanged(
            extraId: 3,
            branchId: (int) $branch->id,
            isAvailable: false,
            reason: 'rupture',
        );

        $listener = new PersistItemExtraAvailabilityChangedToOutbox();
        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(
            1,
            DomainEvent::query()
                ->where('event_type', EventType::MENU_EXTRA_AVAILABILITY_CHANGED)
                ->where('aggregate_id', 3)
                ->where('branch_id', $branch->id)
                ->count(),
            'ItemExtraAvailabilityChanged replay must collapse to one row.'
        );
    }

    // ---------------------------------------------------------------------
    // 6. PersistItemVariationAvailabilityChangedToOutbox
    // ---------------------------------------------------------------------

    public function test_persist_item_variation_availability_changed_replay_yields_one_row(): void
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        $event = new ItemVariationAvailabilityChanged(
            variationId: 17,
            branchId: (int) $branch->id,
            isAvailable: true,
            reason: null,
        );

        $listener = new PersistItemVariationAvailabilityChangedToOutbox();
        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(
            1,
            DomainEvent::query()
                ->where('event_type', EventType::MENU_VARIATION_AVAILABILITY_CHANGED)
                ->where('aggregate_id', 17)
                ->where('branch_id', $branch->id)
                ->count(),
            'ItemVariationAvailabilityChanged replay must collapse to one row.'
        );
    }

    // ---------------------------------------------------------------------
    // 7. UNIQUE-constraint atomicity contract (replay-as-pseudo-concurrency)
    // ---------------------------------------------------------------------

    /**
     * SQLite (CI test backend) treats `lockForUpdate()` as a no-op — Laravel
     * does not emit row-level locks outside MySQL/Postgres. We therefore
     * exercise the UNIQUE-constraint atomicity contract via a sequential replay
     * of `handle()` rather than true concurrency. The contract is the same:
     * a second insert with the same idempotency_key MUST collapse silently
     * (firstOrCreate caught the conflict) and MUST NOT leak a QueryException.
     *
     * Across all 6 listeners, the total final outbox row count must equal
     * the number of listeners, not 2× — i.e. each listener's replay collapsed.
     */
    public function test_concurrent_listener_fires_atomic_via_unique_constraint(): void
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'queue_number' => 'Q-cc',
        ]);

        $listeners = [
            'order_table_changed' => function () use ($order) {
                $event = new OrderTableChanged(
                    order: $order,
                    previousTableId: 5,
                    newTableId: 6,
                );
                (new PersistOrderTableChangedToOutbox())->handle($event);
                (new PersistOrderTableChangedToOutbox())->handle($event);
            },
            'catalog_changed' => function () use ($branch) {
                $event = new CatalogChanged('item', 50, 'updated', (int) $branch->id);
                $listener = new PersistCatalogChangedToOutbox(app(MenuSnapshot::class));
                $listener->handle($event);
                $listener->handle($event);
            },
            'coupon_changed' => function () use ($branch) {
                $event = new CouponChanged(
                    couponId: 60,
                    changeType: CouponChanged::CHANGE_UPDATED,
                    code: 'CC',
                    status: 1,
                    branchScope: [(int) $branch->id],
                );
                $listener = new PersistCouponChangedToOutbox();
                $listener->handle($event);
                $listener->handle($event);
            },
            'item_availability' => function () use ($branch) {
                $event = ItemAvailabilityChanged::forBranch(
                    itemId: 70, branchId: (int) $branch->id, isAvailable: false, reason: null
                );
                $listener = new PersistItemAvailabilityChangedToOutbox();
                $listener->handle($event);
                $listener->handle($event);
            },
            'item_extra_availability' => function () use ($branch) {
                $event = new ItemExtraAvailabilityChanged(80, (int) $branch->id, false, null);
                $listener = new PersistItemExtraAvailabilityChangedToOutbox();
                $listener->handle($event);
                $listener->handle($event);
            },
            'item_variation_availability' => function () use ($branch) {
                $event = new ItemVariationAvailabilityChanged(90, (int) $branch->id, true, null);
                $listener = new PersistItemVariationAvailabilityChangedToOutbox();
                $listener->handle($event);
                $listener->handle($event);
            },
        ];

        foreach ($listeners as $label => $closure) {
            try {
                $closure();
            } catch (QueryException $e) {
                $this->fail("Listener {$label} leaked a QueryException on replay — UNIQUE conflict not absorbed by firstOrCreate: " . $e->getMessage());
            }
        }

        $this->assertSame(
            6,
            DomainEvent::query()->count(),
            'Each of the 6 listeners must have produced exactly one outbox row after replay (6 listeners × 1 row each).'
        );
    }
}
