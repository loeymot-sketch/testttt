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
            'dining_table_id' => ['required', 'numeric'],
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
            // S17-01: V1 dine-in gate. This is the public QR table-order endpoint
            // (dining_table_id required) — it is dine-in by nature. The POS path
            // (PosOrderRequest) and kiosk path (OrderRequest) reject DINING_TABLE
            // server-side when `pos_dine_in_enabled` is off, but this endpoint had
            // NO gate, so anyone could create a dine-in order while V1 ships
            // "à emporter only". Owner 2026-06-05: close the hole / honor the OFF
            // flag (defaults false). Reject unconditionally when dine-in is off —
            // the client cannot bypass by spoofing order_type.
            if (! (bool) Settings::group('pos')->get('pos_dine_in_enabled', false)) {
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