<?php

namespace App\Http\Requests;

use App\Rules\ValidPhone;
use App\Support\NomClient;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileRequest extends FormRequest
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
    /**
     * [W7-D D-016 2026-11-04] Le prénom/nom du compte acceptait NUL, ESC et consorts (`users.name` avec `\0`, renvoyé
     * tel quel par `/api/profile`, imprimé sur le ticket et affiché en cuisine) alors que le nom de commande
     * (`pos_customer_name`) est assaini par `NomClient`. Même règle, même outil : contrôle → espace, formatage
     * invisible retiré. Un nom qui ne contient plus aucune lettre devient vide → `required` répond 422.
     */
    protected function prepareForValidation(): void
    {
        $propre = [];
        foreach (['first_name', 'last_name'] as $champ) {
            if ($this->has($champ) && is_string($this->input($champ))) {
                $propre[$champ] = NomClient::nettoyer(mb_substr($this->input($champ), 0, 190)) ?? '';
            }
        }
        if ($propre !== []) {
            $this->merge($propre);
        }
    }

    public function rules()
    {
        return [
            'first_name'   => ['required', 'string', 'max:190'],
            'last_name'    => ['required', 'string', 'max:190'],
            'email'        => [
                'required',
                'email',
                'max:190',
                Rule::unique("users", "email")->ignore(auth()->user()->id)
            ],
            'phone'        => ['required', 'string', 'max:20', new ValidPhone(), Rule::unique("users", "phone")->ignore(auth()->user()->id)],
            'country_code' => ['required', 'string', 'max:20'],
        ];
    }
}