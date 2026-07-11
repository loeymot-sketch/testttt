<?php

namespace App\Services;

use App\Domain\Order\AutoPrepareOnPaidPolicy;
use App\Domain\Order\OrderStateMachine;
use App\Domain\Order\PaymentStateMachine;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderCanceled;
use App\Events\OrderPaidAtCounter;
use App\Events\OrderStatusChanged;
use App\Events\RefundCreated;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    public function payment($order, $gatewaySlug, $transactionNo)
    {
        // [P0-POS-02 GOAL round-2 2026-05-18] Authorization gate — marking
        // an order as PAID is a fiscally-significant action (NF525 audit
        // chain + Transaction creation). Before this guard the public
        // method `payment()` had ZERO authz check: any caller (queue job,
        // future controller, console command) could supply an arbitrary
        // $order + $transactionNo and silently mark it PAID without an
        // actual gateway settlement happening.
        //
        // The legitimate callers are the gateway `success()` callbacks
        // (Stripe::success @ line 112, Credit, PayPal). Those classes all
        // extend `PaymentAbstract`. We enforce the gateway-context
        // invariant by walking the backtrace and asserting at least one
        // frame is a `PaymentAbstract` subclass. This is non-spoofable
        // from outside the gateway hierarchy and avoids the brittleness
        // of role/permission checks (queue workers may not have an
        // authenticated user).
        $this->assertGatewayContext();

        $this->assertPilotPaymentMethodAllowed($order, (string) $gatewaySlug, 'payment');

        $transaction = Transaction::where(['order_id' => $order->id])->first();
        if (! $transaction) {
            $this->assertTransactionReferenceAvailable($order, (string) $transactionNo);

            $transaction = Transaction::create([
                'order_id' => $order->id,
                'transaction_no' => $transactionNo,
                'amount' => $order->total,
                'payment_method' => $gatewaySlug,
                'sign' => '+',
                'type' => 'payment',
            ]);
        }
        $order->payment_status = PaymentStatus::PAID;
        $order->save();

        return $transaction;
    }

    private function assertTransactionReferenceAvailable($order, string $transactionNo): void
    {
        $transactionNo = trim($transactionNo);
        if ($transactionNo === '') {
            return;
        }

        $duplicate = Transaction::query()
            ->where('transaction_no', $transactionNo)
            ->where('type', 'payment')
            ->where('order_id', '!=', (int) $order->id)
            ->exists();

        if (! $duplicate) {
            return;
        }

        throw ValidationException::withMessages([
            'transaction_no' => 'This payment transaction reference is already attached to another order.',
        ]);
    }

    public function cashBack($order, $gatewaySlug, $transactionNo)
    {
        // Idempotent early-return — stays OUTSIDE the transaction envelope so
        // a no-op second call does not waste a tx + savepoint. Mirrors the
        // pre-heal behaviour: a re-fire of cashBack on an already-refunded
        // order returns the prior row without re-dispatching RefundCreated.
        $existingCashBack = Transaction::where(['order_id' => $order->id])
            ->where('type', 'cash_back')
            ->first();

        if ($existingCashBack) {
            return $existingCashBack;
        }

        // [HEAL-PLAN-D.2 / Z8 P1-2 2026-05-19] Atomic envelope around the
        // four mutating side-effects of a cash-back issuance:
        //   1. Transaction::create('cash_back')
        //   2. User->balance += $order->total
        //   3. AuditLogService::write — NF525 HMAC chain append
        //   4. RefundCreated::dispatch — stock/availability release + broadcast
        //
        // Pre-heal, an exception between steps 1-2 and step 3 (audit chain
        // head missing, unique-constraint collision, HMAC failure) left
        // orphan Transaction rows + inflated balance + no audit footprint +
        // no downstream RefundCreated -> stock never released, payment_status
        // never flipped. Post-heal, any throw inside the closure rolls back
        // all three writes atomically.
        //
        // Nested-tx safe: when called from OrderService::changeStatus (which
        // wraps its work in its own DB::transaction(lockForUpdate)), Laravel
        // uses a savepoint — the inner tx participates in the outer one.
        //
        // DispatchableAfterCommit on RefundCreated defers the dispatch to
        // commit of the OUTERMOST tx — a rollback discards the deferred
        // callback before any listener runs, preserving consistent rollback
        // semantics.
        $transaction = null;
        DB::transaction(function () use ($order, $gatewaySlug, $transactionNo, &$transaction): void {
            $priorPayment = Transaction::where(['order_id' => $order->id])
                ->where('type', 'payment')
                ->first();
            if (! $priorPayment) {
                return; // No prior payment -> no cashBack (legacy behavior preserved).
            }

            $transaction = Transaction::create([
                'order_id' => $order->id,
                'transaction_no' => $transactionNo,
                'amount' => $order->total,
                'payment_method' => $gatewaySlug,
                'sign' => '-',
                'type' => 'cash_back',
            ]);

            $user = User::find($order->user_id);
            if ($user) {
                $user->balance = ($user->balance + $order->total);
                $user->save();
            }

            // [POS-9.4.BL.2] NF525 audit trail on cash back. A cash back is
            // fiscally equivalent to a refund — it must leave a tamper-evident
            // record on the HMAC chain so a fraudulent cashier can be
            // detected even if the Transaction row is later mutated.
            app(AuditLogService::class)->write([
                'branch_id' => (int) ($order->branch_id ?? 0),
                'user_id' => Auth::check() ? (int) Auth::id() : null,
                'action' => 'payment.cash_back_issued',
                'resource' => 'order',
                'resource_id' => (int) $order->id,
                'payload' => [
                    'order_serial_no' => $order->order_serial_no,
                    'transaction_id' => $transaction?->id,
                    'transaction_no' => $transactionNo,
                    'payment_method' => $gatewaySlug,
                    'amount' => round((float) $order->total, 2),
                    'fiscal_sequence_no' => $order->fiscal_sequence_no,
                ],
            ]);

            // [AUDIT-F-003] Cash drawer hook — record cashback as direction=out.
            // recordCashBackMovement is self-shielded (try/catch + Log::warning)
            // so its failure CANNOT abort the outer tx; the cash drawer
            // movement is intentionally best-effort.
            // [CAISSE-LOGIC-HEAL 2026-07-11] Le mouvement de tiroir ne doit être écrit
            // que si le REMBOURSEMENT est émis EN ESPÈCES ($gatewaySlug='cash') — c.-à-d.
            // des espèces quittent physiquement le tiroir. Un remboursement carte/en-ligne
            // retourne au moyen d'origine et ne doit PAS mouvementer le tiroir (sinon
            // `expected` sous-évalué au rapprochement = faux surplus). Le critère est la
            // MÉTHODE du remboursement (param), pas le pos_payment_method d'origine : une
            // commande COUNTER_DEFERRED remboursée en espèces mouvemente bien le tiroir.
            if ($order instanceof Order && strtolower((string) $gatewaySlug) === 'cash') {
                $this->recordCashBackMovement($order, (float) $order->total);
            }

            // [REFUND-EVENT-WIRE] Fire RefundCreated so listeners release stock /
            // availability counters. Inside the transaction so the dispatch
            // is registered on Laravel's afterCommit hook — listeners only
            // fire on durable commit. The idempotent early-return above
            // guarantees we never re-fire on a second cashBack() call.
            // Double-fire with OrderCanceled (admin RETURN/CANCEL paths in
            // OrderService / FrontendOrderService) is acceptable —
            // AvailabilityService is idempotent via the released_qty ledger.
            RefundCreated::dispatch($order);
        });

        return $transaction;
    }

    public function confirmCounterPayment(Order $order, int $mode, ?float $received = null, ?string $note = null): Order
    {
        // [BYPASS-P2] Audit-log structuré si payment.bypass.enabled — invariants
        // (sealing fiscal, Outbox OrderPaidAtCounter, audit log) restent intacts.
        \App\Services\Bypass\BypassAuditLogger::paymentBypassed([
            'service' => 'PaymentService::confirmCounterPayment',
            'order_id' => $order->id,
            'mode' => $mode,
        ]);

        $allowedModes = [
            PosPaymentMethod::CASH,
            PosPaymentMethod::CARD,
            PosPaymentMethod::MOBILE_BANKING,
            PosPaymentMethod::OTHER,
            PosPaymentMethod::TICKET_RESTAURANT,
        ];

        if (! in_array($mode, $allowedModes, true)) {
            throw ValidationException::withMessages([
                'mode' => 'Mode de paiement comptoir invalide.',
            ]);
        }

        $paid = false;

        DB::transaction(function () use ($order, $mode, $received, $note, &$paid): void {
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCounterOrderVisible($locked);

            // [GOAL-K2-HEAL-01 2026-05-24] Phase K.4 H9 P1 + J-CASCADE H9
            // UNHEALED — when two cashiers simultaneously click "Encaisser"
            // on the same Q10 pending-counter row, cashier A wins the
            // lockForUpdate above and flips payment_status=PAID. Cashier B's
            // transaction then enters this branch on its post-acquire reread.
            //
            // PRE-HEAL behavior unconditionally short-circuited to a 200
            // no-op for ANY caller (same-cashier replay OR different-cashier
            // race). For cashier B that meant the route closure shipped a
            // 200 + OrderDetailsResource back, the `PosCounterCollectModal`
            // success branch toasted `cash_drawer_opened_simulation`, and
            // cashier B believed they had collected the money. Phantom
            // drawer-open + till-count drift risk. Data integrity was
            // intact (single fiscal_sequence_no, single cash_movement,
            // single audit row, single Transaction row) but the
            // operational defect was real — reported by Phase J adversarial
            // round + Phase K.4 deep audit.
            //
            // POST-HEAL: discriminate on WHO collected vs WHO is calling
            // now. The source of truth for the original collector is the
            // `order.counter_payment_confirmed` audit_logs row written by
            // the first transaction (lines 309-321 below). Its `user_id`
            // is the cashier who actually won the lockForUpdate.
            //
            //   - Same cashier replaying (middleware cache miss, network
            //     blip, double-tap with fresh idempotency key): preserve
            //     the V5.5 sister-guard no-op pattern documented in
            //     `C5_EncaisserKdsPreserveTest:302-355` — return 200 with
            //     the locked attributes hydrated onto $order. This is a
            //     deliberate defense layer behind IdempotencyKeyMiddleware
            //     and must keep working.
            //
            //   - Different cashier (race loser) OR unknown collector (no
            //     audit row found — pre-heal historical data, or audit
            //     write failed): throw typed
            //     PaymentAlreadyCollectedException. The route closure
            //     (`routes/api.php:808-845`) catches it ABOVE the generic
            //     Exception→422 fallback and converts to 409 Conflict with
            //     a structured `error_code: payment_already_collected`
            //     payload. The modal `onConfirm` catch matches on error_code
            //     and shows a clear FR error toast + emits `cancel` so the
            //     Q10 panel refreshes and the cashier moves on without the
            //     phantom drawer-open simulation.
            //
            // 409 is intentionally NOT cached by IdempotencyKeyMiddleware
            // (only stores 2xx) so a subsequent retry with a fresh
            // idempotency key still surfaces the conflict.
            //
            // collected_at is honestly populated from the audit row's
            // created_at timestamp; collected_by_user_id from its user_id.
            // No new migration / column required.
            if ((int) $locked->payment_status === PaymentStatus::PAID) {
                $currentUserId = Auth::check() ? (int) Auth::id() : null;

                $collectorAudit = \App\Models\AuditLog::query()
                    ->where('resource', 'order')
                    ->where('resource_id', (int) $locked->id)
                    ->where('action', 'order.counter_payment_confirmed')
                    ->latest('id')
                    ->first();

                $collectorUserId = $collectorAudit?->user_id !== null
                    ? (int) $collectorAudit->user_id
                    : null;

                // V5.5 sister guard — same cashier replay → no-op (200).
                if ($currentUserId !== null
                    && $collectorUserId !== null
                    && $currentUserId === $collectorUserId) {
                    $order->setRawAttributes($locked->getAttributes(), true);

                    return;
                }

                // K2-HEAL-01 — different cashier (race loser) OR unknown
                // collector → 409. Treating unknown collector as foreign
                // is the safe default: if the audit write somehow failed
                // historically, prefer surfacing the conflict over silently
                // hiding it behind a 200.
                throw new \App\Exceptions\Payment\PaymentAlreadyCollectedException(
                    orderId: (int) $locked->id,
                    collectedByUserId: $collectorUserId,
                    collectedAt: $collectorAudit?->created_at?->toIso8601String(),
                );
            }

            $this->assertCounterDeferredOrder($locked);
            PaymentStateMachine::assertCanTransition((int) $locked->payment_status, PaymentStatus::PAID);

            // [TERMINAL-COLLECT-GUARD 2026-06-26 / P2] An order moved to a terminal
            // status (CANCELED/REJECTED/RETURNED) via the GENERIC OrderService::changeStatus
            // path keeps payment_status=PENDING_COUNTER (that path only touches `status`),
            // so it lingers in the counter-collect queue and would otherwise be collectable
            // here — charging the customer + consuming a fiscal_sequence_no for an order the
            // kitchen treats as void. confirmCounterPayment never read `status`; close the
            // seam. (The signed Z already excludes terminal statuses — ZReportService:350-356 —
            // so this is operational/cash robustness, not a Z-pollution fix.)
            if (in_array((int) $locked->status, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Cette commande est annulée ou retournée : elle ne peut pas être encaissée.',
                ]);
            }

            if ($mode === PosPaymentMethod::CASH && $received !== null && (float) $received < (float) $locked->total) {
                throw ValidationException::withMessages([
                    'received' => 'Le montant recu est inferieur au total a encaisser.',
                ]);
            }

            if ($locked->fiscal_sequence_no === null) {
                $locked->fiscal_sequence_no = app(FiscalSequenceService::class)->next((int) $locked->branch_id);
                // [P1 fiscal_dated_at — LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT 2026-07-07]
                // This is a DEFERRED order (COUNTER_DEFERRED: kiosk Plan B, walk-in,
                // phone): the fiscal sequence is allocated HERE at encaissement, not
                // at creation. Stamp the allocation instant so ZReportService::aggregate
                // seals it in the Z open NOW (fiscal-date window) instead of leaking it
                // out of every Z when created_at falls in a prior, already-closed Z.
                if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'fiscal_dated_at')) {
                    $locked->fiscal_dated_at = now();
                }
            }

            $locked->payment_status = PaymentStatus::PAID;
            $locked->pos_payment_method = $mode;
            $locked->pos_received_amount = $mode === PosPaymentMethod::CASH
                ? ($received ?? (float) $locked->total)
                : null;
            $locked->pos_payment_note = $note;

            // [Wave S-1 — P-OWNER 2026-05-20] Auto-transition ACCEPT → PREPARING
            // the moment a counter-deferred kiosk order is collected by card /
            // MOBILE / TICKET / OTHER. The kitchen sees the ticket already
            // "en cours" without a second tap from the chef. Skipped for
            // mode=CASH per the S-5 sister-mission carve-out: cash physically
            // tendered at the counter waits for explicit cashier validation
            // through the "à encaisser" UI before kitchen release.
            //
            // The transition happens INSIDE the same transaction as the
            // PAID flip so an outer rollback (e.g. fiscal-sequence DB hiccup)
            // discards BOTH the status and payment_status mutations together
            // — no PREPARING-but-not-PAID half-state can leak to KDS.
            //
            // OrderStateMachine::allows(ACCEPT, PREPARING) is true (line 45
            // of OrderStateMachine.php) — we use the historical
            // `$locked->status = PREPARING; ->save();` pattern documented at
            // line 21-23 of OrderStateMachine.php for legacy frozen-zone
            // call sites. recordTransition is emitted after save() to keep
            // the audit trail per CLAUDE.md §8.
            $prePaidStatus = (int) $locked->status;
            if ($prePaidStatus === OrderStatus::ACCEPT
                && AutoPrepareOnPaidPolicy::shouldPromote(
                    surface: (string) ($locked->source_surface ?? 'kiosk'),
                    posPaymentMethod: $mode,
                    isCounterCollect: true,
                )
            ) {
                $locked->status = AutoPrepareOnPaidPolicy::nextStatus();
            }

            $locked->save();

            if ($prePaidStatus === OrderStatus::ACCEPT
                && (int) $locked->status === OrderStatus::PREPARING) {
                OrderStateMachine::recordTransition(
                    \App\Models\Order::class,
                    (int) $locked->id,
                    OrderStatus::ACCEPT,
                    OrderStatus::PREPARING,
                    Auth::check() ? (int) Auth::id() : null,
                    'auto_prepare_on_paid (Wave S-1 counter-collect)',
                );
            }

            Transaction::query()->firstOrCreate(
                [
                    'order_id' => $locked->id,
                    'type' => 'payment',
                ],
                [
                    'transaction_no' => 'COUNTER-'.$locked->id.'-'.now()->format('YmdHis'),
                    'amount' => $locked->total,
                    'payment_method' => $this->counterPaymentMethodLabel($mode),
                    'sign' => '+',
                ]
            );

            app(AuditLogService::class)->write([
                'branch_id' => (int) $locked->branch_id,
                'user_id' => Auth::check() ? (int) Auth::id() : null,
                'action' => 'order.counter_payment_confirmed',
                'resource' => 'order',
                'resource_id' => (int) $locked->id,
                'payload' => [
                    'payment_method' => $mode,
                    'payment_status' => PaymentStatus::PAID,
                    'received' => $received,
                    'fiscal_sequence_no' => $locked->fiscal_sequence_no,
                ],
            ]);

            $locked->refresh();
            $order->setRawAttributes($locked->getAttributes(), true);
            $paid = true;
        });

        if ($paid) {
            OrderPaidAtCounter::dispatch($order, $mode);

            // [SELF-AUDIT R2 P3 2026-07-05 — table dine-in jamais libérée en encaissement différé]
            // tryReleaseTableAfterPosOrderPaid n'était appelée QUE sur le chemin synchrone create-and-pay
            // (OrderService::posOrderStore) — no-op pour les commandes différées (PENDING_COUNTER). Une
            // commande DINING_TABLE en walkin_route_to_counter=true est différée puis scellée ICI → sans
            // cet appel, la table restait « occupied » à vie. La méthode s'auto-garde (DINING_TABLE + PAID),
            // donc l'appel est sûr pour tous les modes ; symétrie avec le chemin inline.
            try {
                app(\App\Services\DiningTableService::class)->tryReleaseTableAfterPosOrderPaid($order);
            } catch (\Throwable $e) {
                Log::warning('[SELF-AUDIT R2 P3] table release on counter-collect failed', [
                    'order_id' => $order->id, 'error' => $e->getMessage(),
                ]);
            }

            // [Wave S-1 — P-OWNER 2026-05-20] When the auto-prepare policy
            // promoted the locked row to PREPARING inside the transaction,
            // surface the ACCEPT→PREPARING transition on the OrderStatusChanged
            // bus so the realtime Suivi / KDS UIs see the status flip without
            // waiting for a poll cycle. The OrderPaidAtCounter listener
            // encodes payment_status + fiscal_sequence_no but NOT status, so
            // a dedicated OrderStatusChanged broadcast is the canonical
            // signal for the new column movement. Best-effort try/catch
            // mirrors the existing cancel path (line 497) so a Pusher hiccup
            // never escalates to an HTTP 5xx for the cashier.
            if ((int) $order->status === OrderStatus::PREPARING) {
                try {
                    OrderStatusChanged::dispatch(
                        $order,
                        OrderStatus::ACCEPT,
                        OrderStatus::PREPARING
                    );
                } catch (\Throwable $e) {
                    Log::warning('[PaymentService] OrderStatusChanged broadcast (auto-prepare Wave S-1) failed: '.$e->getMessage(), [
                        'order_id' => (int) $order->id,
                        'mode' => $mode,
                    ]);
                }
            }

            // [AUDIT-F-003] Cash drawer movement hook — best-effort.
            // Si une session caisse OPEN existe pour le caissier sur la branch,
            // on enregistre le mouvement order_payment direction=in. Si aucune
            // session n'est ouverte (legacy ou avant rollout cash sessions),
            // log warning + continue — l'order reste valide, l'audit comptable
            // sera fait post-hoc via reconciliation.
            if ($mode === PosPaymentMethod::CASH) {
                $this->recordCashOrderMovement($order, $note);
            }
        }

        return $order;
    }

    /**
     * [AUDIT-F-003 + Sprint 1B 2026-05-16] Enregistre un movement cash IN sur
     * la session OPEN du caissier.
     *
     * Deux modes :
     *   - $strict = false (legacy, kiosk counter-collect) — best-effort,
     *     log + return si pas de session OPEN. NF525 fiscalement loose
     *     mais préserve la backward-compat kiosk takeaway.
     *   - $strict = true (Sprint 1B, POS direct + split CASH) — throw
     *     `CashDrawerSessionNotOpenException` (422) si pas de session.
     *     L'order est rollback par la transaction parente : 0 order,
     *     0 movement, 0 audit ; le caissier doit ouvrir sa session
     *     avant de pouvoir vendre cash.
     *
     * `$amountOverride` permet d'écrire un movement multi-tender (tranche
     * cash seule, pas le total order). Si null → fallback `$order->total`
     * (legacy single-tender path).
     *
     * Public depuis Sprint 1B pour être appelable depuis `OrderService`
     * sans réflexion (SplitPaymentService passe par CashDrawerService
     * directement pour les tranches).
     */
    public function recordCashOrderMovement(
        Order $order,
        ?string $note = null,
        bool $strict = false,
        ?float $amountOverride = null,
    ): void {
        // [2026-05-18] Hardware simulation: downgrade strict→soft when the
        // physical drawer is not connected. NF525 invariants unchanged.
        if ($strict && config('pos.simulation_hardware') === true) {
            $strict = false;
        }
        try {
            if (! Auth::check()) {
                if ($strict) {
                    throw new \App\Exceptions\CashDrawerSessionNotOpenException;
                }
                $this->flagCashMovementSkipped($order);

                return;
            }
            $userId = (int) Auth::id();
            $branchId = (int) ($order->branch_id ?? 0);
            if ($branchId <= 0) {
                if ($strict) {
                    throw new \App\Exceptions\CashDrawerSessionNotOpenException;
                }
                $this->flagCashMovementSkipped($order);

                return;
            }

            $cashService = app(CashDrawerService::class);
            $session = $cashService->findOpenSessionForUser($branchId, $userId);

            if (! $session) {
                if ($strict) {
                    Log::warning('[Sprint 1B] POS cash sale blocked — no open cash drawer session', [
                        'order_id' => $order->id,
                        'branch_id' => $branchId,
                        'user_id' => $userId,
                    ]);
                    throw new \App\Exceptions\CashDrawerSessionNotOpenException;
                }
                Log::info('[F-003] No open cash drawer session — order paid cash without session linkage', [
                    'order_id' => $order->id,
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                ]);
                $this->flagCashMovementSkipped($order);

                return;
            }

            $amount = $amountOverride !== null
                ? round((float) $amountOverride, 2)
                : round((float) $order->total, 2);

            $cashService->recordMovement(
                sessionId: (int) $session->id,
                type: \App\Models\CashMovement::TYPE_ORDER_PAYMENT,
                amount: $amount,
                direction: \App\Models\CashMovement::DIRECTION_IN,
                orderId: (int) $order->id,
                notes: $note,
                strict: $strict,
            );
        } catch (\App\Exceptions\CashDrawerSessionNotOpenException $e) {
            // Re-throw : doit remonter pour rollback la transaction order.
            throw $e;
        } catch (\Throwable $e) {
            if ($strict) {
                throw $e;
            }
            Log::warning('[F-003] recordCashOrderMovement failed (non-blocking)', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * [TRAP-3 2026-06-04] Surface the cash-trail gap to the cashier instead
     * of swallowing it in a cron log.
     *
     * On the now-PRIMARY counter-collect CASH path (kiosk Plan-B + walk-in),
     * the cash_movement is best-effort: if there is no open drawer session,
     * the order still goes PAID + fiscal-seq allocated, but NO cash_movement
     * row is written → end-of-day reconciliation silently under-counts.
     *
     * We DO NOT block the sale (NF525-safe: the fiscal trail is untouched).
     * Instead we set a TRANSIENT (non-persisted) attribute on the in-memory
     * order instance so:
     *   - the HTTP layer (OrderDetailsResource) can return
     *     `cash_movement_skipped: true` + a FR warning message, and
     *   - the encaissement modal can show the cashier an explicit warning
     *     toast ("Aucune session caisse ouverte — mouvement non enregistré")
     *     rather than a plain success toast.
     *
     * The attribute is set via setAttribute on the runtime model only; it is
     * never written to the DB (no `cash_movement_skipped` column exists), so
     * no migration / schema change is required and the persisted fiscal state
     * is unchanged.
     */
    private function flagCashMovementSkipped(Order $order): void
    {
        $order->cash_movement_skipped = true;
    }

    /**
     * [AUDIT-F-003] Hook side-effect : enregistre le cashback comme movement
     * direction=out sur la session OPEN du caissier (si elle existe). Best-effort.
     */
    /**
     * [SELF-AUDIT R2 P2 2026-07-05] Point d'entrée PUBLIC pour poser la sortie tiroir compensatoire d'un
     * remboursement dont la commande n'a PAS de ligne Transaction (vente POS cash DIRECTE / counter-collect
     * single-tender) : le chemin cashBack() est gardé sur $order->transaction et saute ces ventes, laissant
     * un trou au rapprochement tiroir. Réutilise l'unique writer recordCashBackMovement (DRY, même
     * CASHBACK/OUT + session ouverte courante + best-effort).
     */
    public function recordCashRefundMovement(Order $order, float $amount): void
    {
        $this->recordCashBackMovement($order, $amount);
    }

    private function recordCashBackMovement(Order $order, float $amount): void
    {
        try {
            if (! Auth::check()) {
                return;
            }
            $userId = (int) Auth::id();
            $branchId = (int) ($order->branch_id ?? 0);
            if ($branchId <= 0) {
                return;
            }

            $cashService = app(CashDrawerService::class);
            $session = $cashService->findOpenSessionForUser($branchId, $userId);

            if (! $session) {
                Log::info('[F-003] No open cash drawer session — cashback without session linkage', [
                    'order_id' => $order->id,
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                ]);

                return;
            }

            $cashService->recordMovement(
                sessionId: (int) $session->id,
                type: \App\Models\CashMovement::TYPE_CASHBACK,
                amount: round($amount, 2),
                direction: \App\Models\CashMovement::DIRECTION_OUT,
                orderId: (int) $order->id,
            );
        } catch (\Throwable $e) {
            Log::warning('[F-003] recordCashBackMovement failed (non-blocking)', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function cancelCounterPayment(Order $order, ?string $reason = null): Order
    {
        $oldStatus = null;
        $canceled = false;

        DB::transaction(function () use ($order, $reason, &$oldStatus, &$canceled): void {
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCounterOrderVisible($locked);

            if ((int) $locked->payment_status === PaymentStatus::REFUNDED) {
                $order->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            $this->assertCounterDeferredOrder($locked);
            PaymentStateMachine::assertCanTransition((int) $locked->payment_status, PaymentStatus::REFUNDED);

            // [GOAL-2026-05-30 WD1-03] COMPENSATING ACTION (intentional, like recall/refund):
            // the cashier cancels an abandoned counter-deferred order. Since W-D1 the chef may have
            // already bumped it to PREPARING/PREPARED before the cashier cancels, so $oldStatus can
            // be PREPARED — a transition OrderStateMachine::allows() would reject for the NORMAL path.
            // We deliberately bypass allows() (recordTransition just appends the business-events
            // journal row, NOT the HMAC fiscal chain) because an out-of-band cancel is a legitimate
            // compensating action regardless of kitchen progress (owner accepts the food-waste risk
            // of prep-before-pay). NF525: no fiscal-seq was allocated (never collected), so nothing
            // enters the signed Z.
            $oldStatus = (int) $locked->status;

            // [C4-CAISSE-TELEPHONE FIX-1 / P2 2026-07-07] Symétrie remboursement fidélité sur
            // l'annulation INTERACTIVE d'une commande différée (borne Plan B / téléphone / walk-in).
            // Une commande différée créée avec un loyalty_code débite les points à la création
            // (LoyaltyTransaction type=redeem, points<0). Les chemins d'annulation « normaux »
            // (OrderService::changeStatus:2160,2324 + FrontendOrderService::changeStatus:782 + le
            // cron de purge CleanupStalePendingKioskOrders) remboursent déjà via
            // LoyaltyService::refundPoints, mais CE chemin counter-collect cancel ne le faisait PAS
            // → les points redeem d'une commande différée annulée au comptoir étaient BRÛLÉS
            // définitivement (même forme d'asymétrie que C36, sur le chemin interactif cette fois).
            // On rembourse ICI, sur la commande verrouillée, DANS la même transaction que le save
            // REFUNDED, avant la mutation terminale — exactement comme changeStatus. refundPoints
            // est idempotent + no-op : return immédiat si loyalty_customer_code est nul (commande
            // sans fidélité), si aucune ligne redeem n'existe, ou si le remboursement (manual_add)
            // a déjà été écrit → zéro régression sur les commandes sans fidélité, pas de double-
            // crédit sur un rejeu. source_surface='kiosk' garde son canal, tout le reste ('pos',
            // 'phone', null) tombe sur 'pos' (aligné OrderService::changeStatus).
            app(LoyaltyService::class)->refundPoints(
                $locked,
                (string) $locked->source_surface === 'kiosk' ? 'kiosk' : 'pos'
            );

            $locked->payment_status = PaymentStatus::REFUNDED;
            $locked->status = OrderStatus::CANCELED;
            $locked->pos_payment_note = $reason;
            $locked->save();

            OrderStateMachine::recordTransition(
                Order::class,
                (int) $locked->id,
                $oldStatus,
                OrderStatus::CANCELED,
                Auth::check() ? (int) Auth::id() : null,
                $reason
            );

            app(AuditLogService::class)->write([
                'branch_id' => (int) $locked->branch_id,
                'user_id' => Auth::check() ? (int) Auth::id() : null,
                'action' => 'order.counter_payment_canceled',
                'resource' => 'order',
                'resource_id' => (int) $locked->id,
                'payload' => [
                    'payment_status' => PaymentStatus::REFUNDED,
                    'reason' => $reason,
                    'fiscal_sequence_no' => $locked->fiscal_sequence_no,
                ],
            ]);

            $locked->refresh();
            $order->setRawAttributes($locked->getAttributes(), true);
            $canceled = true;
        });

        if ($canceled) {
            OrderCanceled::dispatch($order);
            OrderStatusChanged::dispatch($order, $oldStatus, OrderStatus::CANCELED);
        }

        return $order;
    }

    private function assertCounterOrderVisible(Order $order): void
    {
        $actorBranchId = Auth::check() ? (int) (Auth::user()?->branch_id ?? 0) : 0;
        if ($actorBranchId > 0 && (int) $order->branch_id !== $actorBranchId) {
            throw new HttpException(403, 'Commande hors branche.');
        }
    }

    private function assertCounterDeferredOrder(Order $order): void
    {
        // [GOAL-CAISSE-UNIFIED delta-(B) 2026-05-30] Accept BOTH origins of a
        // counter-deferred order: Borne (kiosk Plan B) AND Caisse walk-in routed
        // through pos.walkin_route_to_counter. The canonical deferred signal is
        // the marker TRIPLE (CASH_ON_DELIVERY + COUNTER_DEFERRED + PENDING_COUNTER,
        // the latter checked by the caller's PaymentStateMachine guard), set
        // identically at creation by FrontendOrderService (kiosk) and
        // OrderService::posOrderStore (pos). source_surface is restricted to the
        // two collection origins so a regular paid POS/online order can never be
        // routed through the counter-collect seal.
        // [C4-CAISSE-TELEPHONE 2026-07-07] 'phone' rejoint 'kiosk'/'pos' : une commande téléphone
        // est une 3e origine LÉGITIME de counter-collect (créée différée par OrderService::posOrderStore
        // avec le MÊME marqueur triple). Sans 'phone' ici, confirmCounterPayment rejetterait 422 la
        // commande téléphone → INENCAISSABLE (garde asymétrique). Miroir strict de la clause ajoutée
        // dans la file /counter-collect/pending (routes/api.php).
        $surface = (string) ($order->source_surface ?? '');
        $isCounterDeferred = in_array($surface, ['kiosk', 'pos', 'phone'], true)
            && (int) $order->payment_method === \App\Enums\PaymentGateway::CASH_ON_DELIVERY
            && (int) $order->pos_payment_method === PosPaymentMethod::COUNTER_DEFERRED;

        if (! $isCounterDeferred) {
            throw new \InvalidArgumentException('This order is not a pending counter payment.', 422);
        }
    }

    private function counterPaymentMethodLabel(int $mode): string
    {
        return match ($mode) {
            PosPaymentMethod::CASH => 'counter_cash',
            PosPaymentMethod::CARD => 'counter_card',
            PosPaymentMethod::MOBILE_BANKING => 'counter_mobile_banking',
            PosPaymentMethod::TICKET_RESTAURANT => 'counter_ticket_restaurant',
            default => 'counter_other',
        };
    }

    public function isPilotPaymentMethodAllowed(string $gatewaySlug): bool
    {
        if (! (bool) config('payment.pilot_restrict.enabled', true)) {
            return true;
        }

        $method = $this->normalizePaymentMethod($gatewaySlug);
        $allowed = array_map(
            fn ($value) => $this->normalizePaymentMethod((string) $value),
            (array) config('payment.pilot_restrict.allowed_methods', ['credit'])
        );

        return in_array($method, array_values(array_unique($allowed)), true);
    }

    public function assertPilotPaymentMethodAllowed($order, string $gatewaySlug, string $attemptType = 'payment'): void
    {
        if ($this->isPilotPaymentMethodAllowed($gatewaySlug)) {
            return;
        }

        $method = $this->normalizePaymentMethod($gatewaySlug);
        $this->auditRestrictedAttempt($order, $method, $attemptType);

        throw ValidationException::withMessages([
            'payment_method' => sprintf(
                'Payment method "%s" is not available in the restricted payment pilot.',
                $method
            ),
        ]);
    }

    private function auditRestrictedAttempt($order, string $method, string $attemptType): void
    {
        try {
            app(AuditLogService::class)->write([
                'branch_id' => (int) ($order->branch_id ?? 0),
                'user_id' => Auth::check() ? (int) Auth::id() : null,
                'action' => (string) config('payment.pilot_restrict.audit_action', 'payment.method_restricted'),
                'resource' => 'order',
                'resource_id' => (int) ($order->id ?? 0),
                'payload' => [
                    'attempt_type' => $attemptType,
                    'blocked_method' => $method,
                    'reason' => 'restricted_payment_pilot',
                    'allowed_methods' => array_values((array) config('payment.pilot_restrict.allowed_methods', ['credit'])),
                    'actor_id' => Auth::check() ? (int) Auth::id() : null,
                    'actor_branch_id' => Auth::check() ? (int) (Auth::user()?->branch_id ?? 0) : null,
                    'order_branch_id' => (int) ($order->branch_id ?? 0),
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('payment.method_restricted_audit_failed', [
                'order_id' => (int) ($order->id ?? 0),
                'branch_id' => (int) ($order->branch_id ?? 0),
                'method' => $method,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function normalizePaymentMethod(string $gatewaySlug): string
    {
        return strtolower(trim($gatewaySlug));
    }

    /**
     * [P0-POS-02 GOAL round-2 2026-05-18] Enforce gateway-callback context
     * for `PaymentService::payment()`.
     *
     * Walks the backtrace and confirms at least one calling frame is a
     * subclass of `\App\Services\PaymentAbstract`. The only legitimate
     * callers of `payment()` are the gateway `success()` callbacks
     * (Stripe::success, Credit::success, PayPal::success) — those all
     * extend `PaymentAbstract`. A direct call from a controller, job, or
     * console command will not have any `PaymentAbstract` frame above
     * and will be rejected with HTTP 403.
     *
     * This is a defense-in-depth guard, not a replacement for HTTP-layer
     * authz: the public-facing payment routes are already CSRF + gateway
     * signature protected. The guard exists to prevent a future caller
     * (queue retry, admin action, future SDK) from forging a paid state.
     *
     * @throws HttpException 403 when called outside a PaymentAbstract context.
     */
    private function assertGatewayContext(): void
    {
        // Allow tests + console commands that explicitly opt in by setting
        // a runtime flag. Cleared on every assert so a leak across tests
        // is impossible (each call has to re-set the flag).
        if (app()->bound('payment.service.allow_direct_call')
            && app('payment.service.allow_direct_call') === true) {
            app()->forgetInstance('payment.service.allow_direct_call');

            return;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
        foreach ($trace as $frame) {
            $cls = $frame['class'] ?? null;
            if ($cls === null || $cls === self::class) {
                continue;
            }
            if (is_subclass_of($cls, \App\Services\PaymentAbstract::class)) {
                return;
            }
        }

        Log::warning('[P0-POS-02] PaymentService::payment called outside gateway context — rejected', [
            'top_caller_class' => $trace[1]['class'] ?? null,
            'top_caller_func' => $trace[1]['function'] ?? null,
        ]);

        throw new HttpException(
            403,
            'PaymentService::payment() can only be invoked from a gateway callback.'
        );
    }
}
