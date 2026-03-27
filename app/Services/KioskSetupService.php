<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\KioskSetupRequest;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;

class KioskSetupService
{
    /**
     * @throws Exception
     */
    public function list(): array
    {
        try {
            return Settings::group('kiosk_setup')->all();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(KioskSetupRequest $request): array
    {
        try {
            Settings::group('kiosk_setup')->set($request->validated());
            return $this->list();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
