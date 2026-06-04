<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CurrencyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        // V1.0.1 R7 heal: defense-in-depth — CurrencyController middleware enforces
        // `permission:settings` on store/update/destroy/show; FormRequest doubles down
        // so any future route bypass (e.g. inline controller invocation) still authz-checks.
        return $this->user()?->can('settings') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        return [
            'name'              => [
                'required',
                'string',
                'max:190',
                Rule::unique("currencies", "name")->ignore($this->route('currency.id'))
            ],
            'symbol'            => ['required', 'string', 'max:190'],
            'code'              => ['required', 'string', 'max:20'],
            'is_cryptocurrency' => ['required', 'numeric', 'max:15'],
            'exchange_rate'     => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
        ];
    }
}
