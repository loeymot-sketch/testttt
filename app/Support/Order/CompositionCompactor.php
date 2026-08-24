<?php

namespace App\Support\Order;

use App\Models\OrderItem;

/**
 * [GOAL-CAISSE-VISION 2026-08-24] Composition d'une ligne, en FORME COMPACTE.
 *
 * POURQUOI CETTE CLASSE EXISTE
 * ----------------------------
 * Le suivi de caisse doit montrer ce que le client a VRAIMENT pris — produits ET
 * personnalisations — pour qu'un caissier puisse identifier quelqu'un dont il n'a
 * pas le nom. Mais le suivi rafraîchit 100 commandes toutes les 5 secondes : y
 * recopier le `composition_snapshot` brut doublerait le payload (mesuré : +124 %
 * sur les commandes les plus composées). D'où cette projection compacte.
 *
 * DEUX FORMES À RÉCONCILIER — et leurs rôles sont INVERSÉS
 * -------------------------------------------------------
 *  • INSTANTANÉ NF525 (`composition_snapshot`, post-T07) :
 *      `attribute_name` = le LIBELLÉ (« Sauce »), `variation_name` = la VALEUR (« Algérienne »).
 *  • HÉRITÉE (`item_variations`, pré-T07) :
 *      `variation_name` = le LIBELLÉ, `name` = la VALEUR.
 *
 * Confondre les deux produit des « undefined » à l'écran — c'est exactement le
 * défaut qu'a corrigé `resources/js/helpers/posReceiptBuilder.js:146-190` côté
 * navigateur. CETTE CLASSE EN EST LE PORT FIDÈLE : même discriminant, mêmes replis,
 * même règle de rejet (une entrée sans valeur lisible est écartée plutôt que rendue
 * en « : » orphelin). Toute divergence entre les deux ferait diverger la carte de
 * suivi et le ticket pour la MÊME commande — se référer au normaliseur JS comme
 * source de vérité si l'un des deux doit évoluer.
 *
 * CE QU'ELLE N'EXPÉDIE PAS, VOLONTAIREMENT
 * ----------------------------------------
 *  • Aucun prix. La caisse identifie un client ici ; le détail et le ticket portent
 *    déjà les montants, et NF525 fait foi là-bas, pas sur une carte de suivi.
 *  • Aucune clé vide. Une commande sans personnalisation n'ajoute pas un seul octet
 *    (c'est ce qui tient la moyenne à ~+32 o/commande, budget GOAL §3 = 150 o).
 *  • `quantity` omise quand elle vaut 1 — implicite, donc inutile à transporter.
 *
 * COÛT SQL : ZÉRO. `item_variations`, `item_extras` et `composition_snapshot` sont
 * des COLONNES de `order_items` (`app/Models/OrderItem.php:71-76`), déjà rapatriées
 * par le `select *` de la requête existante. Cette classe ne touche aucune relation.
 */
final class CompositionCompactor
{
    /**
     * Projection compacte de la composition d'UNE ligne de commande.
     *
     * @return array{options?: list<array{label:string,value:string,quantity?:int}>, extras?: list<array{name:string,quantity?:int}>, addons?: list<array{name:string,quantity?:int}>}
     *         Les clés vides sont ABSENTES du tableau — jamais présentes à vide.
     */
    public static function forLine(OrderItem $line): array
    {
        $snapshot = self::asArray($line->composition_snapshot);

        $options = self::compactOptions(
            self::pick($snapshot, 'lines') ?? self::asArray($line->item_variations)
        );
        $extras = self::compactNamed(
            self::pick($snapshot, 'extras') ?? self::asArray($line->item_extras),
            ['extra_name', 'name']
        );
        // Les suppléments de formule n'existent QUE dans l'instantané : ils sont nés
        // avec le composeur, il n'y a pas d'ancienne forme à rattraper.
        $addons = self::compactNamed(
            self::pick($snapshot, 'addons') ?? [],
            ['addon_name', 'name', 'addon_item_name']
        );

        return array_filter([
            'options' => $options,
            'extras'  => $extras,
            'addons'  => $addons,
        ], static fn (array $v): bool => $v !== []);
    }

    /**
     * Une section de l'instantané, si elle est présente ET non vide — sinon `null`,
     * ce qui déclenche le repli sur la colonne héritée. Même priorité que
     * `OrderItemResource::resolveVariationsForApi()`.
     *
     * @return list<mixed>|null
     */
    private static function pick(array $snapshot, string $section): ?array
    {
        $value = $snapshot[$section] ?? null;

        return is_array($value) && $value !== [] ? array_values($value) : null;
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<array{label:string,value:string,quantity?:int}>
     */
    private static function compactOptions(array $raw): array
    {
        $out = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            // Discriminant identique au normaliseur JS (posReceiptBuilder.js:174).
            $fromSnapshot = is_string($entry['attribute_name'] ?? null)
                || array_key_exists('variation_id', $entry);

            $label = $fromSnapshot
                ? ($entry['attribute_name'] ?? $entry['variation_name'] ?? '')
                : ($entry['variation_name'] ?? $entry['attribute_name'] ?? '');
            $value = $fromSnapshot
                ? ($entry['variation_name'] ?? $entry['name'] ?? '')
                : ($entry['name'] ?? $entry['variation_name'] ?? '');

            $value = trim((string) $value);
            if ($value === '') {
                // Un identifiant nu n'est pas un libellé : rien à montrer au caissier.
                continue;
            }

            $ligne = ['label' => trim((string) $label), 'value' => $value];
            if (($q = self::quantity($entry)) > 1) {
                $ligne['quantity'] = $q;
            }
            $out[] = $ligne;
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $raw
     * @param  list<string>  $nameKeys  clés candidates, par ordre de préférence
     * @return list<array{name:string,quantity?:int}>
     */
    private static function compactNamed(array $raw, array $nameKeys): array
    {
        $out = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = '';
            foreach ($nameKeys as $key) {
                $candidate = trim((string) ($entry[$key] ?? ''));
                if ($candidate !== '') {
                    $name = $candidate;
                    break;
                }
            }

            if ($name === '') {
                continue;
            }

            $ligne = ['name' => $name];
            if (($q = self::quantity($entry)) > 1) {
                $ligne['quantity'] = $q;
            }
            $out[] = $ligne;
        }

        return $out;
    }

    /** Quantité positive, 1 par défaut — comme le normaliseur JS. */
    private static function quantity(array $entry): int
    {
        $raw = $entry['quantity'] ?? null;

        if (! is_numeric($raw)) {
            return 1;
        }

        return max(0, (int) $raw) ?: 1;
    }

    /**
     * Décodage tolérant : la colonne peut arriver déjà castée en tableau
     * (`composition_snapshot`) ou en chaîne JSON (`item_variations`, `item_extras`).
     * Un JSON invalide donne un tableau vide — jamais une exception en plein service.
     *
     * @return array<array-key, mixed>
     */
    private static function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
