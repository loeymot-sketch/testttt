<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Scopes\BranchScope;
use App\Services\Menu\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * [GOAL MEGA W-MOBILE 2026-07-22] Données stock du mini-app mobile (/m), PIN-gated.
 *
 *  - catalog() : lecture PRODUITS (items) par catégorie + INGRÉDIENTS (extras
 *    sauces/suppléments + variations) groupés/dédoublonnés par nom, avec leur
 *    état dispo/rupture pour la branche unique V1, en miroir de la lecture du
 *    dashboard stock
 *    ({@see \App\Http\Controllers\Admin\StockRuptureDashboardController::catalogOverview}).
 *  - toggle() : délègue au SSOT {@see AvailabilityService::toggle()} (verrou +
 *    idempotence + dispatch after-commit vers POS/KDS/borne). AUCUN chemin
 *    parallèle : raison 'stock_rupture' comme le 86 manuel admin.
 *  - toggleExtra() : [HEAL F3 2026-07-24] rupture d'un INGRÉDIENT (extra/variation)
 *    — « plus d'Andalouse », « plus de merguez ». Délègue au MÊME SSOT
 *    {@see AvailabilityService::toggleExtra()} / {@see AvailabilityService::toggleVariation()}
 *    que le panneau caisse/cuisine. Raison manuelle 'out_of_stock_manual'
 *    (StockLevel::MANUAL_UNAVAILABLE_REASONS), écriture polymorphe stock_levels.
 *
 * HORS NF525 (stock uniquement). AUCUNE donnée fiscale/CA n'est exposée ici.
 */
class MobileStockController extends Controller
{
    /**
     * Full item catalogue (active categories + active items) with the effective
     * per-branch availability for the single V1 branch. Ruptures are also
     * surfaced flat as the "À acheter" shopping list.
     */
    public function catalog(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId();

        // 1) Categories + active items (eager, mirrors catalogOverview step 1).
        $categories = ItemCategory::query()
            ->where('status', Status::ACTIVE)
            ->orderBy('sort')
            ->orderBy('id')
            ->with(['items' => function ($q): void {
                $q->where('status', Status::ACTIVE)
                    ->orderBy('order')
                    ->orderBy('id');
            }])
            ->get();

        $allItemIds = $categories->flatMap(fn (ItemCategory $cat) => $cat->items->pluck('id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        // 2) Per-branch overrides (one SELECT). No authenticated user here, so
        //    BranchScope is inert — we hard-scope by branch_id explicitly and drop
        //    the global scope defensively (mirrors catalogOverview step 2).
        $itemOverrides = ItemBranchAvailability::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereIn('item_id', $allItemIds ?: [0])
            ->get(['item_id', 'is_available', 'unavailable_reason'])
            ->keyBy('item_id');

        $shopping = [];
        $categoriesPayload = $categories->map(function (ItemCategory $cat) use ($itemOverrides, &$shopping): array {
            $items = $cat->items->map(function (Item $item) use ($itemOverrides, $cat, &$shopping): array {
                $override = $itemOverrides->get((int) $item->id);
                $isAvailable = (bool) $item->is_available;
                $reason = null;
                if ($override !== null && ! (bool) $override->is_available) {
                    $isAvailable = false;
                    $reason = $override->unavailable_reason !== null
                        ? (string) $override->unavailable_reason
                        : null;
                }

                $row = [
                    'id' => (int) $item->id,
                    'name' => (string) $item->name,
                    'is_available' => $isAvailable,
                    'reason' => $reason,
                ];

                if (! $isAvailable) {
                    $shopping[] = [
                        'id' => (int) $item->id,
                        'name' => (string) $item->name,
                        'category' => (string) $cat->name,
                    ];
                }

                return $row;
            })->values()->all();

            return [
                'id' => (int) $cat->id,
                'name' => (string) $cat->name,
                'items' => $items,
            ];
        })->values()->all();

        // 3) Ingredients (extras + variations) — same rupture SSOT the borne/caisse
        //    read, reduced to the MANUAL axis the owner toggles here. Ruptured tiles
        //    are also appended into the "À acheter" shopping list ($shopping by ref).
        $ingredients = $this->buildIngredientGroups($branchId, $shopping);

        return response()->json([
            'branch_id' => $branchId,
            'shopping' => $shopping,
            'categories' => $categoriesPayload,
            'ingredients' => $ingredients,
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Toggle one item's availability for the single V1 branch. Delegates to the
     * shared SSOT service (no parallel write path). reason='stock_rupture' when
     * marking a rupture — identical to the admin manual 86.
     */
    public function toggle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'is_available' => ['required', 'boolean'],
        ]);

        $itemId = (int) $validated['item_id'];
        $isAvailable = (bool) $validated['is_available'];
        $branchId = $this->resolveBranchId();
        $reason = $isAvailable ? null : 'stock_rupture';

        $row = app(AvailabilityService::class)->toggle($itemId, $branchId, $isAvailable, $reason);

        return response()->json([
            'ok' => true,
            'item_id' => $itemId,
            'branch_id' => $branchId,
            'is_available' => (bool) $row->is_available,
            'unavailable_reason' => $row->unavailable_reason,
        ]);
    }

    /**
     * [HEAL F3 2026-07-24] Toggle the availability of an INGREDIENT — one or many
     * ItemExtra (sauces/suppléments) or ItemVariation rows — for the single V1
     * branch. This is the surface the owner uses for "plus d'Andalouse" / "plus
     * de merguez".
     *
     * `ids` is the deduped-by-name group's underlying ids (the catalog groups
     * "Andalouse" once but it may map to N ItemExtra rows across products); we
     * cascade the toggle to every id so a rupture takes effect everywhere — the
     * exact behaviour of the admin/caisse dashboard (which iterates extra_ids).
     *
     * Delegates to the SHARED SSOT {@see AvailabilityService::toggleExtra()} /
     * {@see AvailabilityService::toggleVariation()} (row lock, idempotency,
     * after-commit dispatch of ItemExtraAvailabilityChanged /
     * ItemVariationAvailabilityChanged to borne/POS/KDS). No parallel write path.
     * Manual reason 'out_of_stock_manual' — identical to the admin panel
     * (StockLevel::MANUAL_UNAVAILABLE_REASONS). Hard-scoped to branch 1.
     */
    public function toggleExtra(Request $request): JsonResponse
    {
        // Resolve the target table from `kind` BEFORE validation so the array
        // existence rule targets the correct table; an invalid `kind` is still
        // rejected by the `in:` rule below (422).
        $table = $request->input('kind') === 'variation' ? 'item_variations' : 'item_extras';

        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:extra,variation'],
            'ids' => ['required', 'array', 'min:1', 'max:300'],
            'ids.*' => ['integer', Rule::exists($table, 'id')->whereNull('deleted_at')],
            'is_available' => ['required', 'boolean'],
        ]);

        $kind = (string) $validated['kind'];
        $isAvailable = (bool) $validated['is_available'];
        $branchId = $this->resolveBranchId();
        // Manual rupture reason for stockables (extras/variations) — MUST belong to
        // StockLevel::MANUAL_UNAVAILABLE_REASONS. Mirrors the admin/caisse panel
        // DEFAULT_REASON so the borne/dashboard rupture resolution is identical.
        $reason = $isAvailable ? null : 'out_of_stock_manual';

        $ids = collect($validated['ids'])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $service = app(AvailabilityService::class);
        foreach ($ids as $id) {
            if ($kind === 'extra') {
                $service->toggleExtra($id, $branchId, $isAvailable, $reason);
            } else {
                $service->toggleVariation($id, $branchId, $isAvailable, $reason);
            }
        }

        return response()->json([
            'ok' => true,
            'kind' => $kind,
            'branch_id' => $branchId,
            'is_available' => $isAvailable,
            'ids' => $ids->all(),
        ]);
    }

    /**
     * Build the ingredient rail: active ItemExtra (grouped by group_label) and
     * ItemVariation (grouped by attribute), each deduped by name into a single
     * toggleable tile carrying every underlying id. Current availability is read
     * from the MANUAL rupture set owned by AvailabilityService (the same SSOT the
     * toggle writes/clears). Ruptured tiles are appended into $shopping.
     *
     * @param  list<array{id?:int, name:string, category:string}>  $shopping  by-ref shopping list.
     * @return list<array{group:string, kind:string, items:list<array{name:string, ids:list<int>, is_available:bool}>}>
     */
    private function buildIngredientGroups(int $branchId, array &$shopping): array
    {
        $service = app(AvailabilityService::class);
        $rupturedExtraIds = array_flip($service->getUnavailableExtraIdsForBranch($branchId));
        $rupturedVariationIds = array_flip($service->getUnavailableVariationIdsForBranch($branchId));

        $groups = [];

        // --- Extras: grouped by group_label, deduped by name. ---
        $extras = ItemExtra::query()
            ->where('status', Status::ACTIVE)
            ->orderBy('group_label')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'group_label']);

        foreach ($extras->groupBy(fn (ItemExtra $e): string => (string) ($e->group_label ?: 'other')) as $groupKey => $bucket) {
            $label = $this->ingredientGroupLabel((string) $groupKey);
            $items = $this->dedupeByName($bucket, $rupturedExtraIds, $label, $shopping);
            $groups[] = ['group' => $label, 'kind' => 'extra', 'items' => $items];
        }

        // --- Variations: grouped by attribute, deduped by name. ---
        $variations = ItemVariation::query()
            ->where('status', Status::ACTIVE)
            ->whereNotNull('item_attribute_id')
            ->with(['itemAttribute:id,name'])
            ->orderBy('item_attribute_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        foreach ($variations->groupBy(fn (ItemVariation $v): int => (int) $v->item_attribute_id) as $attributeId => $bucket) {
            $attribute = $bucket->first()->itemAttribute;
            $label = (string) ($attribute->name ?? ('#' . $attributeId));
            $items = $this->dedupeByName($bucket, $rupturedVariationIds, $label, $shopping);
            $groups[] = ['group' => $label, 'kind' => 'variation', 'items' => $items];
        }

        usort($groups, fn (array $a, array $b): int => strcmp($a['group'], $b['group']));

        return $groups;
    }

    /**
     * Dedupe a bucket of extras/variations by name into toggleable tiles. A tile
     * is available iff NONE of its underlying ids is in $rupturedIds. Ruptured
     * tiles are appended to $shopping under the given $groupLabel.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $bucket
     * @param  array<int, int>  $rupturedIds  id => index (from array_flip).
     * @param  list<array{id?:int, name:string, category:string}>  $shopping  by-ref.
     * @return list<array{name:string, ids:list<int>, is_available:bool}>
     */
    private function dedupeByName($bucket, array $rupturedIds, string $groupLabel, array &$shopping): array
    {
        $items = [];
        foreach ($bucket->groupBy(fn ($row): string => (string) $row->name) as $name => $rows) {
            $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

            $available = true;
            foreach ($ids as $id) {
                if (isset($rupturedIds[$id])) {
                    $available = false;
                    break;
                }
            }

            $items[] = [
                'name' => (string) $name,
                'ids' => $ids,
                'is_available' => $available,
            ];

            if (! $available) {
                $shopping[] = ['name' => (string) $name, 'category' => $groupLabel];
            }
        }

        usort($items, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $items;
    }

    /**
     * Presentation-only humanisation of an ItemExtra group_label slug. NOT an
     * SSOT — availability reads + toggles all go through AvailabilityService; this
     * only names the mobile rail sections. Unknown slugs humanise acceptably.
     */
    private function ingredientGroupLabel(string $key): string
    {
        $labels = [
            'sauce_supp' => 'Sauces supplémentaires',
            'frites_style' => 'Frites - format',
            'gratine' => 'Gratiné',
            'supplement_bol' => 'Suppléments bol',
            'supplement' => 'Suppléments',
            'topping' => 'Toppings',
            'extra' => 'Extras',
        ];

        if (isset($labels[$key])) {
            return $labels[$key];
        }

        if ($key === 'other' || $key === '') {
            return 'Autres ingrédients';
        }

        return Str::title(str_replace('_', ' ', $key));
    }

    /**
     * Resolve the single active V1 branch. No authenticated user on this PIN-gated
     * surface, so we take the first active branch (mono-branche = branch_id 1).
     * Mirrors the status filter of StockRuptureDashboardController::scopedBranches().
     */
    private function resolveBranchId(): int
    {
        return (int) (Branch::query()
            ->whereNull('deleted_at')
            ->whereIn('status', [Status::ACTIVE, 1])
            ->orderBy('id')
            ->value('id') ?? 1);
    }
}
