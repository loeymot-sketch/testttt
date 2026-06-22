<?php

namespace App\Http\Requests;

use App\Enums\Activity;
use App\Enums\OrderType;
use App\Http\Requests\Concerns\ValidatesOrderItemVariations;
use App\Rules\ValidJsonOrder;
use App\Services\Delivery\DeliveryFeeService;
use Illuminate\Validation\Rule;
use Smartisan\Settings\Facades\Settings;
use Illuminate\Foundation\Http\FormRequest;

class TableOrderRequest extends FormRequest
{
    use ValidatesOrderItemVariations;

    /**
     * [abuse-heal 2026-06-20 table-delivery-twin] NF525 delivery-fee tamper close —
     * SYMMETRIC TWIN of OrderRequest:129-145 and PosOrderRequest:39-65.
     *
     * The QR table-order endpoint (POST /api/table/dining-order, UNAUTHENTICATED,
     * apiKey-only) accepted a nullable `delivery_charge` and NEVER neutralized it.
     * tableOrderStore (OrderService:1370) unsets only total/subtotal/discount, so a
     * client-typed delivery_charge flowed raw into PricingRequest::forTable
     * (OrderService:1397) -> PricingService:351 added it straight into the stored
     * total -> signed into the NF525 Z when the order is counter-paid
     * (changePaymentStatus -> FiscalSequenceService::next, OrderService:2008;
     * ZReportService:340/663 sums $order->total). Proven: TAKEAWAY via this
     * endpoint (the killswitch only blocks DINING_TABLE) returns 201 with dine-in
     * OFF and persists delivery_charge=100 -> total=112.
     *
     * A table order is dine-in / at-table service — there is NO server-trusted
     * basis for a delivery fee on this surface, so we force the value to
     * DeliveryFeeService's null-distance answer (0.0). Backend-computed amounts
     * only (CLAUDE.md §8).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('delivery_charge')) {
            $this->merge([
                'delivery_charge' => app(DeliveryFeeService::class)->fromDistanceKm(null),
            ]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            // [abuse-heal 2026-06-19 qr-table-twin] Existence enforcement.
            // The QR dining-order path was the ONLY order-create path that did
            // not verify the table exists (rule was numeric-only), so a POST
            // with dining_table_id=999999 created an order pointing at a
            // non-existent table (HTTP 201). Require it to reference a real row
            // in `dining_tables` (the table backing FrontendDiningTable). The
            // branch-scoped variant lives in withValidator() below — `exists`
            // bypasses BranchScope (DB query builder), so cross-branch checking
            // is done explicitly there, mirroring PosOrderRequest's terminal_id.
            'dining_table_id' => ['required', 'integer', 'exists:dining_tables,id'],
            'customer_id' => ['required', 'numeric'],
            'branch_id' => ['required', 'numeric'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            // [P6] Symmetry with OrderRequest/PosOrderRequest — reject bogus negative amounts at QR table.
            'total' => ['required', 'numeric', 'min:0'],
            // [abuse-heal 2026-06-20 W1b W1B-DINEIN-01] Constrain order_type to the known enum.
            // Previously ['required','numeric'] accepted ANY number — and the V1 dine-in killswitch
            // (withValidator) only matches DINING_TABLE===20, so an out-of-enum value (e.g. 3, 99)
            // slipped past every gate onto a real dining table while dine-in is OFF in V1, with a
            // type no downstream consumer (KDS station, OSS whereIn, reports) recognises. Reject
            // unknown types 422 before order creation.
            'order_type' => ['required', 'numeric', Rule::in([
                OrderType::DELIVERY,
                OrderType::TAKEAWAY,
                OrderType::POS,
                OrderType::DINING_TABLE,
                OrderType::KIOSK,
            ])],
            'is_advance_order' => ['required', 'numeric'],
            'address_id' => ['nullable'],
            'delivery_time' => ['nullable'],
            'coupon_id' => ['nullable', 'numeric'],
            'source' => ['required', 'numeric'],
            'token' => ['nullable', 'string'],
            'items' => ['required', 'json', new ValidJsonOrder]
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // [abuse-heal 2026-06-19 qr-table-twin] V1 dine-in killswitch.
            // A QR table order IS a dine-in order. The POS path
            // (PosOrderRequest::withValidator → "Dine-in is disabled for this
            // branch.") and the kiosk path (OrderRequest::withValidator → "Le
            // service sur place est désactivé en V1…") both reject DINING_TABLE
            // when `pos_dine_in_enabled` is false; this path historically did
            // not, so a QR client (or replay) could create a dine-in order even
            // when the service is disabled in V1. Mirror the POS server-side
            // gate exactly (message + early return so no downstream variation
            // checks run on a rejected order).
            $orderTypeInt = (int) request('order_type', 0);
            if ($orderTypeInt === OrderType::DINING_TABLE
                && ! (bool) Settings::group('pos')->get('pos_dine_in_enabled', false)) {
                $validator->errors()->add('order_type', 'Dine-in is disabled for this branch.');

                return;
            }

            if (request('order_type') == OrderType::DELIVERY && Settings::group('order_setup')->get("order_setup_delivery") == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } else if (request('order_type') == OrderType::TAKEAWAY && Settings::group('order_setup')->get("order_setup_takeaway") == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } else if (blank(request('order_type'))) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            }

            $this->validateOrderItemVariationsAfter($validator);
        });
    }
}