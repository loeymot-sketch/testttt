<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * V1.0.2 BUILD-6 heal: defense-in-depth — PermissionController middleware enforces
     * `permission:settings` on update (the only verb that injects PermissionRequest).
     * FormRequest doubles down so any future route bypass still authz-checks. Permission
     * mutation is a Spatie RBAC root operation — never allow without explicit settings gate.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        return $this->user()?->can('settings') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        return [
            'permissions'   => ['nullable', 'array'],
            // Avant : PUT {permissions:[999999]} passait. Spatie syncait
            // zéro ligne : le Chef n'avait plus d'écran cuisine, HTTP 200.
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }
}
