<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\KioskSetupRequest;
use App\Http\Resources\KioskSetupResource;
use App\Services\KioskSetupService;
use Exception;

class KioskSetupController extends AdminController
{
    public KioskSetupService $kioskSetupService;

    public function __construct(KioskSetupService $kioskSetupService)
    {
        parent::__construct();
        $this->kioskSetupService = $kioskSetupService;
        // [GAP-19-2] Apply permission:settings on both read and write to prevent
        // any authenticated user (e.g. POS Operator, Chef) from reading admin kiosk config.
        $this->middleware(['permission:settings'])->only('index', 'update');
    }

    public function index(): \Illuminate\Http\Response|KioskSetupResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new KioskSetupResource($this->kioskSetupService->list());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(KioskSetupRequest $request): \Illuminate\Http\Response|KioskSetupResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new KioskSetupResource($this->kioskSetupService->update($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
