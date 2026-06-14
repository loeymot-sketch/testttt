<?php

namespace App\Http\Resources;

use App\Enums\TaxType;
use App\Models\Currency;
use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;
use Smartisan\Settings\Facades\Settings;

class OrderItemResource extends JsonResource
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
            'id'                               => $this->id,
            'order_id'                         => $this->order_id,
            'branch_id'                        => $this->branch_id,
            'item_id'                          => $this->orderItem?->id,
            'item_name'                        => $this->orderItem?->name,
            'item_image'                       => $this->orderItem?->thumb,
            'quantity'                         => $this->quantity,
            'discount'                         => AppLibrary::currencyAmountFormat($this->discount),
            'price'                            => AppLibrary::currencyAmountFormat($this->price),
            // [T07] Prefer immutable composition_snapshot when present (post-T07 rows);
            // legacy rows (composition_snapshot=null) fall back to raw item_variations / item_extras JSON.
            'item_variations'                  => $this->resolveVariationsForApi(),
            'item_extras'                      => $this->resolveExtrasForApi(),
            'item_addons'                      => $this->resolveAddonsForApi(),
            'composition_snapshot'             => $this->safeJsonDecode($this->composition_snapshot),
            'allergens_snapshot'               => $this->safeJsonDecode($this->allergens_snapshot),
            'item_variation_currency_total'    => AppLibrary::currencyAmountFormat($this->item_variation_total),
            'item_extra_currency_total'        => AppLibrary::currencyAmountFormat($this->item_extra_total),
            // [WT-D-R1-F4 2026-05-20] Raw numeric line `total_price` for the
            // canonical `formatPrice()` admin renderer (mirrors
            // SimpleOrderResource shape). `total_convert_price` already
            // returns a float but rounded via `number_format(..., '.', '')`,
            // which on some locales surfaces as a string in JSON — we ship
            // an unambiguous float to feed Intl.NumberFormat without parse
            // surprises.
            'total_price'                      => round((float) ($this->total_price ?? 0), 2),
            'total_convert_price'              => AppLibrary::convertAmountFormat($this->total_price),
            'total_currency_price'             => AppLibrary::currencyAmountFormat($this->total_price),
            'instruction'                      => $this->instruction,
            'kds_station'                      => $this->orderItem?->kds_station ?? 'none',
            'tax_type'                         => $this->tax_type === TaxType::FIXED ? config('currency.code') : '%',
            'tax_rate'                         => $this->tax_rate,
            'tax_currency_rate'                => AppLibrary::flatAmountFormat($this->tax_rate),
            'tax_name'                         => $this->tax_name,
            'tax_currency_amount'              => AppLibrary::currencyAmountFormat($this->tax_amount),
            'total_without_tax_currency_price' => AppLibrary::currencyAmountFormat($this->total_price - $this->tax_amount),
        ];
    }

    /**
     * [T07] Variations resolution with snapshot priority.
     *
     * Returns the snapshot's `lines` array (rich shape) when composition_snapshot
     * is present and well-formed. Otherwise falls back to the legacy
     * `item_variations` JSON column (lean [{id,quantity?}] shape).
     *
     * Backward compatibility note: existing kiosk/POS clients that consume the
     * legacy `item_variations` shape continue to work because the snapshot's
     * `lines` is also an array of objects keyed by `variation_id`. Front-ends that
     * want richer data (attribute_name, line_total) can read `composition_snapshot`.
     */
    private function resolveVariationsForApi(): array
    {
        $snapshot = $this->safeJsonDecode($this->composition_snapshot);
        if (is_array($snapshot) && isset($snapshot['lines']) && is_array($snapshot['lines']) && $snapshot['lines'] !== []) {
            return array_values($snapshot['lines']);
        }
        return $this->safeJsonDecode($this->item_variations);
    }

    /**
     * [T07] Extras resolution with snapshot priority. See resolveVariationsForApi.
     */
    private function resolveExtrasForApi(): array
    {
        $snapshot = $this->safeJsonDecode($this->composition_snapshot);
        if (is_array($snapshot) && isset($snapshot['extras']) && is_array($snapshot['extras']) && $snapshot['extras'] !== []) {
            return array_values($snapshot['extras']);
        }
        return $this->safeJsonDecode($this->item_extras);
    }

    /**
     * Composer addons only exist in the immutable composition snapshot.
     */
    private function resolveAddonsForApi(): array
    {
        $snapshot = $this->safeJsonDecode($this->composition_snapshot);
        if (is_array($snapshot) && isset($snapshot['addons']) && is_array($snapshot['addons']) && $snapshot['addons'] !== []) {
            return array_values($snapshot['addons']);
        }

        return [];
    }

    /**
     * Safely decode JSON with error checking
     */
    private function safeJsonDecode(mixed $value): mixed
    {
        // [T07] When Eloquent has already cast the column (composition_snapshot
        // and allergens_snapshot are cast to 'array'), preserve associative keys
        // intact — array_values() would strip schema_version/lines/extras.
        if (is_array($value)) {
            return $value;
        }

        if (empty($value) || !is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        if (is_array($decoded)) {
            return $decoded;
        }

        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
