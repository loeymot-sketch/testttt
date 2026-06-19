<?php

namespace App\Http\Requests;

use App\Enums\Activity;
use App\Enums\OrderType;
use App\Http\Requests\Concerns\ValidatesOrderItemVariations;
use App\Rules\ValidJsonOrder;
use Illuminate\Validation\Rule;
use Smartisan\Settings\Facades\Settings;
use Illuminate\Foundation\Http\FormRequest;

class TableOrderRequest extends FormRequest
{
    use ValidatesOrderItemVariations;

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
            'order_type' => ['required', 'numeric'],
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