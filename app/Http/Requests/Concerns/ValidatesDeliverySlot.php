<?php

namespace App\Http\Requests\Concerns;

use App\Enums\OrderType;
use App\Services\Delivery\DeliveryWaveService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * [LIVRAISON 2026-10-20] Le créneau de départ choisi par le client doit être valide AVANT le
 * paiement (grille 30 min, heures de service, horizon, tournée non pleine). Sur une commande qui
 * n'est pas une livraison, le champ est simplement ignoré.
 */
trait ValidatesDeliverySlot
{
    protected function validateDeliverySlotAfter($validator): void
    {
        if ((int) $this->input('order_type', 0) !== OrderType::DELIVERY || ! $this->filled('delivery_slot_at') || $validator->errors()->has('delivery_slot_at')) {
            return;
        }

        $branchId = (int) $this->input('branch_id', 0) ?: (int) ($this->user()?->branch_id ?? 0);
        try {
            app(DeliveryWaveService::class)->validatedChosenWave(
                $branchId,
                Carbon::createFromFormat('Y-m-d H:i:s', (string) $this->input('delivery_slot_at')),
                Carbon::now()
            );
        } catch (ValidationException $e) {
            foreach ($e->errors()['delivery_slot_at'] ?? [] as $msg) {
                $validator->errors()->add('delivery_slot_at', $msg);
            }
        }
    }
}
