<?php

namespace App\Http\Requests;

use App\Enums\Activity;
use App\Rules\ValidPhone;
use Illuminate\Foundation\Http\FormRequest;
use Smartisan\Settings\Facades\Settings;

/**
 * [APP MOBILE 2026-09-02 — GOAL_APP_MOBILE_APPSTORE §A1] Connexion « e-mail d'abord »
 * (POST /api/auth/guest-signup/email-login).
 *
 * Deux formes, UN écran côté client :
 *   - {email}                          → le serveur dit si un compte invité existe (known) et,
 *                                         si oui, envoie le code à l'e-mail DU COMPTE.
 *   - {email, first_name, phone}       → inscription : même moteur que email-otp.
 *
 * Le nom de famille n'est plus exigé (demande propriétaire 2026-09-02 : « prénom, e-mail,
 * téléphone »). Il reste accepté s'il est fourni.
 */
class GuestEmailLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Même toggle que GuestSignupController::register() : guest login désactivé ⇒ aucun code.
        return Settings::group('site')->get('site_guest_login') != Activity::DISABLE;
    }

    public function rules(): array
    {
        return [
            'email'      => ['required', 'string', 'email:rfc', 'max:190'],
            'first_name' => ['nullable', 'required_with:phone', 'string', 'min:2', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'phone'      => ['nullable', 'required_with:first_name', 'string', 'max:190', new ValidPhone()],
            // Indicatif pays optionnel (défaut +33 côté contrôleur) — jamais « numeric » (rejette +33).
            'code'       => ['nullable', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required_with' => 'Indique ton prénom pour créer ton compte.',
            'phone.required_with'      => 'Indique ton numéro de téléphone pour créer ton compte.',
        ];
    }
}
