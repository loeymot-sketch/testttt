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
            'company_name'         => ['required', 'string', 'max:190'],
            'company_email'        => ['required', 'email', 'max:190'],
            'company_phone'        => ['required', 'string', 'max:20'],
            'company_website'      => ['nullable', 'url', 'max:500'],
            'company_city'         => ['required', 'string', 'max:190'],
            'company_state'        => ['required', 'string', 'max:190'],
            'company_country_code' => ['required', 'string', 'max:190'],
            'company_zip_code'     => ['required', 'string', 'max:190'],
            'company_address'      => ['required', 'string', 'max:500'],
            // NF525 legal identity printed on every receipt. SIRET is mandatory
            // for French businesses; the other three are kept nullable so the
            // form unblocks an empty install without forcing a fake value.
            'company_siret'        => ['nullable', 'string', 'regex:/^\d{14}$/'],
            'company_tva_intra'    => ['nullable', 'string', 'regex:/^FR\d{11}$/i', 'max:13'],
            'company_naf'          => ['nullable', 'string', 'regex:/^\d{4}[A-Z]$/'],
            'company_legal_form'   => ['nullable', 'string', 'max:30'],
        ];
    }
}
