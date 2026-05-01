<?php

namespace App\Http\Requests;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\Status;
use App\Exceptions\Delivery\GeocodeUnavailableException;
use App\Http\Requests\Concerns\ValidatesOrderItemVariations;
use App\Models\KioskMachine;
use App\Rules\ValidJsonOrder;
use App\Services\Delivery\DeliveryFeeService;
use App\Services\Delivery\DeliveryQuoteService;
use Smartisan\Settings\Facades\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Sanctum\TransientToken;

class OrderRequest extends FormRequest
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

    protected function prepareForValidation(): void
    {
        if ($this->has('is_advance_order') && (int) $this->input('is_advance_order') === 0) {
            $this->merge(['is_advance_order' => Ask::NO]);
        }

        $kiosk = $this->kioskMachineForToken();
        if ($kiosk) {
            $this->merge(['branch_id' => (int) $kiosk->branch_id]);
        }

        $isDelivery = (int) $this->input('order_type', 0) === OrderType::DELIVERY;

        if ($isDelivery && $this->filled('delivery_distance_km') && $this->filled('address_id') && $this->user() && ! $this->filled('branch_id')) {
            throw new GeocodeUnavailableException();
        }

        if ($isDelivery
            && $this->filled('delivery_distance_km')
            && $this->filled('branch_id')
            && $this->filled('address_id')
            && $this->user()) {
            $quote = app(DeliveryQuoteService::class)->quoteForSavedAddress(
                (int) $this->input('branch_id'),
                (int) $this->input('address_id'),
                (int) $this->user()->id
            );
            $this->merge([
                'delivery_distance_km' => $quote['distance_km'],
                'delivery_charge' => $quote['delivery_charge'],
            ]);
        } elseif ($isDelivery && $this->filled('delivery_distance_km')) {
            $this->merge([
                'delivery_charge' => app(DeliveryFeeService::class)
                    ->fromDistanceKm($this->input('delivery_distance_km')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        $isKioskMachineOrder = $this->isKioskMachineOrder();
        $orderTypeInt = (int) $this->input('order_type', 0);
        $isDelivery = $orderTypeInt === OrderType::DELIVERY;

        return [
            // Kiosk branch_id is always server-resolved from KioskMachine; web/app legacy clients may still send it.
            'branch_id' => ($isDelivery || $isKioskMachineOrder)
                ? ['nullable', 'numeric']
                : ['required', 'numeric'],
            // [GAP-31-1] subtotal is recalculated server-side — nullable here, backend ignores client value
            // [P7] Reject negative client-sent amounts (symmetry with P5–P6).
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge' => $isDelivery ? [
                'required',
                'numeric',
                'min:0',
            ] : ['nullable', 'numeric', 'min:0'],
            'delivery_distance_km' => $isDelivery
                ? ['required', 'numeric', 'min:0']
                : ['nullable', 'numeric', 'min:0'],
            // [P9.5.8] Frontend kiosk payload no longer sends client totals; server recomputes via PricingService SSOT.
            // [P5] If a client still sends total, reject negatives (align PosOrderRequest / audit P50-4).
            'total' => ['nullable', 'numeric', 'min:0'],
            'order_type' => ['required', 'numeric'],
            'is_advance_order' => ['required', 'numeric'],
            'address_id' => $isDelivery ? [
                'required',
                'numeric'
            ] : ['nullable'],
            'delivery_time' => $isDelivery ? [
                'required',
                'string'
            ] : ['nullable'],
            'coupon_id' => ['nullable', 'numeric'],
            'loyalty_code' => ['nullable', 'string', 'max:25'],
            'quote_token' => $this->isKioskOrderToken()
                ? ['required', 'uuid']
                : ['nullable', 'uuid'],
            'quote_signature' => $this->isKioskOrderToken()
                ? ['required', 'string', 'size:64']
                : ['nullable', 'string', 'size:64'],
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
            $orderTypeInt = (int) $orderType;

            // [GAP-22-1] Kiosk machine orders (KIOSK=25 or TAKEAWAY=10 from a kiosk token) are always
            // allowed regardless of order_setup_takeaway setting. The kiosk is a physical machine in
            // the restaurant — it must offer "à emporter" independently of the web ordering settings.
            $isKioskToken = $this->isKioskOrderToken();
            if ($isKioskToken) {
                $kiosk = $this->kioskMachineForToken();
                if (! $kiosk) {
                    $validator->errors()->add('branch_id', 'Kiosk machine is not registered for this token.');
                    return;
                }
                if ((int) $kiosk->status !== (int) Status::ACTIVE) {
                    $validator->errors()->add('branch_id', 'Kiosk machine is inactive.');
                    return;
                }
            }

            if ($isKioskToken && in_array($orderTypeInt, [OrderType::KIOSK, OrderType::TAKEAWAY], true)) {
                $this->validateOrderItemVariationsAfter($validator);
                return; // Kiosk orders bypass order_type setting restrictions
            }

            if ($orderTypeInt === OrderType::DELIVERY && Settings::group('order_setup')->get("order_setup_delivery") == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } else if ($orderTypeInt === OrderType::TAKEAWAY && Settings::group('order_setup')->get("order_setup_takeaway") == Activity::DISABLE) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            } else if (blank($orderType)) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            }

            $this->validateOrderItemVariationsAfter($validator);
        });
    }

    private function isKioskMachineOrder(): bool
    {
        return (bool) ($this->isKioskOrderToken()
            && in_array((int) $this->input('order_type'), [OrderType::KIOSK, OrderType::TAKEAWAY], true));
    }

    private function kioskMachineForToken(): ?KioskMachine
    {
        $user = $this->user('sanctum');
        if (! $user || ! $this->isKioskOrderToken()) {
            return null;
        }

        return KioskMachine::query()
            ->where('user_id', (int) $user->id)
            ->first();
    }

    private function isKioskOrderToken(): bool
    {
        $user = $this->user('sanctum');
        if (! $user) {
            return false;
        }

        $token = $user->currentAccessToken();
        if (! $token || $token instanceof TransientToken) {
            return false;
        }

        return (bool) $user->tokenCan('kiosk:order');
    }
}
