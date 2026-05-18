<?php

namespace App\Http\Controllers\Admin;

use App\Events\SettingsUpdated;
use App\Http\Requests\SiteRequest;
use App\Http\Resources\SiteResource;
use App\Services\SiteService;
use Exception;

class SiteController extends AdminController
{
    public SiteService $siteService;

    public function __construct(SiteService $siteService)
    {
        parent::__construct();
        $this->siteService = $siteService;
        $this->middleware(['permission:settings'])->only('update');
    }

    public function index(): SiteResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new SiteResource($this->siteService->list());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(SiteRequest $request): SiteResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            if (env('DEMO')) {
                $resource = new SiteResource($this->siteService->update($request));
            } else {
                $resource = new SiteResource($this->siteService->update($request));
            }
            // [Wave 5G R9 heal 2026-05-17] Fan-out settings.updated -> outbox.
            SettingsUpdated::dispatch(['site']);
            return $resource;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
