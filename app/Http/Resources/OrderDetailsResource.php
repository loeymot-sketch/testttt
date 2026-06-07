<?php

namespace App\Http\Resources;

use App\Enums\Ask;
use App\Libraries\AppLibrary;
use App\Services\Receipt\ReceiptDataService;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function toArray($request): array
    {
        // [NF525 RECEIPT WIRE-IN 2026-05-18] ReceiptDataService is the
        // SSOT for the six fiscal/operator header fields used on the
        // printed POS ticket. Delegating here keeps the HTTP resource
        // and the JS-side receipt builder converged on a single source
        // of truth — see ReceiptDataServiceWireInTest for the contract.
        $receipt = app(ReceiptDataService::class)->buildForOrderModel($this->resource);

        // [HEAL-H7 / CP-1 2026-06-07] Discount-netted per-rate buckets are the
        // SSOT for the printed ticket's TVA. Built ONCE here and reused for both
        // the `tax_lines` projection and the header so they can never drift. The
        // header total_tax = Σ tax_lines (= the signed Z's total_tva); the HT
        // base = post-discount HT (total − netted TVA), not subtotal − gross TVA.
        $taxLines = $this->buildTaxLines();
        $nettedTotalTax = round(array_sum(array_map(
            static fn ($l) => (float) ($l['tax'] ?? 0),
            $taxLines
        )), 2);
        $nettedTotalForHt = round((float) ($this->total ?? 0), 2);

        return [
            'id' => $this->id,
            'order_serial_no' => $this->order_serial_no,
            'queue_number' => $this->queue_number,
            'token' => $this->token,
            // [HEAL-H1 2026-06-07] Expose receipt_print_count so the DUPLICATA
            // marker (ReceiptDuplicataMarker.vue, keys on receipt_print_count>=2)
            // is functional on the order-history reprint receipt
            // (PosOrderReceiptComponent), matching the POS ReceiptComponent.
            // NF525: a reprinted fiscal ticket MUST be visually marked DUPLICATA.
            'receipt_print_count' => (int) ($this->receipt_print_count ?? 0),
            // [G5-F-001 / G2-HEAL-01 2026-05-23] Expose refund counter-entry
            // parent FK so ReceiptRemboursementMarker.vue (F2-HEAL-03 /
            // commit 8ebbd057a) can render the REMBOURSEMENT badge on
            // refund tickets (NF525 visual distinction requirement,
            // Loi de Finance France). Marker keys on this exact field.
            'parent_order_id' => $this->parent_order_id,
            // [N-HEAL-02 K.5 NEW-1 2026-05-24] Trace-back line on refund
            // receipt — ReceiptRemboursementMarker.vue:53 falls back to
            // bare parent_order_id when this is null. Order model has no
            // `parent` relation defined (verified 2026-05-24), so lookup
            // via direct value(); withoutGlobalScopes deliberately omitted
            // since parent always lives in same branch as counter-entry.
            'parent_order_serial_no' => $this->parent_order_id
                ? \App\Models\Order::query()->where('id', $this->parent_order_id)->value('order_serial_no')
                : null,
            // Montants numériques (SSOT) pour kiosk / TPE / intégrations — complément des *_currency_price.
            'subtotal' => round((float) ($this->subtotal ?? 0), 2),
            'discount' => round((float) ($this->discount ?? 0), 2),
            // [HEAL-H7 / CP-1] Netted to the post-discount base so the printed
            // NF525 ticket matches the collected TVA and the signed Z. On
            // non-discount orders ratio=1.0 → identical to the prior gross sum.
            'total_tax' => $nettedTotalTax,
            'total' => round((float) ($this->total ?? 0), 2),
            // [WT-D-R1-F4 2026-05-20] Raw numeric `delivery_charge` to feed
            // the canonical `formatPrice()` admin renderer (mirrors the
            // sibling subtotal/discount/total_tax/total raw projections).
            'delivery_charge' => round((float) ($this->delivery_charge ?? 0), 2),
            'subtotal_currency_price' => AppLibrary::currencyAmountFormat($this->subtotal),
            // [HEAL-H7 / CP-1] Post-discount HT = total − netted TVA, mirroring
            // the Z's total_ht = total_ttc − total_tva. On non-discount orders
            // total == subtotal, so this is byte-identical to the prior value.
            'subtotal_without_tax_currency_price' => AppLibrary::currencyAmountFormat($nettedTotalForHt - $nettedTotalTax),
            'discount_currency_price' => AppLibrary::currencyAmountFormat($this->discount),
            'delivery_charge_currency_price' => AppLibrary::currencyAmountFormat($this->delivery_charge),
            'total_currency_price' => AppLibrary::currencyAmountFormat($this->total),
            'total_tax_currency_price' => AppLibrary::currencyAmountFormat($nettedTotalTax),
            'order_type' => $this->order_type,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'order_datetime' => AppLibrary::datetime($this->order_datetime),
            'order_date' => AppLibrary::date($this->order_datetime),
            'order_time' => AppLibrary::time($this->order_datetime),
            'delivery_date' => $this->is_advance_order == Ask::YES ? AppLibrary::increaseDate($this->order_datetime, 1) : AppLibrary::date($this->order_datetime),
            'delivery_time' => AppLibrary::deliveryTime($this->delivery_time),
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'payment_pending_counter' => (int) $this->payment_status === \App\Enums\PaymentStatus::PENDING_COUNTER,
            // [TRAP-3 2026-06-04] Cash-trail gap surfacing. When a counter-collect
            // CASH payment succeeds but NO open drawer session existed, the order
            // is PAID + fiscal-seq allocated yet NO cash_movement row was written
            // → end-of-day reconciliation silently under-counts. PaymentService
            // sets the TRANSIENT (never-persisted) `cash_movement_skipped` flag on
            // the in-memory order so the encaissement modal can warn the cashier
            // instead of toasting a plain success, and the gap is no longer
            // confined to a cron log. Default false (flag absent on every other
            // path: CARD, session-OPEN cash, kiosk-paid finalize, etc.).
            'cash_movement_skipped' => (bool) ($this->resource->cash_movement_skipped ?? false),
            'cash_movement_skipped_message' => ($this->resource->cash_movement_skipped ?? false)
                ? 'Aucune session caisse ouverte — mouvement non enregistré'
                : null,
            'is_advance_order' => $this->is_advance_order,
            'preparation_time' => $this->preparation_time,
            'status' => $this->status,
            'status_name' => trans('orderStatus.'.$this->status),
            'reason' => $this->reason,
            'user' => new OrderUserResource($this->user?->load('roles', 'media')),
            'order_address' => new AddressResource($this->address),
            'branch' => new BranchResource($this->branch),
            'delivery_boy' => new OrderUserResource($this->deliveryBoy?->load('roles', 'media')),
            'coupon' => new CouponResource($this->coupon),
            'transaction' => new TransactionResource($this->transaction),
            'order_items' => OrderItemResource::collection($this->orderItems->load('orderItem')),
            'table_name' => $this->diningTable?->name,
            'pos_payment_method' => $this->pos_payment_method,
            'pos_payment_note' => $this->pos_payment_note,
            'source' => $this->source,
            // [GOAL-CAISSE-UNIFIED W-ENC 2026-05-30] Origin signal for the
            // unified /admin/encaissement queue badge (Borne='kiosk',
            // Caisse='pos'). Pure projection of an existing nullable column.
            'source_surface' => $this->source_surface,
            'pos_received_amount' => $this->pos_received_amount,
            'pos_received_currency_amount' => AppLibrary::currencyAmountFormat($this->pos_received_amount),
            'cash_back_amount' => $this->pos_received_amount - $this->total,
            'cash_back_currency_amount' => AppLibrary::currencyAmountFormat($this->pos_received_amount - $this->total),
            // [POS-9.1.13] Per-rate VAT breakdown for the printed ticket
            // (CGI art. 242 nonies A — every receipt must list HT base
            // and tax per rate). POS-GA-F-20. [HEAL-H7] Discount-netted; same
            // computed array that feeds the header total_tax above.
            'tax_lines' => $taxLines,
            // [T21 → NF525 RECEIPT WIRE-IN 2026-05-18] Six NF525 fields
            // delegated to ReceiptDataService (SSOT). audit_chain_fingerprint
            // and payments_breakdown stay owned by this resource — they're
            // resource-shaped, not part of the printed-ticket payload.
            'fiscal_sequence_no' => $receipt['fiscal_sequence_no'],
            'audit_chain_fingerprint' => $this->buildAuditFingerprint(),
            'pos_register_id' => $receipt['pos_register_id'],
            'pos_siret' => $receipt['pos_siret'],
            'pos_vat_intra' => $receipt['pos_vat_intra'],
            'pos_legal_footer' => $receipt['pos_legal_footer'],
            'operator_name' => $receipt['operator_name'],
            'payments_breakdown' => $this->buildPaymentsBreakdown(),
        ];
    }

    /**
     * 12-char hex derived from the latest audit chain hash for this order.
     * Never exposes the full HMAC / chain value or any secret.
     */
    private function buildAuditFingerprint(): ?string
    {
        try {
            $orderId = $this->resource->id ?? $this->id;
            $log = \App\Models\AuditLog::query()
                ->where('resource', 'order')
                ->where('resource_id', $orderId)
                ->orderByDesc('id')
                ->first();
            if (! $log || ! $log->current_hash) {
                return null;
            }

            return substr(hash('sha256', (string) $log->current_hash.'|'.$log->id), 0, 12);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildPaymentsBreakdown(): array
    {
        $order = $this->resource;

        if (method_exists($order, 'payments')) {
            try {
                $payments = $order->relationLoaded('payments')
                    ? ($order->payments ?? collect())
                    : $order->payments()->get();

                if ($payments instanceof \Illuminate\Support\Collection && $payments->isNotEmpty()) {
                    return $payments->map(function ($p) {
                        $amount = (float) ($p->amount ?? 0);

                        return [
                            'method' => $p->method ?? $p->payment_method ?? null,
                            'amount' => $amount,
                            'currency_amount' => $p->currency_amount ?? AppLibrary::currencyAmountFormat($amount),
                            'change_amount' => (float) ($p->change_amount ?? 0),
                            'reference' => $p->reference ?? null,
                        ];
                    })->values()->all();
                }
            } catch (\Throwable $e) {
                // Single-tender synthetic fallback below.
            }
        }

        if ($order->pos_payment_method !== null && $order->pos_payment_method !== '') {
            $received = (float) ($order->pos_received_amount ?? 0);
            $total = (float) ($order->total ?? 0);
            $change = max(0.0, $received - $total);
            $amountForLine = $received > 0 ? $received : $total;

            return [[
                'method' => $order->pos_payment_method,
                'amount' => $amountForLine,
                'currency_amount' => AppLibrary::currencyAmountFormat($order->pos_received_amount ?? $amountForLine),
                'change_amount' => $change,
                'reference' => null,
            ]];
        }

        return [];
    }

    /**
     * [POS-9.1.13] Group order_items by (tax_name, tax_rate, tax_type),
     * summing tax_amount and computing the HT base per group.
     *
     * Assumes total_price is TTC at the line level (which matches how
     * OrderService::posOrderStore stores items; tax_amount is also
     * persisted line by line). Returns an array of associative arrays
     * suitable for receipt rendering AND fiscal exports.
     *
     * [HEAL-H7 / CP-1 2026-06-07] NF525 discount-netting on the printed
     * ticket. Per-line tax_amount is the PRE-discount line tax (OrderService
     * applies the coupon/loyalty discount at order level only — see
     * OrderService.php:504-562). The signed Z and the refund counter-entry
     * both NET this by orderDiscountRatio = (subtotal − discount)/subtotal
     * (ZReportService::orderDiscountRatio + taxBreakdownForOrders,
     * RefundWithCounterEntryService). The printed ticket is itself an NF525
     * fiscal document, so its per-rate TVA/HT MUST equal the collected/Z TVA,
     * not the gross pre-discount figure. We mirror that exact ratio here.
     * Rounding matches the Z: net per-rate, round per bucket, then sum
     * (so the header total_tax derived from these buckets equals Σ buckets,
     * the same SSOT the Z uses for total_tva). ratio = 1.0 on non-discount
     * orders → existing receipts byte-identical.
     */
    private function buildTaxLines(): array
    {
        $items = $this->orderItems ?? collect();
        $ratio = $this->orderDiscountRatio();
        $groups = [];
        foreach ($items as $oi) {
            // DECIMAL columns may surface as "10.000000" on MySQL — normalise so
            // receipt JSON keys match SQLite / test expectations (e.g. "10").
            $rate = (string) (0 + (float) ($oi->tax_rate ?? 0));
            $name = (string) ($oi->tax_name ?? '');
            $type = (int) ($oi->tax_type ?? 0);
            $key = $type.'|'.$rate.'|'.$name;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'tax_name' => $name,
                    'tax_rate' => $rate,
                    'tax_type' => $type,
                    'tax_amount_raw' => 0.0,
                    'base_ht_raw' => 0.0,
                ];
            }
            $taxAmount = (float) ($oi->tax_amount ?? 0);
            $totalTtc = (float) ($oi->total_price ?? 0);
            // Net both the tax and the HT base by the discount ratio so the
            // bucket reflects the POST-discount TTC that was actually paid.
            $groups[$key]['tax_amount_raw'] += $taxAmount * $ratio;
            $groups[$key]['base_ht_raw'] += max(0.0, $totalTtc - $taxAmount) * $ratio;
        }
        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'tax_name' => $g['tax_name'],
                'tax_rate' => $g['tax_rate'],
                'tax_type' => $g['tax_type'],
                'base_ht' => round($g['base_ht_raw'], 2),
                'base_ht_currency' => AppLibrary::currencyAmountFormat($g['base_ht_raw']),
                'tax' => round($g['tax_amount_raw'], 2),
                'tax_currency' => AppLibrary::currencyAmountFormat($g['tax_amount_raw']),
            ];
        }

        return $out;
    }

    /**
     * [HEAL-H7 / CP-1 2026-06-07] Discount-netting ratio for this order:
     * (subtotal − discount) / subtotal, clamped to [0,1]. Returns 1.0 when
     * there is no discount (or no positive subtotal) so non-discount orders
     * are unchanged. Mirrors ZReportService::orderDiscountRatio exactly (the
     * frozen fiscal SSOT) so the printed ticket nets identically to the
     * signed Z; do NOT diverge from that formula.
     */
    private function orderDiscountRatio(): float
    {
        $subtotal = (float) ($this->resource->subtotal ?? 0);
        $discount = (float) ($this->resource->discount ?? 0);
        if ($discount <= 0.0 || $subtotal <= 0.0) {
            return 1.0;
        }

        return max(0.0, min(1.0, ($subtotal - $discount) / $subtotal));
    }
}
