<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\LicenseRequest;
use App\Http\Resources\LicenseResource;
use App\Services\LicenseService;
use Exception;

class LicenseController extends AdminController
{
    public LicenseService $licenseService;

    public function __construct(LicenseService $licenseService)
    {
        parent::__construct();
        $this->licenseService = $licenseService;
        // [SET-03 2026-06-26] Gate index too: license_key == MIX_API_KEY (the x-api-key
        // validated on the whole admin group). Read must be settings-only — mirrors
        // PaymentGateway/Sms/MailController ->only('index','update'). Twin of SET-01/SET-02
        // that the GAP-19-2 pass missed (LicenseController stayed ->only('update')).
        $this->middleware(['permission:settings'])->only('index', 'update');
    }

    public function index(): \Illuminate\Http\Response | LicenseResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new LicenseResource($this->licenseService->list());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(LicenseRequest $request): \Illuminate\Http\Response | LicenseResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new LicenseResource($this->licenseService->update($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
