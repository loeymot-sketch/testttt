<?php

namespace App\Http\Requests\Kiosk;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Kiosk Design V1 — Phase 1.8
 *
 * Consentement RGPD pour l'adhésion au programme de fidélité depuis la
 * borne.
 *
 * Invariants §1.6 :
 *  - `consent_accepted` : checkbox NON pré-cochée côté UI + `accepted`
 *    (string 'yes'|'on'|'1'|true — Laravel rule `accepted`).
 *  - `privacy_notice_version` : version lisible par humain de la notice
 *    servie en UI au moment du clic.
 *  - Au moins phone OU email obligatoire (validation cross-field).
 */
class LoyaltyOptInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // endpoint public + rate-limited
    }

    public function rules(): array
    {
        return [
            'phone'                  => ['nullable', 'string', 'min:8', 'max:20', 'required_without:email'],
            'email'                  => ['nullable', 'email', 'max:100', 'required_without:phone'],
            'name'                   => ['nullable', 'string', 'min:2', 'max:100'],

            // RGPD — obligatoires & explicites
            'consent_accepted'       => ['required', 'accepted'],
            'privacy_notice_version' => ['required', 'string', 'min:1', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'consent_accepted.required' => 'Le consentement explicite est requis.',
            'consent_accepted.accepted' => 'Le consentement doit être accepté (RGPD).',
            'privacy_notice_version.required' => 'La version de la notice de confidentialité est requise.',
            'phone.required_without'  => 'Téléphone ou email requis.',
            'email.required_without'  => 'Téléphone ou email requis.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => 'Données invalides',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
