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
    /**
     * [ONB-05 2026-08-28] Chaque lecture porte desormais `?? null`.
     *
     * Elles etaient toutes NON GARDEES : une seule cle absente du groupe `site`
     * faisait lever `Undefined array key` et l'ecran Reglages > Site repondait 500 —
     * pas un message d'erreur, une page morte.
     *
     * Sur la base d'exploitation les 22 cles existent : le defaut est DORMANT, pas
     * actif. Mais il se reveille des qu'une cle manque — installation neuve dont un
     * semoir a change, cle ajoutee au code avant de l'etre en base, ligne supprimee a
     * la main. Un ecran d'administration ne doit pas mourir parce qu'un reglage
     * n'a jamais ete renseigne : il doit l'afficher vide.
     *
     * Trouve par un agent adverse lance sur MON PROPRE travail : quatre de mes bancs
     * de l'ecran Site etaient verts sur ce 500, parce qu'ils n'assertaient que
     * « le code n'est pas 422 ».
     */
    public function toArray($request): array
    {
        return [
            "site_date_format"               => $this->info['site_date_format'] ?? null,
            "site_time_format"               => $this->info['site_time_format'] ?? null,
            "site_default_timezone"          => $this->info['site_default_timezone'] ?? null,
            "site_default_branch"            => $this->info['site_default_branch'] ?? null,
            "site_default_currency"          => $this->info['site_default_currency'] ?? null,
            "site_default_currency_symbol"   => $this->info['site_default_currency_symbol'] ?? null,
            "site_currency_position"         => $this->info['site_currency_position'] ?? null,
            "site_digit_after_decimal_point" => $this->info['site_digit_after_decimal_point'] ?? null,
            "site_email_verification"        => $this->info['site_email_verification'] ?? null,
            "site_phone_verification"        => $this->info['site_phone_verification'] ?? null,
            "site_default_language"          => $this->info['site_default_language'] ?? null,
            "site_language_switch"           => $this->info['site_language_switch'] ?? null,
            "site_app_debug"                 => $this->info['site_app_debug'] ?? null,
            "site_auto_update"               => $this->info['site_auto_update'] ?? null,
            "site_google_map_key"            => $this->info['site_google_map_key'] ?? null,
            "site_android_app_link"          => $this->info['site_android_app_link'] ?? null,
            "site_ios_app_link"              => $this->info['site_ios_app_link'] ?? null,
            "site_copyright"                 => $this->info['site_copyright'] ?? null,
            "site_online_payment_gateway"    => $this->info['site_online_payment_gateway'] ?? null,
            "site_default_sms_gateway"       => $this->info['site_default_sms_gateway'] ?? null,
            "site_guest_login"               => $this->info['site_guest_login'] ?? null,
            "site_default_phone_digit_length" => $this->info['site_default_phone_digit_length'] ?? null
        ];
    }
}