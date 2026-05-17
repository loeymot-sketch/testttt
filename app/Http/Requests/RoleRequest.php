<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use JetBrains\PhpStorm\ArrayShape;

class RoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        // V1.0.1 R7 heal: defense-in-depth — RoleController middleware enforces
        // `permission:settings` on show/store/update/destroy (RoleRequest only injected
        // on store/update); FormRequest doubles down so any future route bypass still authz-checks.
        return $this->user()?->can('settings') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    #[ArrayShape(['name' => "array"])] 
    public function rules() : array
    {
        return [
            'name' => ['required', 'string', 'max:190', Rule::unique("roles", "name")->ignore($this->route('role.id'))],
        ];
    }
}
