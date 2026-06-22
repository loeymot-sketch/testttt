<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiningTableRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * V1.0.2 BUILD-6 heal: defense-in-depth — DiningTableController middleware enforces
     * `permission:dining_tables_create` on store and `permission:dining_tables_edit`
     * on update; FormRequest accepts either since the same class is injected on both
     * verbs. Any future route bypass still authz-checks against the dining_tables family.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        return $user->can('dining_tables_create') || $user->can('dining_tables_edit');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {

        return [
            'name'      => [
                'required',
                'string',
                'max:90',
                Rule::unique('dining_tables', 'name')->where(function ($query) {
                    return $query->where('branch_id', $this->input('branch_id'));
                })->ignore($this->route('diningTable.id')),
            ],
            'size'      => ['required', 'numeric'],
            'branch_id' => ['required', 'numeric'],
            'status'    => ['required', 'numeric', 'max:24'],
        ];
    }
}
