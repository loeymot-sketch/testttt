<?php

namespace App\Http\Requests;

use App\Rules\ValidPhone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Foundation\Http\FormRequest;

class ChefRequest extends FormRequest
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
        return [
            'name'                  => ['required', 'string', 'max:190'],
            'email'                 => [
                'required',
                'email',
                'max:190',
                Rule::unique("users", "email")->ignore($this->route('chef.id'))
            ],
            // [2026-05-18 PR-D T5 heal] V1 GO-LIVE staff password policy.
            'password'              => [
                $this->route('chef.id') ? 'nullable' : 'required',
                'string',
                Password::min(12)->letters()->numbers(),
            ],
            'username'              => [
                'nullable',
                'max:190',
                Rule::unique("users", "username")->ignore($this->route('chef.id'))
            ],
            'device_token'          => ['nullable', 'string'],
            'web_token'             => ['nullable', 'string'],
            'password_confirmation' => [$this->route('chef.id') ? 'nullable' : 'required', 'string', 'min:12'],
            // [ONB-06 2026-08-28] Etait `nullable`, alors que
            // `2026_05_16_140100_make_user_phone_required` rend `users.phone` NOT NULL.
            // Laisser le champ vide provoquait une erreur de base de donnees rendue au
            // commercant comme « erreur de base de donnees » — un message qui ne dit ni
            // quel champ, ni quoi faire. La validation doit refuser AVANT, en nommant le
            // telephone. `ProfileRequest` portait deja `required` : l'intention etait
            // connue, elle n'avait pas ete propagee.
            'phone'                 => [
                'required',
                'string',
                'max:20',
                new ValidPhone(),
                Rule::unique("users", "phone")->ignore($this->route('chef.id'))
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
                $validator->errors()->add('password_confirmation', __('auth.password_confirmation_mismatch'));
            }
        });
    }
}
