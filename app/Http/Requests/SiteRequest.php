<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // [ULTRA-AUDIT V4-DEPLOY 2026-07-02] Defense-in-depth : la route SiteController::update porte déjà
        // `permission:settings` ; on double le garde ici (cohérence, évite un return-true nu sur des settings
        // sensibles écrits en .env via SiteService/EnvEditor).
        return (bool) ($this->user()?->can('settings'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'site_date_format'               => ['required', 'string', 'max:190'],
            'site_time_format'               => ['required', 'string', 'max:190'],
            'site_default_timezone'          => ['required', 'string', 'max:190'],
            'site_default_branch'            => ['required', 'numeric'],
            'site_default_currency'          => ['required', 'numeric'],
            'site_currency_position'         => ['required', 'numeric'],
            'site_digit_after_decimal_point' => ['required', 'numeric', 'max:6'],
            'site_email_verification'        => ['required', 'numeric'],
            'site_phone_verification'        => ['required', 'numeric'],
            'site_default_language'          => ['required', 'numeric'],
            'site_language_switch'           => ['required', 'numeric'],
            'site_app_debug'                 => ['required', 'numeric'],
            'site_auto_update'               => ['nullable', 'numeric'],
            'site_google_map_key'            => ['required', 'string', 'max:190'],
            'site_android_app_link'          => ['nullable', 'string', 'max:190'],
            'site_ios_app_link'              => ['nullable', 'string', 'max:190'],
            'site_copyright'                 => ['required', 'string', 'max:190'],
            'site_online_payment_gateway'    => ['required', 'numeric'],
            'site_default_sms_gateway'       => ['nullable', 'numeric'],
            'site_guest_login'               => ['required', 'numeric'],
            'site_default_phone_digit_length' => ['required', 'numeric'],
        ];
    }
}