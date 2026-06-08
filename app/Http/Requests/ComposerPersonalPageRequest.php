<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 5] "Add a personal / free page" = create a new
 * catalog construct (an ItemExtra group) ON THE FLY and bind a wizard step to it,
 * in one builder action. The page's options each carry their own price ON THE
 * CONSTRUCT (ItemExtra.price) — there is NO price on the wizard step (NF525 SSOT:
 * price is computed backend by id, never authored on a step). source_type stays
 * 'extra_group'; no new enum is introduced.
 */
class ComposerPersonalPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.compose') === true;
    }

    public function rules(): array
    {
        return [
            // Becomes the ItemExtra.group_label binding the step's source_ref (max 50 = column).
            'label' => ['required', 'string', 'max:50'],
            'options' => ['required', 'array', 'min:1', 'max:40'],
            'options.*.name' => ['required', 'string', 'max:191'],
            // Price lives on the construct (ItemExtra), 0 = a free option. NEVER on the step.
            'options.*.price' => ['required', 'numeric', 'min:0'],
            'options.*.description' => ['nullable', 'string', 'max:5000'],
            // [W7 security] image_path is rendered unescaped into the frozen pos-wizard.js
            // img sink — same attribute-breakout / traversal guard as ItemExtraRequest.
            'options.*.image_path' => [
                'nullable', 'string', 'max:2048',
                'regex:/^[A-Za-z0-9._\/:?=&%~+-]+$/',
                'not_regex:/\.\./',
            ],
            'min_select' => ['nullable', 'integer', 'min:0'],
            'max_select' => ['nullable', 'integer', 'min:0'],
            'visible_on' => ['nullable', 'array'],
            'visible_on.*' => ['string', 'in:pos,kiosk,web'],
            // NF525: a price on the page itself is forbidden — options price on the construct.
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
