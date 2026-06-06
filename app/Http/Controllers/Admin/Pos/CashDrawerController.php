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
        ]);

        $branchId = (int) (auth()->user()?->branch_id ?? 0);
        $userId = (int) (auth()->id() ?? 0);
        $printerId = isset($data['printer_id']) ? (int) $data['printer_id'] : null;

        $result = $this->printerService->openDrawer($printerId, $branchId);

        $status = ($result['success'] ?? false) ? 200 : 422;

        // [Sprint 5B Z10-NEW-001 / F-7] NF525 forensic trail — every hardware
        // drawer pop is recorded as a TYPE_DRAWER_OPEN movement against the
        // operator's OPEN cash session. amount=0 (no money moves) but the row
        // anchors the event in the audit chain via CashDrawerService.
        // recordMovement (Sprint 1D writes audit_logs on every movement).
        // No-op when there's no open session (manager-mode drawer-test
        // before shift), recorded as a warning so forensic gaps surface.
        if (($result['success'] ?? false) && $branchId > 0 && $userId > 0) {
            try {
                $session = $this->cashDrawerService->findOpenSessionForUser($branchId, $userId);
                if ($session) {
                    $this->cashDrawerService->recordMovement(
                        sessionId: $session->id,
                        type: CashMovement::TYPE_DRAWER_OPEN,
                        amount: 0.0,
                        direction: CashMovement::DIRECTION_IN,
                        orderId: null,
                        notes: 'Hardware drawer pop via printer_id=' . ($printerId ?? 'default'),
                        strict: false,
                    );
                } else {
                    Log::warning('[F-7] Hardware drawer pop without OPEN session — forensic gap', [
                        'branch_id'  => $branchId,
                        'user_id'    => $userId,
                        'printer_id' => $printerId,
                    ]);

                    // [CASH-03] With no open session there is no CashMovement to
                    // anchor the pop in the audit chain — yet a no-sale hardware
                    // drawer pop is exactly the event NF525 forensics + the
                    // i18n "Action tracée" promise require to be recorded.
                    // Write a session-less audit_logs row directly so the gap is
                    // closed. branch_id is guaranteed > 0 by the outer guard, so
                    // AuditLogService::write will not hit its null-branch reject.
                    app(\App\Services\Fiscal\AuditLogService::class)->write([
                        'branch_id'   => $branchId,
                        'user_id'     => $userId,
                        'action'      => 'pos.cash_drawer.no_sale_pop',
                        'resource'    => 'cash_drawer',
                        'resource_id' => $printerId,
                        'payload'     => [
                            'reason'     => 'no_open_session',
                            'printer_id' => $printerId,
                            'source'     => 'hardware_drawer_pop',
                        ],
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

        return response()->json($result, $status);
    }
}
