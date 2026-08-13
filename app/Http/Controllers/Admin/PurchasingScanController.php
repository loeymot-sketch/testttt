<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Status;
use App\Models\Item;
use App\Models\PurchaseDocument;
use App\Models\PurchaseLine;
use App\Models\RawMaterial;
use App\Services\Purchasing\InvoiceClassificationService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\Vision\InvoiceVisionContract;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3b] Endpoint SCAN de facture.
 *
 * POST /api/admin/purchasing/scan : reçoit une photo → lecture IA (contrat
 * {@see InvoiceVisionContract}, mock↔OpenAI selon la clé) → classification
 * ({@see InvoiceClassificationService}) → crée un PurchaseDocument `draft` +
 * des PurchaseLine `proposed` → renvoie les propositions (avec score).
 *
 * L'owner validera ensuite les lignes (proposed → validated) puis appliquera au
 * stock via PurchaseService::validateDocument (existant). AUCUNE écriture stock
 * ici — « l'IA propose, l'humain valide » (garde-fou plan / NF525).
 *
 * IDEMPOTENCE : `doc_hash` = sha256 du contenu de la photo. Re-scanner la même
 * photo retourne le document existant sans dupliquer (gate applicatif + UNIQUE DB).
 *
 * Gate : `permission:items_create` (miroir du scan stock-rupture, même famille
 * d'opération stock-intake). Domaine NEUF, ADDITIF, HORS NF525.
 */
class PurchasingScanController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:items_create'])->only(['scan', 'apply', 'targets']);
    }

    public function scan(
        Request $request,
        InvoiceVisionContract $vision,
        InvoiceClassificationService $classifier
    ): JsonResponse {
        $validated = $request->validate([
            // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] every other
            // upload FormRequest in this codebase applies
            // NoDangerousFileExtension (GOAL-L2-HEAL-02 2026-05-24 .pht
            // polyglot RCE finding) — this endpoint's inline validate()
            // never got it. `image`/`mimes:` deliberately NOT added here:
            // the stored disk is `local` (non-web-servable, per audit), and
            // real invoice photos legitimately vary in format/size more
            // than the catalogue-photo endpoints this pattern was written
            // for.
            'photo' => ['required', 'file', 'max:12288', new \App\Rules\NoDangerousFileExtension()],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'doc_date' => ['nullable', 'date'],
            'source' => ['nullable', 'in:facture,ticket'],
        ]);

        $branchId = (int) ($request->user()?->branch_id ?: 1);

        $file = $request->file('photo');
        $docHash = 'sha256:'.hash('sha256', (string) $file->get());

        // Idempotence : même photo déjà scannée → renvoyer l'existant, ne rien dupliquer.
        $existing = PurchaseDocument::query()
            ->where('doc_hash', $docHash)
            ->where('branch_id', $branchId)
            ->first();

        if ($existing) {
            return $this->respond($existing, true);
        }

        // Stockage du BRUT (photo d'origine = donnée d'apprentissage B6, amendement #8).
        // Disque 'local' explicite → chemin absolu stable pour la vision (et testable via Storage::fake('local')).
        $storedPath = $file->store('purchasing', 'local');
        $absolutePath = Storage::disk('local')->path($storedPath);

        // Lecture IA (mock ou OpenAI selon binding) puis classification (propositions).
        $lines = $vision->extractLines($absolutePath);
        $proposals = $classifier->propose($lines, $branchId);

        try {
            $document = DB::transaction(function () use ($validated, $branchId, $storedPath, $docHash, $proposals): PurchaseDocument {
                $document = PurchaseDocument::create([
                    'branch_id' => $branchId,
                    'supplier_id' => $validated['supplier_id'] ?? null,
                    'doc_date' => $validated['doc_date'] ?? now()->toDateString(),
                    'photo_path' => $storedPath,
                    'source' => $validated['source'] ?? PurchaseDocument::SOURCE_FACTURE,
                    'status' => PurchaseDocument::STATUS_DRAFT,
                    'doc_hash' => $docHash,
                ]);

                foreach ($proposals as $proposal) {
                    PurchaseLine::create([
                        'purchase_document_id' => $document->id,
                        'raw_label' => $proposal['raw_label'],
                        'qty' => $proposal['qty'],
                        'unit' => $proposal['unit'],
                        'unit_price' => $proposal['unit_price'],
                        'tva_rate' => $proposal['tva_rate'],
                        'target_type' => $proposal['target_type'],
                        'target_id' => $proposal['target_id'],
                        'status' => PurchaseLine::STATUS_PROPOSED,
                        // [P3c] Confiance IA surfacée à l'écran (badge « proposé par IA » + score).
                        'score' => $proposal['score'] ?? null,
                        'matched' => $proposal['matched'] ?? null,
                    ]);
                }

                return $document;
            });
        } catch (QueryException $e) {
            // Course concurrente sur le doc_hash UNIQUE → l'autre a gagné : renvoyer l'existant.
            $winner = PurchaseDocument::query()->where('doc_hash', $docHash)->first();
            if ($winner) {
                return $this->respond($winner, true);
            }

            throw $e;
        }

        return $this->respond($document, false);
    }

    /**
     * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3c] Options de cible pour l'écran :
     * matières premières actives de la branche + items « Boissons » (revendus à
     * l'unité). Alimente les dropdowns « choix de LA matière/l'item ». Read-only,
     * même gate items_create. Miroir des sources du classifieur (SSOT cible).
     */
    public function targets(Request $request): JsonResponse
    {
        $branchId = (int) ($request->user()?->branch_id ?: 1);

        $rawMaterials = RawMaterial::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit'])
            ->map(fn (RawMaterial $m): array => [
                'id' => (int) $m->id,
                'name' => (string) $m->name,
                'unit' => (string) $m->unit,
            ])->all();

        $drinkItems = Item::query()
            ->where('status', Status::ACTIVE)
            ->whereHas('category', function ($query): void {
                // Miroir InvoiceClassificationService::drinkItems() (« Boissons », pas « Poissons »).
                $query->where('slug', 'like', 'boisson%')->orWhere('name', 'like', 'Boisson%');
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Item $i): array => [
                'id' => (int) $i->id,
                'name' => (string) $i->name,
            ])->all();

        return response()->json([
            'ok' => true,
            'raw_materials' => $rawMaterials,
            'drink_items' => $drinkItems,
        ]);
    }

    /**
     * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3c] VALIDATION owner : applique en
     * stock les propositions confirmées. « L'IA propose (scan), l'humain valide ».
     *
     * POST /api/admin/purchasing/{document}/validate — l'owner confirme/corrige la
     * cible de chaque ligne (dropdown matière / produit / charge), puis on flippe
     * les lignes soumises en `validated` et on délègue à PurchaseService (existant,
     * prouvé P3b : matière + avg_cost pondéré / boisson stock_levels / charge). Les
     * lignes NON soumises restent `proposed` (ignorées par le service).
     *
     * Idempotent : un document déjà `validated` renvoie un NO-OP sans ré-ouvrir.
     * Gate permission:items_create (constructeur) + branch scope explicite (le
     * domaine achats n'a pas de BranchScope global — exemption sentinel P3a).
     * ADDITIF, HORS NF525.
     */
    public function apply(
        Request $request,
        PurchaseDocument $document,
        PurchaseService $purchaseService
    ): JsonResponse {
        // Isolation multi-tenant : l'owner ne valide QUE les documents de sa branche.
        $this->authorizeWritableBranchScope($request, (int) $document->branch_id);

        // Idempotence : document déjà appliqué → NO-OP (protège le recalcul d'avg_cost).
        if ($document->status === PurchaseDocument::STATUS_VALIDATED) {
            return $this->respondApplied($document, [
                'document_id' => (int) $document->id,
                'status' => 'noop',
                'applied' => ['raw_material' => 0, 'stock_item' => 0, 'charge' => 0, 'skipped_proposed' => 0],
            ]);
        }

        $data = $request->validate([
            'lines' => ['present', 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.target_type' => ['required', 'in:raw_material,stock_item,charge'],
            'lines.*.target_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $branchId = (int) ($document->branch_id ?: 1);

        // Lignes du document indexées par id (garde-fou : on ne touche QUE ses lignes).
        $ownLines = $document->lines()->get()->keyBy('id');

        foreach ($data['lines'] as $payload) {
            $line = $ownLines->get((int) $payload['id']);
            abort_if($line === null, 422, 'Ligne inconnue pour ce document.');

            $targetType = (string) $payload['target_type'];
            $targetId = $payload['target_id'] ?? null;

            // Cohérence cible ↔ id (miroir des règles PurchaseService).
            if ($targetType === PurchaseLine::TARGET_CHARGE) {
                $targetId = null; // charge = aucun stock.
            } else {
                abort_if(empty($targetId), 422, 'Une cible matière/produit doit être choisie.');
                $this->assertTargetExists($targetType, (int) $targetId, $branchId);
            }

            $update = [
                'target_type' => $targetType,
                'target_id' => $targetId,
                'status' => PurchaseLine::STATUS_VALIDATED,
            ];
            if (array_key_exists('qty', $payload) && $payload['qty'] !== null) {
                $update['qty'] = (float) $payload['qty'];
            }
            if (array_key_exists('unit_price', $payload) && $payload['unit_price'] !== null) {
                $update['unit_price'] = (float) $payload['unit_price'];
            }

            $line->forceFill($update)->save();
        }

        $applied = $purchaseService->validateDocument($document->fresh());

        return $this->respondApplied($document->fresh(), $applied);
    }

    /**
     * Vérifie que la cible existe (matière branch-scopée / item catalogue global).
     * Renvoie 422 sinon — l'owner a pointé une cible inexistante.
     */
    private function assertTargetExists(string $targetType, int $targetId, int $branchId): void
    {
        if ($targetType === PurchaseLine::TARGET_RAW_MATERIAL) {
            $exists = RawMaterial::query()
                ->whereKey($targetId)
                ->where('branch_id', $branchId)
                ->exists();
            abort_unless($exists, 422, 'Matière première introuvable pour cette branche.');

            return;
        }

        if ($targetType === PurchaseLine::TARGET_STOCK_ITEM) {
            abort_unless(Item::query()->whereKey($targetId)->exists(), 422, 'Produit revendu introuvable.');
        }
    }

    /** Construit la réponse : document + propositions (lignes avec score de match). */
    private function respond(PurchaseDocument $document, bool $idempotent): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'idempotent' => $idempotent,
            'document' => $this->documentPayload($document),
            'proposals' => $this->serializeLines($document),
        ]);
    }

    /** Réponse post-validation : document appliqué + résumé stock + lignes rafraîchies. */
    private function respondApplied(PurchaseDocument $document, array $applied): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'applied' => $applied,
            'document' => $this->documentPayload($document),
            'proposals' => $this->serializeLines($document),
        ]);
    }

    /** @return array<string, mixed> */
    private function documentPayload(PurchaseDocument $document): array
    {
        return [
            'id' => (int) $document->id,
            'branch_id' => (int) $document->branch_id,
            'status' => $document->status,
            'source' => $document->source,
            'doc_date' => optional($document->doc_date)->toDateString(),
            'doc_hash' => $document->doc_hash,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function serializeLines(PurchaseDocument $document): array
    {
        return $document->lines()->orderBy('id')->get()->map(fn (PurchaseLine $line): array => [
            'id' => (int) $line->id,
            'raw_label' => $line->raw_label,
            'qty' => (float) $line->qty,
            'unit' => $line->unit,
            'unit_price' => $line->unit_price === null ? null : (float) $line->unit_price,
            'tva_rate' => $line->tva_rate === null ? null : (float) $line->tva_rate,
            'target_type' => $line->target_type,
            'target_id' => $line->target_id === null ? null : (int) $line->target_id,
            'status' => $line->status,
            // [P3c] Confiance IA (le classifieur les calcule ; NULL sur lignes legacy pré-P3c).
            'score' => $line->score === null ? null : (float) $line->score,
            'matched' => $line->matched === null ? null : (bool) $line->matched,
        ])->all();
    }
}
