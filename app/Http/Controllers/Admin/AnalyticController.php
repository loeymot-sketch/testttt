<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\Analytic;
use Illuminate\Http\Request;
use App\Services\AnalyticService;
use App\Http\Requests\AnalyticRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\AnalyticResource;

class AnalyticController extends AdminController
{
    private AnalyticService $analyticService;

    public function __construct(AnalyticService $analyticService)
    {
        parent::__construct();
        $this->analyticService = $analyticService;
        // [REP-ANALYTIC-01 heal 2026-06-01] index/show read the third-party tracking
        // scripts (analytic tags). Consumer-check confirmed the only caller is the
        // Settings>Analytics admin page (admin/setting/analytic*, a settings-perm area);
        // the dashboard analytics widget uses DashboardService, NOT this controller — so
        // gating reads on permission:settings is safe (no widget breakage). Mirrors SET-01.
        $this->middleware(['permission:settings'])->only('index', 'show', 'store', 'update', 'destroy');
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return AnalyticResource::collection($this->analyticService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function store(AnalyticRequest $request): AnalyticResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AnalyticResource($this->analyticService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(AnalyticRequest $request, Analytic $analytic): AnalyticResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AnalyticResource($this->analyticService->update($request, $analytic));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function show(Analytic $analytic): AnalyticResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AnalyticResource($this->analyticService->show($analytic));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function destroy(Analytic $analytic): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->analyticService->destroy($analytic);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
