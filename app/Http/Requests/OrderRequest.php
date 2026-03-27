<?php

namespace App\Http\Requests;

use App\Enums\Activity;
use App\Enums\OrderType;
use App\Rules\ValidJsonOrder;
use Illuminate\Validation\Rule;
use Smartisan\Settings\Facades\Settings;
use Illuminate\Foundation\Http\FormRequest;

class OrderRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'branch_id' => request('order_type') == OrderType::DELIVERY ? ['nullable', 'numeric'] : ['required', 'numeric'],
            // [GAP-31-1] subtotal is recalculated server-side — nullable here, backend ignores client value
            'subtotal' => ['nullable', 'numeric'],
            'discount' => ['nullable', 'numeric'],
            'delivery_charge' => request('order_type') === OrderType::DELIVERY ? [
                'required',
                'numeric'
            ] : ['nullable'],
            'total' => ['required', 'numeric'],
            'order_type' => ['required', 'numeric'],
            'is_advance_order' => ['required', 'numeric'],
            'address_id' => request('order_type') === OrderType::DELIVERY ? [
                'required',
                'numeric'
            ] : ['nullable'],
            'delivery_time' => request('order_type') === OrderType::DELIVERY ? [
                'required',
                'string'
            ] : ['nullable'],
            'coupon_id' => ['nullable', 'numeric'],
            'loyalty_code' => ['nullable', 'string', 'max:25'],
            'source' => ['required', 'numeric'],
            'payment_method' => ['nullable', 'numeric'],
            'token' => ['nullable', 'string'],
            'items' => ['required', 'json', new ValidJsonOrder]
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $orderType = request('order_type');

            // [GAP-22-1] Kiosk machine orders (KIOSK=25 or TAKEAWAY=10 from a kiosk token) are always
            // allowed regardless of order_setup_takeaway setting. The kiosk is a physical machine in
            // the restaurant — it must offer "à emporter" independently of the web ordering settings.
            $user = $this->user('sanctum');
            $isKioskToken = $user && $user->tokenCan('kiosk:order');
            if ($isKioskToken && in_array((int) $orderType, [OrderType::KIOSK, OrderType::TAKEAWAY], true)) {
                return; // Kiosk orders bypass order_type setting restrictions
            }

            if ($orderType == OrderType::DELIVERY && Settings::group('order_setup')->get("order_setup_delivery") == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } else if ($orderType == OrderType::TAKEAWAY && Settings::group('order_setup')->get("order_setup_takeaway") == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } else if (blank($orderType)) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            }
        });
    }
}