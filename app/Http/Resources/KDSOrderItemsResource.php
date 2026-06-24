<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class KDSOrderItemsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'item_id'            => $this->item_id,
            'item_name'          => $this->orderItem?->name,
            'quantity'           => $this->quantity,
            // [POS-OUTPUT-AUDIT 2026-06-24 P2-KDS-SSOT] Prefer the immutable
            // composition_snapshot (NF525 SSOT) like OrderItemResource — the
            // items-board was the only KDS surface still reading the raw legacy
            // item_variations/item_extras columns. Falls back to legacy for
            // pre-snapshot rows. Addons were already snapshot-first below.
            'item_variations'    => $this->resolveVariationsForKds(),
            'item_extras'        => $this->resolveExtrasForKds(),
            'item_addons'        => $this->resolveAddonsForKds(),
            'instruction'        => $this->instruction,
            // [PK-3 Wave 1 Intersection POS×KDS 2026-05-18] Confirms Z-1 KDS
            // deeper-audit finding: items-board endpoint was allergen-blind
            // while today-orders cards (via KDSOrderDetailsResource →
            // OrderItemResource:37) already exposed allergens_snapshot.
            // KitchenDisplaySystemOrderService::orderItems() (lines 297-326)
            // already splits merged KDS lines by allergen-hash for food
            // safety; the data WAS present in the model row but dropped at
            // serialization, breaking contract parity between the two KDS
            // surfaces. The OrderItem model casts allergens_snapshot to
            // 'array' (app/Models/OrderItem.php:76), so the value reaches
            // the resource as array; safeJsonDecodeArray handles both
            // already-decoded and legacy JSON-string shapes defensively.
            // Sentinel: tests/Feature/KDS/KdsOrderItemsResourceAllergenExposureTest.php.
            'allergens_snapshot' => $this->safeJsonDecodeArray($this->allergens_snapshot),
        ];
    }

    /**
     * KDS must see immutable composer addons (drink/side/dessert/menu choices)
     * that live in composition_snapshot; legacy rows simply have no addons.
     */
    private function resolveAddonsForKds(): array
    {
        $snapshot = $this->safeJsonDecodeArray($this->composition_snapshot);
        if (isset($snapshot['addons']) && is_array($snapshot['addons'])) {
            return array_values($snapshot['addons']);
        }

        return [];
    }

    /**
     * Variations from the immutable composition_snapshot.lines (SSOT) when
     * present; legacy rows fall back to the raw item_variations column. Mirrors
     * OrderItemResource::resolveVariationsForApi so both KDS surfaces agree.
     */
    private function resolveVariationsForKds(): mixed
    {
        $snapshot = $this->safeJsonDecodeArray($this->composition_snapshot);
        if (isset($snapshot['lines']) && is_array($snapshot['lines']) && $snapshot['lines'] !== []) {
            return array_values($snapshot['lines']);
        }

        return $this->safeJsonDecode($this->item_variations);
    }

    /**
     * Extras from composition_snapshot.extras (SSOT) when present; legacy rows
     * fall back to the raw item_extras column.
     */
    private function resolveExtrasForKds(): mixed
    {
        $snapshot = $this->safeJsonDecodeArray($this->composition_snapshot);
        if (isset($snapshot['extras']) && is_array($snapshot['extras']) && $snapshot['extras'] !== []) {
            return array_values($snapshot['extras']);
        }

        return $this->safeJsonDecode($this->item_extras);
    }

    /**
     * Safely decode JSON with error checking
     */
    private function safeJsonDecode(?string $json): mixed
    {
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }

    private function safeJsonDecodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (empty($value) || ! is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
