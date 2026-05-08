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

        // 2. Pick rule(s) and gather candidate item ids.
        $candidates = []; // [item_id => ['reason' => string, 'score' => float]]

        if (in_array('burgers', $categoryClasses, true)) {
            foreach ($this->topItemsInClass('sides', $branchId, $cartItemIds, 2) as $item) {
                $candidates[$item->id] = ['reason' => 'side_for_burger', 'score' => 0.85];
            }
            foreach ($this->topItemsInClass('drinks', $branchId, $cartItemIds, 2) as $item) {
                $candidates[$item->id] = ['reason' => 'drink_for_burger', 'score' => 0.80];
            }
        }

        if (count($cart) >= self::DESSERT_TRIGGER_ITEMS && !in_array('desserts', $categoryClasses, true)) {
            foreach ($this->topItemsInClass('desserts', $branchId, $cartItemIds, 2) as $item) {
                if (!isset($candidates[$item->id])) {
                    $candidates[$item->id] = ['reason' => 'dessert_after_meal', 'score' => 0.70];
                }
            }
        }

        if ($cartTotal > 0.0 && $cartTotal < self::COMBO_THRESHOLD_EUR) {
            foreach ($this->topItemsAnyClass($branchId, $cartItemIds, 2) as $item) {
                if (!isset($candidates[$item->id])) {
                    $candidates[$item->id] = ['reason' => 'combo_under_threshold', 'score' => 0.60];
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        // 3. Resolve item rows + project final shape.
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
     * @param  list<int>  $excludeItemIds
     * @return \Illuminate\Database\Eloquent\Collection<int, Item>
     */
    private function topItemsInClass(string $class, int $branchId, array $excludeItemIds, int $limit): \Illuminate\Database\Eloquent\Collection
    {
        $hints = self::CATEGORY_SLUG_HINTS[$class] ?? [];
        if ($hints === []) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        $categoryIds = ItemCategory::query()
            ->where(function ($q) use ($hints) {
                foreach ($hints as $hint) {
                    $q->orWhere('slug', 'like', '%' . $hint . '%')
                      ->orWhere('name', 'like', '%' . $hint . '%');
                }
            })
            ->pluck('id')
            ->all();

        if ($categoryIds === []) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return $this->branchAvailableItemsQuery($branchId, $excludeItemIds)
            ->whereIn('item_category_id', $categoryIds)
            ->orderByDesc('is_chef_pick')
            ->orderBy('order')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<int>  $excludeItemIds
     * @return \Illuminate\Database\Eloquent\Collection<int, Item>
     */
    private function topItemsAnyClass(int $branchId, array $excludeItemIds, int $limit): \Illuminate\Database\Eloquent\Collection
    {
        return $this->branchAvailableItemsQuery($branchId, $excludeItemIds)
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
     * @param  list<int>  $excludeItemIds
     */
    private function branchAvailableItemsQuery(int $branchId, array $excludeItemIds): \Illuminate\Database\Eloquent\Builder
    {
        $availableItemIds = ItemBranchAvailability::query()
            ->where('branch_id', $branchId)
            ->where('is_available', true)
            ->pluck('item_id')
            ->all();

        $q = Item::query()
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
