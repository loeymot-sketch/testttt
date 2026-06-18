<?php

namespace App\Http\Controllers\Admin;

use App\Events\SettingsUpdated;
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
            return $this->jsonError($exception, 422);
        }
    }

    public function update(KioskSetupRequest $request): \Illuminate\Http\Response|KioskSetupResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $resource = new KioskSetupResource($this->kioskSetupService->update($request));
            // [abuse-heal 2026-06-18] Fan-out settings.updated -> outbox so kiosk surfaces refresh
            // live (mirrors CurrencyController/SiteController). NOTE: the local stale-cache fix lives
            // in KioskSetupService::update() — this dispatch is the BROADCAST leg only.
            SettingsUpdated::dispatch(['kiosk_setup']);
            return $resource;
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}
