<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Printer;
use App\Models\Scopes\BranchScope;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [CAISSE-BRIDGE 2026-06-28] Renvoie les OCTETS ESC/POS rendus côté SERVEUR (SSOT
 * NF525) en base64, pour que le frontend caisse les POSTe TELS QUELS au pont local
 * (127.0.0.1:9100/raw) → SAGA imprime SANS fenêtre Chrome. Lecture seule (aucun
 * audit/incrément ici : l'incrément NF525 reste dans PosReceiptPrintController::increment,
 * que le frontend appelle d'abord). Topologie : Laravel = cloud Linux ne peut pas
 * joindre l'USB caisse → le pont local côté caisse fait la sortie physique.
 *
 * Distinct du rendu côté borne (kioskPrinter.js reconstruit un JSON ASCII) : ici on
 * passe les octets fiscaux EXACTS (CP858), donc le ticket papier == rendu serveur.
 */
class PosTicketBytesController extends Controller
{
    public function show(Request $request, int $order): JsonResponse
    {
        abort_unless($request->user()?->can('pos'), 403);

        $ticket = $request->query('ticket') === 'kitchen' ? 'kitchen' : 'client';
        $isDuplicata = filter_var($request->query('duplicata', false), FILTER_VALIDATE_BOOLEAN);

        $branchId = (int) $request->user()->branch_id;
        $found = Order::query()
            ->whereKey($order)
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->firstOrFail(['id', 'branch_id']);

        $bytes = $this->renderTicketBytes((int) $found->branch_id, (int) $found->id, $ticket, $isDuplicata);
        if ($bytes === null) {
            return response()->json(['order_id' => $found->id, 'ticket' => $ticket, 'escpos_b64' => null], 200);
        }

        return response()->json([
            'order_id'   => $found->id,
            'ticket'     => $ticket,
            'escpos_b64' => base64_encode($bytes),
        ]);
    }

    /**
     * Rend les octets ESC/POS du ticket (client ou cuisine) via le renderer SSOT.
     * Largeur/code-page lus de l'imprimante station si configurée, sinon défauts
     * (48 car, CP858) — pour que les octets soient TOUJOURS disponibles au pont caisse
     * même sans row Printer (le serveur ne sort pas physiquement, le pont s'en charge).
     */
    private function renderTicketBytes(int $branchId, int $orderId, string $ticket, bool $isDuplicata): ?string
    {
        // [TICKET-UNIFY 2026-07-01] Délégué au service partagé (SSOT) — la borne l'appelle aussi
        // via Frontend\OrderController::escpos → ticket papier IDENTIQUE caisse/borne.
        return app(\App\Services\Hardware\EscPosTicketBytesService::class)
            ->render($branchId, $orderId, $ticket, $isDuplicata);
    }
}
