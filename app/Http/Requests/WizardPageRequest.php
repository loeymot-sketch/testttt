<?php

namespace App\Http\Requests;

use App\Models\WizardPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class WizardPageRequest extends FormRequest
{
    /**
     * Même droit que le composeur : la route porte déjà `permission:catalog.compose`, on le redit
     * ici pour que l'autorisation vive avec la validation (défense en profondeur — et le cliquet
     * `FormRequestAuthzDriftSentinelTest` ne grandit pas d'un cran).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.compose') ?? false;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

        return [
            'label' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:191'],
            'key' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'kind' => [$isUpdate ? 'sometimes' : 'required', 'string', Rule::in(WizardPage::KINDS)],
            'source_type' => ['nullable', 'string', Rule::in(WizardPage::SOURCE_TYPES)],
            'item_attribute_id' => ['nullable', 'integer', 'exists:item_attributes,id'],
            'extra_group_label' => ['nullable', 'string', 'max:50'],
            'addon_role' => ['nullable', 'string', 'in:drink,side,dessert,menu_component,upsell'],
            'min_select' => ['nullable', 'integer', 'min:0', 'max:99'],
            'max_select' => ['nullable', 'integer', 'min:0', 'max:99'],
            'allow_repeat' => ['nullable', 'boolean'],
            'visible_on' => ['nullable', 'array'],
            'visible_on.*' => ['string', 'in:pos,kiosk,web'],
            'stockable_choices' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'owner_category_id' => ['nullable', 'integer', 'exists:item_categories,id'],
            'description' => ['nullable', 'string', 'max:900'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'price' => ['prohibited'],
            'choices' => ['nullable', 'array'],
            'choices.*.id' => ['nullable', 'integer'],
            'choices.*.name' => ['required_with:choices', 'string', 'max:191'],
            'choices.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'choices.*.addon_item_id' => ['nullable', 'integer', 'exists:items,id'],
            'choices.*.status' => ['nullable', 'integer', 'in:5,10'],
            'choices.*.sort' => ['nullable', 'integer', 'min:0'],
            'choices.*.visible_on' => ['nullable', 'array'],
            'choices.*.visible_on.*' => ['string', 'in:pos,kiosk,web'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $min = $this->input('min_select');
            $max = $this->input('max_select');
            if ($min !== null && $max !== null && is_numeric($min) && is_numeric($max) && (int) $max < (int) $min) {
                $validator->errors()->add('max_select', 'Le maximum doit être supérieur ou égal au minimum.');
            }

            $names = [];
            foreach ((array) $this->input('choices', []) as $index => $choice) {
                $name = mb_strtolower(trim((string) ($choice['name'] ?? '')));
                if ($name === '') {
                    continue;
                }
                if (isset($names[$name])) {
                    $validator->errors()->add("choices.{$index}.name", 'Ce choix apparaît deux fois dans la page.');
                }
                $names[$name] = true;
            }
        });
    }
}
