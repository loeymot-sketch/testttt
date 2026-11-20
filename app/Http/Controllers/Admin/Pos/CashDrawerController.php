<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Admin\AdminController;
use App\Models\CashMovement;
use App\Services\Cash\CashDrawerService;
use App\Services\Hardware\EscPosPrinterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CashDrawerController extends AdminController
{
    public function __construct(
        private readonly EscPosPrinterService $printerService,
        private readonly CashDrawerService $cashDrawerService,
    ) {
        parent::__construct();

        $this->middleware(['permission:pos']);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'printer_id' => ['nullable', 'integer', 'min:1'],
            'client_opened' => ['nullable', 'boolean'],
        ]);

        $branchId = (int) (auth()->user()?->branch_id ?? 0);
        $userId = (int) (auth()->id() ?? 0);
        $printerId = isset($data['printer_id']) ? (int) $data['printer_id'] : null;
        $clientOpened = (bool) ($data['client_opened'] ?? false);

        $result = $this->printerService->openDrawer($printerId, $branchId);
        $serverConfirmed = (bool) ($result['success'] ?? false);

        // [Root cause 2026-09-17, owner-reported "tiroir ouvert mais non
        // enregistré"] $serverConfirmed alone used to gate the whole response.
        // EscPosPrinterService::openDrawer() opens a raw TCP socket to
        // $printer->host:$printer->port FROM THE LARAVEL PROCESS. This
        // deployment runs Laravel on a remote VPS while the print bridge
        // (127.0.0.1:9100) runs on the counter's own PC — two different
        // machines, so that socket can never connect here and $serverConfirmed
        // is always false in production. The browser already calls
        // kioskHardware.openDrawer() (the local-bridge path, LOCK_DRAWER_BRIDGE_
        // VISIBILITY_2026-09-17) BEFORE this endpoint and knows for a fact
        // whether the physical drawer opened — trust that report too, so a
        // structurally-doomed server-side probe doesn't silently defeat the
        // whole anti-theft forensic trail this endpoint exists for (see the
        // 2026-08-01/d945570b0 comment below). A genuine single-box deployment
        // where the server probe can succeed keeps working unchanged; either
        // side confirming is sufficient, neither is required on its own.
        $hardwareConfirmed = $serverConfirmed || $clientOpened;

        $status = $hardwareConfirmed ? 200 : 422;

        // [Sprint 5B Z10-NEW-001 / F-7] NF525 forensic trail — every hardware
        // drawer pop is recorded as a TYPE_DRAWER_OPEN movement against the
        // operator's OPEN cash session. amount=0 (no money moves) but the row
        // anchors the event in the audit chain via CashDrawerService.
        // recordMovement (Sprint 1D writes audit_logs on every movement).
        // No-op when there's no open session (manager-mode drawer-test
        // before shift), recorded as a warning so forensic gaps surface.
        if ($hardwareConfirmed && $branchId > 0 && $userId > 0) {
            try {
                $session = $this->cashDrawerService->findOpenSessionForUser($branchId, $userId);
                if ($session) {
                    $this->cashDrawerService->recordMovement(
                        sessionId: $session->id,
                        type: CashMovement::TYPE_DRAWER_OPEN,
                        amount: 0.0,
                        direction: CashMovement::DIRECTION_IN,
                        orderId: null,
                        notes: sprintf(
                            'Hardware drawer pop via printer_id=%s (server_probe=%s, client_confirmed=%s)',
                            $printerId ?? 'default',
                            $serverConfirmed ? 'ok' : 'failed',
                            $clientOpened ? 'yes' : 'no',
                        ),
                        strict: false,
                    );
                } else {
                    Log::warning('[F-7] Hardware drawer pop without OPEN session — forensic gap', [
                        'branch_id'  => $branchId,
                        'user_id'    => $userId,
                        'printer_id' => $printerId,
                    ]);
                }
            } catch (Throwable $e) {
                // Forensic write must never block the hardware response.
                Log::error('[F-7] Failed to record drawer pop forensic movement', [
                    'branch_id' => $branchId,
                    'user_id'   => $userId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // `success` reflects the merged confirmation (server probe OR client
        // report), not just the server-side attempt; `server_hardware_success`
        // keeps the raw probe result available for diagnostics.
        return response()->json(array_merge($result, [
            'success' => $hardwareConfirmed,
            'server_hardware_success' => $serverConfirmed,
            'client_confirmed' => $clientOpened,
        ]), $status);
    }
}
