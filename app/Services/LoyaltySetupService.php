<?php

namespace App\Services;

use Exception;
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
            return $this->list();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
