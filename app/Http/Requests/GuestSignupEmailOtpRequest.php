<?php

namespace App\Http\Requests;

use App\Enums\Activity;
use App\Rules\ValidPhone;
use Illuminate\Foundation\Http\FormRequest;
use Smartisan\Settings\Facades\Settings;

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
        // Même toggle que GuestSignupController::register() : si le guest login
        // est désactivé, on n'envoie PAS de code (sinon l'endpoint reste un
        // déclencheur d'emails OTP gratuit alors que le register refusera).
        return Settings::group('site')->get('site_guest_login') != Activity::DISABLE;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:190', new ValidPhone()],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            // [OWNER 2026-08-01] Identité complète AVANT l'envoi du code : le compte doit
            // porter « Prénom Nom » (fini les « Guest User » illisibles en caisse).
            'first_name' => ['required', 'string', 'min:2', 'max:100'],
            'last_name'  => ['required', 'string', 'min:2', 'max:100'],
            // Indicatif pays optionnel (défaut +33 côté contrôleur) — jamais « numeric » (rejette +33).
            'code'  => ['nullable', 'string', 'max:32'],
        ];
    }
}
