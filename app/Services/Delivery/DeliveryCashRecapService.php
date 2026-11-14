<?php

namespace App\Services\Delivery;

use App\Enums\OrderStatus;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryBoyCashSession;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Scopes\BranchScope;
use Carbon\Carbon;

/**
 * [LIVREUR CAISSE 2026-11-02] « Ma caisse » : le récapitulatif d'un service livreur, moyen par moyen.
 *
 * Source de vérité, sans nouvelle colonne :
 *  - le fond de début = `delivery_boy_cash_sessions.opening_amount` (fixé à l'ouverture) ;
 *  - ESPÈCES attendues dans la sacoche = fond + Σ mouvements signés du service (exactement la formule de
 *    {@see DeliveryBoyCashSessionService::reconcileSession}) ;
 *  - la répartition par moyen se lit dans les lignes `order_payments` de référence « livraison » des commandes remises par ce livreur
 *    pendant le service (espèces notées sans ligne = règlement historique à un seul moyen : montant encaissé de l'arrêt) ;
 *  - l'écart entre les espèces des commandes et le grand livre de la caisse est exposé (`ledger_gap`) : jamais caché.
 * Tout est calculé en centimes puis rendu en euros.
 */
class DeliveryCashRecapService
{
    /**
     * @return array<string,mixed>
     */
    public function recap(DeliveryBoyCashSession $s, ?Carbon $now = null): array
    {
        $from = $s->opened_at;
        $to = $s->closed_at ?? ($now ?? Carbon::now());

        $movements = DeliveryBoyCashMovement::query()->withoutGlobalScope(BranchScope::class)
            ->where('delivery_boy_cash_session_id', $s->id)->orderBy('id')->get();
        $signedCents = 0;
        $ledgerCollectCents = 0;
        foreach ($movements as $m) {
            $c = (int) round(((float) $m->amount) * 100);
            $signedCents += $m->direction === DeliveryBoyCashMovement::DIRECTION_IN ? $c : -$c;
            if ($m->type === DeliveryBoyCashMovement::TYPE_ORDER_COLLECT) {
                $ledgerCollectCents += $m->direction === DeliveryBoyCashMovement::DIRECTION_IN ? $c : -$c;
            }
        }
        $openingCents = (int) round(((float) $s->opening_amount) * 100);
        $expectedCents = $openingCents + $signedCents;

        // Arrêts remis par CE livreur pendant le service. `delivered_at` est posé APRÈS l'encaissement (donc après l'ouverture
        // automatique du service) : un arrêt remis dans la fenêtre appartient bien à ce service.
        $stops = DeliveryStop::query()->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $s->branch_id)->where('delivery_boy_id', $s->delivery_boy_id)
            ->whereNotNull('delivered_at')->where('delivered_at', '>=', $from)->where('delivered_at', '<=', $to)
            ->orderBy('delivered_at')->orderBy('id')->get()->keyBy('order_id');
        $orderIds = $stops->keys()->merge($movements->pluck('order_id')->filter())->unique()->values();

        $orders = $orderIds->isEmpty() ? collect() : Order::query()->withoutGlobalScope(BranchScope::class)
            ->whereIn('id', $orderIds)->get()->keyBy('id');
        $payRows = $orderIds->isEmpty() ? collect() : OrderPayment::query()->withoutGlobalScope(BranchScope::class)
            ->whereIn('order_id', $orderIds)->where('reference', 'livraison')->orderBy('id')->get()->groupBy('order_id');
        $moveByOrder = $movements->whereNotNull('order_id')->where('type', DeliveryBoyCashMovement::TYPE_ORDER_COLLECT)->groupBy('order_id');

        $byMode = [];
        foreach (DoorPaymentSplit::MODES as $mode) {
            $byMode[$mode] = ['cents' => 0, 'count' => 0];
        }
        $lines = [];
        $settled = 0;
        $cashFromOrders = 0;
        foreach ($orderIds as $orderId) {
            $order = $orders->get($orderId);
            if (! $order) {
                continue;
            }
            $stop = $stops->get($orderId);
            $payments = [];
            foreach ($payRows->get($orderId, collect()) as $p) {
                $mode = DoorPaymentSplit::modeOfPosMethod((int) $p->mode);
                if ($mode !== null) {
                    $payments[] = ['mode' => $mode, 'cents' => (int) round(((float) $p->amount) * 100)];
                }
            }
            if ($payments === []) { // règlement historique en espèces : pas de ligne `order_payments`, la preuve est la caisse / l'arrêt
                $legacy = $moveByOrder->has($orderId)
                    ? (int) $moveByOrder->get($orderId)->sum(fn ($m) => (int) round(((float) $m->amount) * 100) * ($m->direction === 'in' ? 1 : -1))
                    : (($stop && $stop->collect_method === DeliveryStop::COLLECT_CASH) ? (int) $stop->amount_collected_cents : 0);
                if ($legacy > 0) {
                    $payments[] = ['mode' => DoorPaymentSplit::CASH, 'cents' => $legacy];
                }
            }
            $sum = 0;
            foreach ($payments as $p) {
                $byMode[$p['mode']]['cents'] += $p['cents'];
                $byMode[$p['mode']]['count']++;
                $sum += $p['cents'];
                if ($p['mode'] === DoorPaymentSplit::CASH) {
                    $cashFromOrders += $p['cents'];
                }
            }
            if ($sum > 0) {
                $settled++;
            }
            $lines[] = [
                'order_id'     => (int) $orderId,
                'order_number' => $order->order_serial_no,
                'delivered_at' => ($stop?->delivered_at)?->toDateTimeString(),
                'total'        => round(((float) $order->total), 2),
                'paid_online'  => $sum === 0,
                'payments'     => array_map(static fn (array $p) => [
                    'mode' => $p['mode'], 'label' => DoorPaymentSplit::LABELS[$p['mode']], 'amount' => round($p['cents'] / 100, 2),
                ], $payments),
            ];
        }

        $totalCents = array_sum(array_column($byMode, 'cents'));
        $counted = $s->closing_amount === null ? null : round((float) $s->closing_amount, 2);

        return [
            'session_id'          => (int) $s->id,
            'status'              => $s->status,
            'opened_at'           => $s->opened_at?->toDateTimeString(),
            'closed_at'           => $s->closed_at?->toDateTimeString(),
            'opening_amount'      => round($openingCents / 100, 2),
            'by_mode'             => array_map(static fn (array $m) => ['amount' => round($m['cents'] / 100, 2), 'count' => $m['count']], $byMode),
            'total_collected'     => round($totalCents / 100, 2),
            'deliveries_settled'  => $settled,
            'deliveries_delivered' => $stops->count() + $orderIds->diff($stops->keys())->count(),
            'expected_cash'       => round($expectedCents / 100, 2),
            'counted_cash'        => $counted,
            // Écart = espèces comptées − espèces attendues (négatif : il manque de l'argent dans la sacoche).
            'variance'            => $counted === null ? null : round($counted - $expectedCents / 100, 2),
            'ledger_gap'          => round(($cashFromOrders - $ledgerCollectCents) / 100, 2),
            'orders'              => $lines,
        ];
    }

    /**
     * Livraisons parties et pas encore remises dont il reste de l'argent à encaisser : elles empêchent la clôture du service.
     *
     * @return array{count: int, amount: float, orders: list<string>}
     */
    public function pendingToCollect(int $branchId, int $driverId): array
    {
        $waves = app(DeliveryWaveService::class);
        $stops = DeliveryStop::query()->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)->where('delivery_boy_id', $driverId)
            ->whereNotNull('left_at')->whereNull('delivered_at')->get();
        $count = 0;
        $cents = 0;
        $numbers = [];
        foreach ($stops as $stop) {
            $order = Order::query()->withoutGlobalScope(BranchScope::class)->find($stop->order_id);
            if (! $order || in_array((int) $order->status, [OrderStatus::CANCELED, OrderStatus::REJECTED], true)) {
                continue;
            }
            $due = $waves->collectionFor($order);
            if ((int) $due['amount_due_cents'] > 0) {
                $count++;
                $cents += (int) $due['amount_due_cents'];
                $numbers[] = '#'.($order->order_serial_no ?: $order->id);
            }
        }

        return ['count' => $count, 'amount' => round($cents / 100, 2), 'orders' => $numbers];
    }
}
