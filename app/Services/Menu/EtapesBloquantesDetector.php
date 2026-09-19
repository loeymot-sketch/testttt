<?php

namespace App\Services\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;

/**
 * [INCIDENT CAISSE 2026-09-03] Détecte les produits qu'un client ne peut PAS commander
 * parce qu'une étape obligatoire ne lui offre aucun choix satisfaisant.
 *
 * Pourquoi ce service existe : le 2026-09-03 à 22:27:08, une opération de carte a éteint
 * les 45 lignes de viande de Cayenne / Suprême / Sandwich Classique en laissant l'étape
 * « Viande 1 » obligatoire. Les trois produits phares sont devenus invendables, aucun test
 * n'a rougi, aucun écran n'a prévenu, et le restaurant l'a découvert en plein service.
 *
 * L'obligation, côté serveur, est dérivée des variations ACTIVES du produit
 * (App\Rules\MultiVariationConstraint::requiredAttributesByOrderedItem) : un attribut sans
 * aucune variation active n'est pas exigé. Mais l'étape reste construite côté écran, et
 * un pas obligatoire sans tuile à cliquer est une impasse. On signale donc les deux formes.
 *
 * La surface compte : `visible_on` peut réserver un choix à la caisse. Une étape obligatoire
 * dont TOUS les choix sont réservés à une autre surface est une impasse sur celle qu'on
 * consulte — c'est exactement le piège de la règle « les viandes du Cayenne seulement à la
 * caisse » : la borne exigerait une viande qu'elle n'a pas le droit d'afficher.
 */
class EtapesBloquantesDetector
{
    /** Surfaces connues, telles qu'employées par ItemVariation::isVisibleOn(). */
    public const SURFACES = ['kiosk', 'pos', 'web'];

    /**
     * @return array<int, array{
     *     item_id:int, produit:string, attribut_id:int, etape:string,
     *     raison:string, choix_disponibles:int, minimum_exige:int, surface:string
     * }>
     */
    public function detecter(string $surface): array
    {
        $produits = Item::query()
            ->where('status', Status::ACTIVE)
            ->get(['id', 'name'])
            ->keyBy('id');

        if ($produits->isEmpty()) {
            return [];
        }

        $variations = ItemVariation::query()
            ->whereIn('item_id', $produits->keys()->all())
            ->whereNotNull('item_attribute_id')
            ->get(['id', 'item_id', 'item_attribute_id', 'status', 'visible_on']);

        if ($variations->isEmpty()) {
            return [];
        }

        $attributs = ItemAttribute::query()
            ->whereIn('id', $variations->pluck('item_attribute_id')->unique()->values()->all())
            ->get(['id', 'name', 'min_select'])
            ->keyBy('id');

        $constats = [];

        foreach ($variations->groupBy(['item_id', 'item_attribute_id']) as $itemId => $parAttribut) {
            foreach ($parAttribut as $attrId => $lignes) {
                $attribut = $attributs->get((int) $attrId);
                if (! $attribut instanceof ItemAttribute) {
                    continue;
                }

                $minimum = (int) ($attribut->min_select ?? 0);
                if ($minimum < 1) {
                    continue; // Sans obligation, aucun blocage possible.
                }

                $actives = $lignes->filter(fn (ItemVariation $v) => (int) $v->status === Status::ACTIVE);

                if ($actives->isEmpty()) {
                    $constats[] = $this->constat($produits, $itemId, $attribut, 'tous_les_choix_eteints', 0, $minimum, $surface);

                    continue;
                }

                $visibles = $actives->filter(fn (ItemVariation $v) => $v->isVisibleOn($surface))->count();

                if ($visibles === 0) {
                    $constats[] = $this->constat($produits, $itemId, $attribut, 'reserve_a_une_autre_surface', 0, $minimum, $surface);

                    continue;
                }

                if ($visibles < $minimum) {
                    $constats[] = $this->constat($produits, $itemId, $attribut, 'choix_insuffisants', $visibles, $minimum, $surface);
                }
            }
        }

        return $constats;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Item>  $produits
     * @return array{item_id:int, produit:string, attribut_id:int, etape:string, raison:string, choix_disponibles:int, minimum_exige:int, surface:string}
     */
    private function constat($produits, int|string $itemId, ItemAttribute $attribut, string $raison, int $disponibles, int $minimum, string $surface): array
    {
        return [
            'item_id'           => (int) $itemId,
            'produit'           => (string) ($produits->get((int) $itemId)->name ?? ''),
            'attribut_id'       => (int) $attribut->id,
            'etape'             => (string) $attribut->name,
            'raison'            => $raison,
            'choix_disponibles' => $disponibles,
            'minimum_exige'     => $minimum,
            'surface'           => $surface,
        ];
    }
}
