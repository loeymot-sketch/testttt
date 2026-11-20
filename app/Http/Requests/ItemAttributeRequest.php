<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemAttributeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // [GOAL LIVRAISON-TICKETS 2026-11-10 · T-2.4.2] Second verrou : miroir EXACT de la permission portée par la route
        // (le middleware reste la garde principale ; celle-ci tient si la route est un jour recâblée sans lui).
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can('settings');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        return [
            'name'        => [
                'required',
                'string',
                'max:190',
                // route('itemAttribute.id') est null (param = modèle). Sans ignore(id),
                // Enregistrer sans renommer → 422 « déjà pris », min/max jamais écrits.
                Rule::unique("item_attributes", "name")->ignore(optional($this->route('itemAttribute'))->id)
            ],
            'min_select'   => ['nullable', 'integer', 'min:0', 'max:99'],
            'max_select'   => ['nullable', 'integer', 'min:0', 'max:99', 'gte:min_select'],
            'allow_repeat' => ['nullable', 'boolean'],
            'status'       => ['required', 'numeric', 'max:24']
        ];
    }
}
