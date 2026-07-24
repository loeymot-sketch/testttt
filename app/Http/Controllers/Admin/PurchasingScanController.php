<?php

namespace App\Http\Controllers\Admin;

use App\Models\PurchaseDocument;
use App\Models\PurchaseLine;
use App\Services\Purchasing\InvoiceClassificationService;
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

        $this->middleware(['permission:items_create'])->only('scan');
    }

    public function scan(
        Request $request,
        InvoiceVisionContract $vision,
        InvoiceClassificationService $classifier
    ): JsonResponse {
        $validated = $request->validate([
            'photo' => ['required', 'file', 'max:12288'],
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

    /** Construit la réponse : document + propositions (lignes avec score de match). */
    private function respond(PurchaseDocument $document, bool $idempotent): JsonResponse
    {
        $lines = $document->lines()->orderBy('id')->get();

        return response()->json([
            'ok' => true,
            'idempotent' => $idempotent,
            'document' => [
                'id' => (int) $document->id,
                'branch_id' => (int) $document->branch_id,
                'status' => $document->status,
                'source' => $document->source,
                'doc_date' => optional($document->doc_date)->toDateString(),
                'doc_hash' => $document->doc_hash,
            ],
            'proposals' => $lines->map(fn (PurchaseLine $line): array => [
                'id' => (int) $line->id,
                'raw_label' => $line->raw_label,
                'qty' => (float) $line->qty,
                'unit' => $line->unit,
                'unit_price' => $line->unit_price === null ? null : (float) $line->unit_price,
                'tva_rate' => $line->tva_rate === null ? null : (float) $line->tva_rate,
                'target_type' => $line->target_type,
                'target_id' => $line->target_id === null ? null : (int) $line->target_id,
                'status' => $line->status,
            ])->all(),
        ]);
    }
}
