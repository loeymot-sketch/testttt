<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
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

            // 4) Duplicate order_items with negated qty + tax.
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
                    'tax_amount'           => -1 * (float) ($item->tax_amount ?? 0),
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
            $parentPayments = OrderPayment::withoutGlobalScopes()
                ->where('order_id', $parent->id)
                ->get();

            foreach ($parentPayments as $payment) {
                /** @var OrderPayment $payment */
                OrderPayment::create([
                    'order_id'      => $mirror->id,
                    'branch_id'     => $branchId,
                    'mode'          => (int) $payment->mode,
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

            return $mirror->refresh();
        });
    }
}
