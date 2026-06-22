<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class SiteResource extends JsonResource
{

    public $info;

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
        // [abuse-heal 2026-06-18] Null-coalesce every field with FR-correct defaults
        // (mirrors SettingResource). Naked `$this->info['key']` access raised
        // "Undefined array key" notices + serialized nulls when a 'site' setting was
        // unset (fresh install / partial seed), corrupting frontend display.
        return [
            "site_date_format"               => $this->info['site_date_format'] ?? 'd-m-Y',
            "site_time_format"               => $this->info['site_time_format'] ?? 'H:i',
            "site_default_timezone"          => $this->info['site_default_timezone'] ?? 'Europe/Paris',
            "site_default_branch"            => $this->info['site_default_branch'] ?? 1,
            "site_default_currency"          => $this->info['site_default_currency'] ?? 1,
            "site_default_currency_symbol"   => $this->info['site_default_currency_symbol'] ?? '€',
            "site_currency_position"         => $this->info['site_currency_position'] ?? \App\Enums\CurrencyPosition::RIGHT,
            "site_digit_after_decimal_point" => $this->info['site_digit_after_decimal_point'] ?? 2,
            "site_email_verification"        => $this->info['site_email_verification'] ?? 0,
            "site_phone_verification"        => $this->info['site_phone_verification'] ?? 0,
            "site_default_language"          => $this->info['site_default_language'] ?? 1,
            "site_language_switch"           => $this->info['site_language_switch'] ?? 0,
            "site_app_debug"                 => $this->info['site_app_debug'] ?? 0,
            "site_auto_update"               => $this->info['site_auto_update'] ?? 0,
            "site_google_map_key"            => $this->info['site_google_map_key'] ?? '',
            "site_android_app_link"          => $this->info['site_android_app_link'] ?? '',
            "site_ios_app_link"              => $this->info['site_ios_app_link'] ?? '',
            "site_copyright"                 => $this->info['site_copyright'] ?? '© ' . date('Y') . ' ' . config('app.name'),
            "site_online_payment_gateway"    => $this->info['site_online_payment_gateway'] ?? 0,
            "site_default_sms_gateway"       => $this->info['site_default_sms_gateway'] ?? null,
            "site_guest_login"               => $this->info['site_guest_login'] ?? 1,
            "site_default_phone_digit_length" => $this->info['site_default_phone_digit_length'] ?? 10,
        ];
    }
}