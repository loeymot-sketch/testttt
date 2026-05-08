<?php

namespace App\Services\Recommendation\Strategies;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Services\Recommendation\UpsellRecommendationService;
use Illuminate\Support\Str;

/**
 * V2-3 Phase A — RuleBasedStrategy : heuristique catégories baseline.
 *
 * IMPORTANT (cohabitation V1.x) :
 *   `App\Services\Kiosk\UpsellRuleService` reste l'autorité unique pour
 *   l'upsell servi en production kiosk (admin-curated, invariant §1.5).
 *   Cette stratégie heuristique vit en parallèle, **non utilisée** par les
 *   composants frozen tant que l'A/B test (Phase B) n'est pas activé.
 *
 * Heuristiques V1.x (Phase A) :
 *   1. Si panier contient une catégorie de type "burgers" → suggère sides
 *      + drinks dispo branche.
 *   2. Si panier ≥ 3 items et zéro dessert → suggère ≥ 1 dessert.
 *   3. Si panier total < 10€ → suggère "best value" / combo (top items
 *      branch-scoped).
 *
 * Output : max 4 items, dédupliqués, triés par score desc.
 *
 * Limites connues :
 *   - Détection catégorie via slug (case-insensitive contains) — non I18N.
 *     Pour V2 : tagging admin explicite (item.tags ['side','drink','dessert']).
 *   - Branch isolation via `item_branch_availability.is_available=true`.
 *
 * Perf (A3 ultra-review 2026-05-08) :
 *   - `ItemBranchAvailability::pluck` est hoisté 1× au lieu de 4× sur le hot
 *     path burger.
 *   - `ItemCategory` slug→ids lookup est hoisté 1× (toutes les classes
 *     résolues en une requête au lieu d'une par classe).
 *   - Stratégie reste stateless : valeurs hoistées passées en paramètres,
 *     jamais stockées dans `$this->` (anti-pattern + risque cross-request).
 *
 * Voir `plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md`.
 */
final class RuleBasedStrategy implements UpsellRecommendationService
{
    private const MAX_RECOMMENDATIONS = 4;
    private const COMBO_THRESHOLD_EUR = 10.0;
    private const DESSERT_TRIGGER_ITEMS = 3;

    /** @var array<string, list<string>>  category-class → slug substrings */
    private const CATEGORY_SLUG_HINTS = [
        'burgers'  => ['burger', 'sandwich'],
        'sides'    => ['side', 'fries', 'frite', 'accompagnement'],
        'drinks'   => ['drink', 'boisson', 'soda'],
        'desserts' => ['dessert', 'glace', 'cake', 'sweet'],
    ];

    public function recommend(array $cart, int $branchId, ?int $userId = null): array
    {
        if ($cart === []) {
            return [];
        }

        // 1. Hydrate categories from cart (DB fallback if category_id missing).
        $cartItemIds = $this->extractItemIds($cart);
        $categoryClasses = $this->classifyCart($cart, $cartItemIds);
        $cartTotal = $this->computeCartTotal($cart);

        // 2. A3 perf hoist : 1 seule query branch-availability au lieu de 4.
        $availableItemIds = $this->loadBranchAvailableItemIds($branchId);

        // 3. A3 perf hoist : 1 seule query slug→category_ids pour toutes les
        //    classes (évite N requêtes pour N classes activées).
        $categoryIdsByClass = $this->loadCategoryIdsByClass();

        // 4. Pick rule(s) and gather candidate item ids.
        $candidates = []; // [item_id => ['reason' => string, 'score' => float]]

        if (in_array('burgers', $categoryClasses, true)) {
            foreach ($this->topItemsInClass('sides', $cartItemIds, 2, $availableItemIds, $categoryIdsByClass) as $item) {
                $candidates[$item->id] = ['reason' => 'side_for_burger', 'score' => 0.85];
            }
            foreach ($this->topItemsInClass('drinks', $cartItemIds, 2, $availableItemIds, $categoryIdsByClass) as $item) {
                $candidates[$item->id] = ['reason' => 'drink_for_burger', 'score' => 0.80];
            }
        }

        if (count($cart) >= self::DESSERT_TRIGGER_ITEMS && !in_array('desserts', $categoryClasses, true)) {
            foreach ($this->topItemsInClass('desserts', $cartItemIds, 2, $availableItemIds, $categoryIdsByClass) as $item) {
                if (!isset($candidates[$item->id])) {
                    $candidates[$item->id] = ['reason' => 'dessert_after_meal', 'score' => 0.70];
                }
            }
        }

        if ($cartTotal > 0.0 && $cartTotal < self::COMBO_THRESHOLD_EUR) {
            foreach ($this->topItemsAnyClass($cartItemIds, 2, $availableItemIds) as $item) {
                if (!isset($candidates[$item->id])) {
                    $candidates[$item->id] = ['reason' => 'combo_under_threshold', 'score' => 0.60];
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        // 5. Resolve item rows + project final shape.
        return $this->projectAndSort($candidates);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @return list<int>
     */
    private function extractItemIds(array $cart): array
    {
        $ids = [];
        foreach ($cart as $line) {
            $id = (int) ($line['item_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @param  list<int>  $cartItemIds
     * @return list<string>  unique class names ('burgers'|'sides'|'drinks'|'desserts')
     */
    private function classifyCart(array $cart, array $cartItemIds): array
    {
        $categoryIds = [];

        // Prefer category_id from cart payload when provided.
        foreach ($cart as $line) {
            if (isset($line['category_id']) && (int) $line['category_id'] > 0) {
                $categoryIds[] = (int) $line['category_id'];
            }
        }

        // Hydrate missing item->category_id from DB.
        if ($categoryIds === [] && $cartItemIds !== []) {
            $categoryIds = Item::query()
                ->whereIn('id', $cartItemIds)
                ->pluck('item_category_id')
                ->map(fn ($v): int => (int) $v)
                ->all();
        }

        $categoryIds = array_values(array_unique(array_filter($categoryIds, fn ($v) => $v > 0)));
        if ($categoryIds === []) {
            return [];
        }

        $categories = ItemCategory::query()
            ->whereIn('id', $categoryIds)
            ->get(['id', 'name', 'slug']);

        $classes = [];
        foreach ($categories as $cat) {
            $hay = Str::lower((string) ($cat->slug ?? '') . ' ' . (string) ($cat->name ?? ''));
            foreach (self::CATEGORY_SLUG_HINTS as $class => $hints) {
                foreach ($hints as $hint) {
                    if (str_contains($hay, Str::lower($hint))) {
                        $classes[] = $class;
                        break 2;
                    }
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     */
    private function computeCartTotal(array $cart): float
    {
        $total = 0.0;
        foreach ($cart as $line) {
            if (isset($line['price'])) {
                $qty = max(1, (int) ($line['quantity'] ?? 1));
                $total += ((float) $line['price']) * $qty;
            }
        }

        if ($total > 0) {
            return $total;
        }

        // Fallback: hydrate prices from DB.
        $itemIds = $this->extractItemIds($cart);
        if ($itemIds === []) {
            return 0.0;
        }

        $priceById = Item::query()
            ->whereIn('id', $itemIds)
            ->pluck('price', 'id')
            ->all();

        foreach ($cart as $line) {
            $id = (int) ($line['item_id'] ?? 0);
            if ($id > 0 && isset($priceById[$id])) {
                $qty = max(1, (int) ($line['quantity'] ?? 1));
                $total += ((float) $priceById[$id]) * $qty;
            }
        }

        return $total;
    }

    /**
     * A3 hoist : 1 seule query au lieu de 4 sur le hot path burger.
     *
     * @return list<int>  branch-scoped available item ids ([] = no pivot rows
     *                    for this branch → fallback all-active in branchAvailableItemsQuery)
     */
    private function loadBranchAvailableItemIds(int $branchId): array
    {
        return ItemBranchAvailability::query()
            ->where('branch_id', $branchId)
            ->where('is_available', true)
            ->pluck('item_id')
            ->all();
    }

    /**
     * A3 hoist : 1 seule query SQL pour résoudre toutes les classes
     * ('sides', 'drinks', 'desserts'...) en category_ids, au lieu d'une
     * requête par classe activée.
     *
     * @return array<string, list<int>>  class name → category_ids
     */
    private function loadCategoryIdsByClass(): array
    {
        // Build a single OR-where that captures every hint.
        $allHints = [];
        foreach (self::CATEGORY_SLUG_HINTS as $hints) {
            foreach ($hints as $h) {
                $allHints[] = $h;
            }
        }
        $allHints = array_values(array_unique($allHints));

        if ($allHints === []) {
            return [];
        }

        $categories = ItemCategory::query()
            ->where(function ($q) use ($allHints) {
                foreach ($allHints as $hint) {
                    $q->orWhere('slug', 'like', '%' . $hint . '%')
                      ->orWhere('name', 'like', '%' . $hint . '%');
                }
            })
            ->get(['id', 'name', 'slug']);

        // Now bucket each category into its class(es).
        $result = [];
        foreach (self::CATEGORY_SLUG_HINTS as $class => $hints) {
            $result[$class] = [];
            foreach ($categories as $cat) {
                $hay = Str::lower((string) ($cat->slug ?? '') . ' ' . (string) ($cat->name ?? ''));
                foreach ($hints as $hint) {
                    if (str_contains($hay, Str::lower($hint))) {
                        $result[$class][] = (int) $cat->id;
                        break;
                    }
                }
            }
            $result[$class] = array_values(array_unique($result[$class]));
        }

        return $result;
    }

    /**
     * @param  list<int>  $excludeItemIds
     * @param  list<int>  $availableItemIds  hoisted branch-availability list
     * @param  array<string, list<int>>  $categoryIdsByClass  hoisted slug-mapping
     * @return \Illuminate\Database\Eloquent\Collection<int, Item>
     */
    private function topItemsInClass(string $class, array $excludeItemIds, int $limit, array $availableItemIds, array $categoryIdsByClass): \Illuminate\Database\Eloquent\Collection
    {
        $categoryIds = $categoryIdsByClass[$class] ?? [];
        if ($categoryIds === []) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return $this->branchAvailableItemsQuery($availableItemIds, $excludeItemIds)
            ->whereIn('item_category_id', $categoryIds)
            ->orderByDesc('is_chef_pick')
            ->orderBy('order')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<int>  $excludeItemIds
     * @param  list<int>  $availableItemIds  hoisted branch-availability list
     * @return \Illuminate\Database\Eloquent\Collection<int, Item>
     */
    private function topItemsAnyClass(array $excludeItemIds, int $limit, array $availableItemIds): \Illuminate\Database\Eloquent\Collection
    {
        return $this->branchAvailableItemsQuery($availableItemIds, $excludeItemIds)
            ->orderByDesc('is_chef_pick')
            ->orderBy('order')
            ->limit($limit)
            ->get();
    }

    /**
     * Branch-scoped query : seuls items active + dispos sur la branche.
     * Utilise `item_branch_availability` quand la pivot existe ; sinon
     * fallback sur tous les items active (cohérent avec le menu kiosk
     * legacy).
     *
     * A3 perf : reçoit `$availableItemIds` déjà hoisté en paramètre (au lieu
     * de pluck à chaque appel). Stratégie reste stateless (pas de
     * `$this->cache`).
     *
     * @param  list<int>  $availableItemIds  hoisted from loadBranchAvailableItemIds()
     * @param  list<int>  $excludeItemIds
     */
    private function branchAvailableItemsQuery(array $availableItemIds, array $excludeItemIds): \Illuminate\Database\Eloquent\Builder
    {
        // [SEC-HEAL-2026-05-08 iter2] Ultra-review P1 perf finding : pré-fix,
        // `Item::query()` faisait SELECT * (30+ colonnes : description, allergen_flags,
        // chef_pick_order, soft-delete, media...). Les consumers ici
        // (`topItemsInClass`, `topItemsAnyClass`) n'utilisent que :
        //   - `id` (keying + projectAndSort hand-off)
        //   - `item_category_id` (whereIn dans topItemsInClass)
        //   - `is_chef_pick` (orderByDesc)
        //   - `order` (orderBy)
        //   - `status` (where dans cette query)
        // `projectAndSort` re-fetch ensuite (name, price, kiosk_emoji,
        // item_category_id) sur les ids gagnants — pas de double-coût ici.
        $q = Item::query()
            ->select(['id', 'item_category_id', 'is_chef_pick', 'order', 'status'])
            ->where('status', Status::ACTIVE);

        if ($excludeItemIds !== []) {
            $q->whereNotIn('id', $excludeItemIds);
        }

        // If the pivot table is populated for this branch, restrict to those
        // items. Otherwise (greenfield branch / migration in progress), fall
        // back to all active items — same posture as menu kiosk service.
        if ($availableItemIds !== []) {
            $q->whereIn('id', $availableItemIds);
        }

        return $q;
    }

    /**
     * @param  array<int, array{reason:string, score:float}>  $candidates  keyed by item_id
     * @return list<array{item_id:int, name:string, price:float, reason:string, score:float}>
     */
    private function projectAndSort(array $candidates): array
    {
        $itemIds = array_keys($candidates);
        $items = Item::query()
            ->whereIn('id', $itemIds)
            ->where('status', Status::ACTIVE)
            ->get(['id', 'name', 'price', 'kiosk_emoji', 'item_category_id'])
            ->keyBy('id');

        $out = [];
        foreach ($candidates as $itemId => $meta) {
            if (!$items->has($itemId)) {
                continue;
            }
            /** @var Item $item */
            $item = $items[$itemId];
            $out[] = [
                'item_id' => (int) $item->id,
                'name'    => (string) $item->name,
                'price'   => (float) $item->price,
                'reason'  => $meta['reason'],
                'score'   => (float) $meta['score'],
            ];
        }

        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, self::MAX_RECOMMENDATIONS);
    }
}
