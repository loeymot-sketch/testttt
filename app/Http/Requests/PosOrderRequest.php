<?php

namespace App\Http\Requests;

use App\Enums\Activity;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Rules\ValidJsonOrder;
use Smartisan\Settings\Facades\Settings;
use Illuminate\Foundation\Http\FormRequest;

class PosOrderRequest extends FormRequest
{
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
     * [POS-9.1.1] Discount permission thresholds (% of subtotal).
     * - cashier (pos-discount-up-to-10) : 0-10%
     * - manager (pos-discount-over-10-requires-manager) : 10-50%
     * - owner   (pos-discount-unlimited) : 50-100%
     */
    private const DISCOUNT_CASHIER_MAX_PCT = 10.0;
    private const DISCOUNT_MANAGER_MAX_PCT = 50.0;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            // Numeric daily counter OR delivery call-out name (prénom) — must not be digits-only
            'token' => ['nullable', 'string', 'max:191'],
            'customer_id' => ['required', 'numeric'],
            'branch_id' => ['required', 'numeric'],
            // [GAP-31-1] subtotal is recalculated server-side — nullable here, backend ignores client value
            'subtotal' => ['nullable', 'numeric'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            // [POS-9.1.1] Mandatory motif for any discount above 0
            'discount_reason' => ['nullable', 'string', 'max:191'],
            'dining_table_id' => request('order_type') === OrderType::DINING_TABLE ? [
                'required',
                'numeric'
            ] : ['nullable'],
            'delivery_charge' => request('order_type') === OrderType::DELIVERY ? [
                'required',
                'numeric'
            ] : ['nullable'],
            // [AUDIT-P50-BUG4] Allow total=0 for 100% loyalty-redemption orders
            'total' => ['required', 'numeric', 'min:0'],
            'order_type' => ['required', 'numeric'],
            'is_advance_order' => ['required', 'numeric'],
            'address_id' => request('order_type') === OrderType::DELIVERY ? [
                'required',
                'numeric'
            ] : ['nullable'],
            'delivery_time' => ['nullable'],
            'coupon_id' => ['nullable', 'numeric'],
            'source' => ['required', 'numeric'],
            'items' => ['required', 'json', new ValidJsonOrder],
            'pos_payment_method' => ['required', 'numeric'],
            'pos_payment_note' => request('pos_payment_method') === PosPaymentMethod::CARD || request('pos_payment_method') === PosPaymentMethod::MOBILE_BANKING || request('pos_payment_method') === PosPaymentMethod::OTHER ? (request('pos_payment_method') === PosPaymentMethod::CARD ? ['required', 'numeric', 'min_digits:4', 'max_digits:4'] : ['required', 'string']) : ['nullable', 'string'],
            'pos_received_amount' => request('pos_payment_method') === PosPaymentMethod::CASH ? ['required', 'numeric'] : ['nullable', 'numeric'],
            'loyalty_customer_code' => ['nullable', 'string', 'min:4', 'max:25'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if (request('order_type') == OrderType::DELIVERY && Settings::group('order_setup')->get("order_setup_delivery") == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } else if (request('order_type') == OrderType::TAKEAWAY && Settings::group('order_setup')->get("order_setup_takeaway") == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } else if (blank(request('order_type'))) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            }
            // [AUDIT-P1-B] NOTE: This validation uses the client-sent 'total' as a preliminary check.
            // The server recalculates the real total in OrderService::posOrderStore.
            // A second validation against the server-computed total is enforced there.
            // This check only prevents obvious UI errors (cashier entered less cash than shown).
            if (request('pos_payment_method') == PosPaymentMethod::CASH && ((float) request('total') > (float) request('pos_received_amount'))) {
                $validator->errors()->add('pos_received_amount', 'The received amount can not be less than the total amount.');
            }

            // [POS-9.1.1] Discount permission gate:
            //  - every non-zero discount requires a written motif (≥ 3 chars)
            //  - discount_pct = discount / subtotal * 100
            //  - cashier  (pos-discount-up-to-10)                    ≤ 10%
            //  - manager  (pos-discount-over-10-requires-manager)    ≤ 50%
            //  - owner    (pos-discount-unlimited)                   > 50%
            $discount = (float) request('discount', 0);
            $subtotal = (float) request('subtotal', 0);
            if ($discount > 0) {
                $reason = trim((string) request('discount_reason', ''));
                if (strlen($reason) < 3) {
                    $validator->errors()->add('discount_reason', 'A reason is required for any POS discount (min 3 characters).');
                    return;
                }

                if ($subtotal <= 0) {
                    $validator->errors()->add('discount', 'Cannot apply discount without a valid subtotal.');
                    return;
                }

                $pct = ($discount / $subtotal) * 100.0;
                $user = auth()->user();

                if (!$user) {
                    $validator->errors()->add('discount', 'Authentication required to apply a discount.');
                    return;
                }

                if ($pct > self::DISCOUNT_MANAGER_MAX_PCT && !$user->can('pos-discount-unlimited')) {
                    $validator->errors()->add('discount', 'Only an owner can apply a discount above ' . self::DISCOUNT_MANAGER_MAX_PCT . '%.');
                } elseif ($pct > self::DISCOUNT_CASHIER_MAX_PCT && !$user->can('pos-discount-over-10-requires-manager') && !$user->can('pos-discount-unlimited')) {
                    $validator->errors()->add('discount', 'Discount above ' . self::DISCOUNT_CASHIER_MAX_PCT . '% requires manager approval.');
                } elseif (!$user->can('pos-discount-up-to-10') && !$user->can('pos-discount-over-10-requires-manager') && !$user->can('pos-discount-unlimited')) {
                    $validator->errors()->add('discount', 'You do not have permission to apply POS discounts.');
                }
            }
        });
    }

    public function messages()
    {
        return [
            'pos_payment_note.required' => request('pos_payment_method') == PosPaymentMethod::CARD ? 'Last 4 digits of card is required' : (request('pos_payment_method') == PosPaymentMethod::MOBILE_BANKING ? 'Transaction ID field is required' : 'Payment note field is required'),
            'pos_payment_note.min_digits' => 'The cart must contain at least 4 digits',
            'pos_payment_note.max_digits' => 'The cart must not contain more than 4 digits',
            'pos_received_amount.required' => 'The received amount field is required',
            'dining_table_id.required' => 'The dining table field is required'
        ];
    }
}