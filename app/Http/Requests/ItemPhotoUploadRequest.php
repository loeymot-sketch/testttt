<?php

namespace App\Http\Requests;

use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;

class ItemPhotoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // [GOAL LIVRAISON-TICKETS 2026-11-10 · T-2.4.2] Second verrou : miroir EXACT de la permission portée par la route
        // (le middleware reste la garde principale ; celle-ci tient si la route est un jour recâblée sans lui).
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can('items_edit');
    }

    public function rules(): array
    {
        return [
            // [GOAL-L2-HEAL-02 2026-05-24] Phase L7.1-V1: NoDangerousFileExtension
            // blocks .pht / double-extension polyglot attacks. Field is `photo`
            // (not `image`) and accepts webp — both unaffected by the new rule.
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', new NoDangerousFileExtension()],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'La photo du produit est obligatoire.',
            'photo.image'    => 'Le fichier doit être une image valide.',
            'photo.mimes'    => 'La photo doit être au format jpg, jpeg, png ou webp.',
            'photo.max'      => 'La photo ne doit pas dépasser 4 Mo.',
        ];
    }
}
