<?php

namespace App\Jobs;

use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderCanceled;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Models\FrontendOrder;
use App\Models\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;

class CleanupStalePendingKioskOrders
{
    public function handle(): void
    {
        // [TRAP-2 2026-06-04] Config-driven TTL. Walk-away kiosk orders sit in
        // the cashier collect queue + KDS until purged; a few hours is the
        // safe envelope so a customer who genuinely takes their time queuing
        // is never auto-cancelled mid-service. Override via
        // KIOSK_STALE_COLLECT_TTL_MINUTES. Default 180 min (3 h).
        $ttlMinutes = (int) config('kiosk.stale_collect_ttl_minutes', 180);
        $staleThreshold = now()->subMinutes($ttlMinutes);

        /*
         * [W9-AUDIT FIX-5] Console job runs without Auth context: BranchScope is bypassed
         * naturally, but `withoutGlobalScopes()` would ALSO drop SoftDeletingScope, risking
         * the auto-rejection of orders that were already soft-deleted (e.g. by a manual
         * admin action). Drop only BranchScope (multi-tenant by design) and keep the
         * soft-delete guard intact.
         */
        // [TRAP-2 2026-06-04] CORRECTED ABANDONED SET. The real walk-away kiosk
        // order auto-accepts to `status=ACCEPT` (Plan-B cash counter-deferred,
        // FrontendOrderService:208,590-593), NOT `status=PENDING`. The previous
        // gate filtered `status=PENDING` only, so it matched ZERO real kiosk
        // orders — DEAD CODE despite the GAP-C1-002 comment. Two legal entry
        // statuses are now targeted:
        //   - PENDING (kiosk card/TR never auto-accepted, UNPAID)   → REJECTED
        //   - ACCEPT  (kiosk cash auto-accepted, PENDING_COUNTER)   → CANCELED
        // OrderStateMachine.allows(): PENDING→REJECTED and ACCEPT→CANCELED are
        // both legal (OrderStateMachine.php:38,52) — no frozen-zone edit needed.
        //
        // NF525-safety: only rows with NO fiscal_sequence_no are touched. A
        // collected order has been sealed (payment_status=PAID + a fiscal
        // sequence allocated at PaymentService:321-322) and is excluded by both
        // the payment_status filter AND the explicit whereNull below — a
        // fiscalized order is NEVER mutated, so the NF525 chain is untouched
        // (no sequence = no chain impact).
        FrontendOrder::withoutGlobalScope(BranchScope::class)
            ->whereNull('deleted_at')
            ->whereNull('fiscal_sequence_no')
            ->whereIn('status', [OrderStatus::PENDING, OrderStatus::ACCEPT])
            ->whereIn('payment_status', [PaymentStatus::UNPAID, PaymentStatus::PENDING_COUNTER])
            ->where('source_surface', 'kiosk')
            ->whereIn('order_type', [\App\Enums\OrderType::KIOSK, \App\Enums\OrderType::TAKEAWAY])
            ->where(function ($query) use ($staleThreshold): void {
                $query->where('created_at', '<', $staleThreshold)
                    ->orWhere('order_datetime', '<', $staleThreshold);
            })
            ->orderBy('id')
            ->get()
            ->each(fn (FrontendOrder $order) => $this->cleanupStaleDeferredOrder(
                $order,
                [OrderStatus::PENDING, OrderStatus::ACCEPT],
                [PaymentStatus::UNPAID, PaymentStatus::PENDING_COUNTER],
                'kiosk',
                $ttlMinutes,
                'kiosk',
            ));

        // [C4-CAISSE-TELEPHONE FIX-2 / P3 2026-07-07] Purge des COMMANDES TÉLÉPHONE
        // abandonnées. Une commande téléphone (source_surface='phone') est prise à
        // l'avance, envoyée en cuisine (auto-accept + board-release → status=ACCEPT ou
        // PREPARING) et différée (PENDING_COUNTER + COUNTER_DEFERRED, aucune séquence
        // fiscale). Si le client ne vient JAMAIS la chercher, elle restait PENDING_COUNTER
        // indéfiniment (file d'encaissement + KDS pollués à vie) : le bloc kiosk ci-dessus
        // ne la voit pas (filtre source_surface='kiosk'). On la purge sur son PROPRE TTL,
        // volontairement plus généreux (défaut 360 min / 6 h) : une commande téléphone est
        // par nature « à l'avance » (« je passe ce soir »), donc on ne l'annule qu'après un
        // délai large ET après son créneau (order_datetime) — jamais trop tôt.
        //
        // NF525-safety identique au bloc kiosk : whereNull('fiscal_sequence_no') → une
        // commande téléphone déjà encaissée (scellée, fiscal alloué) est immunisée. La
        // transition terminale (ACCEPT/PREPARING → CANCELED, toutes deux légales
        // OrderStateMachine.php:52,63) + le refund fidélité + la casse du marqueur passent
        // par le MÊME chemin unifié cleanupStaleDeferredOrder (une seule source de vérité,
        // pas de garde asymétrique kiosk vs téléphone).
        $phoneTtlMinutes = (int) config('kiosk.stale_phone_collect_ttl_minutes', 360);
        $phoneStaleThreshold = now()->subMinutes($phoneTtlMinutes);

        FrontendOrder::withoutGlobalScope(BranchScope::class)
            ->whereNull('deleted_at')
            ->whereNull('fiscal_sequence_no')
            ->whereIn('status', [OrderStatus::ACCEPT, OrderStatus::PREPARING])
            ->where('payment_status', PaymentStatus::PENDING_COUNTER)
            ->where('pos_payment_method', PosPaymentMethod::COUNTER_DEFERRED)
            ->where('source_surface', 'phone')
            // « Ne purge pas trop tôt » : le signal d'ancienneté est le CRÉNEAU PRÉVU
            // (order_datetime — posé à l'heure de création pour une commande immédiate,
            // OrderService:806, ou au slot programmé pour une commande à l'avance). Une
            // commande n'est purgée qu'une fois son créneau dépassé du TTL → une commande
            // téléphone programmée pour plus tard n'est JAMAIS annulée avant l'heure. Repli
            // sur created_at seulement si order_datetime est absent. (Volontairement moins
            // agressif que le OR du bloc kiosk, qui purge dès que l'une des deux dates est
            // vieille — sûr pour la borne non-programmable, dangereux pour le téléphone.)
            ->where(function ($query) use ($phoneStaleThreshold): void {
                $query->where('order_datetime', '<', $phoneStaleThreshold)
                    ->orWhere(function ($q) use ($phoneStaleThreshold): void {
                        $q->whereNull('order_datetime')
                            ->where('created_at', '<', $phoneStaleThreshold);
                    });
            })
            ->orderBy('id')
            ->get()
            ->each(fn (FrontendOrder $order) => $this->cleanupStaleDeferredOrder(
                $order,
                [OrderStatus::ACCEPT, OrderStatus::PREPARING],
                [PaymentStatus::PENDING_COUNTER],
                'pos',
                $phoneTtlMinutes,
                'phone',
            ));
    }

    /**
     * [C4-CAISSE-TELEPHONE FIX-2 2026-07-07] Annulation terminale UNIFIÉE d'une commande
     * différée abandonnée (borne Plan B OU téléphone), dans une transaction verrouillée :
     * re-garde sous lock → refund fidélité idempotent → transition terminale légale →
     * casse du marqueur counter-deferred → dispatch des notifications + release de stock.
     *
     * Source de vérité UNIQUE partagée par les deux canaux pour éviter la dérive de garde
     * asymétrique (kiosk remboursait, téléphone non = shape de bug récurrent).
     *
     * @param int[] $allowedStatuses         Statuts éligibles (re-checkés sous lock).
     * @param int[] $allowedPaymentStatuses  Payment-statuts éligibles (re-checkés sous lock).
     * @param string $refundSurface          'kiosk' | 'pos' — trace audit LoyaltyService.
     * @param int    $ttlMinutes             TTL utilisé (message de motif).
     * @param string $channelLabel           'kiosk' | 'phone' — libellé du motif.
     */
    private function cleanupStaleDeferredOrder(
        FrontendOrder $order,
        array $allowedStatuses,
        array $allowedPaymentStatuses,
        string $refundSurface,
        int $ttlMinutes,
        string $channelLabel,
    ): void {
        $oldStatus = null;
        $newStatus = null;
        $cleaned = false;

        DB::transaction(function () use ($order, $allowedStatuses, $allowedPaymentStatuses, $refundSurface, $ttlMinutes, $channelLabel, &$oldStatus, &$newStatus, &$cleaned): void {
            $locked = FrontendOrder::withoutGlobalScope(BranchScope::class)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            // [TRAP-2] Inner re-guard must mirror the outer query, else every
            // fetched row would be skipped here and the job stays dead. We
            // re-check status, payment_status AND fiscal_sequence_no under the
            // row lock so we lose the race gracefully against a concurrent
            // counter-collect (PaymentService::confirmCounterPayment, which
            // holds its own lockForUpdate and seals fiscal_sequence_no + PAID).
            if (!$locked
                || $locked->fiscal_sequence_no !== null
                || !in_array((int) $locked->status, $allowedStatuses, true)
                || !in_array((int) $locked->payment_status, $allowedPaymentStatuses, true)) {
                return;
            }

            $oldStatus = (int) $locked->status;

            // [C36 2026-07-06] Symétrie remboursement fidélité. Une commande différée
            // créée avec un loyalty_code débite les points à la création (LoyaltyTransaction
            // type=redeem, points<0). Les chemins d'annulation « normaux »
            // (OrderService::changeStatus:2160,2324 + FrontendOrderService::changeStatus:782)
            // remboursent déjà via LoyaltyService::refundPoints, mais ce job de purge ne le
            // faisait PAS → une commande abandonnée annulée ici perdait les points du client
            // DÉFINITIVEMENT (vecteur de griefing avec le loyalty_code d'une victime). On
            // rembourse ici, sur la commande verrouillée, DANS la transaction, avant l'apply
            // terminal — exactement comme changeStatus. refundPoints est idempotent + no-op :
            // return immédiat si loyalty_customer_code est nul (commande sans fidélité), si
            // aucune ligne redeem n'existe, ou si le remboursement (type=manual_add) a déjà
            // été écrit → zéro régression sur les commandes sans fidélité et pas de double-
            // crédit sur un re-run du cron. Aucun audit log dédié : le chemin changeStatus
            // n'en émet pas non plus (refundPoints logue via Log::info).
            app(\App\Services\LoyaltyService::class)->refundPoints($locked, $refundSurface);

            // From-status-aware terminal state, both legal per OrderStateMachine.allows()
            // with no frozen-zone edit :
            //   PENDING            → REJECTED (kiosk card/TR path, tested)
            //   ACCEPT / PREPARING → CANCELED (walk-away cash + téléphone ; ACCEPT→REJECTED
            //                        et PREPARING→REJECTED sont ILLÉGAUX, →CANCELED légal)
            // La règle « PENDING ? REJECTED : CANCELED » est byte-identique au comportement
            // kiosk historique (kiosk n'entre qu'avec PENDING/ACCEPT) et correcte pour le
            // téléphone (ACCEPT/PREPARING).
            $newStatus = $oldStatus === OrderStatus::PENDING
                ? OrderStatus::REJECTED
                : OrderStatus::CANCELED;

            OrderStateMachine::apply(
                $locked,
                $newStatus,
                null,
                $newStatus === OrderStatus::CANCELED
                    ? 'Auto-canceled abandoned counter-pending ' . $channelLabel . ' order after ' . $ttlMinutes . ' minutes.'
                    : 'Auto-rejected stale pending ' . $channelLabel . ' order after ' . $ttlMinutes . ' minutes.'
            );

            // [TRAP-2] Break the counter-deferred marker so a LATE
            // PaymentService::confirmCounterPayment can never fiscalize + PAY a
            // row we just canceled. assertCounterDeferredOrder requires
            // pos_payment_method===COUNTER_DEFERRED; nulling it makes the collect
            // path throw 422 "not a pending counter payment". payment_status is
            // left as-is (no honest CANCELED value exists; REFUNDED would be a
            // lie — no money moved). The CANCELED order status alone removes it
            // from KDS (KitchenReleaseRule::visibleStatuses() = ACCEPT/PREPARING/
            // PREPARED only) and the cashier collect queue.
            if ((int) ($locked->pos_payment_method ?? 0) === PosPaymentMethod::COUNTER_DEFERRED) {
                $locked->pos_payment_method = null;
                $locked->save();
            }

            $locked->refresh();
            $order->setRawAttributes($locked->getAttributes(), true);
            $cleaned = true;
        });

        if (!$cleaned || $oldStatus === null || $newStatus === null) {
            return;
        }

        SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $newStatus]);
        SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $newStatus]);
        SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $newStatus]);
        OrderStatusChanged::dispatch($order, $oldStatus, $newStatus);
        // [F-01] Auto-cleaned stale orders must release any branch-scoped counters
        // consumed at OrderCreated time. Idempotent via released_qty.
        OrderCanceled::dispatch($order);
    }
}
