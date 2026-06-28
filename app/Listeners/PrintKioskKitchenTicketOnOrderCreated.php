<?php

namespace App\Listeners;

use App\Enums\Status;
use App\Events\OrderCreated;
use App\Models\Printer;
use App\Models\Scopes\BranchScope;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [BORNE-KITCHEN 2026-06-28] Quand une commande BORNE (kiosk) est créée, imprime le
 * TICKET CUISINE (format symbolique) sur l'imprimante station 'kitchen' — en plus du
 * KDS écran (qui reçoit déjà la commande) et de la copie CLIENT au comptoir
 * (PrintKioskOrderToCounter, station 'receipt').
 *
 * - ADDITIF : nouveau listener (n'édite pas PrintKioskOrderToCounter).
 * - BEST-EFFORT : no-op si aucune imprimante 'kitchen' ACTIVE OU transport Null
 *   (PRINT_DRIVER non câblé). Ne jette jamais.
 * - Borne uniquement (source_surface=kiosk) ; POS imprime sa cuisine au checkout.
 */
class PrintKioskKitchenTicketOnOrderCreated
{
    public function __construct(
        private readonly OrderReceiptEscPosRenderer $renderer = new OrderReceiptEscPosRenderer,
    ) {}

    public function handle(OrderCreated $event): void
    {
        try {
            $order = $event->order;
            if ((string) ($order->source_surface ?? '') !== 'kiosk') {
                return;
            }
            $branchId = (int) ($order->branch_id ?? 0);
            if ($branchId <= 0) {
                return;
            }

            $printer = Printer::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)
                ->where('station', 'kitchen')
                ->where('status', Status::ACTIVE)
                ->orderBy('id')
                ->first();
            if (! $printer) {
                return; // pas d'imprimante cuisine → la cuisine utilise le KDS écran
            }

            if (method_exists($order, 'loadMissing')) {
                $order->loadMissing(['branch', 'user']);
                if (method_exists($order, 'relationLoaded') && ! $order->relationLoaded('orderItems')) {
                    $order->setRelation('orderItems', $order->orderItems()->withoutGlobalScope(BranchScope::class)->get());
                }
            }

            $bytes = $this->renderer->renderKitchenTicket($order, [
                'width_chars' => (int) ($printer->width_chars ?: 48),
            ]);
            app(EscPosPrinterService::class)->sendRaw($printer, $bytes);
        } catch (Throwable $e) {
            Log::warning('[PrintKioskKitchenTicketOnOrderCreated] kitchen ticket print failed', [
                'order_id' => $event->order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
