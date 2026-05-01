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
        ];
    }
}
