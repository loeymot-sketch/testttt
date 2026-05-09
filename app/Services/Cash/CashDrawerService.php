<?php

namespace App\Services\Cash;

use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
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
            return Cache::lock($lockKey, 5)->block(3, function () use ($branchId, $userId, $openingAmount) {
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

        return DB::transaction(function () use ($sessionId, $closingAmount) {
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
                return $session;
            }

            $session->closing_amount = $closingAmount;
            $session->closed_at      = now();
            $session->status         = CashDrawerSession::STATUS_CLOSED;
            $session->save();

            return $session->refresh();
        });
    }

    /**
     * Reconcilier : calcule expected_closing_amount + variance, fige RECONCILED.
     * Idempotent : appel sur session déjà RECONCILED → renvoie le résultat existant.
     *
     * @return array{session: CashDrawerSession, expected: float, variance: float}
     *
     * @throws HttpException 422 si session OPEN (doit être CLOSED d'abord)
     */
    public function reconcileSession(int $sessionId): array
    {
        return DB::transaction(function () use ($sessionId) {
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
                    'session'  => $session,
                    'expected' => (float) $session->expected_closing_amount,
                    'variance' => (float) $session->variance,
                ];
            }

            $movementsSum = CashMovement::query()
                ->where('cash_drawer_session_id', $session->id)
                ->get()
                ->sum(fn (CashMovement $m) => $m->signedAmount());

            $expected = round((float) $session->opening_amount + (float) $movementsSum, 2);
            $variance = round((float) $session->closing_amount - $expected, 2);

            $session->expected_closing_amount = $expected;
            $session->variance                = $variance;
            $session->status                  = CashDrawerSession::STATUS_RECONCILED;
            $session->save();

            return [
                'session'  => $session->refresh(),
                'expected' => $expected,
                'variance' => $variance,
            ];
        });
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

        return CashMovement::create([
            'cash_drawer_session_id' => $session->id,
            'branch_id'              => $session->branch_id,
            'order_id'               => $orderId,
            'type'                   => $type,
            'amount'                 => $amount,
            'direction'              => $direction,
            'notes'                  => $notes,
        ]);
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
}
