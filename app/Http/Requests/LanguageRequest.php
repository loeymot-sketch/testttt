<?php

namespace App\Http\Requests;

use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LanguageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:190',
                Rule::unique('languages', 'name')->ignore($this->route('language.id')),
            ],

            // [SELF-AUDIT R6 P3 2026-07-05 — path traversal] `code` était interpolé BRUT dans base_path()
            // (LanguageService copy/mkdir) → `../../../public/pwn` écrivait/écrasait hors des dossiers lang.
            // On contraint à un slug strict (lettres/tiret/underscore) : ni '/', ni '\\', ni '..'.
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z_-]+$/'],
            'display_mode' => ['required', 'numeric', 'max:24'],
            'status' => ['required', 'numeric', 'max:24'],
            // [SELF-AUDIT R6 P2 2026-07-05 — upload non validé / stored XSS] Le drapeau de langue était
            // stocké SANS validation → un .svg avec <script> servi depuis /storage public = XSS stocké.
            // Miroir d'ItemCategoryRequest : image réelle + extensions sûres + garde extension dangereuse.
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048', new NoDangerousFileExtension],
        ];
    }
}
