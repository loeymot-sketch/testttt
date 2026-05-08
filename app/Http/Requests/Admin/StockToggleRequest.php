<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * [F-016b minimal]
 *
 * Validates a single rupture toggle (one of items / extras / variations).
 *
 * Branch authorization is enforced in the controller because Spatie
 * permission middleware (`items_edit`) handles the role gate, but
 * cross-tenant scope (admin@branch_id=1 must not toggle branch_id=2)
 * still requires runtime data and lives next to the AvailabilityService
 * call.
 */
class StockToggleRequest extends FormRequest
{
    /**
     * Auth/permission is enforced via Sanctum + the
     * `permission:items_edit` middleware on the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'string', Rule::in(['item', 'extra', 'variation'])],
            'entity_id'   => ['required', 'integer', 'min:1'],
            'branch_id'   => ['required', 'integer', 'exists:branches,id'],
            'is_available' => ['required', 'boolean'],
            // 32 chars matches `item_branch_availability.unavailable_reason`.
            'reason'      => ['nullable', 'string', 'max:32'],
        ];
    }
}
