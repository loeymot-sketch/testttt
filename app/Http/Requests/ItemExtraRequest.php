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
            'name'        => [
                'required',
                'string',
                'max:190',
                Rule::unique("item_extras", "name")->whereNull('deleted_at')->ignore($this->route('itemExtra.id'))->where('item_id', $this->route('item.id')),
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