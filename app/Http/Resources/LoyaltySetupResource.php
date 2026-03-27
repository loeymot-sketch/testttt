<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltySetupResource extends JsonResource
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
            'loyalty_points_per_euro'            => (int) ($this->info['loyalty_points_per_euro']            ?? 10),
            'loyalty_points_for_1_euro_discount' => (int) ($this->info['loyalty_points_for_1_euro_discount'] ?? 100),
            'loyalty_min_redeem_points'          => (int) ($this->info['loyalty_min_redeem_points']          ?? 50),
        ];
    }
}
