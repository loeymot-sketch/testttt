<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * [W9.B / G3] POS receipt print + reprint policy.
 *
 * Every receipt impression is a NF525-relevant event:
 *   - the FIRST print is the original ticket given to the customer;
 *   - the SECOND+ print is a DUPLICATA (legally required to be marked
 *     as such on the ticket via {@see ReceiptDuplicataMarker.vue}).
 *
 * NF525 evidence model (Article 286-I-3 bis du CGI) requires that any
 * reissue of a fiscal document leaves a tamper-evident trace. We do
 * this by writing to the hash-chained `audit_logs` table on every
 * call, with a distinct `action` for first vs subsequent prints. The
 * audit emission is best-effort: a failure to chain MUST NOT block
 * the cashier from physically reprinting the ticket (operational
 * continuity), but the failure is logged so SIEM can alert on it.
 */
class PosReceiptPrintController extends Controller
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function increment(Request $request, int $order): JsonResponse
    {
        $branchId = (int) $request->user()->branch_id;
        $userId = (int) $request->user()->id;

        // Atomic increment scoped to the operator's branch (defense in
        // depth on top of the global tenant scope) — concurrent prints
        // of the same ticket from two stations cannot lose a count.
        $updated = Order::query()
            ->whereKey($order)
            ->where('branch_id', $branchId)
            ->update([
                'receipt_print_count' => DB::raw('COALESCE(receipt_print_count, 0) + 1'),
            ]);

        if ($updated === 0) {
            abort(404);
        }

        $freshOrder = Order::query()
            ->select(['id', 'receipt_print_count'])
            ->whereKey($order)
            ->where('branch_id', $branchId)
            ->firstOrFail();

        $newCount = (int) $freshOrder->receipt_print_count;
        $isDuplicata = $newCount >= 2;

        // [W9.B] NF525 audit trail. Best-effort: a chain failure is
        // surfaced via `audit_emitted=false` in the response so the UI
        // can warn the manager, but the HTTP call still succeeds so
        // the cashier can deliver the printed paper to the customer.
        $auditEmitted = $this->emitAudit($branchId, $userId, $freshOrder->id, $newCount, $isDuplicata);

        return response()->json([
            'order_id' => $freshOrder->id,
            'receipt_print_count' => $newCount,
            'is_duplicata' => $isDuplicata,
            'audit_emitted' => $auditEmitted,
        ]);
    }

    private function emitAudit(int $branchId, int $userId, int $orderId, int $newCount, bool $isDuplicata): bool
    {
        try {
            $this->audit->write([
                'branch_id' => $branchId,
                'user_id' => $userId,
                'action' => $isDuplicata ? 'pos.receipt.reprint' : 'pos.receipt.print',
                'resource' => 'order',
                'resource_id' => $orderId,
                'payload' => [
                    'order_id' => $orderId,
                    'print_count_after' => $newCount,
                    'print_count_before' => max(0, $newCount - 1),
                    'is_duplicata' => $isDuplicata,
                ],
            ]);

            return true;
        } catch (Throwable $e) {
            // Chain failure (lock contention, cache outage, DB hiccup).
            // The fiscal channel logger inside AuditLogService::write
            // already records the timing/outcome; here we only need to
            // tell the caller so the UI can surface a warning to ops.
            report($e);

            return false;
        }
    }
}
