<?php

namespace App\Http\Resources;


use App\Models\ThemeSetting;
use Illuminate\Http\Resources\Json\JsonResource;
use Smartisan\Settings\Facades\Settings;

class SettingResource extends JsonResource
{

    public array $info;

    public function __construct($info)
    {
        parent::__construct($info);
        $this->info = $info;
    }

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        // [PLAN_04 MA-002] Valeurs par défaut pour éviter les null en frontend
        return [
            'company_name'                         => $this->info['company_name'] ?? config('app.name', 'Restaurant'),
            'company_email'                        => $this->info['company_email'] ?? '',
            'company_phone'                        => $this->info['company_phone'] ?? '',
            'company_address'                      => $this->info['company_address'] ?? '',
            'company_country_code'                 => $this->info['company_country_code'] ?? 'FR',
            'site_default_branch'                  => $this->info['site_default_branch'] ?? 1,
            'site_default_language'                => $this->info['site_default_language'] ?? 'fr',
            'site_android_app_link'                => $this->info['site_android_app_link'] ?? '',
            'site_ios_app_link'                    => $this->info['site_ios_app_link'] ?? '',
            'site_copyright'                       => $this->info['site_copyright'] ?? '© ' . date('Y') . ' ' . config('app.name'),
            'site_currency_position'               => $this->info['site_currency_position'] ?? 'left',
            'site_digit_after_decimal_point'       => $this->info['site_digit_after_decimal_point'] ?? 2,
            'site_default_currency_symbol'         => $this->info['site_default_currency_symbol'] ?? '€',
            'site_phone_verification'              => $this->info['site_phone_verification'] ?? 0,
            'site_language_switch'                 => $this->info['site_language_switch'] ?? 0,
            'site_online_payment_gateway'          => $this->info['site_online_payment_gateway'] ?? 0,
            "site_guest_login"                     => $this->info['site_guest_login'] ?? 1,
            'theme_logo'                           => $this->themeImage('theme_logo')?->logo ?? asset('images/theme/theme-logo.png'),
            'theme_footer_logo'                    => $this->themeImage('theme_footer_logo')?->footerLogo ?? asset('images/theme/theme-footer-logo.png'),
            'theme_favicon_logo'                   => $this->themeImage('theme_favicon_logo')?->faviconLogo ?? asset('images/theme/theme-favicon-logo.png'),
            'otp_type'                             => $this->info['otp_type'] ?? 'email',
            'otp_digit_limit'                      => $this->info['otp_digit_limit'] ?? 6,
            'otp_expire_time'                      => $this->info['otp_expire_time'] ?? 5,
            'social_media_facebook'                => $this->info['social_media_facebook'] ?? '',
            'social_media_instagram'               => $this->info['social_media_instagram'] ?? '',
            'social_media_twitter'                 => $this->info['social_media_twitter'] ?? '',
            'social_media_youtube'                 => $this->info['social_media_youtube'] ?? '',
            'order_setup_food_preparation_time'    => $this->info['order_setup_food_preparation_time'] ?? 15,
            'order_setup_takeaway'                 => $this->info['order_setup_takeaway'] ?? 1,
            'order_setup_delivery'                 => $this->info['order_setup_delivery'] ?? 1,
            'order_setup_free_delivery_kilometer'  => $this->info['order_setup_free_delivery_kilometer'] ?? 0,
            'order_setup_basic_delivery_charge'    => $this->info['order_setup_basic_delivery_charge'] ?? 0,
            'order_setup_charge_per_kilo'          => $this->info['order_setup_charge_per_kilo'] ?? 0,
            'cookies_details_page_id'              => $this->info['cookies_details_page_id'] ?? 0,
            'cookies_summary'                      => $this->info['cookies_summary'] ?? '',
            'notification_fcm_api_key'             => $this->info['notification_fcm_api_key'] ?? '',
            'notification_fcm_auth_domain'         => $this->info['notification_fcm_auth_domain'] ?? '',
            'notification_fcm_project_id'          => $this->info['notification_fcm_project_id'] ?? '',
            'notification_fcm_storage_bucket'      => $this->info['notification_fcm_storage_bucket'] ?? '',
            'notification_fcm_messaging_sender_id' => $this->info['notification_fcm_messaging_sender_id'] ?? '',
            'notification_fcm_app_id'              => $this->info['notification_fcm_app_id'] ?? '',
            'notification_fcm_public_vapid_key'    => $this->info['notification_fcm_public_vapid_key'] ?? '',
            'notification_fcm_measurement_id'      => $this->info['notification_fcm_measurement_id'] ?? '',
            'notification_audio'                   => asset('/audio/notification.mp3'),
            'image_cart'                           => asset('/images/cart/empty-cart.gif'),
            'image_confirm'                        => asset('/images/cart/confirm.gif'),
            'image_vag'                            => asset('/images/item-type/veg.png'),
            'image_non_vag'                        => asset('/images/item-type/non-veg.png'),
            'image_app_store'                      => asset('/images/store/app-store.png'),
            'image_play_store'                     => asset('/images/store/play-store.png'),
            'image_order_track'                    => asset('/images/order/track.png'),
            'image_order_placed'                   => asset('/images/order/placed.gif'),
            'image_order_complete'                 => asset('/images/order/complete.gif'),
            'image_order_delivered'                => asset('/images/order/delivered.gif'),
            'image_order_preparing'                => asset('/images/order/preparing_order.gif'),
            'image_order_prepared'                 => asset('/images/order/prepared_order.gif'),
            'image_order_out_for_delivery'         => asset('/images/order/out_for_delivery.gif'),
            'image_order_rejected'                 => asset('/images/order/rejected.gif'),
            'image_order_canceled'                 => asset('/images/order/canceled.gif'),
            'image_order_returned'                 => asset('/images/order/returned.gif'),
            'image_four_zero_four_page'            => asset('/images/accessible/404.gif'),
            'image_four_zero_three_page'           => asset('/images/accessible/403.gif'),
            'image_order_not_found'                => asset('/images/default/not-found.png'),
            'item_not_found'                       => asset('/images/item/item-not-found.png'),
            'demo'                                 => env('DEMO'),

            // [KIOSK-12-1] Alias logo for kiosk idle screen — uses theme_logo as the restaurant logo
            // KioskIdleScreenComponent reads logo_full_path, theme_logo is the canonical source
            'logo_full_path'                       => $this->themeImage('theme_logo')?->logo ?? asset('images/theme/theme-logo.png'),

            // [KIOSK-12-1] Kiosk idle video — configurable via admin settings (kiosk_setup group)
            // Falls back to null so the component shows the animated gradient fallback
            'kiosk_idle_video'                     => $this->info['kiosk_idle_video'] ?? null,

            // [KIOSK-12-2] Kiosk idle screen texts — configurable per restaurant for SaaS
            'kiosk_welcome_title'                  => $this->info['kiosk_welcome_title'] ?? 'Bienvenue !',
            'kiosk_welcome_subtitle'               => $this->info['kiosk_welcome_subtitle'] ?? 'Commandez en quelques touches',
            'kiosk_tap_hint'                       => $this->info['kiosk_tap_hint'] ?? 'Touchez l\'écran pour commander',

            // [ONB-12 2026-08-28] Le tampon « 100 % Halal » de l'ecran d'accueil.
            //
            // Il etait ECRIT EN DUR dans le gabarit. C'est une affirmation sur la
            // nourriture servie — verifiable, engageante, et propre a chaque
            // etablissement. Un nouveau commercant la portait sans l'avoir declaree,
            // et sans aucun moyen de la retirer.
            //
            // Defaut a 0 : on n'affirme rien tant que le commercant ne l'a pas dit.
            // La migration 2026_08_28_120000 declare la valeur des installations
            // existantes pour ne rien leur retirer en silence.
            'kiosk_halal_stamp'                    => (int) (bool) ($this->info['kiosk_halal_stamp'] ?? 0),
            // [ONB-12 2026-08-28] Logo DEDIE a l'ecran d'accueil de la borne.
            // Le logo general des reglages est concu pour un fond clair ; l'attract
            // est orange plein cadre. Sans reglage separe, l'un des deux ecrans est
            // toujours moche. Vide par defaut : on retombe alors sur le logo general,
            // puis sur le NOM du commercant en toutes lettres.
            'kiosk_attract_logo'=> $this->info['kiosk_attract_logo'] ?? null,

            // [PHASE-37] Kiosk multi-language settings
            'kiosk_languages_enabled'              => $this->_parseLanguagesEnabled(),
            'kiosk_default_language'               => $this->info['kiosk_default_language'] ?? 'fr',

            // Customer kiosk is a locked client surface. Staff/admin intervention
            // happens from caisse/admin only, so the kiosk never receives a PIN.
            'kiosk_admin_pin'                      => null,

            // [iter15-mega-fix C-019 2026-05-10] Expose pos.pos_dine_in_enabled
            // to the kiosk frontend so the idle screen can hide the "Sur place"
            // tile when V1 dine-in is disabled. Reads directly from the `pos`
            // settings group (not merged into SettingService::list()) and
            // defaults to FALSE to keep V1 deployments safe per
            // feedback_v1_dine_in_disabled_2026-05-06.
            'pos_dine_in_enabled'                  => (int) (bool) Settings::group('pos')->get('pos_dine_in_enabled', 0),
        ];
    }

    /**
     * [PHASE-37] Parse enabled kiosk languages from settings.
     * Returns array of enabled language codes (e.g., ['fr', 'en', 'ar'])
     * Defaults to ['fr'] if not configured.
     */
    private function _parseLanguagesEnabled(): array
    {
        $enabled = $this->info['kiosk_languages_enabled'] ?? 'fr';

        // If already an array, return it
        if (is_array($enabled)) {
            return array_filter($enabled);
        }

        // If comma-separated string, parse it
        if (is_string($enabled) && str_contains($enabled, ',')) {
            return array_map('trim', array_filter(explode(',', $enabled)));
        }

        // Single value (string)
        return [$enabled];
    }

    public function themeImage($key)
    {
        return ThemeSetting::where(['key' => $key])->first();
    }
}
