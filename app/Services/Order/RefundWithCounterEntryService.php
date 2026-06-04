<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\RefundCreated;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Services\Cash\CashDrawerService;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * [P11-FZH / F-VERIFY-08-02]
 *
 * Creates a "mirror" RETURN_OF order — the formal NF525 counter-entry
 * for a sealed (post-Z) refund. The mirror is created in the CURRENT Z
 * window so ZReportService::aggregate() picks it up via standard query
 * (same accounting outcome as the post-Z negative-adjustment legacy
 * path) — but the parent order stays IMMUTABLE (NF525-compliant).
 *
 * Mirror order properties:
 *  - same branch_id, customer_id, order_type as parent
 *  - parent_order_id = parent.id (FK self-ref, see migration)
 *  - status = RETURNED, payment_status = REFUNDED
 *  - total / subtotal / total_tax NEGATED (× -1)
 *  - fresh fiscal_sequence_no (allocated atomically)
 *  - duplicated order_items with quantity × -1 and tax_amount × -1
 *  - audit_log 'order.refund.counter_entry' with full forensic payload
 */
class RefundWithCounterEntryService
{
    private ConnectionInterface $connection;

    public function __construct(
        private readonly FiscalSequenceService $sequence,
        private readonly AuditLogService $audit,
        ?ConnectionInterface $connection = null,
    ) {
        $this->connection = $connection ?? DB::connection();
    }

    /**
     * @throws InvalidArgumentException 422 when parent is not refundable
     */
    public function execute(Order $parent, string $reason, ?int $userId = null): Order
    {
        if ($parent->fiscal_sequence_no === null) {
            throw new InvalidArgumentException(
                'Parent order has no fiscal_sequence_no — use the standard pre-Z RETURNED path instead.',
                422
            );
        }

        // [WAVE5-POS-001] Require parent to be in a CLOSED Z window before creating
        // a counter-entry mirror. Without this, an order created in the still-open
        // current Z would receive a mirror immediately, AND a separate cashier
        // could subsequently transition the still-mutable parent to RETURNED via
        // the standard pre-Z path — yielding two negatives for one sale in the
        // same Z window (double-counted refund). The standard pre-Z RETURNED path
        // is what should be used for orders inside the open Z. The guard reuses
        // SealedOrderGuard's predicate, keeping a single source of truth for
        // "sealed?" semantics across destroy / changeStatus / aggregate / refund.
        app(\App\Services\Order\SealedOrderGuard::class)
            ->assertSealed($parent, 'refund-with-counter-entry');

        // [HEAL-A.4 verdict 2026-05-19 / Z8 P0-1] Defense-in-depth above the
        // UNIQUE(orders.parent_order_id) constraint introduced by migration
        // 2026_05_19_200000 (heal A.3). This predicate fires ONLY when an
        // out-of-band process has flipped parent.status=RETURNED (admin
        // tooling, console script, stale-state migration) — the normal NF525
        // counter-entry path itself NEVER mutates parent.status (the parent
        // stays IMMUTABLE; the mirror carries RETURNED). Primary uniqueness
        // is now enforced at DB level; this guard remains belt-and-suspenders
        // for the status-flip case covered by RefundMirrorSplitPaymentTest:189
        // and RefundCounterEntryRequiresSealedParentSentinelTest:115.
        if ((int) $parent->status === OrderStatus::RETURNED) {
            throw new InvalidArgumentException(
                'Parent order is already RETURNED — refusing duplicate mirror.',
                422
            );
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('reason is required for refund-with-counter-entry.', 422);
        }

        $userId = $userId ?? (Auth::check() ? (int) Auth::id() : null);
        $branchId = (int) $parent->branch_id;

        return $this->connection->transaction(function () use ($parent, $reason, $userId, $branchId) {
            // 1) Reserve fresh fiscal sequence in current Z window.
            $mirrorSeq = $this->sequence->next($branchId);

            // 2) Create mirror order with NEGATED financial fields.
            //    fillable contains parent_order_id (added in migration).
            //    Other "non-fillable" fields are set via property assignment then save().
            $mirror = Order::create([
                'branch_id'        => $branchId,
                'user_id'          => $parent->user_id,
                'order_type'       => $parent->order_type,
                'parent_order_id'  => $parent->id,
                'status'           => OrderStatus::RETURNED,
                'payment_status'   => PaymentStatus::REFUNDED,
                'subtotal'         => -1 * (float) ($parent->subtotal ?? 0),
                'total_tax'        => -1 * (float) ($parent->total_tax ?? 0),
                'total'            => -1 * (float) ($parent->total ?? 0),
                'discount'         => 0,
                'order_serial_no'  => 'RTN-' . ($parent->order_serial_no ?? $parent->id),
                'order_datetime'   => date('Y-m-d H:i:s'),
                'preparation_time' => 0,
                'pos_payment_method' => $parent->pos_payment_method,
                'payment_method'   => $parent->payment_method,
                'source_surface'   => $parent->source_surface ?? 'pos',
            ]);

            // 3) Set fiscal_sequence_no + reason via property + save (not in fillable).
            $mirror->fiscal_sequence_no = $mirrorSeq;
            $mirror->reason = $reason;
            $mirror->save();

            // [LOCK_ZREPORT_REFUND_DISCOUNT_TVA_NETTING 2026-06-01 — ZRPT-SEM-01]
            // Replicate ZReportService::orderDiscountRatio for the PARENT so the mirror
            // reverses the parent's POST-discount (net) per-rate TVA, not the full
            // pre-discount TVA. The Z scales the parent's per-line tax by parentRatio,
            // but the mirror's negative subtotal makes orderDiscountRatio(mirror)=1.0,
            // so without pre-scaling the mirror would over-reverse TVA by tax×(1−ratio),
            // making the signed Z understate per-rate TVA across the close/refund windows.
            // Non-discounted orders → parentRatio = 1.0 → byte-identical to prior behavior.
            $parentSubtotal = (float) ($parent->subtotal ?? 0);
            $parentDiscount = (float) ($parent->discount ?? 0);
            $parentRatio = ($parentDiscount > 0.0 && $parentSubtotal > 0.0)
                ? max(0.0, min(1.0, ($parentSubtotal - $parentDiscount) / $parentSubtotal))
                : 1.0;

            // 4) Duplicate order_items with negated qty + (discount-netted) tax.
            $parent->loadMissing('orderItems');
            foreach ($parent->orderItems as $item) {
                /** @var OrderItem $item */
                OrderItem::create([
                    'order_id'             => $mirror->id,
                    'branch_id'            => $branchId,
                    'item_id'              => $item->item_id,
                    'quantity'             => -1 * (int) $item->quantity,
                    'discount'             => $item->discount,
                    'tax_name'             => $item->tax_name,
                    'tax_rate'             => $item->tax_rate,
                    'tax_type'             => $item->tax_type,
                    'tax_amount'           => -1 * (float) ($item->tax_amount ?? 0) * $parentRatio,
                    'price'                => $item->price,
                    'item_variations'      => $item->item_variations,
                    'item_extras'          => $item->item_extras,
                    'composition_snapshot' => $item->composition_snapshot,
                    'item_variation_total' => $item->item_variation_total,
                    'item_extra_total'     => $item->item_extra_total,
                    'total_price'          => -1 * (float) ($item->total_price ?? 0),
                    'instruction'          => 'RTN: ' . ($item->instruction ?? ''),
                    'allergens_snapshot'   => $item->allergens_snapshot,
                ]);
            }

            // 4-bis) [iter15-P0-10] Mirror split-payment tranches with NEGATED amounts.
            //
            // Pre-fix the mirror Order had negated total/subtotal/tax + items but
            // ZERO order_payments rows. Z reconciliation aggregates per-mode cash
            // via order_payments → split-payment refunds were under-credited
            // (cash mode kept its full positive aggregate; the refund only
            // hit the synthetic legacy `pos_payment_method` field which Z does
            // not break out per-tranche).
            //
            // Fix: for each parent OrderPayment, create a mirror OrderPayment
            // with `amount` and `change_amount` negated, same `mode` + `tendered`,
            // reference suffixed `-REFUND`, and `paid_at = now()` so Z ranges
            // capture it in the current window. Inside the same DB::transaction
            // as the mirror Order — atomicity is preserved.
            //
            // BranchScope on OrderPayment: query parent payments WITHOUT global
            // scope so cross-branch refund tools (admin) still work in tests
            // where the test user's branch may differ from the parent's branch.
            // [Z6-P1-WGS 2026-05-19] singular form — OrderPayment has no
            // SoftDeletes; explicit BranchScope::class arg documents intent.
            $parentPayments = OrderPayment::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('order_id', $parent->id)
                ->get();

            foreach ($parentPayments as $payment) {
                /** @var OrderPayment $payment */
                // [Sprint H2 P1-Z7-01 2026-05-17] Carry forward terminal_id from the
                // parent payment being mirrored. Semantically the refund debits fees
                // through the SAME physical TPE that processed the original sale,
                // so Z-report TPE breakdown stays balanced (parent +N, mirror -N
                // on the same terminal bucket). NULL parent_id stays NULL on the
                // mirror — preserves legacy / COUNTER_DEFERRED contract.
                OrderPayment::create([
                    'order_id'      => $mirror->id,
                    'branch_id'     => $branchId,
                    'mode'          => (int) $payment->mode,
                    'terminal_id'   => $payment->terminal_id !== null
                        ? (int) $payment->terminal_id
                        : null,
                    'amount'        => -1 * (float) ($payment->amount ?? 0),
                    'tendered'      => $payment->tendered !== null
                        ? -1 * (float) $payment->tendered
                        : null,
                    'change_amount' => -1 * (float) ($payment->change_amount ?? 0),
                    'reference'     => $payment->reference !== null
                        ? ((string) $payment->reference) . '-REFUND'
                        : null,
                    'paid_at'       => now(),
                ]);
            }

            // 4-ter) [GOAL-K2-HEAL-07 2026-05-24] Phase K.5 NEW-2 P1
            //
            // Counter-entry path was skipping CashMovement on CASH refund.
            // The Z report still balanced via mirror order_payments (4-bis
            // above), and the NF525 audit chain captured the counter-entry
            // event, but the drawer-session reconciliation surface had NO
            // refund line. Result: `expected_closing_amount` silently
            // skewed by the refunded cash amount on every counter-entry
            // CASH refund — cashier reconciling at end of shift saw an
            // unexplained negative variance equal to the day's refunds.
            //
            // The pre-Z cashBack() path (PaymentService::cashBack →
            // recordCashBackMovement) already does this correctly. We
            // replicate the SAME pattern inline here (single call site
            // does not justify widening PaymentService's API).
            //
            // Why TYPE_CASHBACK + DIRECTION_OUT (not a new 'refund' type):
            //   1. CashMovement::TYPE_* is a closed enum (whitelisted by
            //      CashDrawerService::recordMovement at line 374-389).
            //      Adding a 'refund' type would require a migration +
            //      schema change + Z enrichment update — out of scope
            //      for a P1 reconciliation-surface fix.
            //   2. Semantically, "cash leaving the drawer to refund a
            //      customer" IS a cashback in NF525 terms — same fiscal
            //      treatment as PaymentService::cashBack uses.
            //   3. signedAmount() resolves DIRECTION_OUT to negative, so
            //      `expected = opening + Σ(signedAmount)` correctly
            //      subtracts the refund.
            //
            // Strict=false: graceful no-open-session case (cashier might
            // process a post-Z refund while their session is closed for
            // the day — drawer reconciliation gap surfaces in cron alerts
            // rather than blocking the refund). Mirrors I.1 silent-fail
            // observability discipline.
            //
            // Attach to mirror->id: the cash_movement row points to the
            // formal NF525 RETURN_OF entity (not the immutable parent).
            // Reconciliation queries follow `cash_movements.order_id`
            // to the mirror — unambiguous "refund line" semantics.
            $cashService = app(CashDrawerService::class);
            foreach ($parentPayments as $payment) {
                /** @var OrderPayment $payment */
                if ((int) $payment->mode !== PosPaymentMethod::CASH) {
                    continue;
                }

                try {
                    if (! Auth::check()) {
                        Log::info('[GOAL-K2-HEAL-07] cash_movement skipped — no auth context', [
                            'parent_order_id' => $parent->id,
                            'mirror_order_id' => $mirror->id,
                            'payment_id'      => $payment->id,
                        ]);
                        continue;
                    }

                    $session = $cashService->findOpenSessionForUser($branchId, (int) Auth::id());
                    if (! $session) {
                        Log::info('[GOAL-K2-HEAL-07] cash_movement skipped — no open cash drawer session', [
                            'parent_order_id' => $parent->id,
                            'mirror_order_id' => $mirror->id,
                            'payment_id'      => $payment->id,
                            'branch_id'       => $branchId,
                            'user_id'         => Auth::id(),
                        ]);
                        continue;
                    }

                    $cashService->recordMovement(
                        sessionId: (int) $session->id,
                        type: CashMovement::TYPE_CASHBACK,
                        amount: round((float) ($payment->amount ?? 0), 2),
                        direction: CashMovement::DIRECTION_OUT,
                        orderId: (int) $mirror->id,
                        notes: 'RTN: refund counter-entry',
                        strict: false,
                    );
                } catch (\Throwable $e) {
                    // Best-effort: cash_movement failure MUST NOT abort the
                    // counter-entry transaction. The Z chain still records the
                    // refund via mirror order_payments (4-bis), and the drawer
                    // reconciliation gap surfaces in cron alerts.
                    Log::warning('[GOAL-K2-HEAL-07] cash_movement record failed', [
                        'parent_order_id' => $parent->id,
                        'mirror_order_id' => $mirror->id,
                        'payment_id'      => $payment->id,
                        'error'           => $e->getMessage(),
                    ]);
                }
            }

            // 5) Audit trail — immutable forensic link parent ↔ mirror.
            $this->audit->write([
                'branch_id'   => $branchId,
                'user_id'     => $userId,
                'action'      => 'order.refund.counter_entry',
                'resource'    => 'order',
                'resource_id' => (int) $mirror->id,
                'payload'     => [
                    'parent_order_id'           => (int) $parent->id,
                    'parent_serial_no'          => (string) ($parent->order_serial_no ?? ''),
                    'parent_fiscal_sequence_no' => (int) $parent->fiscal_sequence_no,
                    'mirror_fiscal_sequence_no' => (int) $mirrorSeq,
                    'mirror_total'              => round(-1 * (float) ($parent->total ?? 0), 2),
                    'mirror_payments_count'     => $parentPayments->count(),
                    'reason'                    => $reason,
                ],
            ]);

            try {
                Log::channel('fiscal')->info('order.refund.counter_entry', [
                    'parent_order_id' => $parent->id,
                    'mirror_order_id' => $mirror->id,
                    'branch_id'       => $branchId,
                    'user_id'         => $userId,
                    'mirror_total'    => $mirror->total,
                ]);
            } catch (\Throwable) {
                // best-effort log
            }

            // [WG-1-WF6-P1-2 heal/cms-pr1-quickwins-2026-05-18]
            // Companion loyalty-points reversal. The 3 cashBack() callers in
            // OrderService / FrontendOrderService correctly call refundPoints
            // RIGHT AFTER cashBack (app/Services/OrderService.php:1702, :1805
            // and app/Services/FrontendOrderService.php:707) — but
            // PosOrderController::refundWithCounterEntry (sole caller of this
            // service) did NOT. Without this, a customer who redeemed loyalty
            // points and later receives a post-Z refund loses BOTH cash AND
            // points (cash refunded via mirror order, points NEVER credited
            // back). refundPoints() is no-op-safe when loyalty_customer_code
            // is null — unconditional call is correct.
            //
            // Placement: inside the DB::transaction so the loyalty reversal
            // ledger row + the customer's loyalty_points re-credit are atomic
            // with the mirror order + mirror payments + audit row. A failure
            // in any leg rolls them all back together — same atomicity
            // guarantee as the pre-Z cash refund path.
            //
            // [GOAL-K2-HEAL-03 2026-05-24] Phase K.10 K10-NEW-01 P2 RED:
            // refundPoints() was called IN-TX with NO try/catch wrapper. The
            // HEAL-A.2 NOOP at LoyaltyService.php:56+ handled only the
            // alreadyRefunded duplicate case and the customer-deleted
            // silent-return case (line 47-54); any OTHER throw (DB blip
            // during increment, UNIQUE collision race, lockForUpdate timeout,
            // etc.) cascaded and rolled back the ENTIRE mirror refund
            // INCLUDING the fiscal_sequence_no allocation reserved at line
            // 100 via $this->sequence->next($branchId). Fail-closed UX:
            // cashier sees error → MUST retry → meanwhile customer waits.
            //
            // Fix: wrap in try/catch + Log::error tier matching the
            // ClawbackLoyaltyPointsOnRefund.php:82-93 cascade-isolation
            // pattern (HEAL-A.2 / I2-HEAL-01). Loyalty side-effect failures
            // MUST NOT halt the fiscal refund. If loyalty rollback fails,
            // admin can manually adjust the user balance post-incident
            // (loyalty is non-fiscal; only NF525 chain integrity is
            // criminally-binding).
            //
            // Spec deviation note: GOAL-K2-HEAL-03 task description also
            // proposed dispatching OutboxBroadcastSwallowedEvent. That event
            // is NF525 pager-grade — it routes through
            // EscalateOutboxBroadcastSwallowed to Log::critical on the
            // `fiscal` channel (the operator's nightly grep stream, 400d
            // retention). Loyalty hiccups are non-fiscal; mis-routing them
            // there would generate false pager alarms AND breach the
            // OutboxBroadcastSwallowedListenerTest payload-shape invariant
            // (the event's 7 required readonly props are outbox-specific:
            // domainEventId / eventType / aggregateId / branchId / listener /
            // errorMessage / failedAt). Following the spec's explicitly-
            // referenced sibling pattern (ClawbackLoyaltyPointsOnRefund
            // :82-93) which is Log-only. If typed loyalty observability is
            // wanted later, introduce a dedicated LoyaltyRefundSwallowedEvent
            // — deferred V1.0.2 backlog.
            try {
                app(\App\Services\LoyaltyService::class)->refundPoints($parent, 'pos');
            } catch (\Throwable $loyaltyException) {
                Log::error('[RefundWithCounterEntryService] loyalty refundPoints failed (isolated, fiscal refund preserved)', [
                    'gate'             => 'GOAL-K2-HEAL-03',
                    'parent_order_id'  => $parent->id,
                    'mirror_order_id'  => $mirror->id,
                    'branch_id'        => $branchId,
                    'user_id'          => $userId,
                    'error'            => $loyaltyException->getMessage(),
                    'exception'        => $loyaltyException,
                ]);
            }

            // [REFUND-EVENT-WIRE] Fire RefundCreated for stock + availability release.
            // Pass PARENT (positive qty) — listeners iterate $order->orderItems and use
            // qty as a positive release amount; the mirror order has NEGATED qty which
            // would no-op / corrupt the released_qty ledger. DispatchableAfterCommit
            // ensures the event fires only after this DB::transaction commits.
            RefundCreated::dispatch($parent);

            return $mirror->refresh();
        });
    }
}
