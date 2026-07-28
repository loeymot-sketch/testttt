<?php

namespace App\Http\Requests;

use App\Rules\ValidPhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * [WAVE C EMAIL-OTP 2026-07-28] Demande de code signup par EMAIL
 * (POST /api/auth/guest-signup/email-otp). Le téléphone reste la clé
 * (fidélité) ; l'email est le canal d'envoi + futur login.
 * Aucune règle unique sur l'email ici : ne jamais révéler si un email
 * existe déjà (anti-énumération).
 */
class GuestSignupEmailOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:190', new ValidPhone()],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            // Indicatif pays optionnel (défaut +33 côté contrôleur) — jamais « numeric » (rejette +33).
            'code'  => ['nullable', 'string', 'max:32'],
        ];
    }
}
