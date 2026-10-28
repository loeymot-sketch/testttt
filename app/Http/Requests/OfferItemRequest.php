<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class OfferItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    /**
     * [ONB-13 C7 2026-08-28] Defense en profondeur — etait `return true;`.
     *
     * Miroir exact de la permission que porte la route : OfferItemController:22.
     * Second verrou si une route est un jour recablee sans son middleware.
     */
    public function authorize(): bool
    {
        $utilisateur = $this->user();

        if ($utilisateur === null) {
            return false;
        }

        return $utilisateur->can('offers_show');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [

            'item_id'   => [
                'required',
                'numeric',
                Rule::unique("offer_items", "item_id")->ignore($this->route('offerItem.id'))->where('offer_id', $this->route('offer.id')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'item_id.required' => 'The item field is required.'
        ];
    }
}