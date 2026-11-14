<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProInvoiceCancelRequest;
use App\Http\Requests\ProInvoiceStoreRequest;
use App\Models\ProInvoice;
use App\Services\ProInvoice\ProInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * [FACTURE PRO 2026-11-14 · propriétaire] « Facture pro » à la caisse : une facture à montant
 * libre pour un client professionnel (raison sociale, désignation, montant, date, règlement).
 *
 * HORS CHAÎNE NF525, par décision du propriétaire : rien ici ne touche une commande, la séquence
 * fiscale, le Z, le journal d'audit, le tiroir-caisse ni le calcul des prix.
 *
 * Pas de route de suppression ni de modification : une facture se crée, se réimprime, s'annule.
 * Portée : BranchScope sur le modèle (la liaison de route rend 404 pour une autre branche).
 */
class ProInvoiceController extends Controller
{
    public function __construct(private ProInvoiceService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('pos'), 403, 'Vous n\'avez pas le droit de consulter les factures pro.');

        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:50'],
        ], [
            'date_from.date_format'  => 'La date de début est invalide.',
            'date_to.date_format'    => 'La date de fin est invalide.',
            'date_to.after_or_equal' => 'La date de fin doit suivre la date de début.',
            'per_page.max'           => '50 factures par page au plus.',
        ]);

        $query = ProInvoice::query()->with('creator:id,name')->orderByDesc('id');
        if (! empty($data['date_from'])) {
            $query->whereDate('created_at', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('created_at', '<=', $data['date_to']);
        }

        $page = $query->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (ProInvoice $i) => $this->present($i))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    public function store(ProInvoiceStoreRequest $request): JsonResponse
    {
        $invoice = $this->service->create($request->validated(), $request->user());
        $invoice->load('creator:id,name');

        return response()->json([
            'message' => 'Facture '.$invoice->number.' créée.',
            'data'    => $this->present($invoice),
        ], 201);
    }

    public function show(Request $request, ProInvoice $proInvoice): JsonResponse
    {
        abort_unless($request->user()?->can('pos'), 403, 'Vous n\'avez pas le droit de consulter les factures pro.');
        $proInvoice->load('creator:id,name');

        return response()->json([
            'data'   => $this->present($proInvoice),
            'seller' => $this->service->sellerIdentity((int) $proInvoice->branch_id),
        ]);
    }

    /** Document A4 imprimable (HTML), rendu côté serveur : une seule source pour les mentions. */
    public function document(Request $request, ProInvoice $proInvoice): Response
    {
        abort_unless($request->user()?->can('pos'), 403, 'Vous n\'avez pas le droit d\'imprimer les factures pro.');

        $html = view('pro-invoice.document', [
            'invoice' => $proInvoice,
            'seller'  => $this->service->sellerIdentity((int) $proInvoice->branch_id),
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function cancel(ProInvoiceCancelRequest $request, ProInvoice $proInvoice): JsonResponse
    {
        $invoice = $this->service->cancel($proInvoice, $request->user(), $request->validated()['cancel_reason']);
        $invoice->load('creator:id,name');

        return response()->json([
            'message' => 'Facture '.$invoice->number.' annulée. Son numéro est conservé.',
            'data'    => $this->present($invoice),
        ]);
    }

    private function present(ProInvoice $i): array
    {
        return [
            'id'                => $i->id,
            'number'            => $i->number,
            'client_name'       => $i->client_name,
            'client_address'    => $i->client_address,
            'client_siret'      => $i->client_siret,
            'client_vat_number' => $i->client_vat_number,
            'service_date'      => $i->service_date?->toDateString(),
            'label'             => $i->label,
            'quantity'          => (int) $i->quantity,
            'amount_ttc'        => (string) $i->amount_ttc,
            'vat_rate'          => (string) $i->vat_rate,
            'amount_ht'         => (string) $i->amount_ht,
            'vat_amount'        => (string) $i->vat_amount,
            'payment_method'    => $i->payment_method,
            'payment_label'     => $i->paymentLabel(),
            'notes'             => $i->notes,
            'created_at'        => $i->created_at?->toIso8601String(),
            'created_by_name'   => $i->creator?->name,
            'cancelled'         => $i->isCancelled(),
            'cancelled_at'      => $i->cancelled_at?->toIso8601String(),
            'cancel_reason'     => $i->cancel_reason,
        ];
    }
}
