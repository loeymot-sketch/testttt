<?php

namespace App\Http\Requests\Assistant;

use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;

/**
 * [ONB-04 2026-08-28] Téléversement de la photo de carte.
 *
 * Reprend la garde du chemin FACTURE, qui est la plus stricte des deux lectures
 * d'image déjà en service — `NoDangerousFileExtension` bloque les doubles
 * extensions et les polyglottes `.pht`. Le chemin Uber l'avait oubliée ; on ne
 * reproduit pas cet oubli sur une troisième porte d'entrée.
 *
 * Le plafond de taille et la liste des formats viennent de `config/assistant.php`
 * plutôt que d'être écrits ici : c'est un réglage d'exploitation, pas une constante.
 */
class LectureDeCarteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $utilisateur = $this->user();

        // Lire une carte, c'est préparer une écriture au catalogue : on exige le
        // même droit que pour créer un article à la main. Une lecture n'écrit rien,
        // mais elle ne doit pas devenir une porte dérobée pour cartographier le
        // catalogue de quelqu'un d'autre.
        return $utilisateur !== null && $utilisateur->can('items_create');
    }

    public function rules(): array
    {
        $formats = implode(',', (array) config('assistant.menu_extraction.formats', ['jpg', 'png']));
        $tailleKo = (int) config('assistant.menu_extraction.taille_max_ko', 12288);

        return [
            'photo' => [
                'required',
                'file',
                'mimes:' . $formats,
                'max:' . $tailleKo,
                new NoDangerousFileExtension(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => "Ajoutez la photo de votre carte.",
            'photo.mimes'    => "Ce format n'est pas accepté. Formats possibles : "
                . implode(', ', (array) config('assistant.menu_extraction.formats', [])) . '.',
            'photo.max'      => "La photo est trop lourde. Maximum "
                . round(((int) config('assistant.menu_extraction.taille_max_ko', 12288)) / 1024) . " Mo.",
        ];
    }
}
