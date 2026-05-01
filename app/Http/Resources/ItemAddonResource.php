<?php

namespace App\Http\Resources;


use App\Enums\Status;
use App\Libraries\AppLibrary;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

use function PHPUnit\Framework\isNull;

class ItemAddonResource extends JsonResource
{

    public $variation = 0;

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */


    public function toArray($request)
    {
        $this->variation = $this->variationTotal();
        $price = $this?->addonItem?->price;
        $offer = $this->addonItem?->offer?->filter(function ($offer) use ($price) {
            if (
                Carbon::now()->between(
                    $offer->start_date,
                    $offer->end_date
                ) && $offer->status == Status::ACTIVE
            ) {
                $amount = ($price - ($price / 100 * $offer->amount));
                $offer->flat_price = AppLibrary::flatAmountFormat($amount);
                $offer->convert_price = AppLibrary::convertAmountFormat($amount);
                $offer->currency_price = AppLibrary::currencyAmountFormat($amount);
                return $offer;
            }
        });
        if (isNull($offer)) {
            $offer = [];
        }
        $total = $this->variation?->price + (count($offer) ? $offer[0]->convert_price : $this->addonItem?->price);
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_addon_id' => $this->addon_item_id,
            'role' => $this->role,
            'item_name' => optional($this->item)->name,
            'addon_item_name' => optional($this->addonItem)->name,
            'addon_item_price' => optional($this->addonItem)->price,
            "addon_item_flat_price" => AppLibrary::flatAmountFormat(optional($this->addonItem)->price),
            "addon_item_convert_price" => AppLibrary::convertAmountFormat(optional($this->addonItem)->price),
            "addon_item_currency_price" => AppLibrary::currencyAmountFormat(optional($this->addonItem)->price),
            'addon_item_status' => optional($this->addonItem)->status,
            'is_available' => $this->is_available === null ? true : (bool) $this->is_available,
            'unavailable_reason' => $this->unavailable_reason,
            'variations' => $this->safeJsonDecode($this->addon_item_variation),
            'variation_total' => optional($this->variation)->price,
            "variation_total_flat_price" => AppLibrary::flatAmountFormat(optional($this->variation)->price),
            "variation_total_convert_price" => AppLibrary::convertAmountFormat(optional($this->variation)->price),
            "variation_total_currency_price" => AppLibrary::currencyAmountFormat(optional($this->variation)->price),
            'total' => $total,
            "total_flat_price" => AppLibrary::flatAmountFormat($total),
            "total_convert_price" => AppLibrary::convertAmountFormat($total),
            "total_currency_price" => AppLibrary::currencyAmountFormat($total),
            'variation_names' => $this->variation?->name,
            "thumb" => $this->addonItem?->thumb ?? null,
            "cover" => $this->addonItem?->cover ?? null,
            "preview" => $this->addonItem?->preview ?? null,
            "caution" => optional($this->addonItem?->caution) == null ? '' : optional(
                $this->addonItem
            )->caution,
            "offer" => SimpleOfferResource::collection($offer)
        ];
    }

    private function variationTotal()
    {
        $variationArray = $this->addonItem?->variations?->mapWithKeys(function ($variation) {
            return [$variation->id => $variation];
        });
        if ($this->addon_item_variation) {
            $decoded = json_decode($this->addon_item_variation, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return (object) ['price' => 0, 'name' => []];
            }
            $variations = (object) $decoded;
            $price = 0;
            $name = [];
            foreach ($variations as $variation) {
                if (isset($variationArray[$variation])) {
                    $name[] = [
                        'id' => $variationArray[$variation]->id,
                        'name' => $variationArray[$variation]->name,
                        'attribute_name' => $variationArray[$variation]->itemAttribute->name
                    ];
                    $price += $variationArray[$variation]->price;
                }
            }
            return (object) [
                'price' => $price,
                'name' => $name
            ];
        }
        return (object) ['price' => 0, 'name' => []];
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
}
