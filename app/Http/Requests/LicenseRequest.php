<?php

namespace App\Http\Requests;

use App\Services\InstallerService;
use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Foundation\Http\FormRequest;

class LicenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] license_key is
            // written verbatim into .env as MIX_API_KEY (withValidator below,
            // EnvEditor::addData) — same injection vector guarded on
            // company_name ([ULTRA-AUDIT V4-DEPLOY 2026-07-02]): a raw
            // \r/\n/" lets the value inject an independent .env line.
            'license_key' => ['required', 'string', 'max:500', 'regex:/^[^\r\n"]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'license_key.required' => 'The license code field is required'
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $installerService = new InstallerService();
            $response         = $installerService->licenseCodeChecker($validator->validated());
            $request          = $validator->validated();
            if (isset($response->status) && $response->status) {
                $envService = new EnvEditor();
                $envService->addData([
                    'MIX_API_KEY' => $request['license_key'],
                ]);
            } else {
                $validator->errors()->add('license_key', $response->message);
            }
        });
    }
}