<?php

namespace App\Http\Requests;


use App\Enums\Ask;
use App\Rules\ValidPhone;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class SignupRequest extends FormRequest
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
    public function rules(): array
    {
        return [
            'first_name'   => ['required', 'string', 'max:255'],
            'last_name'    => ['required', 'string', 'max:255'],
            'email'        => ['nullable', 'string', 'email', 'max:255', Rule::unique("users", "email")->whereNull('deleted_at')->where('is_guest', Ask::NO)],
            'phone'        => ['required', 'numeric', new ValidPhone(), Rule::unique("users", "phone")->whereNull('deleted_at')->where('is_guest', Ask::NO)],
            'country_code' => ['required', 'numeric'],
            'password'     => ['required', 'string', 'min:6'],
        ];
    }

    /**
     * [Sprint H1 Z6-05 2026-05-17] Strip server-controlled fields from the
     * validated payload before persistence. CLAUDE.md §9.
     *
     * Defense-in-depth: SignupController::register() currently enumerates
     * fields explicitly when calling User::create([...]), so this override
     * has no behavioral impact today. It exists to lock the FormRequest
     * layer against future refactors that switch to
     * `User::create($request->validated())` (or `->fill(...)`) AND broaden
     * rules() to validate any of these keys.
     *
     * The 3 fields stripped are server-controlled:
     *   - branch_id: set to 0 for public signup (CUSTOMER scope agnostic)
     *   - is_guest:  set to Ask::NO for non-guest signup
     *   - status:    DB default Status::ACTIVE; future moderation queue
     *                must remain server-controlled (e.g. INACTIVE pending
     *                phone-verification or anti-fraud review)
     *
     * User::$fillable stays permissive (admin tooling, factories, console
     * commands explicitly set these in trusted code). The strip layer
     * applies only at this public-facing FormRequest.
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if (is_array($validated)) {
            unset($validated['branch_id'], $validated['is_guest'], $validated['status']);
        }

        return $validated;
    }
}