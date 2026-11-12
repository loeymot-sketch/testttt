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
     * [W7-D D-020 · cycle F 2026-11-04] Refuser l'adresse d'AUTRUI répondait « This action is unauthorized. » en anglais :
     * `authorize()` coupe avant le contrôleur, donc le message français de `AddressController` n'était jamais atteint sur
     * PUT/PATCH. Même code HTTP (403), mais le texte du dépôt (`all.message.unauthorized`).
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(__('all.message.unauthorized'));
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
            // [LIVRAISON 2026-10-20] Le serveur géocode l'adresse : les coordonnées du navigateur ne
            // sont plus exigées (et sont remplacées quand le géocodage serveur est actif).
            'latitude'  => [config('delivery.geocoder.enabled', true) ? 'nullable' : 'required', 'max:190'],
            'longitude' => [config('delivery.geocoder.enabled', true) ? 'nullable' : 'required', 'max:190'],
            'address'   => ['required', 'string', 'max:500'],
            'apartment' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * [PROFIL APP 2026-11-12] Messages en français, lisibles par le client (le carnet d'adresses du profil les affiche
     * tels quels) : « The label has already been taken. » ne disait rien à personne.
     */
    public function messages(): array
    {
        return [
            'label.required'   => 'Donnez un nom à cette adresse (par exemple Maison ou Travail).',
            'label.max'        => 'Ce nom est trop long (190 caractères au plus).',
            'label.unique'     => 'Vous avez déjà une adresse portant ce nom. Choisissez-en un autre.',
            'address.required' => 'Saisissez l\'adresse : numéro, rue, code postal et ville.',
            'address.max'      => 'Cette adresse est trop longue (500 caractères au plus).',
            'apartment.max'    => 'Le complément est trop long (200 caractères au plus).',
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

            // [PROFIL APP 2026-11-12] Le numéro JOIGNABLE du compte (téléphone, ou numéro déclaré d'un compte ouvert par
            // Apple / Google — `contact_phone`), comme la garde de commande `require_customer_phone`. Avant, seul
            // `users.phone` comptait : un client venu par Apple, qui avait pourtant donné son numéro, ne pouvait
            // enregistrer AUCUNE adresse — ni dans son profil, ni au paiement en livraison.
            $phone = (string) ($user->phone ?? '');
            if (($phone === '' || str_starts_with($phone, 'PENDING_')) && method_exists($user, 'numeroJoignable')) {
                $phone = (string) ($user->numeroJoignable() ?? '');
            }
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