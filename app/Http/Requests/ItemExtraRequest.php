<?php

namespace App\Http\Requests;

use App\Rules\IniAmount;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class ItemExtraRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    /**
     * [ONB-13 C7 2026-08-28] Defense en profondeur — etait `return true;`.
     *
     * Miroir EXACT de la permission que porte la route : ItemExtraController:22-23 (items_show en lecture, items_edit en ecriture).
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
            'name'        => [
                'required',
                'string',
                'max:190',
                // [AUDIT 2026-07-13 P2] Les params de route sont des MODÈLES liés
                // (`{item}`→Item, `{itemExtra}`→ItemExtra, cf. ItemExtraController::store/update).
                // L'ancien `route('itemExtra.id')` / `route('item.id')` (accès pointé) renvoyait
                // NULL → `ignore(null)` + `where('item_id', null)` (→ whereNull) → la garde
                // d'unicité par produit ne bloquait JAMAIS un doublon.
                Rule::unique("item_extras", "name")->whereNull('deleted_at')->ignore(optional($this->route('itemExtra'))->id)->where('item_id', optional($this->route('item'))->id),
            ],
            // [CAISSE-LOGIC-HEAL 2026-07-11 P1-D] `IniAmount()` (défaut $zero=false) rejette
            // price ≤ 0, alors que 78/377 extras réels sont GRATUITS (crudités Salade/Tomate/
            // Oignon…). Éditer l'un d'eux (même juste le nom) resoumettait price=0 → rejet.
            // Aligné sur ItemVariationRequest qui utilise `IniAmount(true)` (0 autorisé, <0 rejeté).
            'price'       => ['required', new IniAmount(true)],
            'status'      => ['required', 'numeric', 'max:24'],
            // Surface visibility: null = all surfaces; array of "kiosk", "pos", "web"
            'visible_on'  => ['nullable', 'array'],
            'visible_on.*'=> ['string', Rule::in(['kiosk', 'pos', 'web'])],
            'group_label' => ['nullable', 'string', 'max:50'],
        ];
    }
}