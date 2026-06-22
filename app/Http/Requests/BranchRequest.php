<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // V1.0.1 R7 heal: defense-in-depth — BranchController middleware enforces
        // `permission:settings` on store/update/destroy/updateZone; FormRequest doubles down
        // so any future route bypass still authz-checks.
        return $this->user()?->can('settings') ?? false;
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
                'max:190',
                Rule::unique("branches", "name")->ignore($this->route('branch.id'))
            ],
            'email'     => ['nullable', 'email', 'max:190'],
            'phone'     => ['nullable', 'string', 'max:20'],
            'latitude'  => ['nullable', 'max:190'],
            'longitude' => ['nullable', 'max:190'],
            'city'      => ['required', 'string', 'max:190'],
            'state'     => ['required', 'string', 'max:190'],
            'zip_code'  => ['required', 'string'],
            'address'   => ['required', 'string', 'max:500'],
            'status'    => ['required', 'numeric', 'max:24'],
        ];
    }
}
