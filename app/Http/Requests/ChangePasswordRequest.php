<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
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
        // - old_password stays min:6 (legacy users may have weaker existing passwords;
        //   we can't force them invalid before they get a chance to rotate).
        // - new password: min:12 + letters + numbers (Laravel Password rule).
        //   Strict enough for staff + admin paths; consumer paths (CustomerRequest,
        //   SignupRequest) keep min:6 — documented in
        //   plans/v1-0-2-backlog/PASSWORD_POLICY_2026-05-18.md.
        return [
            'old_password'          => ['required', 'string', 'min:6'],
            'password'              => ['required', 'string', Password::min(12)->letters()->numbers()],
            'password_confirmation' => ['required', 'string', 'min:12'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if (!$this->checkOldPassword()) {
                $validator->errors()->add('old_password', __('auth.old_password_mismatch'));
            }
            if ($this->password !== $this->password_confirmation) {
                $validator->errors()->add('password_confirmation', __('auth.password_confirmation_mismatch'));
            }
        });
    }

    public function checkOldPassword()
    {
        $old_password = request('old_password');
        return Hash::check($old_password, auth()->user()->password);
    }
}
