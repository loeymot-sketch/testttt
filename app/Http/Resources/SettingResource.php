<?php

namespace App\Http\Resources;


use App\Models\ThemeSetting;
use Illuminate\Http\Resources\Json\JsonResource;

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
        return [
            'company_name'                         => $this->info['company_name'] ?? null,
            'company_email'                        => $this->info['company_email'] ?? null,
            'company_phone'                        => $this->info['company_phone'] ?? null,
            'company_address'                      => $this->info['company_address'] ?? null,
            'company_country_code'                 => $this->info['company_country_code'] ?? null,
            'site_default_branch'                  => $this->info['site_default_branch'] ?? null,
            'site_default_language'                => $this->info['site_default_language'] ?? null,
            'site_android_app_link'                => $this->info['site_android_app_link'] ?? null,
            'site_ios_app_link'                    => $this->info['site_ios_app_link'] ?? null,
            'site_copyright'                       => $this->info['site_copyright'] ?? null,
            'site_currency_position'               => $this->info['site_currency_position'] ?? null,
            'site_digit_after_decimal_point'       => $this->info['site_digit_after_decimal_point'] ?? null,
            'site_default_currency_symbol'         => $this->info['site_default_currency_symbol'] ?? null,
            'site_phone_verification'              => $this->info['site_phone_verification'] ?? null,
            'site_language_switch'                 => $this->info['site_language_switch'] ?? null,
            'site_online_payment_gateway'          => $this->info['site_online_payment_gateway'] ?? null,
            "site_guest_login"                     => $this->info['site_guest_login'] ?? null,
            'theme_logo'                           => $this->themeImage('theme_logo')?->logo ?? asset('images/theme/theme-logo.png'),
            'theme_footer_logo'                    => $this->themeImage('theme_footer_logo')?->footerLogo ?? asset('images/theme/theme-footer-logo.png'),
            'theme_favicon_logo'                   => $this->themeImage('theme_favicon_logo')?->faviconLogo ?? asset('images/theme/theme-favicon-logo.png'),
            'otp_type'                             => $this->info['otp_type'] ?? null,
            'otp_digit_limit'                      => $this->info['otp_digit_limit'] ?? null,
            'otp_expire_time'                      => $this->info['otp_expire_time'] ?? null,
            'social_media_facebook'                => $this->info['social_media_facebook'] ?? null,
            'social_media_instagram'               => $this->info['social_media_instagram'] ?? null,
            'social_media_twitter'                 => $this->info['social_media_twitter'] ?? null,
            'social_media_youtube'                 => $this->info['social_media_youtube'] ?? null,
            'order_setup_food_preparation_time'    => $this->info['order_setup_food_preparation_time'] ?? null,
            'order_setup_takeaway'                 => $this->info['order_setup_takeaway'] ?? null,
            'order_setup_delivery'                 => $this->info['order_setup_delivery'] ?? null,
            'order_setup_free_delivery_kilometer'  => $this->info['order_setup_free_delivery_kilometer'] ?? null,
            'order_setup_basic_delivery_charge'    => $this->info['order_setup_basic_delivery_charge'] ?? null,
            'order_setup_charge_per_kilo'          => $this->info['order_setup_charge_per_kilo'] ?? null,
            'cookies_details_page_id'              => $this->info['cookies_details_page_id'] ?? null,
            'cookies_summary'                      => $this->info['cookies_summary'] ?? null,
            'notification_fcm_api_key'             => $this->info['notification_fcm_api_key'] ?? null,
            'notification_fcm_auth_domain'         => $this->info['notification_fcm_auth_domain'] ?? null,
            'notification_fcm_project_id'          => $this->info['notification_fcm_project_id'] ?? null,
            'notification_fcm_storage_bucket'      => $this->info['notification_fcm_storage_bucket'] ?? null,
            'notification_fcm_messaging_sender_id' => $this->info['notification_fcm_messaging_sender_id'] ?? null,
            'notification_fcm_app_id'              => $this->info['notification_fcm_app_id'] ?? null,
            'notification_fcm_public_vapid_key'    => $this->info['notification_fcm_public_vapid_key'] ?? null,
            'notification_fcm_measurement_id'      => $this->info['notification_fcm_measurement_id'] ?? null,
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
        ];
    }

    public function themeImage($key)
    {
        return ThemeSetting::where(['key' => $key])->first();
    }
}