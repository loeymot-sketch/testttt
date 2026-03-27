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
            // PIN must be exactly 4 digits — validated as string to preserve leading zeros
            'kiosk_admin_pin'       => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }
}
