<?php

namespace App\Http\Requests;

use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Photo d'une OPTION du wizard (supplément ou variation).
 *
 * Mêmes garde-fous que la photo d'un produit : NoDangerousFileExtension bloque
 * les doubles extensions et les polyglottes .pht, le champ s'appelle `photo`,
 * et le webp est accepté. 4 Mo suffisent largement pour une vignette de 168 px.
 */
class ItemOptionPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Le catalogue est GLOBAL, pas rattaché à une branche : un éditeur de
        // branche ne doit pas pouvoir changer la photo vue par toutes les
        // surfaces. Même règle que la photo d'un produit
        // (ItemController::changeImage), vérifiée par ProductPhotoAuthzTest.
        $u = $this->user();

        return (bool) $u && ($u->hasRole('Admin') || $u->hasRole('Tenant Admin'));
    }

    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', new NoDangerousFileExtension()],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => "La photo de l'option est obligatoire.",
            'photo.image'    => 'Le fichier doit être une image valide.',
            'photo.mimes'    => 'La photo doit être au format jpg, jpeg, png ou webp.',
            'photo.max'      => 'La photo ne doit pas dépasser 4 Mo.',
        ];
    }
}
