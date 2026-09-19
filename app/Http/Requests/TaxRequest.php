<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\Status;
use Illuminate\Validation\Rule;

class TaxRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // V1.0.1 R7 heal: defense-in-depth — TaxController middleware enforces
        // `permission:settings` on show/store/update/destroy; FormRequest doubles down
        // so any future route bypass still authz-checks.
        return $this->user()?->can('settings') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name'              => [
                'required',
                'string',
                'max:190'
            ],
            'code'              => [
                'required',
                'string',
                'max:20',
                Rule::unique("taxes", "code")->ignore($this->route('tax.id'))
            ],
            // [ONB-04 2026-08-28] Le plafond valait 9 999 999 999 999.
            //
            // Un commercant qui saisit « 2000 » en pensant « 20 % » obtenait un taux
            // de 2000 %, accepte sans un mot et facture au client. Un taux de TVA
            // au-dela de 100 % n'existe dans aucun regime : le borner n'enleve
            // aucune possibilite reelle, et attrape la faute de frappe la plus
            // naturelle qui soit.
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            // `max:24` acceptait des valeurs qu'aucun code ne sait lire :
            // `App\Enums\Status` ne definit que 5 (actif) et 10 (inactif).
            'status'   => ['required', 'numeric', Rule::in([Status::ACTIVE, Status::INACTIVE])],
        ];
    }

    public function messages(): array
    {
        return [
            'tax_rate.max' => 'Un taux de TVA ne depasse pas 100 %. Saisissez 20 pour 20 %, pas 2000.',
            'tax_rate.min' => 'Un taux de TVA ne peut pas etre negatif.',
            'status.in'    => 'Statut inconnu : une taxe est active ou inactive.',
        ];
    }
}
