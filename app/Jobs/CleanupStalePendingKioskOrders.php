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
use Illuminate\Support\Facades\Log;

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
        // [TRAP-2 2026-06-04 / CLUSTER-5 2026-07-09] CORRECTED ABANDONED SET. The real
        // walk-away kiosk order auto-accepts to `status=ACCEPT` (Plan-B cash counter-
        // deferred, FrontendOrderService:208,590-593), NOT `status=PENDING`. The KDS then
        // bumps it ACCEPT→PREPARING (KitchenReleaseRule) BEFORE the counter collects the
        // cash, so a genuinely-abandoned kiosk order is frequently found at PREPARING, not
        // ACCEPT. The lane must therefore be SYMMETRIC to the 'phone' lane below (which
        // reaps ACCEPT+PREPARING) so an UNPAID/unfiscalized kiosk order is reaped after the
        // SAME staleness window regardless of its kitchen status. Three legal entry
        // statuses are targeted:
        //   - PENDING   (kiosk card/TR never auto-accepted, UNPAID)      → REJECTED
        //   - ACCEPT    (kiosk cash auto-accepted, PENDING_COUNTER)      → CANCELED
        //   - PREPARING (kiosk cash bumped on KDS pre-collect)           → CANCELED
        // OrderStateMachine.allows(): PENDING→REJECTED, ACCEPT→CANCELED and
        // PREPARING→CANCELED are all legal (OrderStateMachine.php:38,52,63) — no frozen-
        // zone edit needed.
        //
        // PREPARED is DELIBERATELY EXCLUDED (same as the phone lane): PREPARED→CANCELED is
        // ILLEGAL in the frozen OrderStateMachine (PREPARED only allows OUT_FOR_DELIVERY/
        // DELIVERED/RETURNED — OrderStateMachine.php:65-71). Reaping a PREPARED kiosk
        // orphan requires an owner-gated frozen-zone change (add PREPARED→CANCELED); until
        // then, fetching a PREPARED row here would make OrderStateMachine::apply() throw
        // and abort the whole janitor run — so it stays out of the query.
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
            ->whereIn('status', [OrderStatus::PENDING, OrderStatus::ACCEPT, OrderStatus::PREPARING])
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
                [OrderStatus::PENDING, OrderStatus::ACCEPT, OrderStatus::PREPARING],
                [PaymentStatus::UNPAID, PaymentStatus::PENDING_COUNTER],
                'kiosk',
                $ttlMinutes,
                'kiosk',
            ));

        // [GOAL-8AXES V7 T-5.1.2 2026-08-05] CARTES WEB ABANDONNÉES. La commande web
        // est créée AVANT le paiement Mollie (funnel placeOrder → mollieCheckout) : un
        // abandon SANS webhook (onglet fermé pendant 3DS, retour navigateur, expiration
        // Mollie) la laissait UNPAID pour toujours — historique client pollué + suivi
        // « EN PRÉPARATION » mensonger. La garde caisse existe (R1 SÉCU 2026-08-04 :
        // exclue de la file web) ; ici le 2e étage : l'EXPIRATION.
        // Périmètre STRICT : web + PENDING + UNPAID + paiement CARTE en ligne. Les
        // PENDING_COUNTER web (client qui viendra payer au comptoir) ont leur propre
        // cycle counter-collect et ne sont PAS des abandons. TTL court dédié (60 min —
        // un 3DS ne dure jamais 1 h) : WEB_STALE_UNPAID_TTL_MINUTES.
        // NF525 : même garde absolue fiscal_sequence_no NULL (jamais de séquence sur
        // une UNPAID — défense en profondeur).
        $webTtlMinutes = (int) config('order.web_stale_unpaid_ttl_minutes', 60);
        $webStaleThreshold = now()->subMinutes($webTtlMinutes);
        FrontendOrder::withoutGlobalScope(BranchScope::class)
            ->whereNull('deleted_at')
            ->whereNull('fiscal_sequence_no')
            ->where('status', OrderStatus::PENDING)
            ->where('payment_status', PaymentStatus::UNPAID)
            // [PROCUREUR cycle 6 — 2026-08-05 · P1 F-2] `db9748e66` a étendu la surface
            // 'delivery' aux 2 gardes du chemin payé, mais ces lanes de nettoyage sont restées
            // 'web'-only : une commande LIVRAISON abandonnée n'était donc jamais annulée, et
            // comme le stock est décrémenté INCONDITIONNELLEMENT à la création, elle le
            // déplétait À VIE. Le web n'envoie pas la surface : `FrontendOrder::creating` la
            // force à 'delivery' dès que `order_type === DELIVERY` — les deux valeurs
            // désignent la même chose, une commande passée depuis le site.
            ->whereIn('source_surface', ['web', 'delivery'])
            ->where('payment_method', \App\Enums\PaymentGateway::CARD)
            ->where('created_at', '<', $webStaleThreshold)
            ->orderBy('id')
            ->get()
            ->each(fn (FrontendOrder $order) => $this->cleanupStaleDeferredOrder(
                $order,
                [OrderStatus::PENDING],
                [PaymentStatus::UNPAID],
                'web',
                $webTtlMinutes,
                'web-card-abandoned',
            ));

        // [CLUSTER-5-reste 2026-07-11] PHANTOMS BORNE AU STATUT PREPARED — purge par SOFT-DELETE.
        // 8/9 des commandes borne UNPAID fantômes observées sont au statut PREPARED (status=8) :
        // la borne Plan-B cash auto-accepte (ACCEPT), le KDS la fait avancer ACCEPT→PREPARING→
        // PREPARED avant que la caisse encaisse. Le bloc kiosk ci-dessus ne peut PAS les CANCEL :
        // PREPARED→CANCELED est ILLÉGALE dans le OrderStateMachine FROZEN (l.65-71, PREPARED
        // n'autorise que OUT_FOR_DELIVERY/DELIVERED/RETURNED) — les inclure ferait throw
        // IllegalTransitionException et avorterait tout le job. Donc au lieu d'une transition de
        // statut interdite, on SOFT-DELETE le fantôme ($order->delete() → deleted_at) : il quitte
        // « à encaisser » ET le KDS (tous deux filtrés par le SoftDeletingScope) SANS toucher le
        // frozen state machine ni changer son statut.
        //
        // GARDE ABSOLUE NF525 (mirror du bloc kiosk) : whereNull('fiscal_sequence_no') +
        // payment_status ∈ {UNPAID, PENDING_COUNTER} → une commande encaissée (PAID + séquence
        // fiscale scellée) n'est JAMAIS soft-deletée. Même fenêtre de staleness que le bloc kiosk
        // ($staleThreshold / $ttlMinutes). Le comportement CANCELED existant reste inchangé pour
        // PENDING/ACCEPT/PREPARING (bloc ci-dessus).
        FrontendOrder::withoutGlobalScope(BranchScope::class)
            ->whereNull('deleted_at')
            ->whereNull('fiscal_sequence_no')
            ->where('status', OrderStatus::PREPARED)
            ->whereIn('payment_status', [PaymentStatus::UNPAID, PaymentStatus::PENDING_COUNTER])
            // [P1-3 CUMUL 2026-08-04 · cycle1] Étendu web+phone : le fantôme PREPARED impayé qui
            // conserve les points GAGNÉS existait AUSSI hors borne (award traite TAKEAWAY web comme
            // kiosk mais la purge était kiosk-only). Même garde NF525 (fiscal_sequence_no null).
            ->whereIn('source_surface', ['kiosk', 'web', 'phone', 'delivery'])
            ->whereIn('order_type', [\App\Enums\OrderType::KIOSK, \App\Enums\OrderType::TAKEAWAY])
            ->where(function ($query) use ($staleThreshold): void {
                $query->where('created_at', '<', $staleThreshold)
                    ->orWhere('order_datetime', '<', $staleThreshold);
            })
            // [P2 2026-08-04 · cycle3] JAMAIS purger une commande À L'AVANCE avant son créneau
            // (+TTL) : un repas pré-commandé pour plus tard, atteignant PREPARED tôt, serait
            // supprimé en silence (order-loss). On exige que scheduled_at soit AUSSI périmé.
            ->where(function ($query) use ($staleThreshold): void {
                $query->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<', $staleThreshold);
            })
            ->orderBy('id')
            ->get()
            ->each(fn (FrontendOrder $order) => $this->softDeleteStalePreparedPhantom($order, $ttlMinutes));

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

        // [STOCK-01 2026-07-15 / P1] LANE WEB — purge des commandes SITE WEB abandonnées.
        // Une commande web (source_surface='web') décrémente le stock à la CRÉATION
        // (StockService::decrementForOrder, FrontendOrderService:563) EXACTEMENT comme la
        // borne, mais AUCUNE lane du janitor ne la visait (filtres 'kiosk'/'phone') → une
        // commande jamais encaissée/retirée restait PENDING/ACCEPT/PREPARING indéfiniment →
        // stock déplété À VIE (faux « 86 » rupture, file caisse + KDS pollués). On la reap
        // sur son PROPRE TTL, généreux et order_datetime-priority (comme le téléphone) : une
        // commande web « à emporter ce soir » n'est annulée qu'après son créneau + TTL,
        // JAMAIS trop tôt (repli created_at si order_datetime absent).
        // NF525-safety IDENTIQUE aux lanes kiosk/phone : whereNull('fiscal_sequence_no') →
        // une commande web encaissée (scellée) est immunisée. Transitions PENDING→REJECTED /
        // ACCEPT|PREPARING→CANCELED toutes légales (OrderStateMachine) ; release stock +
        // refund fidélité via le MÊME chemin unifié cleanupStaleDeferredOrder. PREPARED
        // exclu (PREPARED→CANCELED illégale dans le state machine frozen).
        $webTtlMinutes = (int) config('kiosk.stale_web_collect_ttl_minutes', 360);
        $webStaleThreshold = now()->subMinutes($webTtlMinutes);

        FrontendOrder::withoutGlobalScope(BranchScope::class)
            ->whereNull('deleted_at')
            ->whereNull('fiscal_sequence_no')
            ->whereIn('status', [OrderStatus::PENDING, OrderStatus::ACCEPT, OrderStatus::PREPARING])
            ->whereIn('payment_status', [PaymentStatus::UNPAID, PaymentStatus::PENDING_COUNTER])
            // [PROCUREUR cycle 6 — 2026-08-05 · P1 F-2] `db9748e66` a étendu la surface
            // 'delivery' aux 2 gardes du chemin payé, mais ces lanes de nettoyage sont restées
            // 'web'-only : une commande LIVRAISON abandonnée n'était donc jamais annulée, et
            // comme le stock est décrémenté INCONDITIONNELLEMENT à la création, elle le
            // déplétait À VIE. Le web n'envoie pas la surface : `FrontendOrder::creating` la
            // force à 'delivery' dès que `order_type === DELIVERY` — les deux valeurs
            // désignent la même chose, une commande passée depuis le site.
            ->whereIn('source_surface', ['web', 'delivery'])
            ->where(function ($query) use ($webStaleThreshold): void {
                $query->where('order_datetime', '<', $webStaleThreshold)
                    ->orWhere(function ($q) use ($webStaleThreshold): void {
                        $q->whereNull('order_datetime')
                            ->where('created_at', '<', $webStaleThreshold);
                    });
            })
            ->orderBy('id')
            ->get()
            ->each(fn (FrontendOrder $order) => $this->cleanupStaleDeferredOrder(
                $order,
                [OrderStatus::PENDING, OrderStatus::ACCEPT, OrderStatus::PREPARING],
                [PaymentStatus::UNPAID, PaymentStatus::PENDING_COUNTER],
                'web',
                $webTtlMinutes,
                'web',
            ));

        // [CLUSTER-7 / P3 2026-07-11] Re-credit ORPHAN self-service pre-redemptions.
        // The pre-redeem endpoint (LoyaltyController::redeem) debits points and writes a
        // PENDING ledger row (type='redeem', order_id=NULL). An order backfills that row's
        // order_id (10-min window, FrontendOrderService); if NO order is ever placed the
        // row stays order_id=NULL forever and the order-keyed refundPoints can never
        // re-credit it → points burned. This reaper (window > the 10-min attach window, so
        // it can never race a legitimate late order) re-credits any unconsumed pending
        // redeem. Idempotent + isolated (loyalty_transactions + users only, zero order /
        // NF525 / fiscal_sequence impact) — safe to run alongside the order janitor above.
        app(\App\Services\LoyaltyService::class)->reapOrphanRedemptions();
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

    /**
     * [CLUSTER-5-reste 2026-07-11] Purge par SOFT-DELETE d'un fantôme borne bloqué au statut
     * PREPARED, dans une transaction verrouillée. Contrairement à cleanupStaleDeferredOrder, on
     * NE fait PAS de transition de statut : PREPARED→CANCELED est illégale dans le OrderStateMachine
     * FROZEN et la forcer avorterait le job. On soft-delete la ligne à la place → elle disparaît de
     * « à encaisser » et du KDS (SoftDeletingScope) sans toucher le frozen ni le statut.
     *
     * GARDE ABSOLUE NF525 (re-checkée sous lock) : on ne soft-delete JAMAIS une commande payée
     * (payment_status PAID) ou fiscalisée (fiscal_sequence_no non-null).
     */
    private function softDeleteStalePreparedPhantom(FrontendOrder $order, int $ttlMinutes): void
    {
        $purged = false;

        DB::transaction(function () use ($order, $ttlMinutes, &$purged): void {
            $locked = FrontendOrder::withoutGlobalScope(BranchScope::class)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            // Re-garde sous lock, miroir de la requête externe. On perd la course gracieusement
            // contre un encaissement concurrent (confirmCounterPayment scelle fiscal_sequence_no +
            // PAID sous son propre lock) : garde ABSOLUE fiscal_sequence_no + payment_status.
            if (!$locked
                || $locked->fiscal_sequence_no !== null
                || (int) $locked->status !== OrderStatus::PREPARED
                || !in_array((int) $locked->payment_status, [PaymentStatus::UNPAID, PaymentStatus::PENDING_COUNTER], true)) {
                return;
            }

            // Remboursement fidélité idempotent, exactement comme le chemin d'annulation, AVANT la
            // purge (refundPoints no-op si pas de loyalty_code / déjà remboursé).
            app(\App\Services\LoyaltyService::class)->refundPoints($locked, 'kiosk');

            // [P1-2 CUMUL 2026-08-04] refundPoints ne rend que les points DÉPENSÉS. Une commande
            // borne atteignant PREPARED a AUSSI CUMULÉ des points (award au PREPARED). Purgée sans
            // clawback, le client garde des points gagnés sur une vente inexistante (exploit « QR +
            // faire préparer + partir sans payer »). On reprend les points GAGNÉS, miroir exact de
            // ClawbackLoyaltyPointsOnRefund (idempotent : NOOP si déjà repris).
            $awarded = (int) ($locked->loyalty_points_awarded ?? 0);
            if ($awarded > 0) {
                $loyaltyUser = null;
                if (! empty($locked->loyalty_customer_code)) {
                    $loyaltyUser = \App\Models\User::where('loyalty_code', $locked->loyalty_customer_code)->first();
                }
                if (! $loyaltyUser && ! empty($locked->user_id)) {
                    $cand = \App\Models\User::find($locked->user_id);
                    if ($cand && $cand->loyalty_code) {
                        $loyaltyUser = $cand;
                    }
                }
                if ($loyaltyUser) {
                    app(\App\Services\LoyaltyService::class)->clawbackEarnedPoints(
                        $loyaltyUser->id,
                        $awarded,
                        $locked->id,
                        'Clawback fidélité — commande borne jamais payée purgée'
                    );
                }
            }

            // Casse du marqueur counter-deferred → un encaissement tardif ne peut plus fiscaliser
            // + PAYER une ligne qu'on vient de purger (assertCounterDeferredOrder throw 422).
            if ((int) ($locked->pos_payment_method ?? 0) === PosPaymentMethod::COUNTER_DEFERRED) {
                $locked->pos_payment_method = null;
                $locked->save();
            }

            // Soft-delete : deleted_at posé → sort de « à encaisser » + KDS via le SoftDeletingScope,
            // aucune transition de statut illégale, aucun changement du frozen state machine.
            $locked->delete();

            // Trace de purge sur le MÊME canal que le reste du job (LoyaltyService logue via Log).
            Log::info('[CleanupStalePendingKioskOrders] Phantom PREPARED kiosk order soft-deleted', [
                'order_id'   => $locked->id,
                'branch_id'  => $locked->branch_id,
                'status'     => (int) $locked->status,
                'ttl_min'    => $ttlMinutes,
                'reason'     => 'PREPARED→CANCELED illegal in frozen state machine; soft-delete purge instead.',
            ]);

            $locked->refresh();
            $order->setRawAttributes($locked->getAttributes(), true);
            $purged = true;
        });

        if (!$purged) {
            return;
        }

        // [F-01] Release des compteurs branch-scoped consommés à la création (idempotent
        // released_qty). Le fantôme purgé libère sa réservation de dispo/stock comme une annulation.
        OrderCanceled::dispatch($order);
    }
}
