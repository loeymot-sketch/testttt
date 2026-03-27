<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KioskSetupResource extends JsonResource
{
    public $info;

    public function __construct($info)
    {
        parent::__construct($info);
        $this->info = $info;
    }

    public function toArray($request): array
    {
        return [
            'kiosk_idle_video'       => $this->info['kiosk_idle_video']       ?? null,
            'kiosk_welcome_title'    => $this->info['kiosk_welcome_title']    ?? 'Bienvenue !',
            'kiosk_welcome_subtitle' => $this->info['kiosk_welcome_subtitle'] ?? 'Commandez en quelques touches',
            'kiosk_tap_hint'         => $this->info['kiosk_tap_hint']         ?? 'Touchez l\'écran pour commander',
            // PIN is returned masked — never expose the real value to the frontend
            'kiosk_admin_pin_set'    => !empty($this->info['kiosk_admin_pin']),
        ];
    }
}
