<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();

        // Staff/admin roles can change any order status
        if ($user->hasAnyRole(['Admin', 'Branch Manager', 'Chef', 'POS Operator', 'Cashier'])) {
            return true;
        }

        // Kiosk machine users can only cancel their OWN orders.
        // The service layer enforces ownership + status constraints.
        if ($user->tokenCan('kiosk:order') && (int) $this->input('status') === OrderStatus::CANCELED) {
            return true;
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {

        return [
            'status' => ['required', 'numeric'],
        ];
    }
}
