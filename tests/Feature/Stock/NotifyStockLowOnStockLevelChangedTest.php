<?php

namespace Tests\Feature\Stock;

use App\Events\StockLevelChanged;
use App\Listeners\NotifyStockLowOnStockLevelChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class NotifyStockLowOnStockLevelChangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_logs_warning_when_on_hand_below_threshold_and_flag_enabled(): void
    {
        config(['catalog_v15.stock_low_alert.enabled' => true]);
        Cache::flush();
        Log::shouldReceive('warning')->once()
            ->with('[stock.low-alert] threshold reached', Mockery::on(fn ($ctx) =>
                $ctx['on_hand'] === 2 && $ctx['threshold_low'] === 5
            ));

        [$row, $event] = $this->makeEvent(['on_hand' => 2, 'threshold_low' => 5]);

        app(NotifyStockLowOnStockLevelChanged::class)->handle($event);
    }

    public function test_listener_skips_when_flag_disabled(): void
    {
        config(['catalog_v15.stock_low_alert.enabled' => false]);
        Log::shouldReceive('warning')->never();

        [$row, $event] = $this->makeEvent(['on_hand' => 0, 'threshold_low' => 5]);

        app(NotifyStockLowOnStockLevelChanged::class)->handle($event);
        $this->assertTrue(true);
    }

    public function test_listener_throttles_repeat_alerts_within_window(): void
    {
        config([
            'catalog_v15.stock_low_alert.enabled' => true,
            'catalog_v15.stock_low_alert.throttle_seconds' => 3600,
        ]);
        Cache::flush();
        Log::shouldReceive('warning')->once();

        [$row, $event] = $this->makeEvent(['on_hand' => 1, 'threshold_low' => 10]);

        app(NotifyStockLowOnStockLevelChanged::class)->handle($event);
        app(NotifyStockLowOnStockLevelChanged::class)->handle($event);
    }

    public function test_listener_skips_rows_above_threshold(): void
    {
        config(['catalog_v15.stock_low_alert.enabled' => true]);
        Cache::flush();
        Log::shouldReceive('warning')->never();

        [$row, $event] = $this->makeEvent(['on_hand' => 15, 'threshold_low' => 10]);

        app(NotifyStockLowOnStockLevelChanged::class)->handle($event);
        $this->assertTrue(true);
    }

    /**
     * @param  array{on_hand: int, threshold_low: int}  $attributes
     * @return array{0: StockLevel, 1: StockLevelChanged}
     */
    private function makeEvent(array $attributes): array
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $row = StockLevel::query()->create(array_merge([
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
