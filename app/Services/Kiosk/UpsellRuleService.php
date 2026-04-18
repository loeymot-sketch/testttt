<?php

namespace App\Services\Kiosk;

use App\Enums\Status;
use App\Models\Item;
use App\Models\UpsellRule;
use Carbon\Carbon;

/**
 * Kiosk Design V1 — Phase 1.7
 *
 * Service de suggestion d'upsell basé sur la table `upsell_rules`.
 * Invariant §1.5 : 100 % règles admin statiques, jamais de stats / ML.
 *
 * Usage :
 *   - Matche les règles `active` dont la fenêtre temporelle couvre `now()`.
 *   - Évalue le trigger vs. le panier fourni par le client.
 *   - Dédup les items déjà dans le panier.
 *   - Limite à N items (default 4) triés par `priority DESC`.
 *   - Si 0 match → retourne tableau vide (le controller peut fallback).
 */
final class UpsellRuleService
{
    public const DEFAULT_LIMIT = 4;

    /**
     * @param  array<int, array<string, mixed>>  $cartLines  ex. [['item_id'=>1,'category_id'=>2,'quantity'=>1]]
     * @return array<int, array<string, mixed>>  items projetés
     */
    public function suggest(
        int $branchId,
        array $cartLines,
        float $cartTotal,
        int $limit = self::DEFAULT_LIMIT,
        ?Carbon $at = null
    ): array {
        $at ??= now();
        $limit = max(1, min(12, $limit));

        $cartItemIds = array_values(array_unique(array_map(
            fn ($l) => (int) ($l['item_id'] ?? 0),
            $cartLines
        )));

        $rules = UpsellRule::query()
            ->activeForBranch($branchId, $at)
            ->get();

        $matchedRules = $rules->filter(function (UpsellRule $rule) use ($cartLines, $cartTotal, $cartItemIds) {
            if (in_array((int) $rule->suggested_item_id, $cartItemIds, true)) {
                return false;
            }
            return $rule->matches($cartLines, $cartTotal);
        })->sortByDesc('priority')->values();

        if ($matchedRules->isEmpty()) {
            return [];
        }

        // Récupère les items suggérés (dédup, order-preserving sur priority).
        $suggestedIds = $matchedRules->pluck('suggested_item_id')
            ->map(fn ($v): int => (int) $v)
            ->unique()
            ->take($limit)
            ->values();

        $items = Item::query()
            ->with(['category:id,name,slug'])
            ->where('status', Status::ACTIVE)
            ->whereIn('id', $suggestedIds->all())
            ->get()
            ->keyBy('id');

        // Ordre : priorité DESC, en dédupant par item_id et en préservant l'ordre.
        $ordered = [];
        $seen = [];
        foreach ($matchedRules as $rule) {
            $id = (int) $rule->suggested_item_id;
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            if (!$items->has($id)) continue;
            $ordered[] = $this->projectItem($items[$id], $rule);
            if (count($ordered) >= $limit) break;
        }

        return $ordered;
    }

    private function projectItem(Item $item, UpsellRule $rule): array
    {
        return [
            'id'                => (int) $item->id,
            'name'              => (string) $item->name,
            'slug'              => (string) $item->slug,
            'price'             => (float) $item->price,
            'kiosk_emoji'       => $item->kiosk_emoji,
            'is_chef_pick'      => (bool) ($item->is_chef_pick ?? false),
            'category_id'       => (int) $item->item_category_id,
            'category_name'     => $item->category?->name,
            'upsell_rule' => [
                'id'           => (int) $rule->id,
                'trigger_type' => (string) $rule->trigger_type,
                'priority'     => (int) $rule->priority,
            ],
        ];
    }
}
