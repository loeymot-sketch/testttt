<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalyticRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-5.3.4] Défense en profondeur.
        //
        // Les routes `analytic*` sont déjà derrière la permission `settings` (routes/api.php).
        // `return true;` s'appuyait donc entièrement sur le middleware : si la route était un
        // jour déplacée hors du groupe protégé, la requête n'aurait plus AUCUNE autorisation.
        // On rend la garantie locale, et on la fait tenir par un test.
        return (bool) $this->user()?->can('settings');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        return [
            'name'   => ['required', 'string', 'max:200', Rule::unique("analytics", "name")->ignore($this->route('analytic.id'))],
            'status' => ['required', 'numeric'],
        ];
    }
}
