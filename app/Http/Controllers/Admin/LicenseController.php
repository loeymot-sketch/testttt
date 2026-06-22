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
        // [abuse-heal 2026-06-20 W5 W5-SET-LICENSE-INDEX-02] Gate index too — GET /admin/setting/license
        // echoes license_key, which config/app.php maps to MIX_API_KEY == the global x-api-key gating the
        // whole API. A non-settings staff member could read it back. Mirror the Mail/Notification secret-index
        // heal. NOTE for owner (G-LICENSE-KEY): LicenseService::update writes license_key into MIX_API_KEY, so
        // a license edit silently rotates the global API key — consider decoupling them.
        $this->middleware(['permission:settings'])->only('index', 'update');
    }

    public function index(): \Illuminate\Http\Response | LicenseResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new LicenseResource($this->licenseService->list());
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function update(LicenseRequest $request): \Illuminate\Http\Response | LicenseResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new LicenseResource($this->licenseService->update($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}
