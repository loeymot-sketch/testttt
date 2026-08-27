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
        // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] adversarial-dispute
        // finding: site_default_timezone, site_date_format, site_time_format
        // and site_google_map_key are written verbatim into .env
        // (SiteService.php:47-60, same EnvEditor::addData sink as
        // Mail/License/Company) but never got the injection guard those
        // siblings received — a raw \r/\n/" lets the value inject an
        // independent .env line (e.g. APP_DEBUG=true).
        $noEnvInjection = 'regex:/^[^\r\n"]*$/';

        return [
            'site_date_format'               => ['required', 'string', 'max:190', $noEnvInjection],
            'site_time_format'               => ['required', 'string', 'max:190', $noEnvInjection],
            'site_default_timezone'          => ['required', 'string', 'max:190', $noEnvInjection],
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
            // [ONB-10 2026-08-27] Ces deux-là étaient `required` et valent NULL sur
            // l'installation réelle : l'écran Site entier était donc inenregistrable.
            // Un commerçant qui voulait changer son fuseau horaire, son format de date
            // ou la position du symbole € se prenait un 422 sur une clé d'API Google
            // Maps qu'il n'a pas — V1 est mono-établissement, en local, livraison
            // désactivée. Sa seule issue était d'inventer une valeur, écrite ensuite
            // VERBATIM dans le `.env`. Une clé d'API tierce et une mention de pied de
            // page ne conditionnent pas le fuseau horaire d'une caisse.
            //
            // Le garde-fou anti-injection `.env` ci-dessus reste appliqué : c'est lui
            // qui compte, et ReglagesDuSiteEnregistrablesTest vérifie qu'il mord encore.
            'site_google_map_key'            => ['nullable', 'string', 'max:190', $noEnvInjection],
            'site_android_app_link'          => ['nullable', 'string', 'max:190'],
            'site_ios_app_link'              => ['nullable', 'string', 'max:190'],
            'site_copyright'                 => ['nullable', 'string', 'max:190'],
            'site_online_payment_gateway'    => ['required', 'numeric'],
            'site_default_sms_gateway'       => ['nullable', 'numeric'],
            'site_guest_login'               => ['required', 'numeric'],
            'site_default_phone_digit_length' => ['required', 'numeric'],
        ];
    }
}