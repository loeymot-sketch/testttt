<?php

namespace App\Http\Resources;


use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderSummaryResource extends JsonResource
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
            "delivered"        => $this->info['delivered'],
            "returned"         => $this->info['returned'],
            "canceled"         => $this->info['canceled'],
            "rejected"         => $this->info['rejected'],
            // [CDASH-DONUT-01 / W-VAL c1 2026-06-12] Régression CDV-05 : le
            // service pose total_orders (DashboardService:200) mais le Resource
            // le supprimait → centre du donut « Total 0 ». La série est en
            // POURCENTAGES ; le centre doit afficher le VRAI nombre de commandes.
            "total_orders"     => $this->info['total_orders'] ?? 0,
        ];
    }
}
