<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    /**
     * [ONB-13 C7 2026-08-28] Defense en profondeur — etait `return true;`.
     *
     * Miroir exact de la permission que porte la route : MenuTemplateController:29.
     * Second verrou si une route est un jour recablee sans son middleware.
     */
    public function authorize(): bool
    {
        $utilisateur = $this->user();

        if ($utilisateur === null) {
            return false;
        }

        return $utilisateur->can('settings');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name'        => [
                'required',
                'string',
                'max:190',
                Rule::unique("menu_templates", "name")->ignore($this->route('menuTemplate.id'))
            ]
        ];
    }
}