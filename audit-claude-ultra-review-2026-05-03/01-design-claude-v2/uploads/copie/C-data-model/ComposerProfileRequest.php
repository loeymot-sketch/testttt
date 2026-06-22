<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ComposerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template' => ['required', 'string', 'in:simple,sandwich,tacos,assiette,snacking,menu,custom'],
            'branch_id_scope' => ['nullable', 'integer', 'exists:branches,id'],
            'price' => ['prohibited'],
            'steps' => ['nullable', 'array'],
            'steps.*.step_key' => ['required_with:steps', 'string', 'max:120'],
            'steps.*.label' => ['required_with:steps', 'string', 'max:191'],
            'steps.*.source_type' => ['required_with:steps', 'string', 'in:item_attribute,extra_group,addon'],
            'steps.*.source_ref' => ['nullable', 'string', 'max:191'],
            'steps.*.source_item_attribute_id' => ['nullable', 'integer', 'exists:item_attributes,id'],
            'steps.*.min_select' => ['nullable', 'integer', 'min:0'],
            'steps.*.max_select' => ['nullable', 'integer', 'min:0'],
            'steps.*.allow_repeat' => ['nullable', 'boolean'],
            'steps.*.visible_on' => ['nullable', 'array'],
            'steps.*.visible_on.*' => ['string', 'in:pos,kiosk,web'],
            'steps.*.stockable_choices' => ['nullable', 'boolean'],
            'steps.*.position' => ['nullable', 'integer', 'min:0'],
            'steps.*.is_active' => ['nullable', 'boolean'],
            'steps.*.addon_role' => ['nullable', 'string', 'in:drink,side,dessert,menu_component,upsell'],
            'steps.*.price' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('steps', []) as $index => $step) {
                $this->validateSelectionRange($validator, "steps.{$index}.max_select", (array) $step);
            }
        });
    }

    private function validateSelectionRange(Validator $validator, string $errorKey, array $step): void
    {
        $min = $step['min_select'] ?? null;
        $max = $step['max_select'] ?? null;

        if ($min === null || $max === null || ! is_numeric($min) || ! is_numeric($max)) {
            return;
        }

        if ((int) $max < (int) $min) {
            $validator->errors()->add($errorKey, 'The max select must be greater than or equal to min select.');
        }
    }
}
