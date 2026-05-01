<?php

namespace App\Listeners;

use App\Models\Branch;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InvalidateKioskMenuCacheOnCatalogChange
{
    private const CACHE_KEY_PREFIX = 'kiosk.menu.branch.';
    private const SYNC_WARNING_THRESHOLD = 100;

    public function __construct(
        private readonly MenuSnapshot $snapshot,
    ) {
    }

    public function handle(object $event): void
    {
        try {
            $branchId = property_exists($event, 'branchId') ? $event->branchId : null;

            if ($branchId !== null) {
                $this->forgetBranch((int) $branchId, $event);
                return;
            }

            $branchIds = Branch::query()->pluck('id');
            if ($branchIds->count() > self::SYNC_WARNING_THRESHOLD) {
                Log::warning('[KioskMenu] catalog invalidation iterating many branches synchronously', [
                    'branch_count' => $branchIds->count(),
                    'event' => $event::class,
                ]);
            }

            $branchIds->each(function ($id) use ($event): void {
                $this->forgetBranch((int) $id, $event);
            });
        } catch (\Throwable $e) {
            Log::warning('[KioskMenu] catalog cache invalidation failed', [
                'event' => $event::class,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function forgetBranch(int $branchId, object $event): void
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $branchId;
        Cache::forget($cacheKey);
        $snapshotVersion = $this->snapshot->bump($branchId);

        Log::info('[KioskMenu] catalog cache invalidated', [
            'branch_id' => $branchId,
            'cache_key' => $cacheKey,
            'snapshot_version' => $snapshotVersion,
            'event' => $event::class,
        ]);
    }
}
