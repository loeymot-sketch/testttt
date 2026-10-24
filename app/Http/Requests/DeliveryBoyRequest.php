<?php

namespace App\Http\Requests;

use App\Rules\ValidPhone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Foundation\Http\FormRequest;

class DeliveryBoyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * V1.0.2 BUILD-6 heal: defense-in-depth — DeliveryBoyController middleware enforces
     * `permission:delivery-boys_create` on store and `permission:delivery-boys_edit`
     * on update; FormRequest accepts either since the same class is injected on both
     * verbs. Any future route bypass still authz-checks against the family.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        return $user->can('delivery-boys_create') || $user->can('delivery-boys_edit');
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
                Rule::unique("users", "email")->ignore($this->route('deliveryBoy.id'))
            ],
            // [2026-05-18 PR-D T5 heal] V1 GO-LIVE staff password policy.
            'password'              => [
                $this->route('deliveryBoy.id') ? 'nullable' : 'required',
                'string',
                Password::min(12)->letters()->numbers(),
            ],
            'password_confirmation' => [$this->route('deliveryBoy.id') ? 'nullable' : 'required', 'string', 'min:12'],
            'username'              => [
                'nullable',
                'max:190',
                Rule::unique("users", "username")->ignore($this->route('deliveryBoy.id'))
            ],
            'device_token'          => ['nullable', 'string'],
            'web_token'             => ['nullable', 'string'],
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
                Rule::unique("users", "phone")->ignore($this->route('deliveryBoy.id'))
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
