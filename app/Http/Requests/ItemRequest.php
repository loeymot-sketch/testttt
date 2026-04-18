<?php

namespace App\Http\Requests;

use App\Models\Allergen;
use App\Rules\IniAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        $allergenCodes = Allergen::query()->pluck('code')->all();

        return [
            'name'            => [
                'required',
                'string',
                'max:190',
                Rule::unique("items", "name")->whereNull('deleted_at')->ignore($this->route('item.id'))
            ],
            'item_category_id' => ['required', 'numeric', 'not_in:0'],
            'tax_id'           => ['nullable', 'numeric', 'not_in:0'],
            'item_type'        => ['required', 'numeric', 'not_in:0'],
            'price'            => ['required', new IniAmount()],
            'is_featured'      => ['required', 'numeric', 'not_in:0'],
            // [GAP-27-1] is_upsell — optional flag for Splash-style upsell suggestions on kiosk
            'is_upsell'        => ['nullable', 'numeric'],
            'is_chef_pick'     => ['nullable', 'boolean'],
            'is_new'           => ['nullable', 'boolean'],
            'is_available'     => ['nullable', 'boolean'],
            'is_spicy'         => ['nullable', 'boolean'],
            'is_vegetarian'    => ['nullable', 'boolean'],
            'is_pork_free'     => ['nullable', 'boolean'],
            'is_halal'         => ['nullable', 'boolean'],
            'is_gluten_free'   => ['nullable', 'boolean'],
            'chef_pick_order'  => ['nullable', 'integer', 'min:0', 'max:9999'],
            'channels'         => ['nullable', 'array'],
            'channels.*'       => ['string', 'in:kiosk,pos,web'],
            'allergen_flags'   => ['nullable', 'array'],
            'allergen_flags.*' => array_values(array_filter([
                'string',
                $allergenCodes !== [] ? Rule::in($allergenCodes) : null,
            ])),
            'kiosk_emoji'      => ['nullable', 'string', 'max:10'],
            'description'      => ['nullable', 'string', 'max:5000'],
            'caution'          => ['nullable', 'string', 'max:5000'],
            'status'           => ['required', 'numeric', 'max:24'],
            'order'            => ['required', 'numeric'],
            'variations'       => ['nullable', 'json'],
            'extras'           => ['nullable', 'json'],
            'image'            => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }

    public function attributes()
    {
        return [
            'item_category_id' => strtolower(trans('all.label.item_category_id')),
            'tax_id'           => strtolower(trans('all.label.tax_id')),
        ];
    }
}
