<?php

/**
 * F-03 / NEW-02 / NEW-04
 * Authority: plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md (Lot 1.C)
 *
 * Polling fallback endpoint for KDS. Idempotent (read-only). Multi-tenant
 * safe: the resolved branch_id always comes from auth()->user() unless the
 * authenticated user is admin (branch_id = 0), in which case an explicit
 * ?branch_id=N override is honoured.
 */

namespace App\Http\Controllers\Admin;

use App\Services\KdsSyncService;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class KdsSyncController extends AdminController
{
    private KdsSyncService $kdsSyncService;

    public function __construct(KdsSyncService $kdsSyncService)
    {
        parent::__construct();
        $this->kdsSyncService = $kdsSyncService;
        $this->middleware(['permission:kitchen-display-system'])->only('sync');
    }

    public function sync(Request $request): JsonResponse
    {
        $since = $request->query('since');

        if (! is_string($since) || $since === '') {
            return response()->json([
                'message' => 'The since query parameter is required.',
            ], 400);
        }

        try {
            $sinceAt = new DateTimeImmutable($since);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'The since query parameter must be a valid ISO-8601 timestamp.',
            ], 400);
        }

        $user = auth()->user();
        $userBranchId = (int) ($user->branch_id ?? 0);
        $requestedBranchId = $request->query('branch_id');
        $resolvedBranchId = $userBranchId;

        if ($userBranchId === 0) {
            // Admin: may scope to a single branch via explicit override.
            if ($requestedBranchId !== null && $requestedBranchId !== '') {
                $resolvedBranchId = (int) $requestedBranchId;
            }
        } elseif ($requestedBranchId !== null
            && $requestedBranchId !== ''
            && (int) $requestedBranchId !== $userBranchId) {
            return response()->json([
                'message' => 'Cross-branch access is forbidden.',
            ], 403);
        }

        $rawIncludeDeleted = $request->query('include_deleted');
        $includeDeleted = true;
        if ($rawIncludeDeleted !== null && $rawIncludeDeleted !== '') {
            $parsed = filter_var($rawIncludeDeleted, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $includeDeleted = $parsed ?? true;
        }

        $payload = $this->kdsSyncService->sync($resolvedBranchId, $sinceAt, $includeDeleted);

        return response()->json($payload);
    }
}
