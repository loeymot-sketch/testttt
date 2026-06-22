<?php

namespace App\Http\Requests;

use App\Services\InstallerService;
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
            'license_key' => ['required', 'string', 'max:500'],
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
            // [G-LICENSE-KEY 2026-06-21] DECOUPLED. The success branch previously wrote
            // MIX_API_KEY = license_key (config('app.api_key')) — rotating the global
            // x-api-key on every license save and bricking already-open SPA tabs (400
            // invalid_api_key). The remote license check stays (rejects an invalid code);
            // it must NOT hijack the API key. See LicenseService::update + sentinel
            // LicenseKeyApiKeyDecouplingSentinelTest.
            if (! (isset($response->status) && $response->status)) {
                $validator->errors()->add('license_key', $response->message);
            }
        });
    }
}