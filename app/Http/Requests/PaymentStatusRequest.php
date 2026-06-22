<?php

namespace App\Http\Requests;

use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if (!auth()->check()) {
            return false;
        }
        return auth()->user()->hasAnyRole(['Admin', 'Branch Manager', 'POS Operator']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * [P13 — F-VERIFY-09-01] Whitelist payment_status values via enum constants.
     * Note: PaymentStatus is an interface holding `const`s (not a BackedEnum),
     * so the allowed list is materialised manually.
     *
     * @return array
     */
    public function rules(): array
    {
        $allowed = [
            PaymentStatus::PAID,
            PaymentStatus::UNPAID,
            PaymentStatus::PENDING_COUNTER,
            PaymentStatus::REFUNDED,
        ];

        return [
            'payment_status' => ['required', 'integer', Rule::in($allowed)],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_status.in' => 'Statut de paiement invalide.',
        ];
    }
}
