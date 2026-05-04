<?php

namespace App\Listeners;

use App\Enums\Status;
use App\Events\CatalogChanged;
use App\Events\IngredientAvailabilityChanged;
use App\Models\Branch;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InvalidateMenuProjectionOnIngredientChange
{
    private const CACHE_KEY_PREFIX = 'kiosk.menu.branch.';

    public function __construct(
        private readonly MenuSnapshot $snapshot,
    ) {
    }

    public function handle(IngredientAvailabilityChanged $event): void
    {
        try {
            $branchIds = $event->branchId !== null
                ? collect([(int) $event->branchId])
                : Branch::query()
                    ->where('status', Status::ACTIVE)
                    ->pluck('id')
                    ->map(fn ($branchId): int => (int) $branchId);

            $branchIds->each(function (int $branchId) use ($event): void {
                $this->forgetBranch($branchId, $event);
            });

            CatalogChanged::dispatch(
                entityType: 'ingredient',
                entityId: $event->id,
                changeType: 'availability_changed',
                branchId: $event->branchId,
                payloadDiff: [
                    'type' => $event->type,
                    'is_available' => $event->isAvailable,
                    'reason' => $event->reason,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('[KioskMenu] ingredient cache invalidation failed', [
                'ingredient_type' => $event->type,
                'ingredient_id' => $event->id,
                'branch_id' => $event->branchId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function forgetBranch(int $branchId, IngredientAvailabilityChanged $event): void
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $branchId;
        Cache::forget($cacheKey);
        $snapshotVersion = $this->snapshot->bump($branchId);

        Log::info('[KioskMenu] ingredient cache invalidated', [
            'branch_id' => $branchId,
            'cache_key' => $cacheKey,
            'snapshot_version' => $snapshotVersion,
            'ingredient_type' => $event->type,
            'ingredient_id' => $event->id,
        ]);
    }
}
