<?php

namespace App\Http\Requests;

use App\Rules\ValidPhone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Foundation\Http\FormRequest;

class EmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // [Wave 1 P1 W-2 / 2026-05-18] Defense-in-depth — mirror Wave 5H pattern on
        // AdministratorRequest. EmployeeController constructor middleware already
        // enforces `permission:employees_create` on store and `permission:employees_edit`
        // on update; FormRequest accepts either since rules apply to both verbs, so any
        // future route mis-wire still authz-checks against the employees capability family.
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        return $user->can('employees_create') || $user->can('employees_edit');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name'                  => ['required', 'string', 'max:190'],
            'email'                 => [
                'required',
                'email',
                'max:190',
                Rule::unique("users", "email")->ignore($this->route('employee.id'))
            ],
            // [2026-05-18 PR-D T5 heal] V1 GO-LIVE staff password policy.
            'password'              => [
                $this->route('employee.id') ? 'nullable' : 'required',
                'string',
                Password::min(12)->letters()->numbers(),
            ],
            'password_confirmation' => [$this->route('employee.id') ? 'nullable' : 'required', 'string', 'min:12'],
            'username'              => [
                'nullable',
                'max:190',
                Rule::unique("users", "username")->ignore($this->route('employee.id'))
            ],
            'device_token'          => ['nullable', 'string'],
            'web_token'             => ['nullable', 'string'],
            'phone'                 => [
                'nullable',
                'string',
                'max:20',
                new ValidPhone(),
                Rule::unique("users", "phone")->ignore($this->route('employee.id'))
            ],
            'branch_id'             => ['nullable', 'numeric'],
            'status'                => ['required', 'numeric', 'max:24'],
            'role_id'               => ['required', 'numeric'],
            'country_code'          => ['required', 'string', 'max:20'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->password !== $this->password_confirmation) {
                $validator->errors()->add('password_confirmation', __('auth.password_confirmation_mismatch'));
            }
        });
    }
}