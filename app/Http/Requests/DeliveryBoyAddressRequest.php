<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeliveryBoyAddressRequest extends FormRequest
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

        // Une adresse dans l'URL = modification (`delivery-boys_edit`), sinon création (`delivery-boys_create`) : la coupe
        // exacte du contrôleur (store / update). Hors route (test unitaire), c'est la création qui est jugée.
        return $this->route('address') !== null ? $user->can('delivery-boys_edit') : $user->can('delivery-boys_create');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'label'     => ['required', 'string', 'max:190', Rule::unique("addresses", "label")->ignore($this->route('address.id'))->where('user_id', $this->route('deliveryBoy.id'))],
            'latitude'  => ['required', 'max:190'],
            'longitude' => ['required', 'max:190'],
            'address'   => ['required', 'string', 'max:500'],
            'apartment' => ['nullable', 'string', 'max:200'],
        ];
    }
}
