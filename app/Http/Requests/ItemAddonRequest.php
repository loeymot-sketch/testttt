<?php

namespace App\Http\Requests;

use App\Rules\IniAmount;
use App\Models\ItemAddon;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class ItemAddonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    /**
     * [ONB-13 C7 2026-08-28] Defense en profondeur — etait `return true;`.
     *
     * Miroir EXACT de la permission que porte la route : ItemAddonController:21-22 (items_show en lecture, items_edit en ecriture).
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

            'addon_item_id'   => [
                'required',
                'numeric',
                Rule::unique("item_addons", "addon_item_id")->whereNull('deleted_at')->ignore($this->route('itemAddon.id'))->where('item_id', $this->route('item.id')),
            ],
            'addon_item_variation'   => ['nullable', 'json'],
            'role'                   => ['nullable', 'string', Rule::in(ItemAddon::ROLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'addon_item_id.required' => 'The addon item field is required',
        ];
    }
}
