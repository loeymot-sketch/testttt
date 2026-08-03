<?php

namespace App\Listeners;

use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Events\OrderPaidAtCounter;
use App\Models\Printer;
use App\Models\Scopes\BranchScope;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [G10/G12 2026-06-28 — audit adversaire borne] À l'ENCAISSEMENT au comptoir
 * (counter-collect ; ex. commande borne « paiement à la caisse »), imprime le REÇU
 * client (l'ordre est PAYÉ + scellé à ce moment) sur l'imprimante caisse ET ouvre le
 * tiroir si paiement ESPÈCES.
 *
 * - ADDITIF : n'édite pas PaymentService::confirmCounterPayment (domaine sensible /
 *   chantier parallèle). Écoute simplement l'événement déjà dispatché.
 * - Distinct de PrintKioskOrderToCounter (copie ORDRE à la CRÉATION) : ici c'est le
 *   reçu à l'ENCAISSEMENT — honore le bouton « Confirmer & Imprimer ticket ».
 * - BEST-EFFORT : no-op si aucune imprimante `receipt` ACTIVE OU transport Null
 *   (PRINT_DRIVER non câblé) → s'active dès que la caisse SAGA est configurée
 *   (PRINT_DRIVER=windows_raw + row Printer station=receipt). Ne jette jamais.
 * - NF525 : lecture seule (le scellé fiscal est déjà fait dans confirmCounterPayment) ;
 *   on ne fait que rendre + envoyer les octets + kicker le tiroir.
 */
class PrintFiscalReceiptAndOpenDrawerOnCounterPaid
{
    public function __construct(
        private readonly OrderReceiptEscPosRenderer $renderer = new OrderReceiptEscPosRenderer,
    ) {}

    public function handle(OrderPaidAtCounter $event): void
    {
        try {
            $order = $event->order;
            $branchId = (int) ($order->branch_id ?? 0);
            if ($branchId <= 0) {
                return;
            }

            $printer = Printer::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)
                ->where('station', 'receipt')
                ->where('status', Status::ACTIVE)
                ->orderBy('id')
                ->first();
            if (! $printer) {
                return; // pas d'imprimante caisse configurée → no-op
            }

            $service = app(EscPosPrinterService::class);

            // [G10] Reçu d'encaissement (best-effort). On ne re-query QUE ce qui n'est
            // pas déjà chargé (l'ordre vient de confirmCounterPayment, souvent déjà
            // hydraté) → plus efficace + testable sans persister toute la chaîne FK.
            if (method_exists($order, 'loadMissing')) {
                $order->loadMissing(['branch', 'user']);
                if (method_exists($order, 'relationLoaded') && ! $order->relationLoaded('orderItems')) {
                    $order->setRelation('orderItems', $order->orderItems()->withoutGlobalScope(BranchScope::class)->get());
                }
            }
            $bytes = $this->renderer->renderClientTicket($order, [
                'width_chars' => (int) ($printer->width_chars ?: 48),
            ]);
            $service->sendRaw($printer, $bytes);

            // [G12] Tiroir-caisse à l'encaissement ESPÈCES uniquement.
            if ((int) $event->paymentMethod === PosPaymentMethod::CASH) {
                $service->openDrawer($printer->id, $branchId);
            }
        } catch (Throwable $e) {
            Log::warning('[PrintFiscalReceiptAndOpenDrawerOnCounterPaid] best-effort print/drawer failed', [
                'order_id' => $event->order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
