<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupTestFixturesCommand extends Command
{
    protected $signature = 'foodking:cleanup-test-fixtures
        {--prefix=PW- : Test fixture prefix to remove}
        {--dry-run : Report matching rows without deleting}
        {--apply : Delete matching rows}
        {--confirm= : Required confirmation token for apply mode}
        {--json : Print machine-readable JSON}';

    protected $description = 'Safely remove Playwright/Codex test fixtures from local or staging databases.';

    public function handle(): int
    {
        $prefix = trim((string) $this->option('prefix'));
        if ($prefix === '' || strlen($prefix) < 3) {
            $this->error('Prefix must be at least 3 characters.');
            return 2;
        }

        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;
        $confirm = (string) $this->option('confirm');

        $before = $this->buildReport($prefix);
        $deleted = [];

        if ($apply) {
            if ($confirm !== 'PW-FIXTURES') {
                $this->error('Refusing apply mode without --confirm=PW-FIXTURES.');
                return 2;
            }

            if (app()->environment('production')) {
                $this->error('Refusing to delete test fixtures in production.');
                return 2;
            }

            $deleted = $this->deleteFixtures($prefix);
            Cache::flush();
        }

        $after = $apply ? $this->buildReport($prefix) : $before;
        $payload = [
            'prefix' => $prefix,
            'dry_run' => $dryRun,
            'applied' => $apply,
            'confirmation_required' => 'PW-FIXTURES',
            'before' => $before,
            'deleted' => $deleted,
            'after' => $after,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $this->info($apply ? 'FoodKing test fixtures cleanup applied.' : 'FoodKing test fixtures cleanup dry-run.');
        foreach ($before as $key => $count) {
            $this->line(str_pad($key, 34) . ': ' . $count);
        }

        return 0;
    }

    /**
     * @return array<string,int>
     */
    public function buildReport(string $prefix = 'PW-'): array
    {
        $ids = $this->collectIds($prefix);

        return [
            'orders' => $ids['order_ids']->count(),
            'order_items' => $ids['order_item_ids']->count(),
            'transactions' => $this->countWhereIn('transactions', 'order_id', $ids['order_ids']),
            'domain_events' => $this->countWhereIn('domain_events', 'aggregate_id', $ids['order_ids']),
            'stock_movements' => $ids['stock_movement_ids']->count(),
            'stock_levels' => $ids['stock_level_ids']->count(),
            'item_addons' => $ids['addon_ids']->count(),
            'item_extras' => $ids['extra_ids']->count(),
            'item_variations' => $ids['variation_ids']->count(),
            'item_wizard_profiles' => $ids['wizard_profile_ids']->count(),
            'item_wizard_steps' => $ids['wizard_step_ids']->count(),
            'item_branch_availability' => $this->countWhereIn('item_branch_availability', 'item_id', $ids['item_ids']),
            'items' => $ids['item_ids']->count(),
            'item_categories' => $ids['category_ids']->count(),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function deleteFixtures(string $prefix): array
    {
        return DB::transaction(function () use ($prefix): array {
            $ids = $this->collectIds($prefix);
            $deleted = [];

            $deleted['transactions'] = $this->deleteWhereIn('transactions', 'order_id', $ids['order_ids']);
            $deleted['order_status_transitions'] = $this->deleteWhereIn('order_status_transitions', 'order_id', $ids['order_ids']);
            $deleted['domain_events'] = $this->deleteWhereIn('domain_events', 'aggregate_id', $ids['order_ids']);
            $deleted['audit_logs'] = 0;
            $deleted['order_items'] = $this->deleteWhereIn('order_items', 'id', $ids['order_item_ids']);
            $deleted['orders'] = $this->deleteWhereIn('orders', 'id', $ids['order_ids']);
            $deleted['stock_movements'] = $this->deleteWhereIn('stock_movements', 'id', $ids['stock_movement_ids']);
            $deleted['stock_levels'] = $this->deleteWhereIn('stock_levels', 'id', $ids['stock_level_ids']);
            $deleted['item_branch_availability'] = $this->deleteWhereIn('item_branch_availability', 'item_id', $ids['item_ids']);
            $deleted['item_wizard_steps'] = $this->deleteWhereIn('item_wizard_steps', 'id', $ids['wizard_step_ids']);
            $deleted['item_wizard_profiles'] = $this->deleteWhereIn('item_wizard_profiles', 'id', $ids['wizard_profile_ids']);
            $deleted['item_addons'] = $this->deleteWhereIn('item_addons', 'id', $ids['addon_ids']);
            $deleted['item_extras'] = $this->deleteWhereIn('item_extras', 'id', $ids['extra_ids']);
            $deleted['item_variations'] = $this->deleteWhereIn('item_variations', 'id', $ids['variation_ids']);
            $deleted['items'] = $this->deleteWhereIn('items', 'id', $ids['item_ids']);
            $deleted['item_categories'] = $this->deleteWhereIn('item_categories', 'id', $ids['category_ids']);

            return $deleted;
        });
    }

    /**
     * @return array<string,Collection<int,int>>
     */
    private function collectIds(string $prefix): array
    {
        $categoryIds = $this->matchingIds('item_categories', $prefix);
        $itemIds = $this->matchingIds('items', $prefix);
        if ($categoryIds->isNotEmpty() && Schema::hasTable('items') && Schema::hasColumn('items', 'item_category_id')) {
            $itemIds = $itemIds
                ->merge(DB::table('items')->whereIn('item_category_id', $categoryIds)->pluck('id'))
                ->unique()
                ->values();
        }

        $variationIds = $this->childIds('item_variations', 'item_id', $itemIds);
        $extraIds = $this->childIds('item_extras', 'item_id', $itemIds);
        $addonIds = collect();
        if (Schema::hasTable('item_addons')) {
            $addonIds = DB::table('item_addons')
                ->whereIn('item_id', $itemIds)
                ->orWhereIn('addon_item_id', $itemIds)
                ->pluck('id')
                ->unique()
                ->values();
        }

        $orderIds = collect();
        if (Schema::hasTable('orders')) {
            $orderIds = DB::table('orders')
                ->where('order_serial_no', 'like', $prefix . '%')
                ->orWhere('token', 'like', $prefix . '%')
                ->pluck('id');
        }
        if (Schema::hasTable('order_items')) {
            if ($itemIds->isNotEmpty()) {
                $orderIds = $orderIds->merge(DB::table('order_items')->whereIn('item_id', $itemIds)->pluck('order_id'));
            }
            if (Schema::hasColumn('order_items', 'instruction')) {
                $orderIds = $orderIds->merge(DB::table('order_items')->where('instruction', 'like', $prefix . '%')->pluck('order_id'));
            }
        }
        $orderIds = $orderIds->unique()->values();
        $orderItemIds = $this->childIds('order_items', 'order_id', $orderIds);

        $stockLevelIds = $this->stockLevelIds($itemIds, $variationIds, $extraIds);
        $stockMovementIds = collect();
        if (Schema::hasTable('stock_movements')) {
            if ($stockLevelIds->isNotEmpty() && Schema::hasColumn('stock_movements', 'stock_level_id')) {
                $stockMovementIds = $stockMovementIds->merge(DB::table('stock_movements')->whereIn('stock_level_id', $stockLevelIds)->pluck('id'));
            }
            if (Schema::hasColumn('stock_movements', 'reference_id')) {
                $stockMovementIds = $stockMovementIds->merge(DB::table('stock_movements')->whereIn('reference_id', $orderIds)->pluck('id'));
            }
            if (Schema::hasColumn('stock_movements', 'idempotency_key')) {
                $stockMovementIds = $stockMovementIds->merge(DB::table('stock_movements')->where('idempotency_key', 'like', $prefix . '%')->pluck('id'));
            }
        }

        $wizardProfileIds = $this->childIds('item_wizard_profiles', 'item_id', $itemIds);
        $wizardStepIds = $this->childIds('item_wizard_steps', 'profile_id', $wizardProfileIds);

        return [
            'category_ids' => $categoryIds,
            'item_ids' => $itemIds,
            'variation_ids' => $variationIds,
            'extra_ids' => $extraIds,
            'addon_ids' => $addonIds,
            'order_ids' => $orderIds,
            'order_item_ids' => $orderItemIds,
            'stock_level_ids' => $stockLevelIds,
            'stock_movement_ids' => $stockMovementIds->unique()->values(),
            'wizard_profile_ids' => $wizardProfileIds,
            'wizard_step_ids' => $wizardStepIds,
        ];
    }

    /**
     * @return Collection<int,int>
     */
    private function matchingIds(string $table, string $prefix): Collection
    {
        if (! Schema::hasTable($table)) {
            return collect();
        }

        $hasName = Schema::hasColumn($table, 'name');
        $hasSlug = Schema::hasColumn($table, 'slug');

        return DB::table($table)
            ->where(function ($query) use ($prefix, $hasName, $hasSlug): void {
                if ($hasName) {
                    $query->where('name', 'like', $prefix . '%');
                }
                if ($hasSlug) {
                    $query->orWhere('slug', 'like', strtolower($prefix) . '%');
                }
            })
            ->pluck('id')
            ->unique()
            ->values();
    }

    /**
     * @param Collection<int,int> $ids
     * @return Collection<int,int>
     */
    private function childIds(string $table, string $column, Collection $ids): Collection
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $ids->isEmpty()) {
            return collect();
        }

        return DB::table($table)->whereIn($column, $ids)->pluck('id')->unique()->values();
    }

    /**
     * @param Collection<int,int> $itemIds
     * @param Collection<int,int> $variationIds
     * @param Collection<int,int> $extraIds
     * @return Collection<int,int>
     */
    private function stockLevelIds(Collection $itemIds, Collection $variationIds, Collection $extraIds): Collection
    {
        if (! Schema::hasTable('stock_levels')) {
            return collect();
        }

        return DB::table('stock_levels')
            ->where(function ($query) use ($itemIds, $variationIds, $extraIds): void {
                if ($itemIds->isNotEmpty()) {
                    $query->where(function ($stock) use ($itemIds): void {
                        $stock->where('stockable_type', Item::class)->whereIn('stockable_id', $itemIds);
                    });
                }
                if ($variationIds->isNotEmpty()) {
                    $query->orWhere(function ($stock) use ($variationIds): void {
                        $stock->where('stockable_type', ItemVariation::class)->whereIn('stockable_id', $variationIds);
                    });
                }
                if ($extraIds->isNotEmpty()) {
                    $query->orWhere(function ($stock) use ($extraIds): void {
                        $stock->where('stockable_type', ItemExtra::class)->whereIn('stockable_id', $extraIds);
                    });
                }
            })
            ->pluck('id')
            ->unique()
            ->values();
    }

    /**
     * @param Collection<int,int> $ids
     */
    private function countWhereIn(string $table, string $column, Collection $ids): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $ids->isEmpty()) {
            return 0;
        }

        return (int) DB::table($table)->whereIn($column, $ids)->count();
    }

    /**
     * @param Collection<int,int> $ids
     */
    private function deleteWhereIn(string $table, string $column, Collection $ids): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $ids->isEmpty()) {
            return 0;
        }

        return (int) DB::table($table)->whereIn($column, $ids)->delete();
    }

}
