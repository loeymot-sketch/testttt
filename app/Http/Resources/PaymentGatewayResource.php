<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class PaymentGatewayResource extends JsonResource
{

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */

    public function toArray($request) : array
    {
        // [HEAL dispute-r1 B-R1-19 2026-06-12] Secret guard at the RESOURCE
        // level (SET-01 intent preserved): gateway option VALUES
        // (stripe_secret, paypal_client_secret, …) are only serialized for
        // `settings` holders. Non-settings readers (Branch Manager with
        // `transactions` feeding the /admin/transactions payment-mode filter)
        // get name/slug/status only.
        $canReadSecrets = (bool) $request->user()?->can('settings');

        return [
            'id'      => $this->id,
            'name'    => $this->name,
            'slug'    => $this->slug,
            'status'  => $this->status,
            'options' => ($canReadSecrets && $this->gatewayOptions)
                ? GatewayOptionsResource::collection($this->gatewayOptions)
                : []
        ];
    }

}
