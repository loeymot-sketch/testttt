<?php

namespace App\Services\RawMaterials;

use App\Models\Item;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RawMaterialRecipeLine;
use App\Models\Scopes\BranchScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P2a — B3] Moteur de consommation
 * THÉORIQUE des matières premières.
 *
 * À chaque commande créée : pour chaque `order_item`, lit son
 * `composition_snapshot` (immuable NF525) et résout les lignes de recette
 * applicables, puis décrémente le stock matière théorique via
 * {@see RawMaterialStockService::consume()}.
 *
 * ─── Résolution (par order_item) ──────────────────────────────────────────
 *  1. PRODUIT   : recipe_lines (subject_type = Item::class, subject_id = item_id).
 *  2. VARIATIONS: pour chaque `snapshot.lines[].variation_id`, recipe_lines
 *                 (subject_type = ItemVariation::class, subject_id = variation_id).
 *  3. EXTRAS    : pour chaque `snapshot.extras[]`, recipe_lines matchant
 *                 (subject_type = ItemExtra::class AND subject_id = extra_id)
 *                 OU (subject_group = normalizeGroup(extra_name)) — plan
 *                 amendement #4 : les recettes mappent des GROUPES logiques
 *                 (43 noms ≈ 535 rows ItemExtra), pas des ids uniques.
 *
 * ─── Quantités ────────────────────────────────────────────────────────────
 *  qty consommée = recipe.qty × order_item.quantity, multipliée en plus par la
 *  quantité de la ligne de snapshot pour les variations/extras (extra.quantity).
 *
 * ─── Somme-puis-consomme (invariant d'idempotence) ────────────────────────
 *  Les lignes qui pointent la MÊME matière (ex : sauce du produit + « sauce
 *  supplémentaire » extra) sont AGRÉGÉES par raw_material_id AVANT l'appel à
 *  consume(). On appelle consume() UNE SEULE FOIS par (order_item, matière),
 *  car l'idempotence du StockService porte sur le triplet
 *  (source_type='order_item', source_id=order_item.id, raw_material_id) : deux
 *  appels séparés sur la même matière verraient le second dédupliqué à tort
 *  (perte silencieuse de la 2ᵉ quantité).
 *
 * ─── Rejeu ────────────────────────────────────────────────────────────────
 *  Rejouer la même commande (retry queue, replay historique) = NO-OP grâce au
 *  triplet ci-dessus : chaque (order_item, matière) n'est consommé qu'une fois.
 *
 * ─── Approximations ASSUMÉES ──────────────────────────────────────────────
 *  - Amendement #1 : le supplément viande GÉNÉRIQUE (« Viande supplémentaire »
 *    sans dire laquelle) est traité comme n'importe quel extra — s'il porte une
 *    ligne de recette (par id ou par groupe) elle s'applique (mix moyen pondéré) ;
 *    SINON il est ignoré silencieusement mais LOGGÉ (pas d'erreur) et rangé dans
 *    `skipped[]`. Précision exacte = snapshot schema_version=2 (hors P2a).
 *  - Les ADDONS (menus : frites/boisson) NE sont PAS résolus en P2a — ils
 *    restent comptés à l'unité par le stock existant (plan amendement #2, une
 *    seule vérité par objet physique). Périmètre volontairement restreint.
 *
 * ─── NF525 / branch ───────────────────────────────────────────────────────
 *  Couche ADDITIVE : LIT les ventes (snapshots), n'écrit RIEN dans la chaîne
 *  fiscale (aucun prix, séquence, audit_log, z_report). Hard-scope branch_id=1
 *  (V1 mono-branche — pattern DailyBookEntry).
 */
class RawMaterialConsumptionService
{
    /** Branche unique V1 (hard-scope). */
    public const BRANCH_ID = 1;

    /** Origine rejouable des mouvements — clé d'idempotence côté StockService. */
    private const SOURCE_TYPE = 'order_item';

    /** Motif métier du mouvement de consommation. */
    private const REASON = 'sale';

    public function __construct(private RawMaterialStockService $stock)
    {
    }

    /**
     * Consomme les matières premières théoriques de TOUTE la commande.
     *
     * @return array{consumed: array<int, array{order_item_id:int, raw_material_id:int, qty:float}>, skipped: array<int, array<string, mixed>>}
     */
    public function consumeForOrder(Order $order): array
    {
        $consumed = [];
        $skipped = [];

        // Items de CETTE commande, par order_id. On retire le BranchScope global
        // (le listener tourne sur la queue, hors contexte auth) : la portée est
        // déjà bornée par la clé étrangère order_id — pas d'élargissement tenant.
        $orderItems = $order->orderItems()
            ->withoutGlobalScope(BranchScope::class)
            ->get();

        foreach ($orderItems as $orderItem) {
            $this->consumeForOrderItem($orderItem, $consumed, $skipped);
        }

        return ['consumed' => $consumed, 'skipped' => $skipped];
    }

    /**
     * @param  array<int, array<string, mixed>>  $consumed
     * @param  array<int, array<string, mixed>>  $skipped
     */
    private function consumeForOrderItem(OrderItem $orderItem, array &$consumed, array &$skipped): void
    {
        $lineQty = max(1, (int) $orderItem->quantity);
        $snapshot = $this->normalizeSnapshot($orderItem->composition_snapshot);

        // Agrégation raw_material_id => qty pour CET order_item (somme AVANT consume).
        $totals = [];

        // 1. Recette PRODUIT.
        $this->addRecipeLines(
            $totals,
            $this->recipeLinesFor(Item::class, (int) $orderItem->item_id),
            $lineQty
        );

        // 2. VARIATIONS (snapshot.lines[].variation_id).
        foreach ($snapshot['lines'] as $line) {
            $variationId = (int) ($line['variation_id'] ?? 0);
            if ($variationId <= 0) {
                continue;
            }
            $multiplier = $lineQty * max(1, (int) ($line['quantity'] ?? 1));
            $this->addRecipeLines(
                $totals,
                $this->recipeLinesFor(ItemVariation::class, $variationId),
                $multiplier
            );
        }

        // 3. EXTRAS (par id OU par groupe de nom).
        foreach ($snapshot['extras'] as $extra) {
            $extraId = (int) ($extra['extra_id'] ?? 0);
            $extraName = (string) ($extra['extra_name'] ?? '');
            $multiplier = $lineQty * max(1, (int) ($extra['quantity'] ?? 1));

            $lines = $this->recipeLinesForExtra($extraId, $extraName);

            if ($lines->isEmpty()) {
                // Amendement #1 : extra (dont supplément générique) sans recette
                // → skip LOGGÉ, jamais d'erreur.
                $skipped[] = [
                    'order_item_id' => (int) $orderItem->id,
                    'kind' => 'extra_no_recipe',
                    'extra_id' => $extraId,
                    'extra_name' => $extraName,
                ];
                Log::info('[RawMaterialConsumption] extra sans recette — ignoré (approximation assumée)', [
                    'order_item_id' => (int) $orderItem->id,
                    'extra_id' => $extraId,
                    'extra_name' => $extraName,
                ]);
                continue;
            }

            $this->addRecipeLines($totals, $lines, $multiplier);
        }

        if ($totals === []) {
            // Produit hors périmètre P1 (aucune recette) et aucun extra recette-porteur.
            $skipped[] = [
                'order_item_id' => (int) $orderItem->id,
                'kind' => 'no_recipe',
                'item_id' => (int) $orderItem->item_id,
            ];

            return;
        }

        foreach ($totals as $rawMaterialId => $qty) {
            if ($qty <= 0.0) {
                continue;
            }

            $this->stock->consume(
                rawMaterialId: (int) $rawMaterialId,
                qty: (float) $qty,
                reason: self::REASON,
                sourceType: self::SOURCE_TYPE,
                sourceId: (int) $orderItem->id,
                meta: [
                    'order_id' => (int) $orderItem->order_id,
                    'item_id' => (int) $orderItem->item_id,
                ],
                branchId: self::BRANCH_ID,
            );

            $consumed[] = [
                'order_item_id' => (int) $orderItem->id,
                'raw_material_id' => (int) $rawMaterialId,
                'qty' => (float) $qty,
            ];
        }
    }

    /**
     * Lignes de recette d'un sujet identifié par (type, id) — hard-scope branch 1.
     */
    private function recipeLinesFor(string $subjectType, int $subjectId): Collection
    {
        if ($subjectId <= 0) {
            return new Collection();
        }

        return RawMaterialRecipeLine::query()
            ->where('branch_id', self::BRANCH_ID)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->get();
    }

    /**
     * Lignes de recette d'un extra : match par id spécifique OU par groupe logique
     * (plan amendement #4). Le AND (type+id) est encapsulé pour que le OR groupe
     * ne fuie pas hors du filtre branch_id.
     */
    private function recipeLinesForExtra(int $extraId, string $extraName): Collection
    {
        $group = self::normalizeGroup($extraName);

        if ($extraId <= 0 && $group === '') {
            return new Collection();
        }

        return RawMaterialRecipeLine::query()
            ->where('branch_id', self::BRANCH_ID)
            ->where(function ($query) use ($extraId, $group) {
                if ($extraId > 0) {
                    $query->where(function ($sub) use ($extraId) {
                        $sub->where('subject_type', ItemExtra::class)
                            ->where('subject_id', $extraId);
                    });
                }
                if ($group !== '') {
                    $query->orWhere('subject_group', $group);
                }
            })
            ->get();
    }

    /**
     * Additionne les qty d'un lot de lignes de recette dans l'accumulateur
     * $totals (keyed par raw_material_id), en appliquant le multiplicateur.
     *
     * @param  array<int, float>  $totals
     */
    private function addRecipeLines(array &$totals, Collection $lines, int $multiplier): void
    {
        foreach ($lines as $line) {
            $rawMaterialId = (int) $line->raw_material_id;
            $qty = (float) $line->qty * $multiplier;
            $totals[$rawMaterialId] = ($totals[$rawMaterialId] ?? 0.0) + $qty;
        }
    }

    /**
     * Normalise un `composition_snapshot` en tableau {lines, extras} défensif
     * (null / string JSON / clés manquantes tolérés). Les addons sont hors P2a.
     *
     * @return array{lines: array<int, array<string, mixed>>, extras: array<int, array<string, mixed>>}
     */
    private function normalizeSnapshot(mixed $snapshot): array
    {
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        if (! is_array($snapshot)) {
            return ['lines' => [], 'extras' => []];
        }

        return [
            'lines' => is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [],
            'extras' => is_array($snapshot['extras'] ?? null) ? $snapshot['extras'] : [],
        ];
    }

    /**
     * Clé de GROUPE canonique d'un nom d'extra (SSOT partageable avec le futur
     * seeding fiche P1b) : minuscule, trim, espaces internes normalisés.
     */
    public static function normalizeGroup(string $name): string
    {
        $lower = mb_strtolower(trim($name));

        return trim((string) preg_replace('/\s+/', ' ', $lower));
    }
}
