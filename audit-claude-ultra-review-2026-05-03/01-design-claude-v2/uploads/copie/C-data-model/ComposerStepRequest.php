<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ComposerStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'step_key' => ['required', 'string', 'max:120'],
            'label' => ['required', 'string', 'max:191'],
            'source_type' => ['required', 'string', 'in:item_attribute,extra_group,addon'],
            'source_ref' => ['nullable', 'string', 'max:191'],
            'source_item_attribute_id' => ['nullable', 'integer', 'exists:item_attributes,id'],
            'min_select' => ['nullable', 'integer', 'min:0'],
            'max_select' => ['nullable', 'integer', 'min:0'],
            'allow_repeat' => ['nullable', 'boolean'],
            'visible_on' => ['nullable', 'array'],
            'visible_on.*' => ['string', 'in:pos,kiosk,web'],
            'stockable_choices' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'addon_role' => ['nullable', 'string', 'in:drink,side,dessert,menu_component,upsell'],
            'price' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $min = $this->input('min_select');
            $max = $this->input('max_select');

            if ($min === null || $max === null || ! is_numeric($min) || ! is_numeric($max)) {
                return;
            }

            if ((int) $max < (int) $min) {
                $validator->errors()->add('max_select', 'The max select must be greater than or equal to min select.');
            }
        });
    }
}
