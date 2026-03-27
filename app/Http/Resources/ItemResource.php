<?php

namespace App\Http\Resources;


use App\Enums\Status;
use App\Libraries\AppLibrary;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        $price = $this->price;
        return [
            "id"               => $this->id,
            "name"             => $this->name,
            "slug"             => $this->slug,
            "item_category_id" => $this->item_category_id,
            "tax_id"           => $this->tax_id,
            "flat_price"       => AppLibrary::flatAmountFormat($this->price),
            "convert_price"    => AppLibrary::convertAmountFormat($this->price),
            "currency_price"   => AppLibrary::currencyAmountFormat($this->price),
            "price"            => $this->price,
            "item_type"        => $this->item_type,
            "is_featured"      => $this->is_featured,
            // [GAP-27-1] Expose is_upsell so admin UI can read/write it (Ask::NO=10 default)
            "is_upsell"        => $this->is_upsell ?? 10,
            "status"           => $this->status,
            "description"      => $this->description === null ? '' : $this->description,
            "caution"          => $this->caution === null ? '' : $this->caution,
            "sort_order"       => $this->order,
            "order_count"      => $this->orders->count(),
            "thumb"            => $this->thumb,
            "cover"            => $this->cover,
            "preview"          => $this->preview,
            "category_name"    => optional($this->category)->name,
            "wizard_template"  => optional($this->category)->wizard_template ?? 'simple',
            "has_menu"         => (bool)(optional($this->category)->has_menu ?? false),
            "category"         => new ItemCategoryResource($this->category),
            "tax"              => new TaxResource($this->tax),
            "variations"       => $this->variations->groupBy('item_attribute_id'),
            "itemAttributes"   => ItemAttributeResource::collection($this->itemAttributeList($this->variations)),
            "extras"           => ItemExtraResource::collection($this->extras),
            "addons"           => ItemAddonResource::collection($this->addons->load('addonItem')),
            "offer"            => SimpleOfferResource::collection(
                $this->offer->filter(function ($offer) use ($price) {
                    if (Carbon::now()->between($offer->start_date, $offer->end_date) && $offer->status === Status::ACTIVE) {
                        $amount                = ($price - ($price / 100 * $offer->amount));
                        $offer->flat_price     = AppLibrary::flatAmountFormat($amount);
                        $offer->convert_price  = AppLibrary::convertAmountFormat($amount);
                        $offer->currency_price = AppLibrary::currencyAmountFormat($amount);
                        return $offer;
                    }
                })
            )
        ];
    }

    private function itemAttributeList($variations)
    {
        $array = [];
        foreach ($variations as $b) {
            if (!isset($array[$b->itemAttribute->id])) {
                $array[$b->itemAttribute->id] = (object)[
                    'id'     => $b->itemAttribute->id,
                    'name'   => $b->itemAttribute->name,
                    'status' => $b->itemAttribute->status
                ];
            }
        }
        return collect($array);
    }
}
