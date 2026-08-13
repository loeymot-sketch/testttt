<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Admin\AdminController;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Services\Cash\CashDrawerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [AUDIT-F-003 / Sub-task 3] Cash drawer SESSION management — Option A.
 *
 * NOTE — drift resolu :
 * Le sub-plan brief mentionnait `CashDrawerController` (avec route
 * `/cash-drawer/open`), mais ce nom + URL existent deja en hardware
 * (CashDrawerController::open via EscPosPrinterService — physique).
 * Le sub-plan §2.4 utilisait deja `CashDrawerSessionController` ; on
 * suit le sub-plan original. Routes prefixees `/cash-drawer/sessions/...`.
 *
 * Branch isolation : middleware permission:pos + filtre via session.branch_id
 * vs auth user branch_id (raw query bypass BranchScope only when justified).
 */
class CashDrawerSessionController extends AdminController
{
    public function __construct(private readonly CashDrawerService $service)
    {
        parent::__construct();
        $this->middleware(['permission:pos']);
    }

    /**
     * POST /api/admin/pos/cash-drawer/sessions/open
     * Body: { opening_amount: float >= 0, branch_id?: int >= 1 }
     *
     * [Wave O / O-1 2026-05-20] Admin branch context heal.
     *
     * Pre-heal: branch_id was derived solely from Auth::user()->branch_id.
     * That broke the documented admin flow where a global Admin (branch_id=0)
     * selects a target filiale via the header dropdown (DefaultAccessService)
     * and operates that branch's drawer. The endpoint refused with 422
     * "Cannot open a cash drawer session without a branch context".
     *
     * Post-heal: optional body `branch_id` mirrors the PrinterController
     * admin-supplies pattern (lines 27-29). Resolution rules:
     *   - Admin / Tenant Admin (auth branch_id=0): MUST supply body.branch_id
     *     (validated against branches table — no orphan sessions).
     *   - Branch-bound staff (auth branch_id>0): body.branch_id optional; if
     *     present it MUST equal auth.branch_id (cross-branch leak gate),
     *     otherwise auth.branch_id wins.
     *
     * The CashDrawerService (NF525) writer is unchanged — audit_logs row
     * carries (user_id=admin.id, branch_id=operated branch) which is the
     * correct dual attribution for cross-branch admin operations.
     */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0'],
            'branch_id'      => ['nullable', 'integer', 'min:1', 'exists:branches,id'],
        ]);

        $user = $request->user();
        abort_if(! $user, 401);

        try {
            $branchId = $this->resolveBranchId($request, (int) $user->branch_id, $data);
        } catch (HttpException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        try {
            $session = $this->service->openSession($branchId, (int) $user->id, (float) $data['opening_amount']);
        } catch (HttpException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status' => true,
            'data'   => $this->serialize($session),
        ], 201);
    }

    /**
     * [Wave O / O-1 2026-05-20] Resolve the operated branch_id from
     * auth context + request payload. Centralises the dual-rule logic so
     * `open` and `current` (and any future endpoint) share the same gate.
     *
     * @param  array<string,mixed>  $data  Validated request input (may contain branch_id).
     *
     * @throws HttpException 422 when admin supplies no branch_id, 403 cross-branch.
     */
    private function resolveBranchId(Request $request, int $authBranchId, array $data): int
    {
        $bodyBranchId = isset($data['branch_id']) ? (int) $data['branch_id'] : 0;

        if ($authBranchId <= 0) {
            // Global Admin / Tenant Admin path: explicit branch selection required.
            if ($bodyBranchId <= 0) {
                throw new HttpException(
                    422,
                    'Cannot open a cash drawer session without a branch context'
                );
            }
            return $bodyBranchId;
        }

        // Branch-bound staff path: cross-branch leak gate.
        if ($bodyBranchId > 0 && $bodyBranchId !== $authBranchId) {
            throw new HttpException(403, 'Cross-branch cash drawer open denied');
        }

        return $authBranchId;
    }

    /**
     * POST /api/admin/pos/cash-drawer/sessions/{session}/close
     * Body: { closing_amount: float >= 0 }
     */
    public function close(Request $request, int $session): JsonResponse
    {
        $data = $request->validate([
            'closing_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $this->assertSessionVisibleToUser($request, $session);

        try {
            $closed = $this->service->closeSession($session, (float) $data['closing_amount']);
        } catch (HttpException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status' => true,
            'data'   => $this->serialize($closed),
        ]);
    }

    /**
     * POST /api/admin/pos/cash-drawer/sessions/{session}/reconcile
     *
     * Body (optional): { variance_reason: string max 255 }
     *
     * [Sprint 1D / F-4] When |variance| > config('cash.variance_threshold_eur'),
     * the request MUST provide a non-empty variance_reason AND the calling
     * user MUST hold cash.reconcile.variance.override permission (Admin or
     * Branch Manager). Otherwise responds 422 with code
     *   CASH_VARIANCE_REASON_REQUIRED  or  CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED.
     */
    public function reconcile(Request $request, int $session): JsonResponse
    {
        $this->assertSessionVisibleToUser($request, $session);

        $data = $request->validate([
            'variance_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->service->reconcileSession(
                $session,
                $data['variance_reason'] ?? null,
                $request->user(),
            );
        } catch (\App\Exceptions\CashVarianceRequiresApprovalException $e) {
            return response()->json([
                'status'    => false,
                'message'   => $e->getMessage(),
                'code'      => $e->getErrorCode(),
                'variance'  => $e->getVariance(),
                'threshold' => $e->getThreshold(),
            ], $e->getStatusCode());
        } catch (HttpException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status' => true,
            'data'   => array_merge($this->serialize($result['session']), [
                'expected' => $result['expected'],
                'variance' => $result['variance'],
            ]),
        ]);
    }

    /**
     * GET /api/admin/pos/cash-drawer/sessions/current?branch_id=N
     * Returns the OPEN session for the calling user on the target branch (or null).
     *
     * [Wave O / O-1 2026-05-20] Admin branch context heal — mirrors the
     * open() resolution rules so the dialog can poll /current immediately
     * after admin opens against branch_id=N and get back the just-created
     * session (instead of null → re-prompt → 409 loop).
     *
     * Branch resolution:
     *   - Admin (auth branch_id=0): ?branch_id=N required to see anything;
     *     without it returns null (no implicit branch fallback).
     *   - Staff (auth branch_id>0): ?branch_id is silently ignored if it
     *     mismatches auth.branch_id (returns null rather than 403 — this
     *     is a read endpoint, BranchScope already enforces isolation, and
     *     a silent null is what the dialog expects when no session exists).
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if(! $user, 401);

        // Validation kept inline (lightweight read endpoint): branch_id is
        // optional, must be a positive integer when present. We do NOT call
        // ->exists() here so a stale query param doesn't 422 a normal poll.
        $queryBranchId = (int) $request->query('branch_id', 0);

        $authBranchId = (int) $user->branch_id;
        $targetBranchId = 0;

        if ($authBranchId <= 0) {
            // Admin: silent null when no branch query (no implicit branch).
            if ($queryBranchId <= 0) {
                return response()->json(['status' => true, 'data' => null]);
            }
            $targetBranchId = $queryBranchId;
        } else {
            // Staff: hard-pin to auth branch; silently ignore mismatched query.
            if ($queryBranchId > 0 && $queryBranchId !== $authBranchId) {
                return response()->json(['status' => true, 'data' => null]);
            }
            $targetBranchId = $authBranchId;
        }

        $session = $this->service->findOpenSessionForUser($targetBranchId, (int) $user->id);

        return response()->json([
            'status' => true,
            'data'   => $session ? $this->serialize($session) : null,
        ]);
    }

    /**
     * GET /api/admin/pos/cash-drawer/sessions/{session}/movements
     */
    public function movements(Request $request, int $session): JsonResponse
    {
        $this->assertSessionVisibleToUser($request, $session);

        $movements = CashMovement::query()
            ->where('cash_drawer_session_id', $session)
            ->orderBy('created_at')
            ->get()
            ->map(fn (CashMovement $m) => [
                'id'        => $m->id,
                'order_id'  => $m->order_id,
                'type'      => $m->type,
                'amount'    => (float) $m->amount,
                'direction' => $m->direction,
                'notes'     => $m->notes,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'status' => true,
            'data'   => $movements,
        ]);
    }

    /**
     * Serialize session uniformly across endpoints.
     *
     * @return array<string,mixed>
     */
    /**
     * Depuis combien d'HEURES cette session est-elle ouverte ? 0 si elle est close ou sans date.
     *
     * En heures et non en jours : une caisse qui a passé UNE nuit n'a pas été comptée, et c'est
     * déjà le fait qu'on veut voir — attendre le lendemain pour le dire laisserait passer
     * exactement le cas qu'on cherche.
     */
    private function ancienneteEnHeures($session): int
    {
        if ($session->closed_at !== null || $session->opened_at === null) {
            return 0;
        }

        return (int) $session->opened_at->diffInHours(now());
    }

    private function serialize(CashDrawerSession $session): array
    {
        return [
            'id'                      => $session->id,
            'branch_id'               => $session->branch_id,
            'opened_by_user_id'       => $session->opened_by_user_id,
            'opened_at'               => optional($session->opened_at)->toIso8601String(),
            'closed_at'               => optional($session->closed_at)->toIso8601String(),
            'opening_amount'          => (float) $session->opening_amount,
            'closing_amount'          => $session->closing_amount === null ? null : (float) $session->closing_amount,
            'expected_closing_amount' => $session->expected_closing_amount === null ? null : (float) $session->expected_closing_amount,
            'variance'                => $session->variance === null ? null : (float) $session->variance,
            // [Sprint 1D / F-4] Expose variance_reason — NF525 evidence,
            // displayed in admin reconciliation screen and Z-report drilldown.
            'variance_reason'         => $session->variance_reason,
            'status'                  => $session->status,

            /*
             * [CAISSE 2026-08-13] L'ANCIENNETÉ DE LA SESSION, ET SON ALERTE.
             *
             * MESURÉ EN PRODUCTION : deux sessions ouvertes depuis **49 jours** et **36 jours**,
             * zéro session close depuis l'installation, 237 mouvements et 3 818,30 € accumulés.
             *
             * Pourquoi c'est pire que « pas encore clôturé » : une session existe pour comparer ce
             * qu'on ATTEND dans le tiroir à ce qu'on y TROUVE. Sur 49 jours, cette comparaison ne
             * veut plus rien dire — l'écart d'un mardi se noie dans celui d'un samedi, et un vol de
             * 20 € disparaît dans le bruit de sept semaines. La fonction n'est pas « en attente »,
             * elle est devenue INUTILISABLE, et c'est arrivé en silence.
             *
             * ⛔ On ne clôture surtout PAS automatiquement : clôturer, c'est compter physiquement
             * l'argent. Un logiciel qui le fait à la place d'un humain invente un montant, donc un
             * écart faux — pire que pas d'écart du tout.
             *
             * On rend l'ancienneté visible là où la caisse regarde DÉJÀ (`/current`, appelée à
             * chaque ouverture d'écran). Un problème invisible ne se corrige jamais ; un problème
             * affiché finit par agacer quelqu'un, et c'est précisément ce qu'on veut.
             *
             * Sentinelle : tests/Feature/Cash/CashSessionStaleWarningTest.php
             */
            'open_since_hours'        => $this->ancienneteEnHeures($session),
            'stale'                   => $this->ancienneteEnHeures($session)
                                            >= (int) config('pos.cash_session_stale_hours', 24),
        ];
    }

    /**
     * Garantit que l'utilisateur courant peut acceder a la session.
     * BranchScope filtrerait deja, mais on ajoute un abort 404 explicite
     * si la session n'est pas visible (cohérent avec l'API REST).
     *
     * [Wave 1 P1 — POS-RED-04 2026-05-18] Ownership tightening.
     * Pre-fix: branch-only check let cashier B close cashier A's drawer
     * on the same branch (closing_amount=0 → variance mis-attribution +
     * NF525 audit_log captures wrong actor_user_id).
     * Post-fix: same-branch users must EITHER own the session OR hold
     * `cash.reconcile.variance.override` (Branch Manager / Admin) to
     * act on someone else's drawer.
     */
    private function assertSessionVisibleToUser(Request $request, int $sessionId): void
    {
        $session = CashDrawerSession::query()->find($sessionId);
        abort_if(! $session, 404, 'Cash drawer session not found');

        $user = $request->user();
        abort_if(! $user, 401);

        // Admin global (branch_id=0) voit tout
        if ((int) $user->branch_id === 0) {
            return;
        }

        abort_if((int) $session->branch_id !== (int) $user->branch_id, 403, 'Cross-branch access denied');

        // [POS-RED-04] Same-branch ownership gate.
        $isOwner   = (int) $session->opened_by_user_id === (int) $user->id;
        $isManager = $user->can('cash.reconcile.variance.override')
            || $user->hasRole('Admin')
            || $user->hasRole('Branch Manager');
        abort_if(! $isOwner && ! $isManager, 403, 'Not session owner (manager permission required)');
    }
}
