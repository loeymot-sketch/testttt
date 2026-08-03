<?php

namespace App\Http\Requests;

use App\Rules\ValidPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddressRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $address = $this->route('address');
        if ($address) {
            return (int) $address->user_id === (int) auth()->id();
        }
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
            'label'     => ['required', 'string', 'max:190', Rule::unique("addresses", "label")->ignore(optional($this->route('address'))->id)->where('user_id', auth()->id())],
            'latitude'  => ['required', 'max:190'],
            'longitude' => ['required', 'max:190'],
            'address'   => ['required', 'string', 'max:500'],
            'apartment' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * [Sprint 2B / DEL-4] Reject address creation / update when the buyer has
     * no usable phone on file. An address-without-phone is a hard blocker for
     * the downstream DELIVERY flow (delivery boy dispatch, SMS callback).
     * Validating here closes the window between "I saved an address" and
     * "I placed a delivery order" so the customer is forced to update their
     * profile while still on the address screen.
     *
     * The check is implemented as a withValidator hook rather than a request
     * field because users.phone lives on the User row, not on the address
     * payload. Adding a phone field to AddressRequest would be silently
     * dropped at DB insert (addresses table has no phone column).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            if (! $user) {
                return;
            }

            $phone = (string) ($user->phone ?? '');
            if ($phone === '' || str_starts_with($phone, 'PENDING_')) {
                $validator->errors()->add(
                    'phone',
                    "Veuillez renseigner un numéro de téléphone valide dans votre profil avant d'ajouter une adresse de livraison."
                );
                return;
            }

            $rule = new ValidPhone();
            if (! $rule->passes('phone', $phone)) {
                $validator->errors()->add('phone', $rule->message());
            }
        });
    }
}