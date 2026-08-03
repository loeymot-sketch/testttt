<?php

namespace App\Http\Controllers\Admin;


use App\Http\Resources\DefaultAccessResource;
use App\Services\DefaultAccessService;
use Exception;
use Illuminate\Http\Request;


class DefaultAccessController extends AdminController
{
    private DefaultAccessService $defaultAccessService;

    public function __construct( DefaultAccessService $defaultAccessService )
    {
        parent::__construct();
        $this->defaultAccessService = $defaultAccessService;
        // [WJ-1 / WI-4-RED-01 P0 SECURITY 2026-05-19] Customer Sanctum tokens
        // could previously POST default-access (a global per-user keystore
        // including branch_id pin) because the route had no permission gate.
        // Gate the WRITE verb only — GET stays accessible to staff bootstraps
        // (POS Operator first-boot path uses GET /api/admin/default-access;
        // see Tests\Feature\Pos\PosMenuRuntimeAccessTest).
        $this->middleware(['permission:settings'])->only('storeOrUpdate');
    }

    public function index() : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | DefaultAccessResource | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new DefaultAccessResource($this->defaultAccessService->show());
        } catch( Exception $exception ) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function storeOrUpdate( Request $request ) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | DefaultAccessResource | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new DefaultAccessResource($this->defaultAccessService->storeOrUpdate($request->all()));
        } catch( Exception $exception ) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
