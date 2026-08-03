<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Printer;
use App\Models\Scopes\BranchScope;
use App\Services\Fiscal\AuditLogService;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            // Branch staff are scoped to their own branch (defense in depth
            // on top of the global tenant scope). Admin (branch_id=0) is the
            // owner/super-user and may reprint ANY branch's receipt — the
            // same bypass the global BranchScope grants admin everywhere.
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->update([
                'receipt_print_count' => DB::raw('COALESCE(receipt_print_count, 0) + 1'),
            ]);

        if ($updated === 0) {
            abort(404);
        }

        $freshOrder = Order::query()
            ->select(['id', 'branch_id', 'receipt_print_count'])
            ->whereKey($order)
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->firstOrFail();

        $newCount = (int) $freshOrder->receipt_print_count;
        $isDuplicata = $newCount >= 2;

        // [W9.B] NF525 audit trail. Best-effort: a chain failure is
        // surfaced via `audit_emitted=false` in the response so the UI
        // can warn the manager, but the HTTP call still succeeds so
        // the cashier can deliver the printed paper to the customer.
        // Attribute the NF525 print audit to the order's ACTUAL branch — an
        // admin reprinting a branch order must audit on that branch, not on 0.
        $auditEmitted = $this->emitAudit((int) $freshOrder->branch_id, $userId, $freshOrder->id, $newCount, $isDuplicata);

        // [PRINT-SAGA 2026-06-24] Best-effort direct ESC/POS print to the configured
        // thermal printer (e.g. USB SAGA). If no printer is configured, returns false
        // and the UI falls back to window.print() — so existing installs are unchanged.
        $printedEscpos = $this->printThermalTicket((int) $freshOrder->branch_id, (int) $freshOrder->id, 'client', $isDuplicata);

        return response()->json([
            'order_id' => $freshOrder->id,
            'receipt_print_count' => $newCount,
            'is_duplicata' => $isDuplicata,
            'audit_emitted' => $auditEmitted,
            'printed_escpos' => $printedEscpos,
        ]);
    }

    /**
     * Kitchen production ticket — no fiscal audit (not a fiscal document), just a
     * best-effort ESC/POS send to the configured thermal printer.
     */
    public function kitchen(Request $request, int $order): JsonResponse
    {
        $branchId = (int) $request->user()->branch_id;
        $found = Order::query()
            ->select(['id', 'branch_id'])
            ->whereKey($order)
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->firstOrFail();

        $printed = $this->printThermalTicket((int) $found->branch_id, (int) $found->id, 'kitchen', false);

        return response()->json(['order_id' => $found->id, 'printed_escpos' => $printed]);
    }

    /**
     * Render + send a ticket to the branch's configured ESC/POS printer.
     * Best-effort: any failure (no printer, transport error) returns false and is
     * logged — it MUST NOT break the print HTTP call (operational continuity).
     */
    private function printThermalTicket(int $branchId, int $orderId, string $ticket, bool $isDuplicata): bool
    {
        try {
            if ($branchId <= 0) {
                return false;
            }
            // [AUDIT P2 2026-06-28] A kitchen ticket should target a kitchen-station
            // printer if one is configured, and only FALL BACK to the receipt printer
            // (the single counter SAGA in V1). A client ticket always uses receipt.
            $stations = $ticket === 'kitchen'
                ? ['kitchen_hot', 'kitchen_cold', 'receipt']
                : ['receipt'];
            $printer = null;
            foreach ($stations as $station) {
                $printer = Printer::withoutGlobalScope(BranchScope::class)
                    ->where('branch_id', $branchId)
                    ->where('station', $station)
                    ->where('status', \App\Enums\Status::ACTIVE)
                    ->orderBy('id')
                    ->first();
                if ($printer) {
                    break;
                }
            }
            if (! $printer) {
                return false;
            }

            $order = Order::withoutGlobalScope(BranchScope::class)
                ->with(['branch', 'user'])
                ->find($orderId);
            if (! $order) {
                return false;
            }
            $order->setRelation('orderItems', $order->orderItems()->withoutGlobalScope(BranchScope::class)->get());

            $renderer = app(OrderReceiptEscPosRenderer::class);
            $opts = [
                'width_chars' => (int) ($printer->width_chars ?: 48),
                'is_duplicata' => $isDuplicata,
            ];
            // [AUDIT P3 2026-06-28] Honor the printer's configured code page (e.g. 16 =
            // CP1252) so accents don't mojibake on firmware that isn't CP858 (19).
            $pOpts = is_array($printer->options) ? $printer->options : [];
            if (! empty($pOpts['code_page'])) {
                $opts['code_page'] = (int) $pOpts['code_page'];
            }
            $bytes = $ticket === 'kitchen'
                ? $renderer->renderKitchenTicket($order, $opts)
                : $renderer->renderClientTicket($order, $opts);

            return app(EscPosPrinterService::class)->sendRaw($printer, $bytes);
        } catch (Throwable $e) {
            Log::warning('[PosReceiptPrintController] thermal print failed', [
                'order_id' => $orderId,
                'branch_id' => $branchId,
                'ticket' => $ticket,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
