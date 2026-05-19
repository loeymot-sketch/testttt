<?php

namespace App\Http\Requests;

use App\Rules\ValidPhone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Foundation\Http\FormRequest;

class AdministratorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // V1.0.1 R7 heal: defense-in-depth — AdministratorController middleware enforces
        // `permission:administrators_create` on store and `permission:administrators_edit`
        // on update; FormRequest accepts either since rules apply to both verbs, so any
        // future route bypass still authz-checks against the administrators capability family.
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        return $user->can('administrators_create') || $user->can('administrators_edit');
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
                Rule::unique("users", "email")->ignore($this->route('administrator.id'))
            ],
            // [2026-05-18 PR-D T5 heal] V1 GO-LIVE staff password policy per
            // CLAUDE.md §1: min:12 + letters + numbers. Update path stays
            // nullable so admins can edit profile without rotating creds.
            'password'              => [
                $this->route('administrator.id') ? 'nullable' : 'required',
                'string',
                Password::min(12)->letters()->numbers(),
            ],
            'password_confirmation' => [$this->route('administrator.id') ? 'nullable' : 'required', 'string', 'min:12'],
            'phone'                 => [
                'nullable',
                'string',
                'max:20',
                new ValidPhone(),
                Rule::unique("users", "phone")->ignore($this->route('administrator.id'))
            ],
            'branch_id'             => ['nullable', 'numeric'],
            'status'                => ['required', 'numeric', 'max:24'],
            'country_code'          => ['required', 'string', 'max:20'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->password !== $this->password_confirmation) {
                $validator->errors()->add('password_confirmation', 'The password confirmation does not match.');
            }

            // [bug_011 / R3 T-2.2.1 Sec S-3 / WH-5 2026-05-19] Close the
            // super-admin mint bypass. The previous M-R3-P0-E heal only
            // covered the EXPLICIT path `branch_id=0`; omitting the
            // `branch_id` field entirely bypassed the gate because
            // `$this->input('branch_id')` returns null and `null !== null`
            // is false, short-circuiting the check. The downstream
            // `DefaultAccessModelTrait::branch()` then treats
            // `(int) null === 0` as a super-admin (BranchScope skipped),
            // so a null branch_id is functionally equivalent to
            // branch_id=0 from an authorization standpoint. The fix:
            // treat null and 0 identically — only Admin / Tenant Admin
            // may mint a user without a branch (cross-branch super-admin).
            // Non-super-admin callers MUST supply a non-zero branch_id.
            //
            // Covers both store (POST) and update (PATCH/PUT) verbs
            // since both controllers consume AdministratorRequest, and
            // AdministratorService::update line 96 writes branch_id from
            // the request unconditionally too.
            $caller = $this->user();
            $bid = $this->input('branch_id');
            $isSuperAdmin = $caller?->hasRole('Admin') || $caller?->hasRole('Tenant Admin');
            if (($bid === null || (int) $bid === 0) && ! $isSuperAdmin) {
                $validator->errors()->add(
                    'branch_id',
                    'Only super-admin (Admin / Tenant Admin) can mint or update a user '
                    . 'at branch_id=0 or with no branch (R3 T-2.2.1 Sec S-3 / bug_011).'
                );
            }
        });
    }
}
