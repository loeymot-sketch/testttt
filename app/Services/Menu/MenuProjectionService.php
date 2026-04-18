<?php

namespace App\Services\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use Illuminate\Support\Collection;

/**
 * Dual-channel menu SSOT projection — V1 section 5 foundation.
 *
 * One catalog (`items` + `item_categories`) projected differently per surface:
 *   - `pos`    : cashier catalog, uses `pos_sort` fallback to `sort`, full names.
 *   - `kiosk`  : customer borne, uses `kiosk_sort`, `kiosk_label`, exposes `kiosk_emoji`.
 *   - `web`    : public site (reserved for V1.5+), same engine with `channel='web'`.
 *
 * Rules:
 *   - NULL `channels` JSON → visible on every surface (V1 back-compat default).
 *   - Availability resolved via `item_branch_availability` (MENU_86 table).
 *     Absent row = available by default.
 *   - Caller supplies the branch; availability is branch-scoped.
 *   - Consumers can poll {@see MenuSnapshot::current()} to decide if the
 *     cached projection needs refresh (cheaper than diffing the full JSON).
 *
 * Consumers today (POS / Kiosk controllers) are NOT yet plugged into this
 * service; they remain on their legacy per-surface queries. V1.5 migrates
 * each surface one at a time to this single code path.
 *
 * @see docs/MENU_PROJECTIONS.md
 */
final class MenuProjectionService
{
    public const CHANNEL_POS = 'pos';
    public const CHANNEL_KIOSK = 'kiosk';
    public const CHANNEL_WEB = 'web';

    public const SUPPORTED_CHANNELS = [
        self::CHANNEL_POS,
        self::CHANNEL_KIOSK,
        self::CHANNEL_WEB,
    ];

    public function __construct(
        private readonly MenuSnapshot $snapshot,
    ) {
    }

    /**
     * Build the channel-scoped projection for a branch.
     *
     * @return array{
     *   categories: array<int, array<string, mixed>>,
     *   snapshot_version: int,
     *   branch_id: int,
     *   channel: string,
     * }
     */
    public function forChannel(string $channel, int $branchId): array
    {
        $channel = $this->normalizeChannel($channel);

        $categories = ItemCategory::query()
            ->where('status', Status::ACTIVE)
            ->get();

        $visibleCategories = $categories
            ->filter(fn (ItemCategory $cat): bool => $cat->isVisibleOn($channel))
            ->sortBy(fn (ItemCategory $cat): int => $cat->sortFor($channel))
            ->values();

        if ($visibleCategories->isEmpty()) {
            return $this->envelope([], $branchId, $channel);
        }

        $categoryIds = $visibleCategories->pluck('id')->all();
        $items = Item::query()
            ->where('status', Status::ACTIVE)
            ->whereIn('item_category_id', $categoryIds)
            ->get();

        $availability = ItemBranchAvailability::query()
            ->whereIn('item_id', $items->pluck('id'))
            ->where('branch_id', $branchId)
            ->get()
            ->keyBy('item_id');

        $itemsByCategory = $items
            ->filter(fn (Item $it): bool => $it->isVisibleOn($channel))
            ->groupBy('item_category_id');

        $out = $visibleCategories->map(function (ItemCategory $cat) use ($channel, $itemsByCategory, $availability): array {
            $catItems = ($itemsByCategory->get($cat->id) ?? collect())
                ->sortBy(fn (Item $it): int => (int) ($it->order ?? 0))
                ->values();

            return [
                'id'              => (int) $cat->id,
                'slug'            => (string) $cat->slug,
                'name'            => $cat->displayNameFor($channel),
                'sort'            => $cat->sortFor($channel),
                'wizard_template' => $cat->wizard_template,
                'items'           => $this->projectItems($catItems, $channel, $availability),
            ];
        })->values()->all();

        return $this->envelope($out, $branchId, $channel);
    }

    /**
     * @param  Collection<int, Item>  $items
     * @param  Collection<int, ItemBranchAvailability>  $availability  keyed by item_id
     * @return array<int, array<string, mixed>>
     */
    private function projectItems(Collection $items, string $channel, Collection $availability): array
    {
        return $items->map(function (Item $item) use ($channel, $availability): array {
            $row = $availability->get($item->id);
            $available = $row ? (bool) $row->is_available : true;

            $projected = [
                'id'         => (int) $item->id,
                'name'       => (string) $item->name,
                'slug'       => (string) $item->slug,
                'price'      => (float) $item->price,
                'available'  => $available,
                'is_upsell'  => (bool) $item->is_upsell,
                'is_featured'=> (bool) $item->is_featured,
                'allergens'  => $item->allergen_flags ?? [],
            ];

            if ($row && !$available) {
                $projected['unavailable_reason'] = $row->unavailable_reason;
            }

            if ($channel === self::CHANNEL_KIOSK && !empty($item->kiosk_emoji)) {
                $projected['emoji'] = (string) $item->kiosk_emoji;
            }

            return $projected;
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    private function envelope(array $categories, int $branchId, string $channel): array
    {
        return [
            'categories'       => $categories,
            'snapshot_version' => $this->snapshot->current($branchId),
            'branch_id'        => $branchId,
            'channel'          => $channel,
        ];
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, self::SUPPORTED_CHANNELS, true)) {
            throw new \InvalidArgumentException(
                "Unsupported menu channel '{$channel}'. Expected one of: "
                . implode(', ', self::SUPPORTED_CHANNELS)
            );
        }

        return $channel;
    }
}
