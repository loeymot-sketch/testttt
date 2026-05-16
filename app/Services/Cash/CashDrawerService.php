<?php

namespace App\Services\Cash;

use App\Exceptions\CashVarianceRequiresApprovalException;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [AUDIT-F-003 / Sub-task 2] Cash drawer service — Option A.
 *
 * API minimale, transactionnelle, idempotente sur close/reconcile.
 *
 * Invariants enforced:
 *   I1. openSession refuse si une session OPEN existe déjà pour (branch_id,
 *       opened_by_user_id) — pas de double-ouverture par caissier.
 *   I2. closeSession refuse si la session n'est pas OPEN (déjà closed/reconciled).
 *   I3. reconcileSession calcule expected = opening + Σ(movements signed)
 *       et variance = closing - expected, puis fige le statut RECONCILED.
 *   I4. recordMovement refuse si la session n'est plus OPEN (rejet 422).
 *   I5. recordMovement valide direction ∈ {in, out} et type whitelisted.
 *
 * [Sprint 1D / F-4 — 2026-05-16] Variance gate:
 *   I6. Si |variance| > config('cash.variance_threshold_eur'), reconcile exige:
 *       - variance_reason non-vide (string, max 255 char)
 *       - permission cash.reconcile.variance.override (Admin + Branch Manager)
 *   Sinon → CashVarianceRequiresApprovalException (422).
 *
 * [Sprint 1D / F-8 — 2026-05-16] Audit binding:
 *   Chaque event NF525-significant (open / close / reconcile / movement) est
 *   posté dans audit_logs HMAC chain via AuditLogService::write(). Best-effort
 *   logging — l'audit log NE doit JAMAIS bloquer l'opération cash si le chain
 *   est indisponible (downgrade en log warning).
 */
class CashDrawerService
{
    /**
     * Ouvrir une session caisse pour un caissier sur une branche.
     *
     * @throws HttpException 409 si une session OPEN existe déjà
     */
    public function openSession(int $branchId, int $userId, float $openingAmount): CashDrawerSession
    {
        if ($openingAmount < 0) {
            throw new HttpException(422, 'opening_amount must be >= 0');
        }

        // [iter15-P0-09] TOCTOU hardening — race-resistant openSession.
        //
        // Pre-fix the check-then-create on (branch_id, user_id, status='open')
        // races: 2 simultaneous opens both saw "no existing" before insert,
        // creating a double-open scenario that broke I1 + corrupted Z totals.
        //
        // Defense-in-depth (3 layers):
        //   1. Cache::lock() per (branch_id, user_id) — serialises requests at
        //      the application tier (works on array driver in tests).
        //   2. DB::transaction() + lockForUpdate() on the existing-session
        //      probe — blocks concurrent SELECT inside the same DB.
        //   3. UNIQUE partial index on (branch_id, opened_by_user_id) WHERE
        //      status='open' (MySQL 8.0.13+ migration 2026_05_10_020000) —
        //      final guarantee at the storage tier even if (1)+(2) bypassed.
        //
        // Lock key namespace `cash_drawer_open_b{branch}_u{user}` is granular
        // enough that distinct cashiers / branches don't contend.
        $lockKey = "cash_drawer_open_b{$branchId}_u{$userId}";

        try {
            $session = Cache::lock($lockKey, 5)->block(3, function () use ($branchId, $userId, $openingAmount) {
                return DB::transaction(function () use ($branchId, $userId, $openingAmount) {
                    $existing = CashDrawerSession::query()
                        ->where('branch_id', $branchId)
                        ->where('opened_by_user_id', $userId)
                        ->where('status', CashDrawerSession::STATUS_OPEN)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        throw new HttpException(409, 'A cash drawer session is already open for this user on this branch');
                    }

                    return CashDrawerSession::create([
                        'branch_id'         => $branchId,
                        'opened_by_user_id' => $userId,
                        'opened_at'         => now(),
                        'opening_amount'    => $openingAmount,
                        'status'            => CashDrawerSession::STATUS_OPEN,
                    ]);
                });
            });
        } catch (LockTimeoutException $e) {
            // Could not acquire lock within 3s — surface as 409 (concurrent
            // open in progress). Idempotent semantics: caller can retry.
            Log::warning('[iter15-P0-09] cash_drawer.open.lock_timeout', [
                'branch_id' => $branchId,
                'user_id'   => $userId,
                'lock_key'  => $lockKey,
            ]);
            throw new HttpException(409, 'A cash drawer session open is already in progress for this user on this branch');
        }

        // [Sprint 1D / F-8] Audit cash.session.opened — NF525 evidence.
        $this->writeAuditLog('cash.session.opened', $session, [
            'session_id'      => $session->id,
            'opening_amount'  => (float) $session->opening_amount,
        ], $userId);

        return $session;
    }

    /**
     * Fermer une session : caissier déclare le cash physiquement compté.
     * Idempotent : appel sur session déjà closed → renvoie la session inchangée.
     *
     * @throws HttpException 422 si session reconciled (terminale) ou closing < 0
     */
    public function closeSession(int $sessionId, float $closingAmount): CashDrawerSession
    {
        if ($closingAmount < 0) {
            throw new HttpException(422, 'closing_amount must be >= 0');
        }

        $result = DB::transaction(function () use ($sessionId, $closingAmount) {
            try {
                $session = CashDrawerSession::query()
                    ->whereKey($sessionId)
                    ->lockForUpdate()
                    ->firstOrFail();
            } catch (ModelNotFoundException $e) {
                throw new HttpException(404, 'Cash drawer session not found');
            }

            if ($session->status === CashDrawerSession::STATUS_RECONCILED) {
                throw new HttpException(422, 'Cannot close a reconciled session');
            }

            // Idempotent: appel répété sur closed → no-op
            if ($session->status === CashDrawerSession::STATUS_CLOSED) {
                return ['session' => $session, 'transitioned' => false];
            }

            $session->closing_amount = $closingAmount;
            $session->closed_at      = now();
            $session->status         = CashDrawerSession::STATUS_CLOSED;
            $session->save();

            return ['session' => $session->refresh(), 'transitioned' => true];
        });

        // [Sprint 1D / F-8] Audit cash.session.closed — only on real transition.
        // Idempotent calls (already-closed) skip the audit entry to avoid
        // duplicate chain rows for the same business event.
        if ($result['transitioned']) {
            $this->writeAuditLog('cash.session.closed', $result['session'], [
                'session_id'     => $result['session']->id,
                'closing_amount' => (float) $result['session']->closing_amount,
            ]);
        }

        return $result['session'];
    }

    /**
     * Reconcilier : calcule expected_closing_amount + variance, fige RECONCILED.
     * Idempotent : appel sur session déjà RECONCILED → renvoie le résultat existant.
     *
     * [Sprint 1D / F-4] Variance gate:
     *   - |variance| <= threshold      → reconcile OK, variance_reason optional
     *   - |variance| >  threshold      → MUST provide variance_reason AND user
     *                                     MUST hold cash.reconcile.variance.override
     *                                     permission (when approval_required=true).
     *
     * @param  int          $sessionId
     * @param  string|null  $varianceReason  Required if |variance| > threshold
     * @param  User|null    $actor           User performing reconcile (defaults to Auth::user())
     * @return array{session: CashDrawerSession, expected: float, variance: float}
     *
     * @throws HttpException 422 si session OPEN (doit être CLOSED d'abord)
     * @throws CashVarianceRequiresApprovalException 422 si variance > threshold sans gate
     */
    public function reconcileSession(
        int $sessionId,
        ?string $varianceReason = null,
        ?User $actor = null,
    ): array {
        // Best-effort actor resolution: passed-in user OR currently authenticated.
        $actor ??= Auth::user() instanceof User ? Auth::user() : null;

        $result = DB::transaction(function () use ($sessionId, $varianceReason, $actor) {
            try {
                $session = CashDrawerSession::query()
                    ->whereKey($sessionId)
                    ->lockForUpdate()
                    ->firstOrFail();
            } catch (ModelNotFoundException $e) {
                throw new HttpException(404, 'Cash drawer session not found');
            }

            if ($session->status === CashDrawerSession::STATUS_OPEN) {
                throw new HttpException(422, 'Cannot reconcile an open session — close it first');
            }

            // Idempotent
            if ($session->status === CashDrawerSession::STATUS_RECONCILED) {
                return [
                    'session'      => $session,
                    'expected'     => (float) $session->expected_closing_amount,
                    'variance'     => (float) $session->variance,
                    'transitioned' => false,
                ];
            }

            $movementsSum = CashMovement::query()
                ->where('cash_drawer_session_id', $session->id)
                ->get()
                ->sum(fn (CashMovement $m) => $m->signedAmount());

            $expected = round((float) $session->opening_amount + (float) $movementsSum, 2);
            $variance = round((float) $session->closing_amount - $expected, 2);

            // [Sprint 1D / F-4] Variance gate.
            $threshold = (float) Config::get('cash.variance_threshold_eur', 2.00);
            $approvalRequired = (bool) Config::get('cash.variance_manager_approval_required', true);
            $permission = (string) Config::get('cash.variance_override_permission', 'cash.reconcile.variance.override');
            $maxReasonLength = (int) Config::get('cash.variance_reason_max_length', 255);

            $trimmedReason = $varianceReason === null ? null : trim($varianceReason);
            if ($trimmedReason === '') {
                $trimmedReason = null;
            }

            if (abs($variance) > $threshold) {
                if ($trimmedReason === null) {
                    throw new CashVarianceRequiresApprovalException(
                        message: sprintf(
                            'Cash variance %.2f€ exceeds threshold %.2f€ — variance_reason required',
                            $variance,
                            $threshold
                        ),
                        errorCode: CashVarianceRequiresApprovalException::CODE_REASON_REQUIRED,
                        variance: $variance,
                        threshold: $threshold,
                    );
                }

                if (mb_strlen($trimmedReason) > $maxReasonLength) {
                    throw new HttpException(
                        422,
                        sprintf('variance_reason exceeds %d characters', $maxReasonLength)
                    );
                }

                if ($approvalRequired) {
                    if ($actor === null || ! $this->actorCanOverrideVariance($actor, $permission)) {
                        throw new CashVarianceRequiresApprovalException(
                            message: sprintf(
                                'Cash variance %.2f€ exceeds threshold %.2f€ — manager approval required (permission %s)',
                                $variance,
                                $threshold,
                                $permission
                            ),
                            errorCode: CashVarianceRequiresApprovalException::CODE_MANAGER_APPROVAL,
                            variance: $variance,
                            threshold: $threshold,
                        );
                    }
                }
            }

            $session->expected_closing_amount = $expected;
            $session->variance                = $variance;
            // Always persist the reason when provided (even under threshold),
            // because cashier voluntary note is useful evidence.
            if ($trimmedReason !== null) {
                $session->variance_reason = $trimmedReason;
            }
            $session->status                  = CashDrawerSession::STATUS_RECONCILED;
            $session->save();

            return [
                'session'      => $session->refresh(),
                'expected'     => $expected,
                'variance'     => $variance,
                'transitioned' => true,
            ];
        });

        // [Sprint 1D / F-8] Audit cash.session.reconciled — only on transition.
        if ($result['transitioned']) {
            $thresholdForAudit = (float) Config::get('cash.variance_threshold_eur', 2.00);
            $this->writeAuditLog('cash.session.reconciled', $result['session'], [
                'session_id'      => $result['session']->id,
                'expected'        => $result['expected'],
                'variance'        => $result['variance'],
                'variance_reason' => $result['session']->variance_reason,
                'threshold'       => $thresholdForAudit,
                'over_threshold'  => abs($result['variance']) > $thresholdForAudit,
            ], $actor?->id);
        }

        return [
            'session'  => $result['session'],
            'expected' => $result['expected'],
            'variance' => $result['variance'],
        ];
    }

    /**
     * Enregistrer un mouvement cash sur une session ouverte.
     *
     * Best-effort : si la session est fermée, la méthode log + retourne null
     * SAUF en mode strict (default = false côté hook PaymentService pour ne
     * jamais bloquer l'order).
     *
     * @throws HttpException 422 (strict only) si session pas OPEN ou type/direction invalides
     */
    public function recordMovement(
        int $sessionId,
        string $type,
        float $amount,
        string $direction,
        ?int $orderId = null,
        ?string $notes = null,
        bool $strict = false,
    ): ?CashMovement {
        $allowedTypes = [
            CashMovement::TYPE_ORDER_PAYMENT,
            CashMovement::TYPE_CASHBACK,
            CashMovement::TYPE_DRAWER_OPEN,
            CashMovement::TYPE_DRAWER_CLOSE,
            CashMovement::TYPE_ADJUSTMENT,
        ];

        if (! in_array($type, $allowedTypes, true)) {
            $msg = "Invalid cash movement type: {$type}";
            if ($strict) {
                throw new HttpException(422, $msg);
            }
            Log::warning('[F-003] '.$msg);
            return null;
        }

        if (! in_array($direction, [CashMovement::DIRECTION_IN, CashMovement::DIRECTION_OUT], true)) {
            $msg = "Invalid cash movement direction: {$direction}";
            if ($strict) {
                throw new HttpException(422, $msg);
            }
            Log::warning('[F-003] '.$msg);
            return null;
        }

        if ($amount < 0) {
            $msg = 'Cash movement amount must be >= 0 (use direction to flip sign)';
            if ($strict) {
                throw new HttpException(422, $msg);
            }
            Log::warning('[F-003] '.$msg);
            return null;
        }

        $session = CashDrawerSession::query()->find($sessionId);
        if (! $session) {
            $msg = "Cash drawer session {$sessionId} not found";
            if ($strict) {
                throw new HttpException(404, $msg);
            }
            Log::warning('[F-003] '.$msg);
            return null;
        }

        if ($session->status !== CashDrawerSession::STATUS_OPEN) {
            $msg = "Cannot record movement on a {$session->status} session ({$sessionId})";
            if ($strict) {
                throw new HttpException(422, $msg);
            }
            Log::warning('[F-003] '.$msg);
            return null;
        }

        $movement = CashMovement::create([
            'cash_drawer_session_id' => $session->id,
            'branch_id'              => $session->branch_id,
            'order_id'               => $orderId,
            'type'                   => $type,
            'amount'                 => $amount,
            'direction'              => $direction,
            'notes'                  => $notes,
        ]);

        // [Sprint 1D / F-8] Audit cash.movement.recorded — NF525 evidence.
        $this->writeAuditLog('cash.movement.recorded', $session, [
            'session_id' => $session->id,
            'movement_id'=> $movement->id,
            'order_id'   => $orderId,
            'type'       => $type,
            'amount'     => (float) $movement->amount,
            'direction'  => $direction,
        ]);

        return $movement;
    }

    /**
     * Helper : trouver la session OPEN courante d'un caissier sur sa branche.
     */
    public function findOpenSessionForUser(int $branchId, int $userId): ?CashDrawerSession
    {
        return CashDrawerSession::query()
            ->where('branch_id', $branchId)
            ->where('opened_by_user_id', $userId)
            ->where('status', CashDrawerSession::STATUS_OPEN)
            ->first();
    }

    /**
     * [Sprint 1D / F-4] Check whether the actor holds the variance-override
     * permission. Uses Spatie's standard ->can() bridge so the same predicate
     * works whether the user inherits the permission through a role or has it
     * granted directly. Fail-closed: any throw → denied.
     */
    private function actorCanOverrideVariance(User $actor, string $permission): bool
    {
        if (! method_exists($actor, 'can')) {
            return false;
        }
        try {
            return (bool) $actor->can($permission);
        } catch (\Throwable $e) {
            Log::warning('[Sprint 1D / F-4] actorCanOverrideVariance threw', [
                'user_id'    => $actor->id ?? null,
                'permission' => $permission,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * [Sprint 1D / F-8] Write an audit_logs row via the official frozen
     * AuditLogService writer (we never bypass HMAC chain). Best-effort:
     * audit log failures are downgraded to log warnings — the fiscal
     * invariants enforced upstream (DB triggers, immutability, FK RESTRICT)
     * remain the source of truth even if the chain temporarily fails.
     *
     * @param  array<string,mixed>  $payload
     */
    private function writeAuditLog(
        string $action,
        CashDrawerSession $session,
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
                'resource'    => 'cash_drawer_session',
                'resource_id' => (int) $session->id,
                'payload'     => $payload,
            ]);
        } catch (\Throwable $e) {
            // Audit chain unavailable → log + continue. DB-layer immutability
            // (cash_movements_no_delete trigger + cash_drawer_sessions_no_delete
            // trigger from migration 2026_05_10_010000) already guarantees the
            // raw fiscal evidence cannot be erased — the audit row is a
            // belt-and-suspenders helper.
            Log::warning('[Sprint 1D / F-8] cash audit_log.write_failed', [
                'action'      => $action,
                'session_id'  => $session->id,
                'branch_id'   => $session->branch_id,
                'exception'   => get_class($e),
                'message'     => $e->getMessage(),
            ]);
        }
    }
}
