<?php

namespace App\Http\Resources;

use App\Enums\Ask;
use App\Libraries\AppLibrary;
use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_serial_no' => $this->order_serial_no,
            'queue_number' => $this->queue_number,
            'token' => $this->token,
            "subtotal_currency_price" => AppLibrary::currencyAmountFormat($this->subtotal),
            "subtotal_without_tax_currency_price" => AppLibrary::currencyAmountFormat($this->subtotal - $this->total_tax),
            "discount_currency_price" => AppLibrary::currencyAmountFormat($this->discount),
            "delivery_charge_currency_price" => AppLibrary::currencyAmountFormat($this->delivery_charge),
            "total_currency_price" => AppLibrary::currencyAmountFormat($this->total),
            "total_tax_currency_price" => AppLibrary::currencyAmountFormat($this->total_tax),
            'order_type' => $this->order_type,
            'order_datetime' => AppLibrary::datetime($this->order_datetime),
            'order_date' => AppLibrary::date($this->order_datetime),
            'order_time' => AppLibrary::time($this->order_datetime),
            'delivery_date' => $this->is_advance_order == Ask::YES ? AppLibrary::increaseDate($this->order_datetime, 1) : AppLibrary::date($this->order_datetime),
            'delivery_time' => AppLibrary::deliveryTime($this->delivery_time),
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'is_advance_order' => $this->is_advance_order,
            'preparation_time' => $this->preparation_time,
            'status' => $this->status,
            'status_name' => trans('orderStatus.' . $this->status),
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
            'pos_received_amount' => $this->pos_received_amount,
            'pos_received_currency_amount' => AppLibrary::currencyAmountFormat($this->pos_received_amount),
            'cash_back_amount' => $this->pos_received_amount - $this->total,
            'cash_back_currency_amount' => AppLibrary::currencyAmountFormat($this->pos_received_amount - $this->total),
            // [POS-9.1.13] Per-rate VAT breakdown for the printed ticket
            // (CGI art. 242 nonies A — every receipt must list HT base
            // and tax per rate). POS-GA-F-20.
            'tax_lines' => $this->buildTaxLines(),
            // [HARDEN-P1-12] NF525 art. 286-I-3°bis — printed receipt must
            // surface the immutable per-branch fiscal sequence number so a
            // tax inspector can cross-reference the ticket against the
            // sealed audit chain. Nullable for back-compat with orders
            // created before F-001 / phase POS-9.4.1.
            'fiscal_sequence_no'      => $this->fiscal_sequence_no,
            // [HARDEN-P1-12] First 8 chars of the latest HMAC link in the
            // audit chain attached to this order. Visual proof on the ticket
            // that the order is sealed in the chain — independent verifier
            // (X/Z report, audit dump) can match it against `audit_logs`.
            'fiscal_signature_short'  => $this->getFiscalSignatureShort(),
        ];
    }

    /**
     * [HARDEN-P1-12] Look up the latest audit log row tied to this order
     * and return the first 8 chars of its `current_hash` (HMAC chain link).
     *
     * Returns null when:
     *   - the order has no `fiscal_sequence_no` (not yet allocated by F-001)
     *   - no audit row exists for this order yet
     *   - the audit_logs table is unavailable (defensive — receipts must
     *     never crash for a presentation concern)
     *
     * Single-row query and only invoked from `toArray()`. Safe because
     * `OrderDetailsResource` is never wrapped in a `::collection(...)`
     * (verified across `app/Http/Controllers/`).
     */
    private function getFiscalSignatureShort(): ?string
    {
        if (empty($this->fiscal_sequence_no)) {
            return null;
        }

        try {
            $audit = AuditLog::query()
                ->where('resource', 'order')
                ->where('resource_id', (int) $this->id)
                ->orderByDesc('id')
                ->first(['current_hash']);

            $hash = $audit?->current_hash;
            return $hash ? substr((string) $hash, 0, 8) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * [POS-9.1.13] Group order_items by (tax_name, tax_rate, tax_type),
     * summing tax_amount and computing the HT base per group.
     *
     * Assumes total_price is TTC at the line level (which matches how
     * OrderService::posOrderStore stores items; tax_amount is also
     * persisted line by line). Returns an array of associative arrays
     * suitable for receipt rendering AND fiscal exports.
     */
    private function buildTaxLines(): array
    {
        $items = $this->orderItems ?? collect();
        $groups = [];
        foreach ($items as $oi) {
            $rate = (string) ($oi->tax_rate ?? '0');
            $name = (string) ($oi->tax_name ?? '');
            $type = (int) ($oi->tax_type ?? 0);
            $key  = $type . '|' . $rate . '|' . $name;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'tax_name' => $name,
                    'tax_rate' => $rate,
                    'tax_type' => $type,
                    'tax_amount_raw' => 0.0,
                    'base_ht_raw'    => 0.0,
                ];
            }
            $taxAmount = (float) ($oi->tax_amount ?? 0);
            $totalTtc  = (float) ($oi->total_price ?? 0);
            $groups[$key]['tax_amount_raw'] += $taxAmount;
            $groups[$key]['base_ht_raw']    += max(0.0, $totalTtc - $taxAmount);
        }
        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'tax_name' => $g['tax_name'],
                'tax_rate' => $g['tax_rate'],
                'tax_type' => $g['tax_type'],
                'base_ht'  => round($g['base_ht_raw'], 2),
                'base_ht_currency'  => AppLibrary::currencyAmountFormat($g['base_ht_raw']),
                'tax'      => round($g['tax_amount_raw'], 2),
                'tax_currency'      => AppLibrary::currencyAmountFormat($g['tax_amount_raw']),
            ];
        }
        return $out;
    }
}