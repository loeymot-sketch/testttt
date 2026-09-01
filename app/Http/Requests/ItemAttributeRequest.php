<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemAttributeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        return [
            'name'        => [
                'required',
                'string',
                'max:190',
                // route('itemAttribute.id') est null (param = modèle). Sans ignore(id),
                // Enregistrer sans renommer → 422 « déjà pris », min/max jamais écrits.
                Rule::unique("item_attributes", "name")->ignore(optional($this->route('itemAttribute'))->id)
            ],
            'min_select'   => ['nullable', 'integer', 'min:0', 'max:99'],
            'max_select'   => ['nullable', 'integer', 'min:0', 'max:99', 'gte:min_select'],
            'allow_repeat' => ['nullable', 'boolean'],
            'status'       => ['required', 'numeric', 'max:24']
        ];
    }
}
