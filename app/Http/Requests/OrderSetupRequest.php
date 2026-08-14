<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderSetupRequest extends FormRequest
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
            // [P10] All values are durations, activity codes (≥0), or money/distances — none may be negative.
            'order_setup_food_preparation_time'        => ['required', 'numeric', 'min:0'],
            'order_setup_schedule_order_slot_duration' => ['required', 'numeric', 'min:0'],
            'order_setup_takeaway'                     => ['required', 'numeric', 'min:0'],
            'order_setup_delivery'                     => ['required', 'numeric', 'min:0'],
            /*
             * [DÉCISION OWNER 2026-08-14] Les 3 champs « frais de livraison » ne sont plus exigés.
             *
             * Ils étaient `required` alors qu'AUCUN code métier ne les lisait : vérifié par grep
             * sur tout `app/`, ils ne faisaient qu'un aller-retour Request ↔ Resource. Le vrai
             * calcul est `DeliveryFeeService`, qui lit des colonnes de `branches`
             * (`delivery_fee_base` / `_per_km` / `_minimum` / `free_km`) — sans écran d'admin.
             * Le formulaire qui les proposait a donc été retiré (voir OrderSetupComponent.vue) :
             * les laisser `required` ici ferait échouer CHAQUE enregistrement en 422 sur des
             * champs que plus personne ne peut remplir.
             *
             * Ils restent acceptés s'ils sont envoyés (rétrocompatibilité d'un client tiers ou
             * d'un ancien cache de bundle), mais ne sont plus obligatoires.
             */
            'order_setup_free_delivery_kilometer'      => ['sometimes', 'numeric', 'min:0'],
            'order_setup_basic_delivery_charge'        => ['sometimes', 'numeric', 'min:0'],
            'order_setup_charge_per_kilo'              => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}