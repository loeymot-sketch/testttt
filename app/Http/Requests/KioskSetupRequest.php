<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KioskSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kiosk_idle_video'      => ['nullable', 'string', 'max:500'],
            'kiosk_welcome_title'   => ['nullable', 'string', 'max:100'],
            'kiosk_welcome_subtitle'=> ['nullable', 'string', 'max:200'],
            'kiosk_tap_hint'        => ['nullable', 'string', 'max:100'],
            // [ONB-12 2026-08-28] Affirmation « 100 % Halal » de l'ecran d'accueil.
            // Elle etait ecrite en dur dans le gabarit ; elle devient un choix du
            // commercant, eteint par defaut.
            'kiosk_halal_stamp'     => ['nullable', 'boolean'],
            // [ONB-12 2026-08-28] Chemin ou URL du logo d'accueil borne.
            'kiosk_attract_logo'    => ['nullable', 'string', 'max:500'],
            // PIN must be exactly 4 digits — validated as string to preserve leading zeros
            'kiosk_admin_pin'       => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }
}
