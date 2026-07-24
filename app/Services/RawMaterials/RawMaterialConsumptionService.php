<?php

namespace App\Services\RawMaterials;

use App\Enums\OrderStatus;
use App\Models\Item;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RawMaterialMovement;
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

    /**
     * Origine des mouvements de REPRISE (rendu de stock sur annulation). DISTINCTE
     * de self::SOURCE_TYPE : la clé d'idempotence du StockService porte sur le
     * triplet (source_type, source_id, raw_material_id) — un source_type dédié
     * garantit que la reprise ne se dédupliquera JAMAIS contre la consommation
     * d'origine (même source_id=order_item.id) et qu'une 2ᵉ annulation retrouvera
     * SA propre reprise → NO-OP (jamais de double-rendu).
     */
    private const REVERSAL_SOURCE_TYPE = 'order_item_reversal';

    /** Motif métier du mouvement de reprise (rendu de stock annulé/refusé/retourné). */
    private const REVERSAL_REASON = 'consumption_reversal';

    /**
     * [R2-2] Statuts terminaux ANNULANTS. Si la commande les a DÉJÀ atteints au
     * moment où le job de consommation tourne, on NE consomme PAS (voir la garde
     * de course dans {@see consumeForOrder}). Miroir exact du périmètre exclu par
     * le replay ({@see \App\Console\Commands\RawMaterialReplayConsumptionCommand}
     * ::EXCLUDED_STATUSES) et de {@see reverseForOrder}.
     */
    private const CANCELING_STATUSES = [
        OrderStatus::CANCELED,
        OrderStatus::REJECTED,
        OrderStatus::RETURNED,
    ];

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

        // [R2-2] GARDE DE COURSE ASYNC. consumeForOrder (listener OrderCreated) ET
        // reverseForOrder (listener OrderCanceled) sont TOUS DEUX ShouldQueue. Une
        // commande créée puis annulée très vite peut voir le job REVERSE s'exécuter
        // AVANT le job CONSUME (≥2 workers, ou drain de backlog) : le reverse ne
        // trouve alors rien à rendre (no-op), puis le consume décrémenterait une
        // commande annulée que plus rien ne reprend = dérive B-1 réintroduite. On
        // relit donc le statut FRAIS en base (le job a pu être enqueué en PENDING)
        // et on SKIP tout statut terminal annulant. Belt-and-suspenders avec le
        // reverse : quel que soit l'ordre d'exécution, une commande annulée n'est
        // jamais consommée. Aligne aussi la vérité LIVE sur le replay (EXCLUDED_STATUSES).
        if ($this->orderReachedCancelingStatus($order)) {
            $skipped[] = [
                'order_id' => (int) $order->id,
                'kind' => 'order_canceled_before_consume',
            ];

            Log::info('[RawMaterialConsumption] consume ignoré — commande déjà annulée (garde course async)', [
                'order_id' => (int) $order->id,
            ]);

            return ['consumed' => $consumed, 'skipped' => $skipped];
        }

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
     * [B-1] REND le stock matière théorique consommé par une commande qui a
     * atteint un statut terminal ANNULANT (CANCELED / REJECTED / RETURNED).
     *
     * Pourquoi : {@see consumeForOrder} décrémente à la CRÉATION quel que soit le
     * statut final. Le rejeu historique ({@see RawMaterialReplayConsumptionCommand})
     * EXCLUT ces trois statuts. Sans reprise, `on_hand` sur-consomme définitivement
     * les annulées et DIVERGE en permanence d'une reconstruction par replay. Cette
     * méthode aligne la vérité LIVE sur la vérité replay — miroir raw-material de
     * {@see \App\Services\Stock\StockService::releaseForOrder} (stock_levels).
     *
     * ─── Idempotence : DOUBLE garde, ne double-rend JAMAIS ────────────────────
     *  (a) Ne rend QUE le RÉELLEMENT consommé : on lit les mouvements de conso de
     *      CETTE commande (source_type='order_item', reason='sale') et on rend
     *      exactement abs(delta) par (order_item, matière). Une commande jamais
     *      consommée → aucun mouvement source → no-op total.
     *  (b) Ne rejoue jamais une reprise : le mouvement de rendu porte un
     *      source_type DÉDIÉ ({@see REVERSAL_SOURCE_TYPE}, même source_id) → sa
     *      clé d'idempotence StockService ne collisionne pas avec la conso, et une
     *      2ᵉ annulation retrouve la reprise déjà écrite → NO-OP. On court-circuite
     *      AUSSI explicitement ici ({@see reversalExists}) pour ne pas même ouvrir
     *      la transaction.
     *
     * NF525 / branch : lit les mouvements, écrit un rendu positif hors chaîne
     * fiscale. La reprise est écrite sur la MÊME branche que la conso d'origine.
     *
     * @return array{reversed: array<int, array{order_item_id:int, raw_material_id:int, qty:float}>, skipped: array<int, array<string, mixed>>}
     */
    public function reverseForOrder(Order $order): array
    {
        $reversed = [];
        $skipped = [];

        // order_item ids de CETTE commande (BranchScope retiré : portée bornée par
        // order_id, contexte queue/console hors auth — miroir de consumeForOrder).
        $orderItemIds = $order->orderItems()
            ->withoutGlobalScope(BranchScope::class)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($orderItemIds === []) {
            return ['reversed' => $reversed, 'skipped' => $skipped];
        }

        // (a) Mouvements de conso RÉELS de la commande (signature exacte de consume()).
        $consumptions = RawMaterialMovement::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->whereIn('source_id', $orderItemIds)
            ->where('reason', self::REASON)
            ->get();

        foreach ($consumptions as $movement) {
            $sourceId = (int) $movement->source_id;
            $rawMaterialId = (int) $movement->raw_material_id;
            $qty = abs((float) $movement->delta);
            $branchId = (int) $movement->branch_id;

            if ($qty <= 0.0) {
                continue;
            }

            // (b) Reprise déjà écrite pour ce (order_item, matière) → skip.
            if ($this->reversalExists($sourceId, $rawMaterialId)) {
                $skipped[] = [
                    'order_item_id' => $sourceId,
                    'raw_material_id' => $rawMaterialId,
                    'kind' => 'already_reversed',
                ];

                continue;
            }

            $this->stock->receive(
                rawMaterialId: $rawMaterialId,
                qty: $qty,
                reason: self::REVERSAL_REASON,
                sourceType: self::REVERSAL_SOURCE_TYPE,
                sourceId: $sourceId,
                meta: [
                    'order_id' => (int) $order->id,
                    'reversed_movement_id' => (int) $movement->id,
                ],
                branchId: $branchId > 0 ? $branchId : self::BRANCH_ID,
            );

            $reversed[] = [
                'order_item_id' => $sourceId,
                'raw_material_id' => $rawMaterialId,
                'qty' => $qty,
            ];
        }

        return ['reversed' => $reversed, 'skipped' => $skipped];
    }

    /**
     * Une reprise (rendu) a-t-elle DÉJÀ été écrite pour ce (order_item, matière) ?
     * Garde explicite d'idempotence, complémentaire à celle du StockService.
     */
    private function reversalExists(int $sourceId, int $rawMaterialId): bool
    {
        return RawMaterialMovement::query()
            ->where('source_type', self::REVERSAL_SOURCE_TYPE)
            ->where('source_id', $sourceId)
            ->where('raw_material_id', $rawMaterialId)
            ->exists();
    }

    /**
     * [R2-2] La commande est-elle DÉJÀ dans un statut terminal annulant
     * (CANCELED/REJECTED/RETURNED) au moment PRÉSENT ? Relit le statut FRAIS en base
     * — l'objet Order passé au job a pu être sérialisé en PENDING puis la commande
     * annulée avant l'exécution du job. BranchScope retiré (contexte queue/console
     * hors auth, portée bornée par la clé primaire — miroir des autres requêtes du
     * service). Statut illisible (commande supprimée) → false : on ne SUR-skip pas
     * (le parcours order_items suivant sera de toute façon vide).
     */
    private function orderReachedCancelingStatus(Order $order): bool
    {
        $status = (int) (Order::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereKey($order->getKey())
            ->value('status') ?? 0);

        return in_array($status, self::CANCELING_STATUSES, true);
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
