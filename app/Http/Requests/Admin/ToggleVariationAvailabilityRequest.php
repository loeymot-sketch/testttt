<?php

namespace App\Http\Requests\Admin;

use App\Models\StockLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * [F-016a-BIS] Validates toggle requests for branch-scoped variation rupture.
 *
 * Sibling of {@see ToggleExtraAvailabilityRequest}; identical contract apart
 * from the resource ID key (variation_id vs extra_id) and the existence rule.
 */
class ToggleVariationAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variation_id' => ['required', 'integer', 'exists:item_variations,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'is_available' => ['required', 'boolean'],
            'reason' => [
                'nullable',
                'string',
                'max:32',
                'required_if:is_available,false',
                'required_if:is_available,0',
                Rule::in(StockLevel::MANUAL_UNAVAILABLE_REASONS),
            ],
        ];
    }
}
