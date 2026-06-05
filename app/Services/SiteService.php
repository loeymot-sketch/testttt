<?php

namespace App\Services;


use Exception;
use App\Models\Currency;
use App\Http\Requests\SiteRequest;
use Illuminate\Support\Facades\Log;
use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Support\Facades\Artisan;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;

class SiteService
{
    public $envService;

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
            return Settings::group('site')->all();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(SiteRequest $request)
    {
        try {
            $currency = Currency::find($request->site_default_currency);
            Settings::group('site')->set($request->validated() + ['site_default_currency_symbol' => $currency->symbol]);

            // [S7-03] APP_DEBUG is INTENTIONALLY not written here. Letting Site
            // settings flip APP_DEBUG in .env is a self-inflicted production boot
            // failure (the prod boot-guard refuses APP_DEBUG=true) + a debug/secret
            // leak vector. APP_DEBUG is ops/deploy-managed only. (Owner 2026-06-05.)
            $this->envService->addData([
                'TIMEZONE'               => $request->site_default_timezone,
                'CURRENCY'               => $currency?->code,
                'CURRENCY_SYMBOL'        => $currency?->symbol,
                'CURRENCY_POSITION'      => $request->site_currency_position,
                'CURRENCY_DECIMAL_POINT' => $request->site_digit_after_decimal_point,
                'DATE_FORMAT'            => $request->site_date_format,
                'TIME_FORMAT'            => $request->site_time_format
            ]);

            if (!$this->envService->getValue('DEMO')) {
                $this->envService->addData([
                    'MIX_GOOGLE_MAP_KEY'     => $request->site_google_map_key,
                ]);
            }

            Artisan::call('optimize:clear');
            return $this->list();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
