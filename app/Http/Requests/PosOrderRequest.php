<?php

namespace App\Http\Requests;

use App\Enums\Activity;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Http\Requests\Concerns\ValidatesOrderItemVariations;
use App\Rules\ValidJsonOrder;
use Illuminate\Foundation\Http\FormRequest;
use Smartisan\Settings\Facades\Settings;

class PosOrderRequest extends FormRequest
{
    use ValidatesOrderItemVariations;

    /**
     * Determine if the user is authorized to make this request.
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
     */
    public function rules(): array
    {
        // [POS-9-H.1.5] F-A5 fix: `request('order_type')` is a string-ish HTTP value
        // and `OrderType::DINING_TABLE` is an int enum value. Strict `===` always
        // returned false, so `dining_table_id` was ALWAYS `nullable` in practice.
        // Cast to int and use ==-style comparison to actually enforce the rule.
        $orderTypeInt = (int) request('order_type', 0);
        $dineInEnabled = (bool) Settings::group('pos')->get('pos_dine_in_enabled', false);

        return [
            // Numeric daily counter OR delivery call-out name (prénom) — must not be digits-only
            'token' => ['nullable', 'string', 'max:191'],
            'customer_id' => ['required', 'numeric'],
            'branch_id' => ['required', 'numeric'],
            // [GAP-31-1] subtotal is recalculated server-side — nullable here, backend ignores client value
            // [P7] Reject negative client-sent amounts if present.
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            // [POS-9.1.1] Mandatory motif for any discount above 0
            'discount_reason' => ['nullable', 'string', 'max:191'],
            'dining_table_id' => ($orderTypeInt === OrderType::DINING_TABLE && $dineInEnabled) ? [
                'required',
                'numeric',
            ] : ['nullable'],
            'delivery_charge' => request('order_type') === OrderType::DELIVERY ? [
                'required',
                'numeric',
                'min:0',
            ] : ['nullable', 'numeric', 'min:0'],
            // [POS-9.1.8] total is recomputed server-side in OrderService::posOrderStore;
            // payload value is only used as a UX cross-check for cash payments
            // (see withValidator below). nullable so a desynced UI cannot bypass
            // server logic by spoofing total. (POS-GA-F-47)
            // [AUDIT-P50-BUG4] kept min:0 — server allows total=0 for 100% loyalty redemption.
            'total' => ['nullable', 'numeric', 'min:0'],
            'order_type' => ['required', 'numeric'],
            'is_advance_order' => ['required', 'numeric'],
            'address_id' => request('order_type') === OrderType::DELIVERY ? [
                'required',
                'numeric',
            ] : ['nullable'],
            'delivery_time' => ['nullable'],
            'coupon_id' => ['nullable', 'numeric'],
            'source' => ['required', 'numeric'],
            'items' => ['required', 'json', new ValidJsonOrder],
            'pos_payment_method' => ['required', 'numeric'],
            'pos_payment_note' => request('pos_payment_method') === PosPaymentMethod::CARD || request('pos_payment_method') === PosPaymentMethod::MOBILE_BANKING || request('pos_payment_method') === PosPaymentMethod::OTHER || (string) request('pos_payment_method') === (string) PosPaymentMethod::TICKET_RESTAURANT ? (request('pos_payment_method') === PosPaymentMethod::CARD ? ['required', 'numeric', 'min_digits:4', 'max_digits:4'] : ['required', 'string', 'max:200']) : ['nullable', 'string'],
            'pos_received_amount' => request('pos_payment_method') === PosPaymentMethod::CASH ? ['required', 'numeric', 'min:0'] : ['nullable', 'numeric', 'min:0'],
            'loyalty_customer_code' => ['nullable', 'string', 'min:4', 'max:25'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // [POS-9-H.1.5] F-A5: Server-side dine-in feature gate.
            // The UI hides dine-in when `pos_dine_in_enabled` is off, but nothing
            // was enforcing it server-side. An attacker posting order_type=15
            // (DINING_TABLE) would bypass the UI and create a dine-in order.
            $orderTypeInt = (int) request('order_type', 0);
            if ($orderTypeInt === OrderType::DINING_TABLE
                && ! (bool) Settings::group('pos')->get('pos_dine_in_enabled', false)) {
                $validator->errors()->add('order_type', 'Dine-in is disabled for this branch.');

                return;
            }

            if ($orderTypeInt === OrderType::DELIVERY && Settings::group('order_setup')->get('order_setup_delivery') == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } elseif ($orderTypeInt === OrderType::TAKEAWAY && Settings::group('order_setup')->get('order_setup_takeaway') == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } elseif (blank(request('order_type'))) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            }
            // [AUDIT-P1-B] NOTE: This validation uses the client-sent 'total' as a preliminary check.
            // The server recalculates the real total in OrderService::posOrderStore.
            // A second validation against the server-computed total is enforced there.
            // This check only prevents obvious UI errors (cashier entered less cash than shown).
            // [POS-9.1.8] Only run this UX cross-check when the client actually
            // sent a `total` (now nullable per POS-GA-F-47). The authoritative
            // total is computed server-side in OrderService::posOrderStore and
            // re-validated against pos_received_amount there.
            if (request('pos_payment_method') == PosPaymentMethod::CASH
                && request()->filled('total')
                && ((float) request('total') > (float) request('pos_received_amount'))) {
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

                if (! $user) {
                    $validator->errors()->add('discount', 'Authentication required to apply a discount.');

                    return;
                }

                if ($pct > self::DISCOUNT_MANAGER_MAX_PCT && ! $user->can('pos-discount-unlimited')) {
                    $validator->errors()->add('discount', 'Only an owner can apply a discount above '.self::DISCOUNT_MANAGER_MAX_PCT.'%.');
                } elseif ($pct > self::DISCOUNT_CASHIER_MAX_PCT && ! $user->can('pos-discount-over-10-requires-manager') && ! $user->can('pos-discount-unlimited')) {
                    $validator->errors()->add('discount', 'Discount above '.self::DISCOUNT_CASHIER_MAX_PCT.'% requires manager approval.');
                } elseif (! $user->can('pos-discount-up-to-10') && ! $user->can('pos-discount-over-10-requires-manager') && ! $user->can('pos-discount-unlimited')) {
                    $validator->errors()->add('discount', 'You do not have permission to apply POS discounts.');
                }
            }

            $this->validateOrderItemVariationsAfter($validator);
        });
    }

    public function messages()
    {
        return [
            'pos_payment_note.required' => request('pos_payment_method') == PosPaymentMethod::CARD ? 'Last 4 digits of card is required' : (request('pos_payment_method') == PosPaymentMethod::MOBILE_BANKING ? 'Transaction ID field is required' : 'Payment note field is required'),
            'pos_payment_note.min_digits' => 'The cart must contain at least 4 digits',
            'pos_payment_note.max_digits' => 'The cart must not contain more than 4 digits',
            'pos_received_amount.required' => 'The received amount field is required',
            'dining_table_id.required' => 'The dining table field is required',
        ];
    }
}
