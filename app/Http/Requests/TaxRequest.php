<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaxRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // V1.0.1 R7 heal: defense-in-depth — TaxController middleware enforces
        // `permission:settings` on show/store/update/destroy; FormRequest doubles down
        // so any future route bypass still authz-checks.
        return $this->user()?->can('settings') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name'              => [
                'required',
                'string',
                'max:190'
            ],
            'code'              => [
                'required',
                'string',
                'max:20',
                // S6-01: ignore the CURRENT row on UPDATE. The route param is the
                // route-model-bound `{tax}` (routes/api.php) — `tax.id` was never a
                // param, so ignore(null) self-collided and every UPDATE returned 422.
                Rule::unique("taxes", "code")->ignore($this->route('tax'))
            ],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'status'   => ['required', 'numeric', 'max:24'],
        ];
    }
}
