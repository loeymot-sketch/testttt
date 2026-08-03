<?php

namespace App\Http\Requests;

use App\Http\Requests\CurrentPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UserChangePasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        // [2026-05-18 PR-D T5 heal] V1 GO-LIVE password policy per CLAUDE.md §1.
        // Sister: ChangePasswordRequest, AdministratorRequest, EmployeeRequest,
        // ChefRequest, WaiterRequest, DeliveryBoyRequest. Consumer paths
        // (CustomerRequest, SignupRequest) keep min:6 — documented in
        // plans/v1-0-2-backlog/PASSWORD_POLICY_2026-05-18.md.
        return [
            'password'              => ['required', 'string', Password::min(12)->letters()->numbers(), 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:12'],
        ];
    }
}
