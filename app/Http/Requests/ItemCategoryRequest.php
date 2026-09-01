<?php

namespace App\Http\Requests;

use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemCategoryRequest extends FormRequest
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
        $currentCategoryId = $this->route('itemCategory')?->id ?? $this->input('id');

        return [
            'name'               => [
                'required',
                'string',
                'max:190',
                // [CAT-DATA-02 heal 2026-06-01] ItemCategory uses SoftDeletes — scope uniqueness
                // to non-deleted rows (mirrors ItemRequest:47) so a soft-deleted category's name
                // is reusable instead of permanently blocked.
                Rule::unique("item_categories", "name")->whereNull('deleted_at')->ignore($currentCategoryId)
            ],
            'parent_id'          => array_values(array_filter([
                'nullable',
                'integer',
                'exists:item_categories,id',
                $currentCategoryId ? Rule::notIn([(int) $currentCategoryId]) : null,
            ])),
            'description'        => ['nullable', 'string', 'max:900'],
            'status'             => ['required', 'numeric', 'max:24'],
            // [GOAL-L2-HEAL-02 2026-05-24] Phase L7.1-V1: NoDangerousFileExtension
            // blocks .pht / double-extension polyglot attacks.
            'image'              => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048', new NoDangerousFileExtension()],
            'wizard_template'    => ['nullable', 'string', 'in:simple,tacos,sandwich,burger,assiette,salade,omelette,snacking'],
            'has_menu'           => ['nullable', 'boolean'],
            'default_menu_kiosk' => ['nullable', 'boolean'],
            'sauce_included_menu'=> ['nullable', 'boolean'],
            'kiosk_upsell_include'         => ['nullable', 'boolean'],
            'kiosk_upsell_skip_after_cart' => ['nullable', 'boolean'],
            'channels'           => ['nullable', 'array'],
            'channels.*'         => ['string', 'in:kiosk,pos,web'],
            'kiosk_sort'         => ['nullable', 'integer', 'min:0', 'max:9999'],
            'pos_sort'           => ['nullable', 'integer', 'min:0', 'max:9999'],
            'kiosk_label'        => ['nullable', 'string', 'max:60'],
        ];
    }
}
