<?php

namespace App\Services;

use Exception;
use App\Events\SettingsUpdated;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\LoyaltySetupRequest;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;

class LoyaltySetupService
{
    /**
     * @throws Exception
     */
    public function list(): array
    {
        try {
            return Settings::group('loyalty_setup')->all();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(LoyaltySetupRequest $request): array
    {
        try {
            Settings::group('loyalty_setup')->set($request->validated());
            // [W-REM T-R3.3 F3-03 2026-06-12] Pattern Wave 5G R9 (Currency/Tax/
            // Company/OrderSetup) : sans ce dispatch, un changement de barème
            // fidélité ne se propageait jamais live aux POS/Kiosk abonnés à
            // private-branch.{id} (ancien barème jusqu'au reload complet).
            // Sentinel: tests/Feature/Loyalty/LoyaltySetupSettingsUpdatedTest.php
            SettingsUpdated::dispatch(['loyalty_setup']);
            return $this->list();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
