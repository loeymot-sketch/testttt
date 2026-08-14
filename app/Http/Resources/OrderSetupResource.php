<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class OrderSetupResource extends JsonResource
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
        return [
            "order_setup_food_preparation_time"        => $this->info['order_setup_food_preparation_time'],
            "order_setup_schedule_order_slot_duration" => $this->info['order_setup_schedule_order_slot_duration'],
            "order_setup_takeaway"                     => $this->info['order_setup_takeaway'],
            "order_setup_delivery"                     => $this->info['order_setup_delivery'],
            /*
             * [DÉCISION OWNER 2026-08-14] Ces 3 clés ne sont plus obligatoires à l'enregistrement
             * (cf. OrderSetupRequest) et leur formulaire a été retiré : un réglage qui n'était lu
             * par AUCUN code métier. On les rend donc tolérantes à l'absence — sans ce repli, un
             * réglage jamais posé ferait planter la lecture de TOUT l'écran de configuration des
             * commandes. Les valeurs déjà en base sont conservées et continuent d'être rendues.
             */
            "order_setup_free_delivery_kilometer"      => $this->info['order_setup_free_delivery_kilometer'] ?? 0,
            "order_setup_basic_delivery_charge"        => $this->info['order_setup_basic_delivery_charge'] ?? 0,
            "order_setup_charge_per_kilo"              => $this->info['order_setup_charge_per_kilo'] ?? 0,
        ];
    }
}