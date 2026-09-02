<?php

namespace App\Http\Resources;


use Carbon\Carbon;
use App\Enums\PaymentStatus;
use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesSummaryResource extends JsonResource
{
    public $info;

    public function __construct($info)
    {
        parent::__construct($info);
        $this->info = $info;
    }

    public function toArray($request)
    {
        return [
            "total_sales"   => $this->info['total_sales'],
            "avg_per_day"   => $this->info['avg_per_day'],
            "per_day_sales" => $this->info['per_day_sales'],
            // [2026-09-02] Les jours, dans le même ordre que les montants. Sans eux le
            // graphique traçait une courbe sans aucune date en abscisse — illisible — et
            // la génération des jours n'était observable par aucun banc.
            "per_day_labels" => $this->info['per_day_labels'] ?? [],
        ];
    }
}
