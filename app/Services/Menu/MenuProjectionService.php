<?php

namespace App\Services\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Services\Composer\ComposerProfileProjection;
use App\Services\Stock\ChoiceAvailabilityResolver;
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
 * Runtime contract today: POS/Kiosk/KDS sync tests and menu-projection APIs use
 * this service as the canonical catalog projection. Legacy per-surface callers
 * may still exist, but they must preserve parity with this SSOT projection.
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
        private readonly ?ComposerProfileProjection $composerProjection = null,
        private readonly ?ChoiceAvailabilityResolver $choiceAvailabilityResolver = null,
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
            ->with([
                'variations:id,item_id,item_attribute_id,name,price,visible_on,status',
                'extras:id,item_id,name,price,visible_on,group_label,status',
                'addons:id,item_id,addon_item_id,addon_item_variation,role',
                'addons.addonItem:id,name,status,is_available,channels',
            ])
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

        $out = $visibleCategories->map(function (ItemCategory $cat) use ($channel, $itemsByCategory, $availability, $branchId): array {
            $catItems = ($itemsByCategory->get($cat->id) ?? collect())
                ->sortBy(fn (Item $it): int => (int) ($it->order ?? 0))
                ->values();

            return [
                'id'              => (int) $cat->id,
                'slug'            => (string) $cat->slug,
                'name'            => $cat->displayNameFor($channel),
                'sort'            => $cat->sortFor($channel),
                'wizard_template' => $cat->wizard_template,
                'items'           => $this->projectItems($catItems, $channel, $availability, $branchId),
            ];
        })->values()->all();

        return $this->envelope($out, $branchId, $channel);
    }

    /**
     * @param  Collection<int, Item>  $items
     * @param  Collection<int, ItemBranchAvailability>  $availability  keyed by item_id
     * @return array<int, array<string, mixed>>
     */
    private function projectItems(Collection $items, string $channel, Collection $availability, int $branchId): array
    {
        $composerProfiles = $this->publishedComposerProfiles($items, $branchId);
        $choiceAvailability = $this->choiceAvailabilityResolver()->snapshotForItems($items, $branchId, $channel);

        return $items->map(function (Item $item) use ($channel, $availability, $composerProfiles, $choiceAvailability, $branchId): array {
            $row = $availability->get($item->id);
            $branchAvailable = $row ? (bool) $row->is_available : true;
            $globalAvailable = $item->is_available === null ? true : (bool) $item->is_available;
            $available = $branchAvailable && $globalAvailable;
            $itemChoiceAvailability = $choiceAvailability[(int) $item->id] ?? [
                'variations' => [],
                'extras' => [],
                'addons' => [],
            ];

            $projected = [
                'id'               => (int) $item->id,
                'category_id'      => (int) $item->item_category_id,
                'item_category_id' => (int) $item->item_category_id,
                'name'             => (string) $item->name,
                'slug'             => (string) $item->slug,
                'price'            => (float) $item->price,
                'tax_id'           => $item->tax_id !== null ? (int) $item->tax_id : null,
                'item_type'        => (int) $item->item_type,
                'status'           => (int) $item->status,
                'available'        => $available,
                'is_available'     => $available,
                'is_upsell'        => (bool) $item->is_upsell,
                'is_featured'      => (bool) $item->is_featured,
                'allergens'        => $item->allergen_flags ?? [],
                'thumb'            => $item->thumb,
                'cover'            => $item->cover,
                'image'            => $item->thumb ?: $item->cover,
                'preview'          => $item->preview,
                'variations'       => $this->projectVariations($item, $channel, $itemChoiceAvailability['variations']),
                'itemAttributes'   => $this->projectItemAttributes($item, $channel),
                'extras'           => $this->projectExtras($item, $channel, $itemChoiceAvailability['extras']),
                'addons'           => $this->projectAddons($item, $channel, $itemChoiceAvailability['addons']),
                'composer_profile' => $this->composerProfileProjection()->project($composerProfiles->get($item->id), $item, $channel, $branchId),
            ];

            if ($row && !$branchAvailable) {
                $projected['unavailable_reason'] = $row->unavailable_reason;
            }

            if ($channel === self::CHANNEL_KIOSK && !empty($item->kiosk_emoji)) {
                $projected['emoji'] = (string) $item->kiosk_emoji;
            }

            return $projected;
        })->values()->all();
    }

    /**
     * @param  array<int, array{is_available: bool, unavailable_reason: ?string}>  $choiceAvailability
     */
    private function projectVariations(Item $item, string $channel, array $choiceAvailability): array
    {
        return $item->variations
            ->filter(fn ($variation): bool => $variation->isVisibleOn($channel))
            ->map(function ($variation) use ($choiceAvailability): array {
                $availability = $choiceAvailability[(int) $variation->id] ?? ['is_available' => true, 'unavailable_reason' => null];

                return [
                    'id' => (int) $variation->id,
                    'item_attribute_id' => $variation->item_attribute_id !== null
                        ? (int) $variation->item_attribute_id
                        : null,
                    'name' => (string) $variation->name,
                    'price' => (float) $variation->price,
                    'status' => (int) $variation->status,
                    'visible_on' => $variation->visible_on,
                    'is_available' => $availability['is_available'],
                    'unavailable_reason' => $availability['unavailable_reason'],
                ];
            })
            ->values()
            ->all();
    }

    private function projectItemAttributes(Item $item, string $channel): array
    {
        return $item->variations
            ->filter(fn ($variation): bool => $variation->isVisibleOn($channel) && $variation->itemAttribute !== null)
            ->map(fn ($variation) => $variation->itemAttribute)
            ->unique('id')
            ->sortBy('id')
            ->values()
            ->map(fn ($attribute): array => [
                'id' => (int) $attribute->id,
                'name' => (string) $attribute->name,
                'status' => (int) $attribute->status,
                'min_select' => (int) ($attribute->min_select ?? 0),
                'max_select' => (int) ($attribute->max_select ?? 1),
                'allow_repeat' => (bool) ($attribute->allow_repeat ?? false),
            ])
            ->all();
    }

    /**
     * @param  array<int, array{is_available: bool, unavailable_reason: ?string}>  $choiceAvailability
     */
    private function projectExtras(Item $item, string $channel, array $choiceAvailability): array
    {
        return $item->extras
            ->filter(fn ($extra): bool => $extra->isVisibleOn($channel))
            ->map(function ($extra) use ($choiceAvailability): array {
                $availability = $choiceAvailability[(int) $extra->id] ?? ['is_available' => true, 'unavailable_reason' => null];

                return [
                    'id' => (int) $extra->id,
                    'name' => (string) $extra->name,
                    'price' => (float) $extra->price,
                    'status' => (int) $extra->status,
                    'group_label' => $extra->group_label,
                    'visible_on' => $extra->visible_on,
                    'is_available' => $availability['is_available'],
                    'unavailable_reason' => $availability['unavailable_reason'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{is_available: bool, unavailable_reason: ?string}>  $choiceAvailability
     */
    private function projectAddons(Item $item, string $channel, array $choiceAvailability): array
    {
        return $item->addons
            ->filter(function ($addon) use ($channel): bool {
                $addonItem = $addon->addonItem;

                return $addonItem !== null
                    && (int) $addonItem->status === Status::ACTIVE
                    && (bool) ($addonItem->is_available ?? true)
                    && $addonItem->isVisibleOn($channel);
            })
            ->map(function ($addon) use ($choiceAvailability): array {
                $availability = $choiceAvailability[(int) $addon->id] ?? ['is_available' => true, 'unavailable_reason' => null];

                return [
                    'id' => (int) $addon->id,
                    'addon_item_id' => (int) $addon->addon_item_id,
                    'addon_item_variation' => $addon->addon_item_variation,
                    'role' => $addon->role,
                    'addon_item_name' => $addon->addonItem?->name,
                    'is_available' => $availability['is_available'],
                    'unavailable_reason' => $availability['unavailable_reason'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return Collection<int, ItemWizardProfile>
     */
    private function publishedComposerProfiles(Collection $items, int $branchId): Collection
    {
        $itemIds = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (empty($itemIds)) {
            return collect();
        }

        $branchFilter = function ($query) use ($branchId): void {
            $query->whereNull('branch_id_scope')
                ->orWhere('branch_id_scope', $branchId);
        };
        $withSteps = ['steps' => fn ($query) => $query->where('is_active', true)->orderBy('position')];
        $pick = fn (Collection $profiles): ItemWizardProfile => $profiles
            ->sort(fn (ItemWizardProfile $a, ItemWizardProfile $b): int => $this->compareComposerProfiles($a, $b))
            ->first();

        $itemOwned = ItemWizardProfile::query()
            ->with($withSteps)
            ->whereIn('item_id', $itemIds)
            ->where('is_published', true)
            ->where($branchFilter)
            ->get()
            ->groupBy('item_id')
            ->map($pick);

        // [WIZARD-STUDIO WS-1 2026-06-15] Category-owned profiles inherit onto each item at render
        // (item-owned wins) so a published category wizard actually reaches the menu/borne.
        $categoryIds = $items->pluck('item_category_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
        $categoryOwned = empty($categoryIds) ? collect() : ItemWizardProfile::query()
            ->with($withSteps)
            ->whereIn('item_category_id', $categoryIds)
            ->whereNull('item_id')
            ->where('is_published', true)
            ->where($branchFilter)
            ->get()
            ->groupBy('item_category_id')
            ->map($pick);

        return $items->mapWithKeys(function (Item $item) use ($itemOwned, $categoryOwned): array {
            $profile = $itemOwned->get($item->id) ?? $categoryOwned->get($item->item_category_id);

            return $profile ? [(int) $item->id => $profile] : [];
        });
    }

    private function compareComposerProfiles(ItemWizardProfile $a, ItemWizardProfile $b): int
    {
        $aScope = $a->branch_id_scope === null ? 0 : 1;
        $bScope = $b->branch_id_scope === null ? 0 : 1;

        return [$bScope, (int) $b->version, (int) $b->id] <=> [$aScope, (int) $a->version, (int) $a->id];
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

    private function composerProfileProjection(): ComposerProfileProjection
    {
        return $this->composerProjection ?? app(ComposerProfileProjection::class);
    }

    private function choiceAvailabilityResolver(): ChoiceAvailabilityResolver
    {
        return $this->choiceAvailabilityResolver ?? app(ChoiceAvailabilityResolver::class);
    }
}
