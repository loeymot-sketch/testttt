<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        // [ULTRA-AUDIT V4-DEPLOY 2026-07-02] Defense-in-depth : la route porte déjà `permission:settings`,
        // mais on double le garde ici (cohérence avec les autres settings-requests, évite un return-true nu).
        return (bool) ($this->user()?->can('settings'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        return [
            // [ULTRA-AUDIT V4-DEPLOY 2026-07-02] company_name est écrit dans .env (APP_NAME=…) par
            // CompanyService → un `\r`/`\n`/`"` permettrait d'INJECTER une ligne .env (ex. APP_DEBUG=true).
            // On interdit sauts de ligne + guillemets (aucun nom d'entreprise légitime n'en contient).
            'company_name'         => ['required', 'string', 'max:190', 'regex:/^[^\r\n"]+$/'],
            'company_email'        => ['required', 'email', 'max:190'],
            'company_phone'        => ['required', 'string', 'max:20'],
            'company_website'      => ['nullable', 'url', 'max:500'],
            'company_city'         => ['required', 'string', 'max:190'],
            'company_state'        => ['required', 'string', 'max:190'],
            'company_country_code' => ['required', 'string', 'max:190'],
            'company_zip_code'     => ['required', 'string', 'max:190'],
            'company_address'      => ['required', 'string', 'max:500'],
        ];
    }
}
