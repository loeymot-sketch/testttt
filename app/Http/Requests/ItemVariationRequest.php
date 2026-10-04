<?php

namespace App\Http\Requests;

use App\Libraries\AppLibrary;
use App\Rules\IniAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemVariationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    /**
     * [ONB-13 C7 2026-08-28] Defense en profondeur — etait `return true;`.
     *
     * Miroir EXACT de la permission que porte la route : ItemVariationController:23-24 (items_show en lecture, items_edit en ecriture).
     *
     * Ce n'est pas la garde principale — le middleware du controleur garde deja
     * l'acces. C'est le second verrou : si une route est un jour recablee sans son
     * middleware, la regle refuse encore. Meme motif que `EmployeeRequest` et
     * `AdministratorRequest`.
     *
     * On accepte la famille de capacites entiere (lecture ET ecriture) parce que
     * les regles s'appliquent aux deux verbes : etre plus strict ici refuserait des
     * requetes que le middleware laisse legitimement passer.
     */
    public function authorize(): bool
    {
        $utilisateur = $this->user();

        if ($utilisateur === null) {
            return false;
        }

        return $utilisateur->can('items_edit') || $utilisateur->can('items_show');
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
                'max:190',
                // [F-VARIATION-ATTR-SCOPE 2026-07-15 / P2] Unicité scopée (item_id, item_attribute_id).
                // Sans le scope attribut, un même nom de viande légitime sous deux groupes distincts
                // (« Viande 1 » ET « Viande 2 » d'un tacos) était refusé 422 → 66 variations jumelles
                // live (6 produits tacos) inéditables via l'endpoint dédié. Miroir du besoin extras.
                Rule::unique("item_variations", "name")->whereNull('deleted_at')
                    ->ignore(optional($this->route('itemVariation'))->id)
                    ->where('item_id', optional($this->route('item'))->id)
                    ->where('item_attribute_id', (int) $this->input('item_attribute_id'))
            ],
            'item_attribute_id' => ['required', 'numeric'],
            'price'             => ['required', new IniAmount(true)],
            'caution'           => ['nullable', 'string', 'max:5000'],
            'status'            => ['required', 'numeric', 'max:24'],
            // Surface visibility: null = all surfaces; array of "kiosk", "pos", "web"
            'visible_on'        => ['nullable', 'array'],
            'visible_on.*'      => ['string', Rule::in(['kiosk', 'pos', 'web'])],
        ];
    }

    public function messages(): array
    {
        return [
            'item_attribute_id.required' => 'The attribute field is required',
        ];
    }
}