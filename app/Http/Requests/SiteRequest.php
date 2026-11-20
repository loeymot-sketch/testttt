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

    /**
     * [ONB-10 2026-08-27] L'interrupteur « Debug application » pouvait éteindre la caisse.
     *
     * `SiteService::update` écrit `APP_DEBUG=true` dans le `.env` quand cet
     * interrupteur passe à ENABLE. Et `AppServiceProvider` REFUSE DE DÉMARRER en
     * production si `APP_DEBUG=true` — c'est un garde-fou volontaire, contre la fuite
     * de traces, de requêtes SQL et d'identifiants de base.
     *
     * Mis bout à bout, le commerçant qui coche « Activer » sur un écran de réglages
     * ne provoque pas une fuite : il provoque l'ARRÊT COMPLET de sa caisse, à la
     * requête suivante, sans moyen de revenir en arrière depuis l'interface — il faut
     * se connecter à la machine et éditer le `.env` à la main. En plein service.
     *
     * Le commentaire du garde-fou dit lui-même que celui-ci tient lieu de pansement
     * « en attendant » que l'écriture soit bridée à la source (backlog V1.0.2
     * M-P0-D/E/F). C'est ce que fait ce contrôle : on refuse l'écriture plutôt que de
     * laisser le commerçant se couper le courant. En développement, où le garde-fou ne
     * s'applique pas, l'interrupteur continue de fonctionner normalement.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! app()->environment('production')) {
                return;
            }

            if ((int) $this->input('site_app_debug') !== \App\Enums\Activity::ENABLE) {
                return;
            }

            // [ONB-05 2026-08-28 · INCOHERENCE CORRIGEE] Le garde portait sur la
            // VALEUR, pas sur la TRANSITION. Or `site_app_debug` est `required` : le
            // formulaire la renvoie toujours telle qu'elle est stockee. Si le reglage
            // etait deja a ENABLE en base — cas exact du scenario decrit dans le
            // docblock, ou l'exploitant remet APP_DEBUG=false dans le `.env` a la main
            // sans toucher a la base — alors CHANGER SON FUSEAU HORAIRE renvoyait un
            // 422 sur un champ qu'il n'a pas touche. C'est le defaut meme que le
            // commit voisin venait de retirer pour la cle Google Maps.
            //
            // On ne bloque donc que l'ALLUMAGE. Laisser un reglage deja allume ne
            // rouvre rien : le garde-fou de demarrage empeche de toute facon
            // l'application de tourner en production avec APP_DEBUG=true, donc si elle
            // tourne, le `.env` est deja a false et la valeur en base n'est qu'un
            // reste.
            //
            // Trouve par un agent adverse lance sur mon propre travail.
            $stocke = (int) (\Smartisan\Settings\Facades\Settings::group('site')
                ->get('site_app_debug') ?? \App\Enums\Activity::DISABLE);

            if ($stocke === \App\Enums\Activity::ENABLE) {
                return;
            }

            $validator->errors()->add(
                'site_app_debug',
                'Le mode debug est interdit en production : le serveur refuserait de '
                . 'démarrer à la requête suivante, et la caisse serait à l\'arrêt jusqu\'à '
                . 'une intervention sur la machine. Ce réglage reste disponible en '
                . 'développement.'
            );
        });
    }
}