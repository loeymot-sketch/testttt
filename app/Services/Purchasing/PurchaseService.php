<?php

namespace App\Services\Purchasing;

use App\Models\Item;
use App\Models\PurchaseDocument;
use App\Models\PurchaseLine;
use App\Models\RawMaterial;
use App\Models\RawMaterialStock;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\RawMaterials\RawMaterialStockService;
use Illuminate\Support\Facades\DB;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3a] Application en stock d'un
 * document d'achat validé. Domaine NEUF, ADDITIF, HORS NF525 — n'écrit JAMAIS
 * la chaîne fiscale (audit_logs / z_reports / fiscal_sequence intouchés).
 *
 * `validateDocument()` parcourt les lignes VALIDÉES (`status = validated` :
 * l'owner a confirmé la cible proposée par l'IA en P3b) et, selon `target_type` :
 *  - raw_material : crédite le stock matière via RawMaterialStockService::receive
 *                   (idempotent par source `purchase_line`/line.id) PUIS recalcule
 *                   `avg_cost` en MOYENNE PONDÉRÉE
 *                   (ancien_stock×ancien_coût + qty×prix) / (ancien_stock + qty).
 *  - stock_item   : incrémente stock_levels de l'item (+qty unités entières) +
 *                   mouvement `manual_in` (pattern StockService existant).
 *  - charge       : aucun mouvement de stock (comptabilisé seulement).
 *
 * IDEMPOTENCE (« re-valider = no-op ») : gate au niveau document — un document
 * déjà `validated` retourne immédiatement (protège le recalcul d'avg_cost, qui
 * n'est PAS couvert par l'idempotence-mouvement). La transaction verrouille +
 * re-teste le statut (garde double-validation concurrente). Défense en
 * profondeur : receive() dédup par mouvement, stock_levels par idempotency_key.
 *
 * Branch : hard-scope explicite (V1 mono-branche = 1). `branch_id` du document
 * fait foi ; défaut 1.
 */
class PurchaseService
{
    /** Précision du coût moyen (aligne raw_materials.avg_cost decimal:4). */
    private const COST_SCALE = 4;

    public function __construct(
        private RawMaterialStockService $rawMaterialStock,
        // [S2 V3 D-3 2026-07-29] Une réception doit LEVER la rupture automatique
        // et notifier les surfaces — StockService est le SSOT de cette règle.
        private \App\Services\Stock\StockService $stockService,
    ) {
    }

    /**
     * Applique en stock un document d'achat. Idempotent (re-valider = no-op).
     *
     * @return array{document_id:int, status:string, applied:array<string,int>}
     */
    public function validateDocument(PurchaseDocument $document): array
    {
        // Gate idempotence : un document déjà validé ne se ré-applique pas.
        if ($document->status === PurchaseDocument::STATUS_VALIDATED) {
            return $this->noopResult($document);
        }

        return DB::transaction(function () use ($document): array {
            // Verrouille + re-teste le statut (double-validation concurrente).
            $locked = PurchaseDocument::query()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status === PurchaseDocument::STATUS_VALIDATED) {
                return $this->noopResult($locked ?? $document);
            }

            $branchId = (int) ($locked->branch_id ?: 1);
            $applied = [
                'raw_material' => 0,
                'stock_item' => 0,
                'charge' => 0,
                'skipped_proposed' => 0,
            ];

            $lines = $locked->lines()->orderBy('id')->get();

            foreach ($lines as $line) {
                // Seules les lignes VALIDÉES par l'owner sont appliquées.
                if ($line->status !== PurchaseLine::STATUS_VALIDATED) {
                    $applied['skipped_proposed']++;
                    continue;
                }

                switch ($line->target_type) {
                    case PurchaseLine::TARGET_RAW_MATERIAL:
                        $this->applyRawMaterialLine($line, $branchId);
                        $applied['raw_material']++;
                        break;

                    case PurchaseLine::TARGET_STOCK_ITEM:
                        $this->applyStockItemLine($line, $branchId);
                        $applied['stock_item']++;
                        break;

                    case PurchaseLine::TARGET_CHARGE:
                        // Charge : comptabilisée seulement, aucun mouvement de stock.
                        $applied['charge']++;
                        break;
                }
            }

            $locked->forceFill(['status' => PurchaseDocument::STATUS_VALIDATED])->save();

            return [
                'document_id' => (int) $locked->id,
                'status' => 'validated',
                'applied' => $applied,
            ];
        });
    }

    /**
     * Matière première : crédite le stock (idempotent par ligne) puis recalcule
     * le coût moyen pondéré. Lit l'état AVANT réception (le stock d'avant et le
     * coût d'avant nourrissent la pondération).
     */
    private function applyRawMaterialLine(PurchaseLine $line, int $branchId): void
    {
        $rawMaterialId = (int) $line->target_id;
        if ($rawMaterialId <= 0) {
            return;
        }

        // [ONB-08 2026-08-28 · P0] La quantite partait BRUTE vers le stock, sans jamais
        // comparer l'unite de la ligne de facture a celle de la matiere.
        //
        // Mesure sur la base reelle : les matieres sont stockees en `g`, `piece`,
        // `tranche` ; les factures arrivent en `kg`, `piece`, `tranche`. Une ligne
        // « Poulet frais 3kg » (`qty=3`, `unit='kg'`) creditait donc **3 GRAMMES** a une
        // matiere en `g` — un facteur MILLE. Consequence deja visible : 11 des 14
        // matieres stockees sont NEGATIVES (Poulet -9 600 g), « Conso & Stock » annonce
        // 17 ruptures sur 20 pendant que la borne vend tous les burgers, et le cout
        // moyen pondere (calcule plus bas avec la meme quantite) est faux du meme
        // facteur.
        //
        // On convertit quand la conversion est CONNUE. Quand elle ne l'est pas, on
        // REFUSE : crediter un nombre dont on ignore l'unite est exactement ce qui a
        // corrompu ce stock. Un refus nomme se corrige ; une corruption silencieuse se
        // decouvre des mois plus tard.
        $qty = $this->quantiteDansLUniteDeLaMatiere($line, $rawMaterialId);
        $unitPrice = $line->unit_price === null ? null : (float) $line->unit_price;

        // État AVANT réception (nécessaire à la pondération).
        $oldStock = (float) (RawMaterialStock::query()
            ->where('raw_material_id', $rawMaterialId)
            ->where('branch_id', $branchId)
            ->value('on_hand') ?? 0.0);

        $oldAvg = RawMaterial::query()
            ->whereKey($rawMaterialId)
            ->value('avg_cost');
        $oldAvg = $oldAvg === null ? null : (float) $oldAvg;

        // Entrée de stock — idempotente par (source_type, source_id, raw_material_id).
        $this->rawMaterialStock->receive(
            $rawMaterialId,
            $qty,
            'purchase',
            'purchase_line',
            (int) $line->id,
            ['purchase_document_id' => (int) $line->purchase_document_id],
            $branchId,
        );

        // Coût moyen pondéré — seulement si un prix unitaire est connu (sinon on
        // ne peut pas revaloriser : on NE touche PAS avg_cost, il reste NULL/inchangé).
        if ($unitPrice !== null) {
            $newAvg = $this->weightedAverageCost($oldStock, $oldAvg, $qty, $unitPrice);
            RawMaterial::query()
                ->whereKey($rawMaterialId)
                ->update(['avg_cost' => $newAvg]);
        }
    }

    /**
     * Moyenne pondérée : (ancien_stock×ancien_coût + qty×prix) / (ancien_stock+qty).
     * Repli sur le prix unitaire quand il n'y a pas de valorisation antérieure
     * exploitable (premier achat, ancien coût inconnu, ou dénominateur ≤ 0 —
     * on_hand est signé et peut être négatif via la conso théorique).
     */
    /**
     * Facteurs de conversion CONNUS, de l'unite de facture vers l'unite de matiere.
     *
     * Volontairement court : on n'ajoute une paire que lorsqu'elle est verifiee. Une
     * table trop large inviterait a deviner, et deviner est precisement ce qui a
     * corrompu ce stock.
     */
    private const CONVERSIONS = [
        'kg:g'  => 1000.0,
        'g:kg'  => 0.001,
        'l:ml'  => 1000.0,
        'ml:l'  => 0.001,
        'cl:ml' => 10.0,
        'ml:cl' => 0.1,
    ];

    /**
     * Les unites de DENOMBREMENT : elles comptent des objets, pas des grandeurs.
     *
     * « 5 tranches » vers une matiere comptee en « piece » vaut 5. Aucun facteur ne
     * peut s'y perdre, contrairement a kg/g. Mesure sur la base reelle : 5 des 10
     * lignes d'achat sont exactement ce cas — les refuser bloquerait la reception
     * entiere, alors qu'aucune corruption n'y est possible.
     *
     * `piece` est aussi la valeur de repli de `InvoiceClassificationService:108`
     * quand l'analyse ne sait pas lire l'unite. La ranger ici evite qu'une hesitation
     * de l'OCR ne bloque un document complet.
     */
    /**
     * [ONB-08 2026-08-28 · correction d'un defaut de ce garde-fou] Ecritures
     * equivalentes d'une meme unite.
     *
     * La premiere version comparait des chaines brutes passees a `mb_strtolower`,
     * qui NE DEPOUILLE PAS LES ACCENTS. « piece » passait, « pièce » non. « unité »,
     * « boîte », « kilo », « litre » — l'ecriture que produit un OCR sur une facture
     * francaise — tombaient dans la branche « conversion inconnue » et faisaient
     * echouer la RECEPTION ENTIERE, l'exception traversant `DB::transaction`.
     *
     * Corriger une corruption silencieuse en fabriquant un blocage bruyant sur des
     * donnees legitimes est un mauvais echange : ca arrete le travail du commercant.
     *
     * ⚠️ « carton », « colis », « caisse » ne sont VOLONTAIREMENT pas ici : un carton
     * contient N pieces, pas une. Les traiter comme un denombrement crediterait 2 la
     * ou il faut 24. Ils restent inconnus, donc refuses avec un message.
     */
    private const ECRITURES_EQUIVALENTES = [
        'kilo' => 'kg', 'kilos' => 'kg', 'kilogramme' => 'kg', 'kilogrammes' => 'kg', 'kgs' => 'kg',
        'gramme' => 'g', 'grammes' => 'g', 'gr' => 'g', 'grs' => 'g',
        'litre' => 'l', 'litres' => 'l', 'lt' => 'l', 'ltr' => 'l',
        'millilitre' => 'ml', 'millilitres' => 'ml',
        'centilitre' => 'cl', 'centilitres' => 'cl',
        'pieces' => 'piece', 'pce' => 'piece', 'pces' => 'piece', 'pcs' => 'piece', 'pc' => 'piece',
        'unite' => 'piece', 'unites' => 'piece', 'u' => 'piece', 'p' => 'piece',
        'tranches' => 'tranche',
        'portions' => 'portion',
        'sachets' => 'sachet',
        'boites' => 'boite',
        'lots' => 'lot',
    ];

    private const UNITES_DE_DENOMBREMENT = [
        'piece', 'tranche', 'portion', 'sachet', 'boite', 'lot',
    ];

    /**
     * Ramene la quantite d'une ligne de facture dans l'unite de la matiere.
     *
     * @throws \InvalidArgumentException quand la conversion n'est pas connue — mieux
     *         vaut un refus nomme qu'un stock corrompu en silence.
     */
    private function quantiteDansLUniteDeLaMatiere($line, int $rawMaterialId): float
    {
        $qty = (float) $line->qty;

        $uniteFacture = $this->normaliserUnite((string) ($line->unit ?? ''));
        $uniteMatiere = $this->normaliserUnite((string) (RawMaterial::query()
            ->whereKey($rawMaterialId)
            ->value('unit') ?? ''));

        // Unite absente d'un cote ou de l'autre, ou identique : rien a convertir.
        if ($uniteFacture === '' || $uniteMatiere === '' || $uniteFacture === $uniteMatiere) {
            return $qty;
        }

        // Deux unites de denombrement : un objet reste un objet. C'etait deja le
        // comportement d'avant, et il est CORRECT ici — le defaut d'origine etait
        // strictement dimensionnel.
        $compteVersCompte = in_array($uniteFacture, self::UNITES_DE_DENOMBREMENT, true)
            && in_array($uniteMatiere, self::UNITES_DE_DENOMBREMENT, true);

        if ($compteVersCompte) {
            return $qty;
        }

        $facteur = self::CONVERSIONS[$uniteFacture . ':' . $uniteMatiere] ?? null;

        if ($facteur === null) {
            $nom = (string) (RawMaterial::query()->whereKey($rawMaterialId)->value('name') ?? '#' . $rawMaterialId);

            /*
             * [ONB-08 2026-08-28] `HttpException(422)` et non `InvalidArgumentException`.
             *
             * `Handler::render` (`app/Exceptions/Handler.php:130`) rend un `HttpException`
             * en **422 avec son message**. Une `InvalidArgumentException` n'est ni celle-la
             * ni une `QueryException` : elle filait vers `parent::render` → **HTTP 500**, et
             * l'ecran affichait « Server Error » en anglais. Le message ci-dessous, qui nomme
             * la matiere ET les deux unites, n'etait lu par personne.
             *
             * C'est aussi l'idiome deja en place dans ce domaine :
             * `PurchasingScanController::apply()` fait `abort_if($x, 422, '...')`.
             */
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(
                422,
                "La ligne « {$nom} » est facturée en « {$uniteFacture} » alors que cette "
                . "matière se compte en « {$uniteMatiere} » : ces deux unités ne mesurent "
                . "pas la même chose, et aucune conversion ne peut être devinée. "
                . "Corrigez l'unité de la ligne, ou celle de la matière, puis revalidez. "
                . "La réception entière est retenue tant que ce n'est pas fait — mieux vaut "
                . "un document en attente qu'un stock faussé sans que rien ne le signale."
            );
        }

        return $qty * $facteur;
    }

    /**
     * Ramene une unite ecrite librement a son ecriture canonique.
     *
     * `raw_materials.unit` est une colonne `string(16)` LIBRE, sans validation ni
     * ecran d'edition, et `purchase_lines.unit` vient d'un OCR. Comparer ces deux
     * champs bruts revenait a exiger que deux sources non contraintes s'accordent
     * au caractere pres.
     */
    private function normaliserUnite(string $brute): string
    {
        $u = mb_strtolower(trim($brute));

        // Les accents d'abord : `mb_strtolower` ne les touche pas, et c'est
        // exactement ce qui faisait echouer « pièce » la ou « piece » passait.
        $u = strtr($u, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'û' => 'u', 'ü' => 'u', 'ù' => 'u',
            'ç' => 'c',
        ]);

        // « kg. » · « pcs » · espaces internes multiples.
        $u = trim(preg_replace('/[\s.]+/u', '', $u) ?? $u);

        return self::ECRITURES_EQUIVALENTES[$u] ?? $u;
    }

    private function weightedAverageCost(float $oldStock, ?float $oldAvg, float $qty, float $unitPrice): float
    {
        $denominator = $oldStock + $qty;

        if ($oldAvg === null || $oldStock <= 0 || $denominator <= 0) {
            return round($unitPrice, self::COST_SCALE);
        }

        $value = ($oldStock * $oldAvg) + ($qty * $unitPrice);

        return round($value / $denominator, self::COST_SCALE);
    }

    /**
     * Item revendu à l'unité (boisson) : incrémente stock_levels (+qty unités) +
     * mouvement `manual_in`. Miroir du pattern StockService (lock + forceFill +
     * mouvement append-only). Idempotence défensive par idempotency_key.
     */
    private function applyStockItemLine(PurchaseLine $line, int $branchId): void
    {
        $itemId = (int) $line->target_id;
        if ($itemId <= 0) {
            return;
        }

        // Unités entières (stock_levels.on_hand est INTEGER, CHECK>=0).
        $qty = (int) round((float) $line->qty);
        if ($qty <= 0) {
            return;
        }

        $idempotencyKey = 'purchase_line:'.(int) $line->id;

        // Défense en profondeur (le gate document est le mécanisme primaire).
        if (StockMovement::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $level = StockLevel::query()
            ->where('branch_id', $branchId)
            ->where('stockable_type', Item::class)
            ->where('stockable_id', $itemId)
            ->lockForUpdate()
            ->first();

        if (! $level) {
            $created = StockLevel::query()->create([
                'branch_id' => $branchId,
                'stockable_type' => Item::class,
                'stockable_id' => $itemId,
                'on_hand' => 0,
            ]);

            $level = StockLevel::query()
                ->whereKey($created->id)
                ->lockForUpdate()
                ->first();
        }

        $level->forceFill(['on_hand' => (int) $level->on_hand + $qty])->save();

        StockMovement::query()->create([
            'stock_level_id' => (int) $level->id,
            'branch_id' => $branchId,
            'delta' => $qty,
            'reason' => 'manual_in',
            'reference_type' => PurchaseLine::class,
            'reference_id' => (int) $line->id,
            'idempotency_key' => $idempotencyKey,
            'created_at' => now(),
        ]);

        // [S2 V3 D-3 2026-07-29] La marchandise est rentrée : on repasse par le
        // SSOT de disponibilité pour lever une rupture AUTOMATIQUE et prévenir
        // caisse/borne/KDS. Un 86 manuel reste posé (règle inchangée côté
        // StockService). Sans ceci, le produit restait invendable après
        // réception — vente perdue silencieuse.
        $this->stockService->syncAvailabilityAfterExternalMutation($level, $branchId);
    }

    /**
     * @return array{document_id:int, status:string, applied:array<string,int>}
     */
    private function noopResult(PurchaseDocument $document): array
    {
        return [
            'document_id' => (int) $document->id,
            'status' => 'noop',
            'applied' => [
                'raw_material' => 0,
                'stock_item' => 0,
                'charge' => 0,
                'skipped_proposed' => 0,
            ],
        ];
    }
}
