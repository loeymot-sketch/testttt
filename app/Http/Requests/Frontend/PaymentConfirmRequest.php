<?php

namespace App\Http\Requests\Frontend;

use App\Enums\PaymentGateway;
use App\Models\KioskMachine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('sanctum') ?? $this->user();
        $token = $user && method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        $hasKioskAbility = $token
            ? $user->tokenCan('kiosk:order')
            : app()->runningUnitTests();

        return $user !== null
            && $hasKioskAbility
            && KioskMachine::query()->where('user_id', $user->id)->exists();
    }

    public function rules(): array
    {
        return [
            'transaction_id' => ['required', 'string', 'max:255'],
            'card_type' => ['nullable', 'string', 'max:50'],
            'payment_method' => [
                'nullable',
                'integer',
                Rule::in([PaymentGateway::CARD, PaymentGateway::TICKET_RESTAURANT]),
            ],
            // [AUDIT-F-002] amount_cents echoed by TPE driver — MUST match order.total
            // (within ±1 cent tolerance for floating rounding artefacts).
            // Without this, a compromised TPE could approve any amount and the backend
            // would mark PAID without detecting the discrepancy. NF525 + PCI-DSS.
            // Echo verification happens in OrderController::paymentConfirm before any state mutation.
            'amount_cents' => ['required', 'integer', 'min:1'],
        ];
    }
}
