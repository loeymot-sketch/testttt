<?php

namespace App\Services\Delivery;

use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryBoyCashSession;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [V1.0.2 Sub-6.3 — 2026-05-18] Delivery boy cash session service.
 *
 * Path B duplicate of CashDrawerService (Planner H plan §0 / Decision Coin #8) :
 * `CashDrawerService` est frozen-adjacent (NF525 wrappers + variance gate) —
 * une extraction de base commune nécessiterait un LOCK plan. Wave 6b duplique
 * le pattern pour rester scope-minimal.
 *
 * API publique :
 *   - openSession(int $branchId, int $deliveryBoyId, float $openingAmount, ?int $openedByUserId)
 *   - closeSession(int $sessionId, float $closingAmount)
 *   - reconcileSession(int $sessionId, ?string $varianceReason, ?User $actor)
 *   - recordMovement(int $sessionId, string $type, float $amount, string $direction, ?int $orderId, ?string $notes, bool $strict)
 *   - findOpenSessionForDeliveryBoy(int $branchId, int $deliveryBoyId) : helper
 *
 * Invariants NF525 :
 *   I1. openSession refuse si une session OPEN existe pour (branch_id,
 *       delivery_boy_id) — défense en profondeur Cache::lock + DB lockForUpdate
 *       + UNIQUE partial index.
 *   I2. closeSession refuse si session reconciled (terminale).
 *   I3. reconcileSession calcule expected = opening + Σ(movements signed)
 *       et variance = closing - expected, puis fige RECONCILED.
 *   I4. recordMovement refuse si la session n'est plus OPEN.
 *   I5. recordMovement valide type ∈ whitelist + direction ∈ {in, out}.
 *
 * Audit chain unification (Planner H plan §7 / Decision Coin #2) :
 *   TOUS les events MUST passer par AuditLogService::write() sur la chain
 *   `audit_logs` par branch_id — JAMAIS de chain séparée. Actions namespace :
 *   `cash.delivery.session.opened|closed|reconciled` + `cash.delivery.movement.recorded`.
 *
 * Audit binding pattern : best-effort try/catch ; failures degraded en log
 * warning. DB-layer immutability (no-delete triggers) reste source de vérité.
 */
class DeliveryBoyCashSessionService
{
    /**
     * Ouvrir une session cash pour un livreur sur une branche.
     *
     * @param  int       $branchId          Branch on which the livreur is on-shift.
     * @param  int       $deliveryBoyId     User.id of the Delivery Boy role member.
     * @param  float     $openingAmount     Float pour rendre la monnaie (>= 0).
     * @param  int|null  $openedByUserId    Admin or livreur-self user.id (defaults to Auth::id()).
     *
     * @throws HttpException 409 si une session OPEN existe déjà
     * @throws HttpException 422 si opening_amount < 0
     */
    public function openSession(
        int $branchId,
        int $deliveryBoyId,
        float $openingAmount,
        ?int $openedByUserId = null,
    ): DeliveryBoyCashSession {
        if ($openingAmount < 0) {
            throw new HttpException(422, 'opening_amount must be >= 0');
        }

        $openedByUserId ??= (Auth::check() ? (int) Auth::id() : $deliveryBoyId);

        // Defense-in-depth — mirror iter15-P0-09 cash-drawer pattern :
        //   Layer-1 Cache::lock per (branch, livreur).
        //   Layer-2 DB::transaction + lockForUpdate sur la probe existing.
        //   Layer-3 UNIQUE partial index (migration 120200).
        $lockKey = "delivery_boy_cash_open_b{$branchId}_dbu{$deliveryBoyId}";

        try {
            $session = Cache::lock($lockKey, 5)->block(3, function () use ($branchId, $deliveryBoyId, $openingAmount, $openedByUserId) {
                return DB::transaction(function () use ($branchId, $deliveryBoyId, $openingAmount, $openedByUserId) {
                    $existing = DeliveryBoyCashSession::query()
                        ->withoutGlobalScopes() // cross-branch check from admin context — explicit
                        ->where('branch_id', $branchId)
                        ->where('delivery_boy_id', $deliveryBoyId)
                        ->where('status', DeliveryBoyCashSession::STATUS_OPEN)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        throw new HttpException(409, 'A delivery boy cash session is already open for this livreur on this branch');
                    }

                    return DeliveryBoyCashSession::create([
                        'branch_id'         => $branchId,
                        'delivery_boy_id'   => $deliveryBoyId,
                        'opened_by_user_id' => $openedByUserId,
                        'opened_at'         => now(),
                        'opening_amount'    => $openingAmount,
                        'status'            => DeliveryBoyCashSession::STATUS_OPEN,
                    ]);
                });
            });
        } catch (LockTimeoutException $e) {
            Log::warning('[V1.0.2 Sub-6.3] delivery_boy_cash.open.lock_timeout', [
                'branch_id'        => $branchId,
                'delivery_boy_id'  => $deliveryBoyId,
                'lock_key'         => $lockKey,
            ]);
            throw new HttpException(409, 'A delivery boy cash session open is already in progress for this livreur on this branch');
        }

        $this->writeAuditLog('cash.delivery.session.opened', $session, [
            'session_id'      => $session->id,
            'opening_amount'  => (float) $session->opening_amount,
            'delivery_boy_id' => (int) $session->delivery_boy_id,
        ], $openedByUserId);

        return $session;
    }

    /**
     * Fermer une session : livreur déclare le cash physiquement compté.
     * Idempotent sur session déjà closed.
     *
     * @throws HttpException 422 si reconciled (terminale) ou closing < 0
     * @throws HttpException 404 si session not found
     */
    public function closeSession(int $sessionId, float $closingAmount): DeliveryBoyCashSession
    {
        if ($closingAmount < 0) {
            throw new HttpException(422, 'closing_amount must be >= 0');
        }

        $result = DB::transaction(function () use ($sessionId, $closingAmount) {
            try {
                $session = DeliveryBoyCashSession::query()
                    ->withoutGlobalScopes() // service-layer — branch enforced upstream
                    ->whereKey($sessionId)
                    ->lockForUpdate()
                    ->firstOrFail();
            } catch (ModelNotFoundException $e) {
                throw new HttpException(404, 'Delivery boy cash session not found');
            }

            if ($session->status === DeliveryBoyCashSession::STATUS_RECONCILED) {
                throw new HttpException(422, 'Cannot close a reconciled delivery boy cash session');
            }

            // Idempotent : appel répété sur closed → no-op
            if ($session->status === DeliveryBoyCashSession::STATUS_CLOSED) {
                return ['session' => $session, 'transitioned' => false];
            }

            $session->closing_amount    = $closingAmount;
            $session->closed_at         = now();
            $session->status            = DeliveryBoyCashSession::STATUS_CLOSED;
            $session->closed_by_user_id = Auth::check() ? (int) Auth::id() : null;
            $session->save();

            return ['session' => $session->refresh(), 'transitioned' => true];
        });

        if ($result['transitioned']) {
            $this->writeAuditLog('cash.delivery.session.closed', $result['session'], [
                'session_id'     => $result['session']->id,
                'closing_amount' => (float) $result['session']->closing_amount,
            ]);
        }

        return $result['session'];
    }

    /**
     * Reconcilier : calcule expected + variance, fige RECONCILED.
     * Idempotent sur session déjà reconciled.
     *
     * NOTE Wave 6b-1 — variance gate (manager approval over threshold) reused
     * from POS (`cash.variance_threshold_eur` config). Wave 6b ultérieure :
     * ajouter un permission spécifique `delivery.cash.reconcile.variance.override`
     * si owner souhaite séparer du POS pattern.
     *
     * @return array{session: DeliveryBoyCashSession, expected: float, variance: float}
     *
     * @throws HttpException 422 si session OPEN (close() requis d'abord)
     * @throws HttpException 404 si session not found
     */
    public function reconcileSession(
        int $sessionId,
        ?string $varianceReason = null,
        ?User $actor = null,
    ): array {
        $actor ??= Auth::user() instanceof User ? Auth::user() : null;

        $result = DB::transaction(function () use ($sessionId, $varianceReason, $actor) {
            try {
                $session = DeliveryBoyCashSession::query()
                    ->withoutGlobalScopes()
                    ->whereKey($sessionId)
                    ->lockForUpdate()
                    ->firstOrFail();
            } catch (ModelNotFoundException $e) {
                throw new HttpException(404, 'Delivery boy cash session not found');
            }

            if ($session->status === DeliveryBoyCashSession::STATUS_OPEN) {
                throw new HttpException(422, 'Cannot reconcile an open delivery boy cash session — close it first');
            }

            // Idempotent
            if ($session->status === DeliveryBoyCashSession::STATUS_RECONCILED) {
                return [
                    'session'      => $session,
                    'expected'     => (float) $session->expected_closing_amount,
                    'variance'     => (float) $session->variance,
                    'transitioned' => false,
                ];
            }

            $movementsSum = DeliveryBoyCashMovement::query()
                ->withoutGlobalScopes()
                ->where('delivery_boy_cash_session_id', $session->id)
                ->get()
                ->sum(fn (DeliveryBoyCashMovement $m) => $m->signedAmount());

            $expected = round((float) $session->opening_amount + (float) $movementsSum, 2);
            $variance = round((float) $session->closing_amount - $expected, 2);

            $trimmedReason = $varianceReason === null ? null : trim($varianceReason);
            if ($trimmedReason === '') {
                $trimmedReason = null;
            }

            $session->expected_closing_amount = $expected;
            $session->variance                = $variance;
            if ($trimmedReason !== null) {
                $session->variance_reason = $trimmedReason;
            }
            $session->reconciled_by_user_id = $actor?->id;
            $session->status                = DeliveryBoyCashSession::STATUS_RECONCILED;
            $session->save();

            return [
                'session'      => $session->refresh(),
                'expected'     => $expected,
                'variance'     => $variance,
                'transitioned' => true,
            ];
        });

        if ($result['transitioned']) {
            $this->writeAuditLog('cash.delivery.session.reconciled', $result['session'], [
                'session_id'      => $result['session']->id,
                'expected'        => $result['expected'],
                'variance'        => $result['variance'],
                'variance_reason' => $result['session']->variance_reason,
            ], $actor?->id);
        }

        return [
            'session'  => $result['session'],
            'expected' => $result['expected'],
            'variance' => $result['variance'],
        ];
    }

    /**
     * Enregistrer un mouvement cash sur une session OPEN.
     *
     * Best-effort par défaut (strict=false) : session fermée / inexistante →
     * log warning + retourne null. Strict mode pour les paths où une absence
     * de session doit BLOQUER (DELIVERED hook → 422 LIVREUR_SHIFT_NOT_OPEN).
     *
     * Race-resistant : DB::transaction + lockForUpdate sur la session row.
     *
     * @throws HttpException 422 / 404 (strict only)
     */
    public function recordMovement(
        int $sessionId,
        string $type,
        float $amount,
        string $direction,
        ?int $orderId = null,
        ?string $notes = null,
        bool $strict = false,
    ): ?DeliveryBoyCashMovement {
        $allowedTypes = [
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            DeliveryBoyCashMovement::TYPE_CHANGE_GIVEN,
            DeliveryBoyCashMovement::TYPE_DRAWER_OPEN,
            DeliveryBoyCashMovement::TYPE_DRAWER_CLOSE,
            DeliveryBoyCashMovement::TYPE_ADJUSTMENT,
        ];

        if (! in_array($type, $allowedTypes, true)) {
            $msg = "Invalid delivery boy cash movement type: {$type}";
            if ($strict) {
                throw new HttpException(422, $msg);
            }
            Log::warning('[V1.0.2 Sub-6.3] '.$msg);

            return null;
        }

        if (! in_array($direction, [DeliveryBoyCashMovement::DIRECTION_IN, DeliveryBoyCashMovement::DIRECTION_OUT], true)) {
            $msg = "Invalid delivery boy cash movement direction: {$direction}";
            if ($strict) {
                throw new HttpException(422, $msg);
            }
            Log::warning('[V1.0.2 Sub-6.3] '.$msg);

            return null;
        }

        if ($amount < 0) {
            $msg = 'Delivery boy cash movement amount must be >= 0 (use direction to flip sign)';
            if ($strict) {
                throw new HttpException(422, $msg);
            }
            Log::warning('[V1.0.2 Sub-6.3] '.$msg);

            return null;
        }

        return DB::transaction(function () use ($sessionId, $type, $amount, $direction, $orderId, $notes, $strict) {
            $session = DeliveryBoyCashSession::query()
                ->withoutGlobalScopes()
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                $msg = "Delivery boy cash session {$sessionId} not found";
                if ($strict) {
                    throw new HttpException(404, $msg);
                }
                Log::warning('[V1.0.2 Sub-6.3] '.$msg);

                return null;
            }

            if ($session->status !== DeliveryBoyCashSession::STATUS_OPEN) {
                $msg = "Cannot record movement on a {$session->status} delivery boy cash session ({$sessionId})";
                if ($strict) {
                    throw new HttpException(422, $msg);
                }
                Log::warning('[V1.0.2 Sub-6.3] '.$msg);

                return null;
            }

            $movement = DeliveryBoyCashMovement::create([
                'delivery_boy_cash_session_id' => $session->id,
                'branch_id'                    => $session->branch_id,
                'order_id'                     => $orderId,
                'type'                         => $type,
                'amount'                       => $amount,
                'direction'                    => $direction,
                'notes'                        => $notes,
            ]);

            // Audit row commits atomically with the movement (NF525 evidence).
            $this->writeAuditLog('cash.delivery.movement.recorded', $session, [
                'session_id'  => $session->id,
                'movement_id' => $movement->id,
                'order_id'    => $orderId,
                'type'        => $type,
                'amount'      => (float) $movement->amount,
                'direction'   => $direction,
            ]);

            return $movement;
        });
    }

    /**
     * Helper : trouver la session OPEN courante d'un livreur sur sa branche.
     */
    public function findOpenSessionForDeliveryBoy(int $branchId, int $deliveryBoyId): ?DeliveryBoyCashSession
    {
        return DeliveryBoyCashSession::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('delivery_boy_id', $deliveryBoyId)
            ->where('status', DeliveryBoyCashSession::STATUS_OPEN)
            ->first();
    }

    /**
     * Write an audit_logs row via the canonical AuditLogService writer.
     * Best-effort : failures dégradés en log warning, DB-layer immutability
     * (no-delete triggers migration 120300) reste la source de vérité.
     *
     * @param  array<string,mixed>  $payload
     */
    private function writeAuditLog(
        string $action,
        DeliveryBoyCashSession $session,
        array $payload,
        ?int $userIdOverride = null,
    ): void {
        try {
            $userId = $userIdOverride
                ?? (Auth::check() ? (int) Auth::id() : (int) ($session->opened_by_user_id ?? 0));

            app(AuditLogService::class)->write([
                'branch_id'   => (int) $session->branch_id,
                'user_id'     => $userId > 0 ? $userId : null,
                'action'      => $action,
                'resource'    => 'delivery_boy_cash_session',
                'resource_id' => (int) $session->id,
                'payload'     => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[V1.0.2 Sub-6.3] delivery_boy_cash audit_log.write_failed', [
                'action'     => $action,
                'session_id' => $session->id,
                'branch_id'  => $session->branch_id,
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
            ]);
        }
    }
}
