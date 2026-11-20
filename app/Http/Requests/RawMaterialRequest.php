<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * [ONB-08 2026-08-28] Déclarer une matière première.
 *
 * ═══ POURQUOI CETTE REQUÊTE N'EXISTAIT PAS ═══
 *
 * `RawMaterial` n'avait AUCUN CRUD. `routes/api.php:436-441` n'exposait que
 * `movements` (lecture) et `adjust` (correction de quantité). Les seules sources de
 * création étaient un seeder et une commande console.
 *
 * Conséquence pour la mission « depuis zéro » : **un nouveau commerçant ne pouvait
 * déclarer aucun ingrédient.** Tout le domaine matières lui arrivait pré-rempli avec
 * celui de Le Cayenne, sans moyen d'en ajouter, d'en retirer, ni de corriger une
 * unité. C'est le blocage le plus lourd de cette mission, et il n'était dans aucun
 * constat de reconnaissance.
 *
 * ═══ CE QUE CETTE REQUÊTE FERME AU PASSAGE ═══
 *
 * `threshold_low` n'avait **aucun chemin d'écriture**. Mesuré : 55/55 `stock_levels`
 * et 20/20 `raw_materials` l'ont à NULL — pas à 0. Or `StockRuptureDashboardController`
 * et `NotifyStockLowOnStockLevelChanged` filtrent tous deux `whereNotNull('threshold_low')` :
 * **100 % des lignes étaient exclues**, donc l'alerte de stock bas était structurellement
 * muette. Le widget, le listener et l'écran d'alertes étaient trois instruments branchés
 * sur une colonne que rien ne remplissait.
 */
class RawMaterialRequest extends FormRequest
{
    /**
     * Même garde que l'ajustement de quantité (`RawMaterialAdjustController:56`) :
     * déclarer une matière et corriger son stock sont la même responsabilité.
     */
    public function authorize(): bool
    {
        $utilisateur = $this->user();

        return $utilisateur !== null && $utilisateur->can('items_create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $matiere = $this->route('rawMaterial');

        return [
            /*
             * La table porte `unique(['branch_id', 'name'])`. Sans cette règle, un
             * doublon remonterait en erreur SQL brute à l'écran — le défaut exact
             * qu'ONB-02 a corrigé sur `kds_station` (« SQLSTATE[01000] Data truncated »).
             *
             * `whereNull('deleted_at')` : le modèle a des suppressions douces, et une
             * matière retirée ne doit pas empêcher d'en recréer une du même nom.
             */
            'name' => [
                'required',
                'string',
                'max:190',
                Rule::unique('raw_materials', 'name')
                    ->where('branch_id', (int) ($this->input('branch_id') ?: 1))
                    ->whereNull('deleted_at')
                    ->ignore(optional($matiere)->id),
            ],

            /*
             * L'unité est comparée à celle des lignes de facture par
             * `PurchaseService::normaliserUnite()`. La colonne est un `string(16)`
             * LIBRE, ce qui a déjà coûté : une facture « 3 kg » créditait 3 grammes.
             *
             * On borne donc la saisie aux écritures que la conversion sait traiter.
             * Laisser le champ libre, c'est laisser un commerçant écrire « kilo »
             * là où la conversion attend autre chose — et découvrir des mois plus
             * tard que son stock est faux d'un facteur mille.
             */
            'unit' => ['required', 'string', Rule::in(self::UNITES_ACCEPTEES)],

            // Le poids d'une pièce, pour les matières comptées à l'unité (une
            // tranche de cheddar, un pain). Facultatif, mais jamais négatif.
            'piece_weight_g' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],

            /*
             * LE SEUIL D'ALERTE. C'est lui qui manquait de chemin d'écriture.
             * `null` reste permis et signifie « pas d'alerte sur cette matière » —
             * il ne faut pas forcer un commerçant à inventer un chiffre.
             */
            'threshold_low' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],

            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Les unités que la conversion d'achat sait traiter.
     *
     * Miroir volontaire de `PurchaseService` : dimensionnelles (kg, g, l, ml, cl) et
     * de dénombrement (pièce, tranche, portion, sachet, boîte, lot). Écrites ici en
     * clair plutôt que partagées par une constante, parce que `PurchaseService`
     * accepte AUSSI les variantes d'écriture d'un OCR (« kilo », « pièce ») alors
     * qu'un formulaire, lui, doit proposer une forme canonique et une seule.
     */
    public const UNITES_ACCEPTEES = [
        'kg', 'g', 'l', 'ml', 'cl',
        'piece', 'tranche', 'portion', 'sachet', 'boite', 'lot',
    ];

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => trans('all.message.matiere_deja_declaree'),
            'unit.in'     => trans('all.message.unite_de_matiere_inconnue', [
                'unites' => implode(' · ', self::UNITES_ACCEPTEES),
            ]),
        ];
    }
}
