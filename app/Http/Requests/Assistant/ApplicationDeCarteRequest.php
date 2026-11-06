<?php

namespace App\Http\Requests\Assistant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * [ONB-04 2026-08-28] Application d'une carte APRÈS relecture par le commerçant.
 *
 * Ce que cette requête reçoit, ce n'est PAS ce que la lecture d'image a proposé :
 * c'est ce que le commerçant a validé, éventuellement corrigé ligne par ligne.
 * D'où deux choix qui sont le sujet de la classe :
 *
 * · `tax_id` et `item_type` sont EXIGÉS ici, au niveau de la charge utile, en plus
 *   de l'être dans `ItemRequest`. Deux verrous plutôt qu'un : celui-ci donne au
 *   commerçant un message clair AVANT que quoi que ce soit ne parte, plutôt que
 *   quarante refus ligne par ligne après coup.
 *
 * · Le nombre de lignes est plafonné par `assistant.menu_extraction.max_items_par_lecture`.
 *   Ce n'est pas une limite technique mais une limite de RELECTURE HUMAINE : au-delà,
 *   personne ne vérifie vraiment, on clique « tout accepter », et la validation
 *   humaine — qui est le seul garde-fou de tout le dispositif — devient une fiction.
 */
class ApplicationDeCarteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $utilisateur = $this->user();

        return $utilisateur !== null && $utilisateur->can('items_create');
    }

    public function rules(): array
    {
        $plafond = (int) config('assistant.menu_extraction.max_items_par_lecture', 60);

        return [
            'articles'               => ['required', 'array', 'min:1', 'max:' . $plafond],
            'articles.*.nom'         => ['required', 'string', 'max:190'],
            'articles.*.categorie'   => ['required', 'string', 'max:190'],
            'articles.*.prix'        => ['required', 'numeric', 'min:0'],
            'articles.*.description' => ['nullable', 'string', 'max:5000'],

            // Choix du COMMERÇANT, jamais de la lecture d'image.
            'tax_id'    => ['required', 'numeric', 'not_in:0', 'exists:taxes,id'],
            'item_type' => ['required', 'numeric', 'not_in:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'articles.max'  => "Trop de lignes d'un coup : au-delà de "
                . config('assistant.menu_extraction.max_items_par_lecture', 60)
                . " produits, plus personne ne relit vraiment. Appliquez la carte en plusieurs fois.",
            'tax_id.required'    => "Choisissez la TVA à appliquer à ces produits. "
                . "Sans elle, ils seraient facturés à 0 %.",
            'item_type.required' => "Choisissez le type de produit.",
            'articles.*.prix.required' => "Chaque produit doit avoir un prix. "
                . "Corrigez les lignes que la lecture n'a pas su lire.",
        ];
    }
}
