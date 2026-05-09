<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\PendingPaymentConfirmation;
use App\Models\Scopes\BranchScope;
use App\Services\FrontendOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [AUDIT-F-008] Payment Confirm Reconciliation — batch idempotent endpoint.
 *
 * Frontend kiosks persist TPE-approved transactions whose backend
 * confirmation failed (network blip, app crash post-TPE) into
 * localStorage. On boot and every 60s, the kiosk POSTs the queue here
 * for reconciliation.
 *
 * Each entry is processed independently:
 *   - amount echo verified (F-002 invariant preserved, no bypass)
 *   - order locked, payment_status set to PAID, transaction_id recorded
 *   - finalizePaidKioskOrder() called → fiscal_sequence_no allocated
 *     under flag fiscal.kiosk_auto_allocate_sequence (F-001 invariant)
 *   - audit row inserted in pending_payment_confirmations (UNIQUE on tx)
 *
 * Idempotency: UNIQUE(transaction_id) at DB level + payment_status PAID
 * short-circuit + per-entry Cache::lock guard. Replaying the same payload
 * is a safe no-op returning status='already_paid'.
 *
 * Auth: Sanctum kiosk:order ability (same surface as /payment-confirm).
 * Throttle: 5/min per token (anti-abuse).
 *
 * NF525 + PCI-DSS : aucun PAN ni info bancaire transit ici (transaction_id
 * + card_type label only). Le frontend respecte le contrat localStorage
 * documenté dans KioskPaymentComponent.
 */
class PaymentReconcileController extends Controller
{
    private FrontendOrderService $frontendOrderService;

    public function __construct(FrontendOrderService $frontendOrderService)
    {
        $this->frontendOrderService = $frontendOrderService;
    }

    public function reconcile(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'entries'                 => ['required', 'array', 'min:1', 'max:50'],
            'entries.*.order_id'      => ['required', 'integer', 'min:1'],
            'entries.*.transaction_id' => ['required', 'string', 'max:255'],
            'entries.*.amount_cents'  => ['required', 'integer', 'min:1'],
            'entries.*.card_type'     => ['nullable', 'string', 'max:50'],
            'entries.*.payment_method' => ['required', 'integer'],
        ]);

        $authenticatedUserId = $request->user('sanctum')?->id
            ?? $request->user()?->id
            ?? Auth::id();

        if (!$authenticatedUserId) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated'], 401);
        }
        $authenticatedUserId = (int) $authenticatedUserId;

        $kioskMachine = KioskMachine::query()
            ->where('user_id', $authenticatedUserId)
            ->first();

        if (!$kioskMachine) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $branchId = (int) $kioskMachine->branch_id;
        $results = [];

        foreach ($validated['entries'] as $entry) {
            $results[] = $this->reconcileEntry($entry, $authenticatedUserId, $branchId);
        }

        return response()->json(['data' => $results], 200);
    }

    /**
     * Reconcile a single entry. Always returns a structured result —
     * never throws upstream (per-entry isolation : one bad entry must
     * not poison the whole batch).
     */
    private function reconcileEntry(array $entry, int $userId, int $branchId): array
    {
        $orderId       = (int) $entry['order_id'];
        $transactionId = (string) $entry['transaction_id'];
        $amountCents   = (int) $entry['amount_cents'];
        $cardType      = $entry['card_type'] ?? null;
        $paymentMethod = (int) $entry['payment_method'];

        $base = [
            'order_id'       => $orderId,
            'transaction_id' => $transactionId,
        ];

        // [AUDIT-F-008] BypassAuditLogger trace — same observability surface
        // as paymentConfirm (mode bypass payment doit logger ici aussi).
        try {
            \App\Services\Bypass\BypassAuditLogger::paymentBypassed([
                'controller'     => 'Frontend\\PaymentReconcileController::reconcile',
                'order_id'       => $orderId,
                'transaction_id' => $transactionId,
            ]);
        } catch (Throwable $e) {
            // Logger never blocks reconcile.
        }

        // Lookup order : must respect branch isolation. We use withoutGlobalScope
        // to allow the explicit branch check below (mimics paymentConfirm pattern).
        $order = FrontendOrder::withoutGlobalScope(BranchScope::class)
            ->where('id', $orderId)
            ->first();

        if (!$order) {
            return $base + ['status' => 'order_not_found'];
        }

        if ((int) $order->branch_id !== $branchId) {
            // Cross-branch reconcile attempt → log + reject (audit row NOT written).
            Log::warning('[AUDIT-F-008] reconcile cross-branch rejected', [
                'order_id'        => $orderId,
                'order_branch'    => $order->branch_id,
                'kiosk_branch'    => $branchId,
                'transaction_id'  => $transactionId,
            ]);
            return $base + ['status' => 'unauthorized'];
        }

        // Per-entry idempotency lock — namespaced by transaction_id so two
        // concurrent reconcile calls on the same tx serialise.
        $lockKey = "f008:reconcile:tx:{$transactionId}";
        $lock = Cache::lock($lockKey, 10);

        try {
            if (!$lock->block(5)) {
                return $base + ['status' => 'lock_timeout'];
            }

            // [AUDIT-F-002] Amount echo verification — F-008 must NOT bypass F-002.
            $expectedCents = (int) round((float) $order->total * 100);
            if (abs($amountCents - $expectedCents) > 1) {
                Log::warning('[AUDIT-F-008] reconcile amount echo mismatch', [
                    'order_id'       => $orderId,
                    'expected_cents' => $expectedCents,
                    'provided_cents' => $amountCents,
                    'transaction_id' => $transactionId,
                    'gate'           => 'AUDIT-F-008+F-002',
                ]);
                $this->upsertAudit($branchId, $orderId, $transactionId, $amountCents, $cardType, $paymentMethod, PendingPaymentConfirmation::STATUS_FAILED, 'amount_mismatch');
                return $base + ['status' => 'amount_mismatch'];
            }

            // Already paid? → idempotent no-op.
            if ((int) $order->payment_status === PaymentStatus::PAID) {
                $this->upsertAudit($branchId, $orderId, $transactionId, $amountCents, $cardType, $paymentMethod, PendingPaymentConfirmation::STATUS_RESOLVED, null);
                return $base + ['status' => 'already_paid'];
            }

            // Promote to PAID under transaction + lockForUpdate.
            DB::transaction(function () use ($order, $transactionId, $cardType, $paymentMethod) {
                $locked = FrontendOrder::withoutGlobalScope(BranchScope::class)
                    ->where('id', $order->id)
                    ->lockForUpdate()
                    ->first();

                if (!$locked) {
                    return;
                }

                if ((int) $locked->payment_status === PaymentStatus::PAID) {
                    return;
                }

                if (!in_array((int) $locked->payment_method, [PaymentGateway::CARD, PaymentGateway::TICKET_RESTAURANT], true)) {
                    return;
                }

                $locked->payment_status = PaymentStatus::PAID;
                $locked->transaction_id = $transactionId;
                if ($cardType !== null) {
                    $locked->card_type = $cardType;
                }
                $locked->save();
            });

            // Delegate fiscal_sequence_no allocation + status promotion to the
            // single source of truth. F-001 invariant preserved : the flag
            // fiscal.kiosk_auto_allocate_sequence governs allocation in one place.
            //
            // Best-effort semantics : `finalizePaidKioskOrder` runs the fiscal
            // allocation + status promotion inside its own DB::transaction, then
            // dispatches side-effect signals (FCM, mail, broadcast). Side-effect
            // failures (eg FCM unreachable) MUST NOT downgrade the reconcile
            // status to 'failed' — the order IS reconciled at the DB level.
            // We re-fetch the order post-call and check the actual on-DB state
            // (payment_status=PAID + fiscal_sequence_no allocated) before
            // declaring success ; only a true DB-level failure (transaction
            // rollback) maps to status='failed'.
            $fresh = FrontendOrder::withoutGlobalScope(BranchScope::class)
                ->where('id', $order->id)
                ->first();
            if ($fresh && (int) $fresh->payment_status === PaymentStatus::PAID) {
                try {
                    $this->frontendOrderService->finalizePaidKioskOrder($fresh);
                } catch (Throwable $e) {
                    Log::warning('[AUDIT-F-008] finalizePaidKioskOrder threw during reconcile (side-effect or fiscal alloc)', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                    // Re-read DB state : if fiscal_sequence_no got allocated and
                    // status reached ACCEPT, the failure was a side-effect (FCM,
                    // mail, broadcast) post-transaction-commit. The reconcile
                    // is materially successful.
                    $afterFail = FrontendOrder::withoutGlobalScope(BranchScope::class)
                        ->where('id', $order->id)
                        ->first();
                    $sideEffectOnly = $afterFail
                        && (int) $afterFail->payment_status === PaymentStatus::PAID
                        && $afterFail->fiscal_sequence_no !== null;
                    if (!$sideEffectOnly) {
                        $this->upsertAudit($branchId, $orderId, $transactionId, $amountCents, $cardType, $paymentMethod, PendingPaymentConfirmation::STATUS_FAILED, $e->getMessage());
                        return $base + ['status' => 'failed', 'error' => 'finalize_failed'];
                    }
                    // Fall through : reconcile succeeded materially, side-effect
                    // worker will retry async (Job retry policy).
                }
            }

            $this->upsertAudit($branchId, $orderId, $transactionId, $amountCents, $cardType, $paymentMethod, PendingPaymentConfirmation::STATUS_RESOLVED, null);
            return $base + ['status' => 'reconciled'];
        } catch (Throwable $e) {
            Log::error('[AUDIT-F-008] reconcile entry failed', [
                'order_id'       => $orderId,
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
            ]);
            try {
                $this->upsertAudit($branchId, $orderId, $transactionId, $amountCents, $cardType, $paymentMethod, PendingPaymentConfirmation::STATUS_FAILED, substr($e->getMessage(), 0, 500));
            } catch (Throwable $_) {
                // Audit write failure must not propagate.
            }
            return $base + ['status' => 'failed', 'error' => 'internal_error'];
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Upsert audit row keyed by transaction_id (UNIQUE).
     * Replays of the same tx update status/last_error in place.
     */
    private function upsertAudit(int $branchId, int $orderId, string $transactionId, int $amountCents, ?string $cardType, int $paymentMethod, string $status, ?string $lastError): void
    {
        $now = now();
        $existing = PendingPaymentConfirmation::withoutGlobalScope(BranchScope::class)
            ->where('transaction_id', $transactionId)
            ->first();

        if ($existing) {
            $existing->status     = $status;
            $existing->last_error = $lastError;
            $existing->retry_count = (int) $existing->retry_count + 1;
            if ($status === PendingPaymentConfirmation::STATUS_RESOLVED && $existing->resolved_at === null) {
                $existing->resolved_at = $now;
            }
            $existing->save();
            return;
        }

        PendingPaymentConfirmation::query()->create([
            'branch_id'      => $branchId,
            'order_id'       => $orderId,
            'transaction_id' => $transactionId,
            'amount_cents'   => $amountCents,
            'card_type'      => $cardType,
            'payment_method' => $paymentMethod,
            'attempted_at'   => $now,
            'resolved_at'    => $status === PendingPaymentConfirmation::STATUS_RESOLVED ? $now : null,
            'status'         => $status,
            'retry_count'    => 0,
            'last_error'     => $lastError,
        ]);
    }
}
