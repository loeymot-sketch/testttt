<?php

namespace App\Services\RawMaterials;

use App\Enums\OrderStatus;
use App\Models\FrontendOrder;
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

    /**
     * [STOCK-VIANDE 2026-08-06 owner] Matières dont la QUANTITÉ appartient désormais au moteur
     * de portions (celui qui alimente le bandeau CUISSON), et non plus à la fiche produit.
     *
     * Règle : une matière a UN SEUL propriétaire de sa quantité.
     *   · ce que le CLIENT choisit  → MeatPortionCalculator, depuis le snapshot scellé
     *   · ce que la RECETTE fixe    → raw_material_recipe_lines (pain, cheddar, crudités, sauce)
     *
     * Les lignes de recette PRODUIT et VARIATION portant ces matières sont donc ignorées :
     * les conserver ferait un double comptage. Elles ne sont PAS supprimées de la base — la
     * propriété est déclarée ici, ce qui reste réversible et préserve l'historique.
     *
     * « Poulet » (vrac, en grammes) figure dans la liste : c'était le forfait 200 g posé sur
     * chaque Cayenne et Chicken Burger, y compris ceux commandés en viande hachée.
     *
     * ⚠️ La propriété est CONDITIONNELLE, jamais globale : une fiche produit n'est écartée que
     * si le moteur de portions a effectivement quelque chose à dire sur CETTE ligne de commande.
     * Un blocage global par nom de matière désactiverait des recettes parfaitement légitimes là
     * où le moteur est muet (produit sans choix de viande, recette non documentée) — c'est-à-dire
     * qu'il transformerait une correction en perte de données.
     *
     * @var array<int, string> noms canoniques, comparés en minuscules
     */
    private const VIANDES_PILOTEES = [
        'viande hachée', 'poulet', 'poulet mariné', 'mexicanos', 'tenders',
        'nuggets', 'fricadelle', 'cordon bleu', 'chicken burger', 'poisson pané',
    ];

    /** @var array<int, string> */
    private const FRITES_PILOTEES = ['portion frites'];

    public function __construct(
        private RawMaterialStockService $stock,
        private \App\Services\Kitchen\MeatPortionCalculator $portions = new \App\Services\Kitchen\MeatPortionCalculator,
        private \App\Services\Kitchen\MeatMaterialResolver $matieres = new \App\Services\Kitchen\MeatMaterialResolver,
        private \App\Services\Hardware\KitchenTicketSymbolicFormatter $symbolic = new \App\Services\Hardware\KitchenTicketSymbolicFormatter,
    )
    {
    }

    /**
     * Consomme les matières premières théoriques de TOUTE la commande.
     *
     * Accepte AUSSI un {@see FrontendOrder} (borne/web, source kiosk/web) : les
     * deux modèles pointent la MÊME table physique `orders` et exposent la même
     * relation `orderItems()` (avec `composition_snapshot`). La consommation est
     * donc identique quel que soit le canal de vente (POS / borne / web) — sans
     * cette parité, 2/3 des canaux ne décrémentent jamais la matière et la vue
     * « À acheter » sous-compte. L'idempotence (triplet source order_item) tient
     * de la même façon : les order_item.id sont uniques sur la table partagée.
     *
     * @return array{consumed: array<int, array{order_item_id:int, raw_material_id:int, qty:float}>, skipped: array<int, array<string, mixed>>}
     */
    public function consumeForOrder(Order|FrontendOrder $order): array
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
     * Accepte AUSSI un {@see FrontendOrder} (borne/web) — miroir de
     * {@see consumeForOrder} : même table `orders`, même relation orderItems(), et
     * la reprise travaille de toute façon sur les `order_item` ids (pas sur le type
     * du modèle commande). Sans cette parité, un FrontendOrder annulé consommerait
     * (fix création) sans jamais rendre → sur-consommation des annulées borne/web.
     *
     * @return array{reversed: array<int, array{order_item_id:int, raw_material_id:int, qty:float}>, skipped: array<int, array<string, mixed>>}
     */
    public function reverseForOrder(Order|FrontendOrder $order): array
    {
        $reversed = [];
        $skipped = [];

        // order_item ids de CETTE commande (BranchScope retiré : portée bornée par
        // order_id, contexte queue/console hors auth — miroir de consumeForOrder).
        // [SUPERVISOR A3-P1 2026-07-31] withTrashed() OBLIGATOIRE : OrderService::destroy() SOFT-DELETE
        // les orderItems AVANT que le listener queue ReverseRawMaterialsOnOrderCanceled ne tourne. Sans
        // withTrashed, le SoftDeletes scope excluait les lignes → [] → early-return → la matière première
        // (BOM) n'était JAMAIS rendue au destroy (sur-consommation permanente). Miroir de StockService
        // ::releaseForOrder (déjà en withTrashed). Les RawMaterialMovement restent indexés par order_item_id.
        $orderItemIds = $order->orderItems()
            ->withoutGlobalScope(BranchScope::class)
            ->withTrashed()
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
    private function orderReachedCancelingStatus(Order|FrontendOrder $order): bool
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

        // 0. [STOCK-VIANDE 2026-08-06 owner] Le moteur de portions parle EN PREMIER : c'est lui
        //    qui décide si la fiche produit garde ou non la main sur les viandes et les frites.
        //    Le nom du produit vient de la relation `orderItem` (Item) : `order_items` ne porte
        //    PAS de colonne `name`, et s'y fier donnerait une chaîne vide en silence — donc
        //    aucune recette fixe reconnue, donc aucune viande de burger comptée.
        $portions = $this->portions->forLine(
            (string) (optional($orderItem->orderItem)->name ?? ''),
            $snapshot,
            $lineQty,
            (string) $orderItem->instruction,
        );
        $aEcarter = $this->matieresReprises($portions['pieces']);

        // 1. Recette PRODUIT.
        $this->addRecipeLines(
            $totals,
            $this->recipeLinesFor(Item::class, (int) $orderItem->item_id),
            $lineQty,
            $aEcarter
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
                $multiplier,
                $aEcarter
            );
        }

        // 3. EXTRAS (par id OU par groupe de nom).
        foreach ($snapshot['extras'] as $extra) {
            $extraId = (int) ($extra['extra_id'] ?? 0);
            $extraName = (string) ($extra['extra_name'] ?? '');
            $multiplier = $lineQty * max(1, (int) ($extra['quantity'] ?? 1));

            // [STOCK-VIANDE 2026-08-06] Supplément viande NOMMÉ → le moteur de portions le
            // décompte dans la VRAIE viande demandée (étape 4). La ligne de recette de groupe
            // (« viande supplémentaire » → 75 g de hachée forfaitaires) ferait alors doublon.
            // Supplément NON nommé → on garde la moyenne historique plutôt que de perdre la
            // consommation : mieux vaut une approximation assumée qu'un trou silencieux.
            if (preg_match('/viande\s*suppl|steak\s*suppl/iu', $extraName)
                && $this->symbolic->extraViandeNames((string) $orderItem->instruction) !== []) {
                continue;
            }

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

        // 4. [STOCK-VIANDE 2026-08-06 owner] VIANDES ET FRITES, depuis le choix RÉEL du client
        //    (calculé à l'étape 0). Même calcul que le bandeau CUISSON du ticket et de l'écran :
        //    la cuisine et le stock ne peuvent plus se contredire.
        $resolu = $this->matieres->toMaterialQuantities($portions['pieces'], self::BRANCH_ID);
        foreach ($resolu['totals'] as $rawMaterialId => $qty) {
            $totals[(int) $rawMaterialId] = ($totals[(int) $rawMaterialId] ?? 0.0) + (float) $qty;
        }
        foreach ($resolu['skipped'] as $s) {
            $skipped[] = [
                'order_item_id' => (int) $orderItem->id,
                'kind' => 'portion_'.$s['reason'],
                'symbol' => $s['symbol'],
                'pieces' => $s['pieces'],
            ];
        }
        if ($portions['inconnu']) {
            // Recette fixe non documentée : signalée, jamais devinée (cf. bandeau CUISSON).
            $skipped[] = [
                'order_item_id' => (int) $orderItem->id,
                'kind' => 'portion_recette_non_documentee',
                'item_id' => (int) $orderItem->item_id,
            ];
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
    /**
     * @param  array<int, float>  $totals
     * @param  array<int, bool>   $aEcarter  raw_material_id => true, matières reprises par le
     *                                       moteur de portions POUR CETTE ligne de commande
     */
    private function addRecipeLines(array &$totals, Collection $lines, int $multiplier, array $aEcarter = []): void
    {
        foreach ($lines as $line) {
            $rawMaterialId = (int) $line->raw_material_id;
            if (isset($aEcarter[$rawMaterialId])) {
                continue;
            }
            $qty = (float) $line->qty * $multiplier;
            $totals[$rawMaterialId] = ($totals[$rawMaterialId] ?? 0.0) + $qty;
        }
    }

    /** @var array<string, array<int, bool>>|null cache catégorie => {raw_material_id: true} */
    private ?array $categories = null;

    /**
     * Matières de la fiche produit à écarter, compte tenu de ce que le moteur de portions a
     * réellement produit sur CETTE ligne.
     *
     * S'il a produit de la viande, les viandes de la recette sont écartées (sinon double
     * comptage, et surtout le forfait « 200 g de poulet » s'appliquerait encore à un Cayenne
     * commandé en hachée). S'il n'a rien produit, la recette s'applique intégralement — c'est
     * le comportement historique, préservé pour tout produit hors de son périmètre.
     *
     * @param  array<string, int>  $pieces
     * @return array<int, bool>
     */
    private function matieresReprises(array $pieces): array
    {
        if ($this->categories === null) {
            $this->categories = ['viande' => [], 'frites' => []];
            foreach (\App\Models\RawMaterial::query()->where('branch_id', self::BRANCH_ID)->get(['id', 'name']) as $m) {
                $nom = mb_strtolower(trim((string) $m->name));
                if (in_array($nom, self::VIANDES_PILOTEES, true)) {
                    $this->categories['viande'][(int) $m->id] = true;
                } elseif (in_array($nom, self::FRITES_PILOTEES, true)) {
                    $this->categories['frites'][(int) $m->id] = true;
                }
            }
        }

        $aEcarter = [];
        $symboles = array_keys(array_filter($pieces, static fn ($n) => (int) $n > 0));

        // « ? » (supplément dont le nom est irrécupérable) ne déclenche AUCUNE reprise : le
        // moteur ne peut rien fournir en échange, et écarter la recette laisserait un trou au
        // lieu d'une correction. La moyenne historique reste alors la meilleure information
        // disponible. Idem pour « F », qui ne concerne que les frites.
        if (array_diff($symboles, ['F', '?']) !== []) {
            $aEcarter += $this->categories['viande'];
        }
        if (in_array('F', $symboles, true)) {
            $aEcarter += $this->categories['frites'];
        }

        return $aEcarter;
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
            return ['lines' => [], 'extras' => [], 'addons' => []];
        }

        return [
            'lines' => is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [],
            'extras' => is_array($snapshot['extras'] ?? null) ? $snapshot['extras'] : [],
            // [STOCK-VIANDE 2026-08-06] Les addons étaient VOLONTAIREMENT écartés (menus hors
            // périmètre P2a). Ils sont désormais nécessaires : les frites de menu se comptaient
            // à 4 % du réel (136 portions vendues, 6 décomptées). Les étapes 1 à 3 ne les
            // lisent pas ; seule l'étape 4 (moteur de portions) les consomme.
            'addons' => is_array($snapshot['addons'] ?? null) ? $snapshot['addons'] : [],
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
