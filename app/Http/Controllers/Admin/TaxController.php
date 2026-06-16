<?php

namespace App\Http\Controllers\Admin;

use App\Events\SettingsUpdated;
use App\Http\Requests\TaxRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\TaxResource;
use App\Models\Tax;
use App\Services\TaxService;
use Exception;

class TaxController extends AdminController
{

    public TaxService $taxService;

    public function __construct(TaxService $taxService)
    {
        parent::__construct();
        $this->taxService = $taxService;
        $this->middleware(['permission:settings'])->only('show', 'store', 'update', 'destroy');
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return TaxResource::collection($this->taxService->list($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function store(TaxRequest $request): \Illuminate\Http\Response|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Contracts\Foundation\Application|TaxResource
    {
        try {
            $resource = new TaxResource($this->taxService->store($request));
            // [Wave 5G R9 heal 2026-05-17] Fan-out settings.updated -> outbox.
            SettingsUpdated::dispatch(['tax']);
            return $resource;
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function update(TaxRequest $request, Tax $tax): \Illuminate\Http\Response|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Contracts\Foundation\Application|TaxResource
    {
        try {
            $resource = new TaxResource($this->taxService->update($request, $tax));
            // [Wave 5G R9 heal 2026-05-17] Fan-out settings.updated -> outbox.
            SettingsUpdated::dispatch(['tax']);
            return $resource;
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function destroy(Tax $tax): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->taxService->destroy($tax);
            // [Wave 5G R9 heal 2026-05-17] Fan-out settings.updated -> outbox.
            SettingsUpdated::dispatch(['tax']);
            return response('', 202);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function show(Tax $tax): \Illuminate\Http\Response|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Contracts\Foundation\Application|TaxResource
    {
        try {
            return new TaxResource($this->taxService->show($tax));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}
