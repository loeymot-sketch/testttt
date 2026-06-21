<?php

namespace App\Services;


use Exception;
use Illuminate\Support\Facades\Log;
use Dipokhalder\EnvEditor\EnvEditor;
use App\Http\Requests\LicenseRequest;
use Illuminate\Support\Facades\Artisan;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;

class LicenseService
{
    public EnvEditor $envService;

    public function __construct(EnvEditor $envEditor)
    {
        $this->envService = $envEditor;
    }

    /**
     * @throws Exception
     */
    public function list()
    {
        try {
            return Settings::group('license')->all();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @param LicenseRequest $request
     * @return
     * @throws Exception
     */
    public function update(LicenseRequest $request)
    {
        try {
            Settings::group('license')->set($request->validated());
            // [G-LICENSE-KEY 2026-06-21] DECOUPLED. Previously wrote license_key into
            // MIX_API_KEY — i.e. config('app.api_key'), the global x-api-key gating ALL
            // /api requests and injected into the SPA at RUNTIME (master.blade.php:134,
            // admin-pos-v4.blade.php). A license save therefore ROTATED the API key and
            // bricked every already-open tab with 400 invalid_api_key. The license string
            // is informational (Settings group 'license'); it must NOT hijack the API key.
            // MIX_API_KEY is owned by .env and set once at install/seed. (envService left
            // injected harmlessly for BC.) Verified: no consumer reads license_key expecting
            // api-key equality. Sentinel: LicenseKeyApiKeyDecouplingSentinelTest.
            Artisan::call('optimize:clear');
            return $this->list();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
