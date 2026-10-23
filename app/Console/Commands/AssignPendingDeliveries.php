<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\DeliveryStop;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Services\Delivery\DeliveryWaveService;
use Illuminate\Console\Command;

/**
 * Filet de sécurité : toute commande de livraison encore active sans tournée y est rattachée.
 * (Le rattachement normal se fait à la création ; ceci rattrape un écouteur qui aurait échoué.)
 */
class AssignPendingDeliveries extends Command
{
    protected $signature = 'delivery:assign-pending';
    protected $description = 'Rattache à une tournée les commandes de livraison actives qui n\'en ont pas';

    public function handle(DeliveryWaveService $waves): int
    {
        // Auto-nettoyage : une commande annulée / refusée ne garde pas sa place (si l'événement a été manqué).
        // [E2E 2026-10-22 · B-001] Un arrêt dont un coursier Uber est demandé n'est supprimé qu'une fois sa course annulée.
        $uber = app(\App\Services\UberDirect\UberDirectDispatchService::class);
        $released = 0;
        DeliveryStop::query()->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->whereNull('left_at')
            ->whereIn('order_id', Order::query()->withoutGlobalScope(BranchScope::class)->whereIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED])->select('id'))
            ->get()->each(function (DeliveryStop $stop) use ($uber, &$released) {
                if ($uber->cancelCourseForClosedOrder((int) $stop->order_id)) {
                    $stop->delete();
                    $released++;
                }
            });

        $orders = Order::query()->withoutGlobalScope(BranchScope::class)
            ->where('order_type', OrderType::DELIVERY)
            ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::DELIVERED, OrderStatus::RETURNED])
            ->where('created_at', '>=', now()->subDay())
            // Une commande carte EN LIGNE non encore payée n'entre en tournée qu'à son paiement (c'est l'événement
            // OrderCreated, retenu jusque-là, qui la rattache) : la rattraper ici l'enverrait au livreur avant paiement.
            ->where(fn ($q) => $q->where('payment_status', '!=', PaymentStatus::UNPAID)
                ->orWhereNotIn('payment_method', [PaymentGateway::CARD, PaymentGateway::E_WALLET, PaymentGateway::PAYPAL])
                ->orWhereNull('payment_method'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('delivery_stops')->whereColumn('delivery_stops.order_id', 'orders.id'))
            ->get();

        $done = 0;
        foreach ($orders as $order) {
            try {
                try {
                    // Le créneau choisi par le client est conservé s'il est encore valable.
                    $waves->assign($order, $order->delivery_slot_at);
                } catch (\Illuminate\Validation\ValidationException) {
                    $order->forceFill(['delivery_slot_at' => null])->saveQuietly();
                    $waves->assign($order->fresh(), null);
                }
                $done++;
            } catch (\Throwable $e) {
                // Une commande sans créneau ne doit pas empêcher les suivantes.
                $this->error("Commande {$order->id} : " . $e->getMessage());
            }
        }
        $this->info("{$done} commande(s) rattachée(s), {$released} arrêt(s) libéré(s).");

        return self::SUCCESS;
    }
}
