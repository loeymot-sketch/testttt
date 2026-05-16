<?php

namespace App\Services;

use App\Domain\Order\PaymentStateMachine;
use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderCanceled;
use App\Events\OrderPaidAtCounter;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    public function payment($order, $gatewaySlug, $transactionNo)
    {
        $this->assertPilotPaymentMethodAllowed($order, (string) $gatewaySlug, 'payment');

        $transaction = Transaction::where(['order_id' => $order->id])->first();
        if (!$transaction) {
            $this->assertTransactionReferenceAvailable($order, (string) $transactionNo);

            $transaction = Transaction::create([
                'order_id'       => $order->id,
                'transaction_no' => $transactionNo,
                'amount'         => $order->total,
                'payment_method' => $gatewaySlug,
                'sign'           => '+',
                'type'           => 'payment'
            ]);
        }
        $order->payment_status = PaymentStatus::PAID;
        $order->save();
        return $transaction;
    }

    private function assertTransactionReferenceAvailable($order, string $transactionNo): void
    {
        $transactionNo = trim($transactionNo);
        if ($transactionNo === '') {
            return;
        }

        $duplicate = Transaction::query()
            ->where('transaction_no', $transactionNo)
            ->where('type', 'payment')
            ->where('order_id', '!=', (int) $order->id)
            ->exists();

        if (! $duplicate) {
            return;
        }

        throw ValidationException::withMessages([
            'transaction_no' => 'This payment transaction reference is already attached to another order.',
        ]);
    }

    public function cashBack($order, $gatewaySlug, $transactionNo)
    {
        $existingCashBack = Transaction::where(['order_id' => $order->id])
            ->where('type', 'cash_back')
            ->first();

        if ($existingCashBack) {
            return $existingCashBack;
        }

        $transaction = Transaction::where(['order_id' => $order->id])
            ->where('type', 'payment')
            ->first();
        if ($transaction) {
            $transaction = Transaction::create([
                'order_id'       => $order->id,
                'transaction_no' => $transactionNo,
                'amount'         => $order->total,
                'payment_method' => $gatewaySlug,
                'sign'           => '-',
                'type'           => 'cash_back'
            ]);

            $user = User::find($order->user_id);
            if ($user) {
                $user->balance = ($user->balance + $order->total);
                $user->save();
            }

            // [POS-9.4.BL.2] NF525 audit trail on cash back. A cash back is
            // fiscally equivalent to a refund — it must leave a tamper-evident
            // record on the HMAC chain so a fraudulent cashier can be
            // detected even if the Transaction row is later mutated.
            app(AuditLogService::class)->write([
                'branch_id'   => (int) ($order->branch_id ?? 0),
                'user_id'     => Auth::check() ? (int) Auth::id() : null,
                'action'      => 'payment.cash_back_issued',
                'resource'    => 'order',
                'resource_id' => (int) $order->id,
                'payload'     => [
                    'order_serial_no'     => $order->order_serial_no,
                    'transaction_id'      => $transaction?->id,
                    'transaction_no'      => $transactionNo,
                    'payment_method'      => $gatewaySlug,
                    'amount'              => round((float) $order->total, 2),
                    'fiscal_sequence_no'  => $order->fiscal_sequence_no,
                ],
            ]);

            // [AUDIT-F-003] Cash drawer hook — record cashback as direction=out.
            // Best-effort hors transaction principale.
            if ($order instanceof Order) {
                $this->recordCashBackMovement($order, (float) $order->total);
            }
        }

        return $transaction;
    }

    public function confirmCounterPayment(Order $order, int $mode, ?float $received = null, ?string $note = null): Order
    {
        // [BYPASS-P2] Audit-log structuré si payment.bypass.enabled — invariants
        // (sealing fiscal, Outbox OrderPaidAtCounter, audit log) restent intacts.
        \App\Services\Bypass\BypassAuditLogger::paymentBypassed([
            'service' => 'PaymentService::confirmCounterPayment',
            'order_id' => $order->id,
            'mode' => $mode,
        ]);

        $allowedModes = [
            PosPaymentMethod::CASH,
            PosPaymentMethod::CARD,
            PosPaymentMethod::MOBILE_BANKING,
            PosPaymentMethod::OTHER,
            PosPaymentMethod::TICKET_RESTAURANT,
        ];

        if (! in_array($mode, $allowedModes, true)) {
            throw ValidationException::withMessages([
                'mode' => 'Mode de paiement comptoir invalide.',
            ]);
        }

        $paid = false;

        DB::transaction(function () use ($order, $mode, $received, $note, &$paid): void {
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCounterOrderVisible($locked);

            if ((int) $locked->payment_status === PaymentStatus::PAID) {
                $order->setRawAttributes($locked->getAttributes(), true);
                return;
            }

            $this->assertCounterDeferredOrder($locked);
            PaymentStateMachine::assertCanTransition((int) $locked->payment_status, PaymentStatus::PAID);

            if ($mode === PosPaymentMethod::CASH && $received !== null && (float) $received < (float) $locked->total) {
                throw ValidationException::withMessages([
                    'received' => 'Le montant recu est inferieur au total a encaisser.',
                ]);
            }

            if ($locked->fiscal_sequence_no === null) {
                $locked->fiscal_sequence_no = app(FiscalSequenceService::class)->next((int) $locked->branch_id);
            }

            $locked->payment_status = PaymentStatus::PAID;
            $locked->pos_payment_method = $mode;
            $locked->pos_received_amount = $mode === PosPaymentMethod::CASH
                ? ($received ?? (float) $locked->total)
                : null;
            $locked->pos_payment_note = $note;
            $locked->save();

            Transaction::query()->firstOrCreate(
                [
                    'order_id' => $locked->id,
                    'type' => 'payment',
                ],
                [
                    'transaction_no' => 'COUNTER-' . $locked->id . '-' . now()->format('YmdHis'),
                    'amount' => $locked->total,
                    'payment_method' => $this->counterPaymentMethodLabel($mode),
                    'sign' => '+',
                ]
            );

            app(AuditLogService::class)->write([
                'branch_id' => (int) $locked->branch_id,
                'user_id' => Auth::check() ? (int) Auth::id() : null,
                'action' => 'order.counter_payment_confirmed',
                'resource' => 'order',
                'resource_id' => (int) $locked->id,
                'payload' => [
                    'payment_method' => $mode,
                    'payment_status' => PaymentStatus::PAID,
                    'received' => $received,
                    'fiscal_sequence_no' => $locked->fiscal_sequence_no,
                ],
            ]);

            $locked->refresh();
            $order->setRawAttributes($locked->getAttributes(), true);
            $paid = true;
        });

        if ($paid) {
            OrderPaidAtCounter::dispatch($order, $mode);

            // [AUDIT-F-003] Cash drawer movement hook — best-effort.
            // Si une session caisse OPEN existe pour le caissier sur la branch,
            // on enregistre le mouvement order_payment direction=in. Si aucune
            // session n'est ouverte (legacy ou avant rollout cash sessions),
            // log warning + continue — l'order reste valide, l'audit comptable
            // sera fait post-hoc via reconciliation.
            if ($mode === PosPaymentMethod::CASH) {
                $this->recordCashOrderMovement($order, $note);
            }
        }

        return $order;
    }

    /**
     * [AUDIT-F-003 + Sprint 1B 2026-05-16] Enregistre un movement cash IN sur
     * la session OPEN du caissier.
     *
     * Deux modes :
     *   - $strict = false (legacy, kiosk counter-collect) — best-effort,
     *     log + return si pas de session OPEN. NF525 fiscalement loose
     *     mais préserve la backward-compat kiosk takeaway.
     *   - $strict = true (Sprint 1B, POS direct + split CASH) — throw
     *     `CashDrawerSessionNotOpenException` (422) si pas de session.
     *     L'order est rollback par la transaction parente : 0 order,
     *     0 movement, 0 audit ; le caissier doit ouvrir sa session
     *     avant de pouvoir vendre cash.
     *
     * `$amountOverride` permet d'écrire un movement multi-tender (tranche
     * cash seule, pas le total order). Si null → fallback `$order->total`
     * (legacy single-tender path).
     *
     * Public depuis Sprint 1B pour être appelable depuis `OrderService`
     * sans réflexion (SplitPaymentService passe par CashDrawerService
     * directement pour les tranches).
     */
    public function recordCashOrderMovement(
        Order $order,
        ?string $note = null,
        bool $strict = false,
        ?float $amountOverride = null,
    ): void
    {
        try {
            if (! Auth::check()) {
                if ($strict) {
                    throw new \App\Exceptions\CashDrawerSessionNotOpenException();
                }
                return;
            }
            $userId = (int) Auth::id();
            $branchId = (int) ($order->branch_id ?? 0);
            if ($branchId <= 0) {
                if ($strict) {
                    throw new \App\Exceptions\CashDrawerSessionNotOpenException();
                }
                return;
            }

            $cashService = app(CashDrawerService::class);
            $session = $cashService->findOpenSessionForUser($branchId, $userId);

            if (! $session) {
                if ($strict) {
                    Log::warning('[Sprint 1B] POS cash sale blocked — no open cash drawer session', [
                        'order_id'  => $order->id,
                        'branch_id' => $branchId,
                        'user_id'   => $userId,
                    ]);
                    throw new \App\Exceptions\CashDrawerSessionNotOpenException();
                }
                Log::info('[F-003] No open cash drawer session — order paid cash without session linkage', [
                    'order_id'  => $order->id,
                    'branch_id' => $branchId,
                    'user_id'   => $userId,
                ]);
                return;
            }

            $amount = $amountOverride !== null
                ? round((float) $amountOverride, 2)
                : round((float) $order->total, 2);

            $cashService->recordMovement(
                sessionId: (int) $session->id,
                type: \App\Models\CashMovement::TYPE_ORDER_PAYMENT,
                amount: $amount,
                direction: \App\Models\CashMovement::DIRECTION_IN,
                orderId: (int) $order->id,
                notes: $note,
                strict: $strict,
            );
        } catch (\App\Exceptions\CashDrawerSessionNotOpenException $e) {
            // Re-throw : doit remonter pour rollback la transaction order.
            throw $e;
        } catch (\Throwable $e) {
            if ($strict) {
                throw $e;
            }
            Log::warning('[F-003] recordCashOrderMovement failed (non-blocking)', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * [AUDIT-F-003] Hook side-effect : enregistre le cashback comme movement
     * direction=out sur la session OPEN du caissier (si elle existe). Best-effort.
     */
    private function recordCashBackMovement(Order $order, float $amount): void
    {
        try {
            if (! Auth::check()) {
                return;
            }
            $userId = (int) Auth::id();
            $branchId = (int) ($order->branch_id ?? 0);
            if ($branchId <= 0) {
                return;
            }

            $cashService = app(CashDrawerService::class);
            $session = $cashService->findOpenSessionForUser($branchId, $userId);

            if (! $session) {
                Log::info('[F-003] No open cash drawer session — cashback without session linkage', [
                    'order_id'  => $order->id,
                    'branch_id' => $branchId,
                    'user_id'   => $userId,
                ]);
                return;
            }

            $cashService->recordMovement(
                sessionId: (int) $session->id,
                type: \App\Models\CashMovement::TYPE_CASHBACK,
                amount: round($amount, 2),
                direction: \App\Models\CashMovement::DIRECTION_OUT,
                orderId: (int) $order->id,
            );
        } catch (\Throwable $e) {
            Log::warning('[F-003] recordCashBackMovement failed (non-blocking)', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public function cancelCounterPayment(Order $order, ?string $reason = null): Order
    {
        $oldStatus = null;
        $canceled = false;

        DB::transaction(function () use ($order, $reason, &$oldStatus, &$canceled): void {
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCounterOrderVisible($locked);

            if ((int) $locked->payment_status === PaymentStatus::REFUNDED) {
                $order->setRawAttributes($locked->getAttributes(), true);
                return;
            }

            $this->assertCounterDeferredOrder($locked);
            PaymentStateMachine::assertCanTransition((int) $locked->payment_status, PaymentStatus::REFUNDED);

            $oldStatus = (int) $locked->status;
            $locked->payment_status = PaymentStatus::REFUNDED;
            $locked->status = OrderStatus::CANCELED;
            $locked->pos_payment_note = $reason;
            $locked->save();

            OrderStateMachine::recordTransition(
                Order::class,
                (int) $locked->id,
                $oldStatus,
                OrderStatus::CANCELED,
                Auth::check() ? (int) Auth::id() : null,
                $reason
            );

            app(AuditLogService::class)->write([
                'branch_id' => (int) $locked->branch_id,
                'user_id' => Auth::check() ? (int) Auth::id() : null,
                'action' => 'order.counter_payment_canceled',
                'resource' => 'order',
                'resource_id' => (int) $locked->id,
                'payload' => [
                    'payment_status' => PaymentStatus::REFUNDED,
                    'reason' => $reason,
                    'fiscal_sequence_no' => $locked->fiscal_sequence_no,
                ],
            ]);

            $locked->refresh();
            $order->setRawAttributes($locked->getAttributes(), true);
            $canceled = true;
        });

        if ($canceled) {
            OrderCanceled::dispatch($order);
            OrderStatusChanged::dispatch($order, $oldStatus, OrderStatus::CANCELED);
        }

        return $order;
    }

    private function assertCounterOrderVisible(Order $order): void
    {
        $actorBranchId = Auth::check() ? (int) (Auth::user()?->branch_id ?? 0) : 0;
        if ($actorBranchId > 0 && (int) $order->branch_id !== $actorBranchId) {
            throw new HttpException(403, 'Commande hors branche.');
        }
    }

    private function assertCounterDeferredOrder(Order $order): void
    {
        $isKioskCash = (string) ($order->source_surface ?? '') === 'kiosk'
            && (int) $order->payment_method === \App\Enums\PaymentGateway::CASH_ON_DELIVERY
            && (int) $order->pos_payment_method === PosPaymentMethod::COUNTER_DEFERRED;

        if (! $isKioskCash) {
            throw new \InvalidArgumentException('This order is not a pending kiosk counter payment.', 422);
        }
    }

    private function counterPaymentMethodLabel(int $mode): string
    {
        return match ($mode) {
            PosPaymentMethod::CASH => 'counter_cash',
            PosPaymentMethod::CARD => 'counter_card',
            PosPaymentMethod::MOBILE_BANKING => 'counter_mobile_banking',
            PosPaymentMethod::TICKET_RESTAURANT => 'counter_ticket_restaurant',
            default => 'counter_other',
        };
    }

    public function isPilotPaymentMethodAllowed(string $gatewaySlug): bool
    {
        if (! (bool) config('payment.pilot_restrict.enabled', true)) {
            return true;
        }

        $method = $this->normalizePaymentMethod($gatewaySlug);
        $allowed = array_map(
            fn ($value) => $this->normalizePaymentMethod((string) $value),
            (array) config('payment.pilot_restrict.allowed_methods', ['credit'])
        );

        return in_array($method, array_values(array_unique($allowed)), true);
    }

    public function assertPilotPaymentMethodAllowed($order, string $gatewaySlug, string $attemptType = 'payment'): void
    {
        if ($this->isPilotPaymentMethodAllowed($gatewaySlug)) {
            return;
        }

        $method = $this->normalizePaymentMethod($gatewaySlug);
        $this->auditRestrictedAttempt($order, $method, $attemptType);

        throw ValidationException::withMessages([
            'payment_method' => sprintf(
                'Payment method "%s" is not available in the restricted payment pilot.',
                $method
            ),
        ]);
    }

    private function auditRestrictedAttempt($order, string $method, string $attemptType): void
    {
        try {
            app(AuditLogService::class)->write([
                'branch_id' => (int) ($order->branch_id ?? 0),
                'user_id' => Auth::check() ? (int) Auth::id() : null,
                'action' => (string) config('payment.pilot_restrict.audit_action', 'payment.method_restricted'),
                'resource' => 'order',
                'resource_id' => (int) ($order->id ?? 0),
                'payload' => [
                    'attempt_type' => $attemptType,
                    'blocked_method' => $method,
                    'reason' => 'restricted_payment_pilot',
                    'allowed_methods' => array_values((array) config('payment.pilot_restrict.allowed_methods', ['credit'])),
                    'actor_id' => Auth::check() ? (int) Auth::id() : null,
                    'actor_branch_id' => Auth::check() ? (int) (Auth::user()?->branch_id ?? 0) : null,
                    'order_branch_id' => (int) ($order->branch_id ?? 0),
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('payment.method_restricted_audit_failed', [
                'order_id' => (int) ($order->id ?? 0),
                'branch_id' => (int) ($order->branch_id ?? 0),
                'method' => $method,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function normalizePaymentMethod(string $gatewaySlug): string
    {
        return strtolower(trim($gatewaySlug));
    }
}
