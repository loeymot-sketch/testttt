<?php

namespace App\Http\Requests\Admin;

use App\Models\StockLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * [F-016a-BIS] Validates toggle requests for branch-scoped extra rupture.
 *
 * Reason whitelist is enforced when is_available=false to keep dashboard
 * buckets clean. The list lives on {@see StockLevel::MANUAL_UNAVAILABLE_REASONS}
 * so any future addition is centralized.
 */
class ToggleExtraAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'extra_id' => ['required', 'integer', 'exists:item_extras,id'],
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
