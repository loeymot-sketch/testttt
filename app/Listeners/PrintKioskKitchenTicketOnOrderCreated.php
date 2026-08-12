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
 * - Borne ET web (source_surface=kiosk|web|online, S4 parité) ; POS imprime sa cuisine au checkout.
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
            // [S4 2026-07-18 · parité impression serveur borne↔web] La commande WEB imprime son
            // ticket cuisine côté serveur comme la borne ('web' = valeur live, 'online' = alias futur).
            // POS reste exclu (il imprime à son checkout). No-op garanti si aucune imprimante cuisine
            // active OU transport Null (PRINT_DRIVER non câblé) — cf. gardes ci-dessous.
            // [PROCUREUR cycle 7 — 2026-08-05 · P2 F-G] La surface 'delivery' manquait :
            // une commande LIVRAISON n'imprimait NI ticket cuisine NI ticket comptoir.
            // [UBER-PHOTO 2026-08-10 · owner « ça imprime directement en cuisine »] La surface
            // 'uber_eats' manquait : une commande Uber naît DÉJÀ au statut ACCEPTÉ (elle est
            // prépayée), elle ne franchit donc jamais le changement de statut sur lequel repose
            // l'autre déclencheur d'impression. Résultat : aucune commande Uber n'a jamais fait
            // sortir de ticket cuisine — ni par le webhook, ni par la photo. La garde atomique de
            // KitchenTicketAutoPrinter rend le doublon impossible si un autre chemin s'y ajoute.
            if (! in_array((string) ($order->source_surface ?? ''), ['kiosk', 'web', 'online', 'delivery', 'uber_eats'], true)) {
                return;
            }
            $branchId = (int) ($order->branch_id ?? 0);
            if ($branchId <= 0) {
                return;
            }

            // [FIX P1 audit 2026-06-28] PrinterRequest n'autorise que receipt/kitchen_hot/
            // kitchen_cold/bar — 'kitchen' seul n'est jamais créable en admin → on matche
            // les vraies stations cuisine (chaude d'abord). Sinon : no-op silencieux permanent.
            $kitchenStations = ['kitchen_hot', 'kitchen', 'kitchen_cold'];
            $printer = null;
            foreach ($kitchenStations as $station) { // kitchen_hot prioritaire (portable, pas de FIELD() SQLite)
                $printer = Printer::withoutGlobalScope(BranchScope::class)
                    ->where('branch_id', $branchId)
                    ->where('station', $station)
                    ->where('status', Status::ACTIVE)
                    ->orderBy('id')
                    ->first();
                if ($printer) {
                    break;
                }
            }
            if (! $printer) {
                return; // pas d'imprimante cuisine → la cuisine utilise le KDS écran
            }

            if (method_exists($order, 'loadMissing')) {
                $order->loadMissing(['branch', 'user']);
                if (method_exists($order, 'relationLoaded') && ! $order->relationLoaded('orderItems')) {
                    $order->setRelation('orderItems', $order->orderItems()->withoutGlobalScope(BranchScope::class)->get());
                }
            }

            // [KITCHEN-AUTOPRINT 2026-08-07] Passe par la garde PARTAGÉE : une commande borne
            // ou web peut aussi atteindre la cuisine par son changement de statut, et sans ce
            // point de passage unique le ticket sortirait deux fois.
            unset($bytes, $printer);
            app(\App\Services\Kitchen\KitchenTicketAutoPrinter::class)->printOnce($order, 'order_created');
        } catch (Throwable $e) {
            Log::warning('[PrintKioskKitchenTicketOnOrderCreated] kitchen ticket print failed', [
                'order_id' => $event->order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
