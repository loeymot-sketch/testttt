<?php

namespace Tests\Feature\Stock;

use App\Events\ItemAvailabilityChanged;
use App\Events\StockLevelChanged;
use App\Listeners\NotifyStockLowOnStockLevelChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * @FK-ID HEAL-2 V102-03 — Stock rupture alert listener invariants.
 *
 * @source heal/cms-pr1-quickwins-2026-05-18 (Heal Agent 2 mission 2026-05-26)
 * @severity P1 (locks anti-double-broadcast invariant)
 *
 * Owner clarification: "pour le moment, les stocks sont juste configurés par
 * stock ou bien rupture" — V1 Le Cayenne tracks BINARY availability (in stock /
 * out of stock), not quantity portions. Threshold semantics (`threshold_low`)
 * remain available for V1.0.2 admin UI but are no-op at runtime today.
 *
 * Invariants pinned here:
 *  1. Listener is wired to StockLevelChanged in EventServiceProvider
 *     (regression guard — unwiring would silently disable preventive logs).
 *  2. Listener short-circuits when FK_CATALOG_STOCK_LOW_ALERT_ENABLED=false
 *     (allows owner kill-switch without redeploy).
 *  3. Listener short-circuits when threshold_low=0 (V1 binary mode safety).
 *  4. Listener MUST NOT broadcast ItemAvailabilityChanged from its handle()
 *     path. Binary rupture broadcast already flows through
 *     StockService::syncItemAvailabilityForStockLevel → ItemAvailabilityChanged::forBranch
 *     → private-branch.{id} → POS/KDS toast. Duplicate broadcast here would
 *     double-toast cashier+chef on every rupture (UX regression).
 *
 * Anti-drift: a future agent refactoring this listener to "also broadcast"
 * the rupture would silently introduce double-toasts. Case 4 catches that.
 */
class StockRuptureAlertListenerSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_is_wired_in_event_service_provider(): void
    {
        // Structural sentinel: registration must survive future refactors.
        $listeners = Event::getListeners(StockLevelChanged::class);

        $listenerClassNames = array_map(
            static function ($listener): string {
                // Laravel wraps listener class names in closures; reflect to
                // recover the original FQCN registered in EventServiceProvider.
                if ($listener instanceof \Closure) {
                    try {
                        $reflection = new \ReflectionFunction($listener);
                        $vars = $reflection->getStaticVariables();
                        // Standard Laravel ListenerSubscriber wraps the listener
                        // as a string in the `$listener` static variable.
                        return is_string($vars['listener'] ?? null) ? $vars['listener'] : '';
                    } catch (\Throwable) {
                        return '';
                    }
                }

                return is_string($listener) ? $listener : (is_object($listener) ? $listener::class : '');
            },
            $listeners,
        );

        $this->assertContains(
            NotifyStockLowOnStockLevelChanged::class,
            $listenerClassNames,
            'NotifyStockLowOnStockLevelChanged must remain wired to StockLevelChanged'
            . ' (regression guard — unwiring silently disables preventive low-stock logs).',
        );
    }

    public function test_listener_short_circuits_when_flag_disabled(): void
    {
        config(['catalog_v15.stock_low_alert.enabled' => false]);
        Cache::flush();

        // Spy on broadcasts: nothing must happen with flag off.
        Event::fake([ItemAvailabilityChanged::class]);

        [$row, $event] = $this->makeRow(['on_hand' => 0, 'threshold_low' => 5]);
        app(NotifyStockLowOnStockLevelChanged::class)->handle($event);

        Event::assertNotDispatched(ItemAvailabilityChanged::class);
        $this->assertFalse(
            Cache::has("stock_low_alert:{$row->branch_id}:" . \App\Models\Item::class . ":{$row->stockable_id}"),
            'Throttle cache key must not be written when flag is disabled.',
        );
    }

    public function test_listener_short_circuits_when_threshold_zero_v1_binary_mode(): void
    {
        // V1 Le Cayenne: all stock_levels rows have threshold_low=0. The flag
        // is enabled but the listener should still be a runtime no-op.
        config(['catalog_v15.stock_low_alert.enabled' => true]);
        Cache::flush();
        Event::fake([ItemAvailabilityChanged::class]);

        [$row, $event] = $this->makeRow(['on_hand' => 0, 'threshold_low' => 0]);
        app(NotifyStockLowOnStockLevelChanged::class)->handle($event);

        Event::assertNotDispatched(ItemAvailabilityChanged::class);
        $this->assertFalse(
            Cache::has("stock_low_alert:{$row->branch_id}:" . \App\Models\Item::class . ":{$row->stockable_id}"),
            'Listener must short-circuit before throttle cache when threshold_low=0 (V1 binary mode).',
        );
    }

    public function test_listener_does_not_broadcast_item_availability_changed(): void
    {
        // The load-bearing invariant: even when the listener path activates
        // (flag enabled + threshold_low > 0 + on_hand below threshold), it
        // MUST NOT emit an ItemAvailabilityChanged broadcast. Rupture toast
        // is broadcast exclusively through StockService::syncItemAvailability...
        // → ItemAvailabilityChanged::forBranch — re-broadcasting here would
        // double-toast cashier+chef.
        config([
            'catalog_v15.stock_low_alert.enabled' => true,
            'catalog_v15.stock_low_alert.throttle_seconds' => 3600,
        ]);
        Cache::flush();
        Event::fake([ItemAvailabilityChanged::class]);

        [$row, $event] = $this->makeRow(['on_hand' => 2, 'threshold_low' => 5]);
        app(NotifyStockLowOnStockLevelChanged::class)->handle($event);

        Event::assertNotDispatched(
            ItemAvailabilityChanged::class,
            'NotifyStockLowOnStockLevelChanged MUST NOT broadcast ItemAvailabilityChanged.'
            . ' Rupture toast is owned by StockService::syncItemAvailabilityForStockLevel.'
            . ' Re-broadcasting here would double-toast cashier+chef on every rupture.',
        );
    }

    public function test_listener_does_not_re_broadcast_when_binary_rupture_crosses_zero(): void
    {
        // Realistic V1 scenario: cashier sells the last unit. StockService
        // dispatches ItemAvailabilityChanged::forBranch(false, 'stock_rupture')
        // AND StockLevelChanged (for the choice-boundary mutation case).
        // The NotifyStockLowOnStockLevelChanged listener receives the latter
        // and must remain quiet — preventive log only.
        config(['catalog_v15.stock_low_alert.enabled' => true]);
        Cache::flush();
        Event::fake([ItemAvailabilityChanged::class]);

        [$row, $event] = $this->makeRow(['on_hand' => 0, 'threshold_low' => 0]);
        app(NotifyStockLowOnStockLevelChanged::class)->handle($event);

        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }

    /**
     * @param  array{on_hand: int, threshold_low: int}  $attributes
     * @return array{0: StockLevel, 1: StockLevelChanged}
     */
    private function makeRow(array $attributes): array
    {
        $branch = Branch::factory()->create();
        $item   = Item::factory()->create();
        $row    = StockLevel::query()->create(array_merge([
            'branch_id'      => $branch->id,
            'stockable_type' => Item::class,
            'stockable_id'   => $item->id,
            'on_hand'        => 0,
            'reserved'       => 0,
            'threshold_low'  => 0,
        ], $attributes));

        return [$row, new StockLevelChanged($branch->id, [$row->id])];
    }
}
