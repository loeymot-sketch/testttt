<?php

namespace App\Http\Controllers\Admin;

use App\Events\SettingsUpdated;
use App\Http\Requests\CurrencyRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\CurrencyResource;
use App\Models\Currency;
use App\Services\CurrencyService;
use Exception;

class CurrencyController extends AdminController
{
    private CurrencyService $currencyService;

    public function __construct(CurrencyService $currencyService)
    {
        parent::__construct();
        $this->currencyService = $currencyService;
        $this->middleware(['permission:settings'])->only('store', 'update', 'destroy', 'show');
    }

    public function index(PaginateRequest $request) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return CurrencyResource::collection($this->currencyService->list($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function store(CurrencyRequest $request) : CurrencyResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $resource = new CurrencyResource($this->currencyService->store($request));
            // [Wave 5G R9 heal 2026-05-17] Fan-out settings.updated -> outbox.
            SettingsUpdated::dispatch(['currency']);
            return $resource;
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function update(CurrencyRequest $request, Currency $currency) : CurrencyResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $resource = new CurrencyResource($this->currencyService->update($request, $currency));
            // [Wave 5G R9 heal 2026-05-17] Fan-out settings.updated -> outbox.
            SettingsUpdated::dispatch(['currency']);
            return $resource;
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function destroy(Currency $currency) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->currencyService->destroy($currency);
            // [Wave 5G R9 heal 2026-05-17] Fan-out settings.updated -> outbox.
            SettingsUpdated::dispatch(['currency']);
            return response('', 202);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function show(Currency $currency) : CurrencyResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new CurrencyResource($currency);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}
