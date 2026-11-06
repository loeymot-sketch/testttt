<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // V1.0.1 R7 heal: defense-in-depth — BranchController middleware enforces
        // `permission:settings` on store/update/destroy/updateZone; FormRequest doubles down
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
            'name'      => [
                'required',
                'string',
                'max:190',
                Rule::unique("branches", "name")->ignore($this->route('branch.id'))
            ],
            'email'     => ['nullable', 'email', 'max:190'],
            'phone'     => ['nullable', 'string', 'max:20'],
            'latitude'  => ['nullable', 'max:190'],
            'longitude' => ['nullable', 'max:190'],
            'city'      => ['required', 'string', 'max:190'],
            'state'     => ['required', 'string', 'max:190'],
            'zip_code'  => ['required', 'string'],
            'address'   => ['required', 'string', 'max:500'],
            'status'    => ['required', 'numeric', 'max:24'],

            // [ONB-01 T-1.2.1 2026-08-27] Identité fiscale de l'établissement.
            // Ces trois colonnes existent depuis la migration
            // 2026_04_20_210000_add_fiscal_identity_to_branches et sont LUES par
            // ReceiptDataService (pos_siret / pos_vat_intra / pos_legal_footer),
            // mais elles n'avaient aucune règle ici. Or BranchService fait
            // `Branch::create($request->validated())` : sans règle, un champ n'est
            // JAMAIS enregistré. Conséquence : un nouveau commerçant imprimait un
            // ticket sans SIRET — ce qui n'est pas tenable sur un ticket français.
            // `nullable` et non `required` : les filiales déjà créées doivent
            // continuer à s'enregistrer sans être bloquées rétroactivement.
            'siret'         => ['nullable', 'string', 'regex:/^\d{14}$/'],
            'vat_intra'     => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z]{2}[A-Za-z0-9 ]{2,14}$/'],
            'legal_footer'  => ['nullable', 'string', 'max:500'],

            // [ONB-01 / agent ROUGE 2026-08-27] Oubli du premier passage, trouvé en
            // cherchant à casser le correctif : `register_id` est fillable sur le
            // modèle (Branch.php:18) ET imprimé sur le ticket comme les trois autres
            // (ReceiptDataService : 'pos_register_id'), mais n'avait aucune règle —
            // donc jamais dans validated(), donc jamais enregistrable. J'avais comblé
            // trois champs sur quatre et annoncé le trou bouché.
            'register_id'   => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Messages en français : un commerçant doit comprendre ce qu'on lui demande
     * sans traduire un message d'expression rationnelle.
     */
    public function messages(): array
    {
        return [
            'siret.regex'     => __('validation.custom.siret.regex'),
            'vat_intra.regex' => __('validation.custom.vat_intra.regex'),
        ];
    }
}
