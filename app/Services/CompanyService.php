<?php

namespace App\Services;


use Exception;
use Illuminate\Support\Facades\Log;
use Dipokhalder\EnvEditor\EnvEditor;
use App\Http\Requests\CompanyRequest;
use Illuminate\Support\Facades\Artisan;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;

class CompanyService
{

    public $envService;

    public function __construct()
    {
        $this->envService = new EnvEditor();
    }

    /**
     * @throws Exception
     */
    public function list()
    {
        try {
            return Settings::group('company')->all();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(CompanyRequest $request)
    {
        try {
            Settings::group('company')->set($request->validated());
            $this->envService->addData(['APP_NAME' => $request->company_name]);
            Artisan::call('optimize:clear');
            return $this->list();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
