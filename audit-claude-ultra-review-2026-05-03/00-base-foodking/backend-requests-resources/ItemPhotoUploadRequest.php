<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ItemPhotoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
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
