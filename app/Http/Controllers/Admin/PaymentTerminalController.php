<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\PaymentTerminalRequest;
use App\Http\Resources\PaymentTerminalResource;
use App\Models\PaymentTerminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [Wave F F-2 / Sprint 1C] Admin CRUD for physical payment terminals.
 *
 * Mounted at /api/admin/payment-terminals (see routes/api.php).
 * Permission gate : `settings` (Spatie). Mutating methods require it
 * explicitly so a Branch Manager without `settings` permission cannot
 * silently tweak fees that affect the Z report breakdown.
 *
 * Branch isolation : enforced by BranchScope global scope on the model.
 * Admin (branch_id=0) bypass remains intact for cross-branch operations.
 */
class PaymentTerminalController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        // [AUDIT-E E3 2026-08-06 · P2] `index` non gaté exposait le `serial_number`
        // des TPE + la grille de commissions à un compte sans permission. Miroir du
        // heal E2 (KioskMachineController) : lire = même permission qu'écrire.
        // NOTE : la modale d'encaissement charge la liste des TPE — le caissier a
        // besoin de cette lecture ; elle passe par `pos` (voir PosCounterCollectModal
        // loadActiveTerminal), pas par `settings`, donc on autorise les deux.
        $this->middleware(['permission:settings|pos'])->only(['index']);
        $this->middleware(['permission:settings'])->only(['store', 'update', 'destroy']);
    }

    public function index(Request $request)
    {
        $query = PaymentTerminal::query()->latest('id');

        $requestBranchId = (int) $request->query('branch_id', 0);
        if ($requestBranchId > 0 && (int) $request->user()?->branch_id === 0) {
            $query->where('branch_id', $requestBranchId);
        }

        // Filter by status when explicitly requested (default = all visible)
        $statusFilter = $request->query('status');
        if ($statusFilter !== null && $statusFilter !== '') {
            $query->where('status', (int) $statusFilter);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        return PaymentTerminalResource::collection($query->paginate($perPage));
    }

    public function store(PaymentTerminalRequest $request)
    {
        $data = $request->validated();
        $data['branch_id']    = $this->resolveBranchId($request);
        $data['fee_percent']  = (float) ($data['fee_percent'] ?? 0);
        $data['fee_fixed']    = (float) ($data['fee_fixed'] ?? 0);
        $data['status']       = (int) ($data['status'] ?? PaymentTerminal::STATUS_ACTIVE);

        $terminal = PaymentTerminal::query()->create($data);

        return (new PaymentTerminalResource($terminal))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PaymentTerminal $payment_terminal): PaymentTerminalResource
    {
        return new PaymentTerminalResource($payment_terminal);
    }

    public function update(PaymentTerminalRequest $request, PaymentTerminal $payment_terminal): PaymentTerminalResource
    {
        $data = $request->validated();

        // Branch-scoped users cannot move terminals across branches
        if ((int) $request->user()?->branch_id > 0) {
            unset($data['branch_id']);
        }

        $payment_terminal->update($data);

        return new PaymentTerminalResource($payment_terminal->fresh());
    }

    /**
     * Soft-archive (status=5). We never hard-delete to preserve historical
     * order_payments.terminal_id traceability for closed Z reports.
     */
    public function destroy(PaymentTerminal $payment_terminal): JsonResponse
    {
        $payment_terminal->update(['status' => PaymentTerminal::STATUS_ARCHIVED]);

        return response()->json(null, 204);
    }

    private function resolveBranchId(Request $request): int
    {
        $userBranchId = (int) ($request->user()?->branch_id ?? 0);

        if ($userBranchId > 0) {
            return $userBranchId;
        }

        return (int) $request->validated('branch_id');
    }
}
