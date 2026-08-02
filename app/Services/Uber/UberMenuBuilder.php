<?php

namespace App\Services\Uber;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;

/**
 * [UBER-BASIC-PROD 2026-08-02] Construit le payload menu Uber Eats v2 depuis la SSOT
 * (DB items/categories — CLAUDE.md §3bis : jamais de produits inventés) et le pousse
 * via PUT /v2/eats/stores/{id}/menus.
 *
 * Choix V1 (délibérément minimal, exigé par la checklist « Basic Production validation ») :
 *  - IDs stables et réversibles : item Uber = "item-<id interne>", catégorie = "cat-<id>".
 *    UberOrderMapper::resolveItemId() sait re-mapper "item-<id>" → item interne sans table
 *    de correspondance manuelle.
 *  - Prix en CENTIMES (int) — source item.price (EUR TTC).
 *  - Pas de modifier_groups V1 (les extras/compositions restent gérés à la réception via
 *    le mapper) ; clé présente vide car le schéma l'exige.
 *  - Disponibilité 7j/7 00:00-23:59 — les horaires réels du store sont pilotés par Uber
 *    Eats Manager, pas par le menu.
 */
class UberMenuBuilder
{
    /** Items exposés à Uber : actifs, et sans exclusion de canal (channels null = tous). */
    public function build(): array
    {
        $categories = ItemCategory::where('status', Status::ACTIVE)->orderBy('id')->get();
        $items = Item::where('status', Status::ACTIVE)->orderBy('item_category_id')->orderBy('order')->get();
        // [UBER-VALIDATION 2026-08-02] Personnalisations réelles (item_extras actifs) →
        // modifier_groups (schéma sourcé : groupe {quantity_info, modifier_options[{id,type:ITEM}]},
        // item {modifier_group_ids:{ids,overrides}} ; les options sont des items normaux).
        $extrasByItem = \App\Models\ItemExtra::where('status', Status::ACTIVE)
            ->orderBy('item_id')->orderBy('id')->get()->groupBy('item_id');

        $itemsPayload = [];
        $modifierGroups = [];
        $entitiesByCategory = [];
        foreach ($items as $item) {
            $uberId = 'item-' . $item->id;
            $entitiesByCategory[(int) $item->item_category_id][] = ['id' => $uberId, 'type' => 'ITEM'];

            $groupIds = [];
            $extras = $extrasByItem->get($item->id) ?? collect();
            if ($extras->isNotEmpty()) {
                $groupId = 'grp-extras-' . $item->id;
                $groupIds[] = $groupId;
                $options = [];
                foreach ($extras as $extra) {
                    $optId = 'opt-' . $extra->id;
                    $options[] = ['id' => $optId, 'type' => 'ITEM'];
                    // Les options de personnalisation sont des entités items[] normales
                    // (hors catégories → non commandables seules).
                    $itemsPayload[] = [
                        'id' => $optId,
                        'external_data' => 'extra:' . $extra->id,
                        'title' => ['translations' => ['en_us' => (string) $extra->name]],
                        'price_info' => ['price' => (int) round(((float) $extra->price) * 100)],
                        'tax_info' => ['tax_rate' => 10.0],
                    ];
                }
                $modifierGroups[] = [
                    'id' => $groupId,
                    'title' => ['translations' => ['en_us' => 'Suppléments']],
                    'quantity_info' => ['quantity' => ['min_permitted' => 0, 'max_permitted' => min(10, count($options))]],
                    'modifier_options' => $options,
                    'display_type' => 'expanded',
                ];
            }

            $itemsPayload[] = [
                'id' => $uberId,
                'external_data' => (string) $item->id,
                'title' => ['translations' => ['en_us' => (string) $item->name]],
                'description' => ['translations' => ['en_us' => (string) ($item->description ?? '')]],
                'price_info' => ['price' => (int) round(((float) $item->price) * 100)],
                // TVA restauration FR 10 % (projet : TVA 10 partout — quickwins 2026-07-18).
                'tax_info' => ['tax_rate' => 10.0],
                'modifier_group_ids' => ['ids' => $groupIds, 'overrides' => []],
            ];
        }

        $categoriesPayload = [];
        $categoryIds = [];
        foreach ($categories as $cat) {
            $entities = $entitiesByCategory[(int) $cat->id] ?? [];
            if ($entities === []) {
                continue; // catégorie vide → Uber la refuse, on la saute.
            }
            $uberCatId = 'cat-' . $cat->id;
            $categoryIds[] = $uberCatId;
            $categoriesPayload[] = [
                'id' => $uberCatId,
                'title' => ['translations' => ['en_us' => (string) $cat->name]],
                'entities' => $entities,
            ];
        }

        $allDay = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $allDay[] = ['day_of_week' => $day, 'time_periods' => [['start_time' => '00:00', 'end_time' => '23:59']]];
        }

        return [
            'menus' => [[
                'id' => 'menu-principal',
                'title' => ['translations' => ['en_us' => 'Menu']],
                'service_availability' => $allDay,
                'category_ids' => $categoryIds,
            ]],
            'categories' => $categoriesPayload,
            'items' => $itemsPayload,
            'modifier_groups' => $modifierGroups,
            'display_options' => ['disable_item_instructions' => false],
        ];
    }

    /** Construit puis pousse le menu vers Uber. True si 2xx. */
    public function push(UberClient $client): bool
    {
        return $client->putMenu($this->build());
    }
}
