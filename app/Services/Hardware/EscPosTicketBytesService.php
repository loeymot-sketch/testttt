<?php

namespace App\Services\Hardware;

use App\Models\Order;
use App\Models\Printer;
use App\Models\Scopes\BranchScope;

/**
 * [TICKET-UNIFY 2026-07-01] Source unique des octets ESC/POS d'un ticket (client ou cuisine).
 *
 * AVANT : la CAISSE rendait via PosTicketBytesController::renderTicketBytes (renderer serveur)
 * tandis que la BORNE reconstruisait un ticket côté client (kioskPrinter.js buildEscPosReceipt,
 * format différent, €→EUR). Résultat : deux formes différentes.
 *
 * MAINTENANT : caisse ET borne appellent CE service → mêmes octets, même renderer width-safe,
 * même largeur d'imprimante → ticket papier IDENTIQUE des deux côtés.
 */
final class EscPosTicketBytesService
{
    public function __construct(private OrderReceiptEscPosRenderer $renderer)
    {
    }

    /**
     * Rend les octets ESC/POS. Largeur/code-page lus de l'imprimante station si configurée,
     * sinon défauts (48 car, CP858). Retourne null si branche/commande introuvable.
     */
    public function render(int $branchId, int $orderId, string $ticket, bool $isDuplicata = false, bool $kioskClient = false): ?string
    {
        if ($branchId <= 0) {
            return null;
        }
        $order = Order::withoutGlobalScope(BranchScope::class)->with(['branch', 'user'])->find($orderId);
        if (! $order) {
            return null;
        }
        $order->setRelation('orderItems', $order->orderItems()->withoutGlobalScope(BranchScope::class)->get());

        $ticket = $ticket === 'kitchen' ? 'kitchen' : 'client';
        $stations = $ticket === 'kitchen' ? ['kitchen_hot', 'kitchen_cold', 'receipt'] : ['receipt'];
        $printer = null;
        foreach ($stations as $station) {
            $printer = Printer::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)->where('station', $station)
                ->where('status', \App\Enums\Status::ACTIVE)->orderBy('id')->first();
            if ($printer) {
                break;
            }
        }
        $opts = [
            'width_chars'  => (int) ($printer->width_chars ?? 0) ?: 48,
            'is_duplicata' => $isDuplicata,
        ];
        $pOpts = ($printer && is_array($printer->options)) ? $printer->options : [];
        if (! empty($pOpts['code_page'])) {
            $opts['code_page'] = (int) $pOpts['code_page'];
        }

        // [TICKET-BORNE-LONG 2026-07-02] Ticket CLIENT imprimé par la BORNE : queue longue +
        // coupe partielle (ne tombe pas). N'affecte QUE la borne (le caissier tend le ticket).
        if ($kioskClient && $ticket === 'client') {
            $opts['feed_lines'] = max(1, (int) config('printing.cut.kiosk_client_feed_lines', 30));
            $opts['cut_partial'] = strtolower((string) config('printing.cut.kiosk_client_mode', 'partial')) === 'partial';
        }

        return $ticket === 'kitchen'
            ? $this->renderer->renderKitchenTicket($order, $opts)
            : $this->renderer->renderClientTicket($order, $opts);
    }
}
