<?php

namespace App\Http\Resources;


use App\Enums\Status;
use App\Libraries\AppLibrary;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class NormalItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $price = $this->price;

        // Surface filtering: pass ?surface=kiosk|pos|web to restrict extras and variations.
        // null surface = no filtering (admin, backward-compatible).
        $surface = $request->get('surface');

        $filteredExtras = $surface
            ? $this->extras->filter(fn($e) => $e->isVisibleOn($surface))->values()
            : $this->extras;

        $filteredVariations = $surface
            ? $this->variations->filter(fn($v) => $v->isVisibleOn($surface))->values()
            : $this->variations;

        return [
            "id" => $this->id,
            "name" => $this->name,
            "category_name" => optional($this->category)->name,
            // [PLAN_12 ARCH-02] Config wizard depuis la catégorie
            "wizard_template" => optional($this->category)->wizard_template ?? 'simple',
            "has_menu" => optional($this->category)->has_menu ?? false,
            "default_menu_kiosk" => optional($this->category)->default_menu_kiosk ?? false,
            "sauce_included_menu" => optional($this->category)->sauce_included_menu ?? false,
            "slug" => $this->slug,
            "flat_price" => AppLibrary::flatAmountFormat($this->price),
            "convert_price" => AppLibrary::convertAmountFormat($this->price),
            "currency_price" => AppLibrary::currencyAmountFormat($this->price),
            "price" => $this->price,
            "item_type" => $this->item_type,
            "status" => $this->status,
            "description" => $this->description === null ? '' : $this->description,
            "caution" => $this->caution === null ? '' : $this->caution,
            "thumb" => $this->thumb,
            "cover" => $this->cover,
            "preview" => $this->preview,
            "variations" => $filteredVariations->groupBy('item_attribute_id'),
            "itemAttributes" => ItemAttributeResource::collection($this->itemAttributeList($filteredVariations)),
            "extras" => ItemExtraResource::collection($filteredExtras->load('item')),
            "addons" => ItemAddonResource::collection($this->addons->load('addonItem', 'addonItem.variations', 'addonItem.offer', 'item')),
            "offer" => SimpleOfferResource::collection(
                $this->offer->filter(function ($offer) use ($price) {
                    if (
                        Carbon::now()->between(
                            $offer->start_date,
                            $offer->end_date
                        ) && $offer->status === Status::ACTIVE
                    ) {
                        $offer->flat_price = AppLibrary::flatAmountFormat($price - ($price / 100 * $offer->amount));
                        $offer->convert_price = AppLibrary::convertAmountFormat(
                            $price - ($price / 100 * $offer->amount)
                        );
                        $offer->currency_price = AppLibrary::currencyAmountFormat(
                            $price - ($price / 100 * $offer->amount)
                        );
                        return $offer;
                    }
                })
            )
        ];
    }

    private function itemAttributeList(
        $variations
    ): \Vanilla\Support\Collection|\IlluminateAgnostic\Str\Support\Collection|\IlluminateAgnostic\StrAgnostic\Str\Support\Collection|\IlluminateAgnostic\Collection\Support\Collection|\IlluminateAgnostic\ArrAgnostic\Arr\Support\Collection|\Illuminate\Support\Collection|\IlluminateAgnostic\Arr\Support\Collection {
        $array = [];
        foreach ($variations as $b) {
            if (!isset($array[$b->itemAttribute->id])) {
                $array[$b->itemAttribute->id] = (object) [
                    'id' => $b->itemAttribute->id,
                    'name' => $b->itemAttribute->name,
                    'status' => $b->itemAttribute->status
                ];
            }
        }
        return collect($array);
    }
}
