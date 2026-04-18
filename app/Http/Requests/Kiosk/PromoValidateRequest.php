<?php

namespace App\Http\Requests\Kiosk;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Kiosk Design V1 — Phase 1.6
 *
 * Validation payload `POST /api/frontend/promo/validate`.
 */
class PromoValidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && $user->tokenCan('kiosk:order');
    }

    public function rules(): array
    {
        return [
            'code'       => ['required', 'string', 'min:3', 'max:64'],
            'cart_total' => ['required', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => 'Payload invalide.',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
