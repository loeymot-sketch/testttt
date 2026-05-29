<?php

namespace App\Http\Controllers\Admin;

use App\Http\Resources\CashOverviewTransactionResource;
use App\Models\CashDrawerSession;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [Wave X — X4 2026-05-21] Admin unified cash & transactions overview.
 *
 * Owner mandate (verbatim translated) :
 *   « Toutes les commandes encaissées (POS direct, borne cash-collected,
 *     livreur) au MÊME ENDROIT en base. Admin part + caisse voient tout.
 *     Pour chaque transaction : source (borne/caisse/livreur) + mode
 *     paiement + total. Totaux par source + grand total. Permet de
 *     détecter écarts (cash manquant). »
 *
 * Endpoint : GET /api/admin/cash-overview
 *
 * Sibling to Wave O O4 `/api/admin/cash-sessions-report` :
 *   - O4 groups CashDrawerSession rows day-by-day (cash drawer detail).
 *   - X4 (this) lists every Transaction across all sources with derived
 *     `source` and normalized `mode` buckets — designed for daily écart
 *     reconciliation.
 *
 * Permission : reuses `cash-sessions-report` (Admin via Permission::all()
 *   + Branch Manager explicit in RolePermissionTableSeeder L77). Per Wave X
 *   PLAN.md §3 X4, same role gate as O4 → no new permission seeded.
 *
 * Branch isolation :
 *   Transaction has NO BranchScope global (Wave K audit + Model::read).
 *   Isolation flows through Order.branch_id via the whereHas filter below.
 *   - Admin (branch_id=0) : bypasses Order's BranchScope inside whereHas
 *     so the join sees rows for all branches.
 *   - Branch Manager (branch_id>0) : Order's BranchScope already filters
 *     to their branch; we still pass an explicit branch filter to keep the
 *     query plan readable + the regression-test predictable.
 *
 * NF525 : READ-ONLY. Fiscal sequence + audit chain untouched. The query
 *   never writes a row.
 *
 * TZ : `config('app.timezone')` = Europe/Paris in V1 — date bounds parsed
 *   in that TZ so "today" maps to the cashier's actual business day.
 *
 * N+1 : eager-load order(:id, queue_number, source_surface, order_type,
 *   delivery_boy_id) + order.deliveryBoy(:id, name). Hard cap 500 rows
 *   protects /admin UI scroll + keeps query count bounded.
 *
 * Supported filters :
 *   - from=YYYY-MM-DD     (inclusive on transactions.created_at, TZ-aware)
 *   - to=YYYY-MM-DD       (inclusive on transactions.created_at, TZ-aware)
 *   - source=caisse|borne|livreur   (POS direct / counter-collect / delivery)
 *   - mode=cash|card|mobile|ticket|other  (normalized buckets)
 *   - branch_id=N         (admin only — Branch Manager always sees own)
 */
class CashOverviewController extends AdminController
{
    /**
     * Max rows returned per call. Larger windows must be filtered down via
     * `from`/`to` rather than paginated — matches owner mandate "écart par
     * jour" granularity.
     */
    private const MAX_ROWS = 500;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * GET /api/admin/cash-overview
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if(! $user, Response::HTTP_UNAUTHORIZED);
        abort_unless(
            $user->can('cash-sessions-report'),
            Response::HTTP_FORBIDDEN,
            'cash-sessions-report permission required.'
        );

        $tz = config('app.timezone') ?: 'Europe/Paris';
        $defaultDate = Carbon::today($tz)->toDateString();
        $from = (string) $request->query('from', $defaultDate);
        $to   = (string) $request->query('to',   $defaultDate);

        try {
            $startBound = Carbon::parse($from, $tz)->startOfDay();
            $endBound   = Carbon::parse($to,   $tz)->endOfDay();
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid date range (expected YYYY-MM-DD)',
            ], 422);
        }

        $isGlobalAdmin = (int) ($user->branch_id ?? 0) === 0;
        $branchFilter  = $this->resolveBranchFilter($request, $user);

        // Build Transaction query. Transaction has no BranchScope; we filter
        // via order.branch_id and bypass the Order BranchScope when
        // necessary (admin cross-branch).
        $query = Transaction::query()
            ->whereBetween('created_at', [$startBound, $endBound])
            // Only "payment" rows count as encaissements. Cash-back rows have
            // sign='-' + type='cash_back' and are excluded — admin écart view
            // is about cash IN, not refunds.
            ->where('type', 'payment')
            ->with([
                'order' => function ($q) use ($isGlobalAdmin) {
                    if ($isGlobalAdmin) {
                        // Admin sees orders from all branches.
                        $q->withoutGlobalScope(BranchScope::class);
                    }
                    $q->select([
                        'id',
                        'branch_id',
                        'queue_number',
                        'order_serial_no',
                        'source_surface',
                        'order_type',
                        'delivery_boy_id',
                    ])->with([
                        'deliveryBoy:id,name',
                    ]);
                },
            ])
            ->whereHas('order', function ($q) use ($isGlobalAdmin, $branchFilter) {
                if ($isGlobalAdmin) {
                    $q->withoutGlobalScope(BranchScope::class);
                }
                if ($branchFilter !== null) {
                    $q->where('branch_id', $branchFilter);
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        // [GOAL-2026-05-29 F7] MAX_ROWS cap moved to the rendered LIST only (below),
        // NOT the builder — the summary must aggregate ALL matching rows.

        // Mode filter (normalized bucket) — applied via raw payment_method LIKE.
        if ($modeRaw = $request->query('mode')) {
            $modePatterns = $this->paymentMethodPatternsForBucket((string) $modeRaw);
            if (!empty($modePatterns)) {
                $query->where(function ($q) use ($modePatterns) {
                    foreach ($modePatterns as $pattern) {
                        $q->orWhere('payment_method', 'like', $pattern);
                    }
                });
            }
        }

        // Source filter — derived; apply at Order level so SQL stays cheap.
        if ($srcRaw = $request->query('source')) {
            $src = strtolower((string) $srcRaw);
            $query->whereHas('order', function ($q) use ($src, $isGlobalAdmin) {
                if ($isGlobalAdmin) {
                    $q->withoutGlobalScope(BranchScope::class);
                }
                switch ($src) {
                    case 'livreur':
                    case 'delivery_boy':
                        $q->whereNotNull('delivery_boy_id');
                        break;
                    case 'borne':
                    case 'kiosk':
                    case 'counter_collect':
                        $q->where(function ($qq) {
                            $qq->where('source_surface', 'like', '%kiosk%')
                                ->orWhere('order_type', \App\Enums\OrderType::KIOSK);
                        });
                        break;
                    case 'caisse':
                    case 'pos':
                    case 'pos_direct':
                    default:
                        $q->whereNull('delivery_boy_id')
                            ->where(function ($qq) {
                                $qq->whereNotIn('order_type', [\App\Enums\OrderType::KIOSK])
                                    ->orWhereNull('order_type');
                            })
                            ->where(function ($qq) {
                                $qq->whereNull('source_surface')
                                    ->orWhere('source_surface', 'not like', '%kiosk%');
                            });
                        break;
                }
            });
        }

        // [GOAL-2026-05-29 F7] Summary aggregates the FULL filtered (date-bounded)
        // set so grand_total / by_source / by_mode are correct even on a
        // >MAX_ROWS window — the previous code summed only the capped list,
        // silently under-reporting reconciliation totals. The window is
        // day-bounded by default (whereBetween created_at), so this is cheap.
        $summary = $this->summarize((clone $query)->get());

        // Rendered list stays capped for performance (UI table only). The
        // summary above is NOT affected by this cap.
        $transactions = $query->limit(self::MAX_ROWS)->get();

        // Cash drawer reconciliation column. Branch Manager / cashier sees
        // their open session. Admin sees the session of the filtered branch
        // when an explicit branch_id is provided; otherwise null (no drawer
        // to reconcile against, owner can drill down per-branch).
        $cashSession = $this->resolveOpenCashSession($user, $branchFilter, $isGlobalAdmin);

        $cashSessionPayload = null;
        if ($cashSession) {
            // [Wave X-C round-2 2026-05-21] Fix C-014 — the reconciliation
            // `cash_collected` is a property of the cash drawer session itself
            // (physical cash IN today, scoped to drawer's branch + day).
            // It MUST be invariant against the UI source/mode/branch filters
            // applied to the transactions list — otherwise filtering
            // source=borne would falsely show the drawer holds only the
            // borne portion of the cash. We compute it from a SEPARATE
            // bounded query restricted to drawer.branch_id + the current
            // day window, IGNORING all UI filters.
            $sessionBranchId = (int) $cashSession->branch_id;
            $cashCollectedToday = (float) $this->drawerCashCollectedUnfiltered(
                $sessionBranchId,
                $startBound,
                $endBound,
                $isGlobalAdmin
            );
            $expectedCash = round(
                (float) $cashSession->opening_amount + $cashCollectedToday,
                2
            );
            $cashSessionPayload = [
                'id'               => (int) $cashSession->id,
                'branch_id'        => $sessionBranchId,
                'opened_at'        => optional($cashSession->opened_at)->toIso8601String(),
                'opening_amount'   => round((float) $cashSession->opening_amount, 2),
                'expected_cash'    => $expectedCash,
                'cash_collected'   => round($cashCollectedToday, 2),
            ];
        }

        return response()->json([
            'status' => true,
            'data'   => CashOverviewTransactionResource::collection($transactions),
            'summary'      => $summary,
            'cash_session' => $cashSessionPayload,
            'meta'         => [
                'from'      => $startBound->toIso8601String(),
                'to'        => $endBound->toIso8601String(),
                'tz'        => $tz,
                'row_count' => $transactions->count(),
                'capped'    => $transactions->count() >= self::MAX_ROWS,
            ],
        ]);
    }

    /**
     * Resolve the branch_id we filter on.
     *  - Admin (branch_id=0) : honours `branch_id` query param, else null
     *    (all branches).
     *  - Branch Manager (branch_id>0) : forced to own branch — query param
     *    silently ignored to avoid leaking "branch exists" info.
     */
    private function resolveBranchFilter(Request $request, $user): ?int
    {
        $userBranch = (int) ($user->branch_id ?? 0);
        if ($userBranch === 0) {
            $hint = $request->query('branch_id');
            if ($hint !== null && (int) $hint > 0) {
                return (int) $hint;
            }
            return null;
        }
        return $userBranch;
    }

    /**
     * Resolve the currently open CashDrawerSession to compute the
     * "espèce attendue" reconciliation column. Admin uses the requested
     * branch_id filter; staff uses their own.
     *
     * [Wave X-C round-1 2026-05-21] Fix C-003 — when admin is browsing
     * without an explicit `?branch_id=` query param we now fall back to
     * the most-recently-opened drawer session across all branches instead
     * of returning null. This makes the reconciliation card mount by
     * default for the admin login the spec exercises, matching the owner
     * mandate « détecter écarts (cash manquant) » without requiring the
     * user to first pick a branch. If literally no drawer is open anywhere
     * the card stays hidden — there is nothing to reconcile against. The
     * sibling /admin/cash-sessions-report behaviour is unchanged.
     */
    private function resolveOpenCashSession($user, ?int $branchFilter, bool $isGlobalAdmin): ?CashDrawerSession
    {
        $query = CashDrawerSession::query();
        if ($isGlobalAdmin) {
            $query->withoutGlobalScope(BranchScope::class);
        }
        $query->where('status', CashDrawerSession::STATUS_OPEN);

        if ($branchFilter !== null && $branchFilter > 0) {
            // Admin with explicit branch_id, OR Branch Manager (forced own).
            $query->where('branch_id', $branchFilter);
        } elseif (! $isGlobalAdmin) {
            // Branch Manager / cashier without their own branch — refuse to
            // leak another branch's drawer.
            $ownBranch = (int) ($user->branch_id ?? 0);
            if ($ownBranch <= 0) {
                return null;
            }
            $query->where('branch_id', $ownBranch);
        }
        // Else: global admin, no explicit filter — fall back to the
        // most-recently-opened drawer across all branches so the card
        // mounts by default for the admin's daily écart view.

        return $query->orderByDesc('opened_at')->first();
    }

    /**
     * [Wave X-C round-2 2026-05-21] Fix C-014 — compute the drawer's
     * `cash_collected` from a SEPARATE bounded query restricted to the
     * drawer's branch + the current day window, IGNORING the UI
     * source/mode/branch filters used to build `$transactions`.
     *
     * Reconciliation cash IN is a physical-drawer invariant: it does NOT
     * change because the admin filtered the transactions list to
     * source=borne or mode=card. Returns the rounded total in EUR.
     *
     * Cost: one extra SELECT bounded by (branch_id + day + type=payment +
     * cash LIKE patterns), well below 100 rows in realistic operation —
     * negligible vs the main list query already capped at 500.
     */
    private function drawerCashCollectedUnfiltered(
        int $branchId,
        Carbon $startBound,
        Carbon $endBound,
        bool $isGlobalAdmin
    ): float {
        $cashPatterns = $this->paymentMethodPatternsForBucket('cash');

        $q = Transaction::query()
            ->whereBetween('created_at', [$startBound, $endBound])
            ->where('type', 'payment')
            ->whereHas('order', function ($oq) use ($branchId, $isGlobalAdmin) {
                if ($isGlobalAdmin) {
                    $oq->withoutGlobalScope(BranchScope::class);
                }
                $oq->where('branch_id', $branchId);
            })
            ->where(function ($pq) use ($cashPatterns) {
                foreach ($cashPatterns as $pattern) {
                    $pq->orWhere('payment_method', 'like', $pattern);
                }
            });

        $sum = (float) $q->sum('amount');
        return round($sum, 2);
    }

    /**
     * Aggregate the transaction list into the summary payload expected by
     * the frontend. Single-pass to stay O(n).
     */
    private function summarize($transactions): array
    {
        $total = 0.0;
        $count = 0;
        $bySource = [];
        $byMode   = [];

        foreach ($transactions as $tx) {
            $amount = round((float) ($tx->amount ?? 0), 2);
            $source = self::deriveSource($tx);
            $mode   = self::derivePaymentBucket((string) ($tx->payment_method ?? ''));

            $total += $amount;
            $count++;

            if (! isset($bySource[$source])) {
                $bySource[$source] = ['count' => 0, 'total' => 0.0];
            }
            $bySource[$source]['count']++;
            $bySource[$source]['total'] = round($bySource[$source]['total'] + $amount, 2);

            if (! isset($byMode[$mode])) {
                $byMode[$mode] = ['count' => 0, 'total' => 0.0];
            }
            $byMode[$mode]['count']++;
            $byMode[$mode]['total'] = round($byMode[$mode]['total'] + $amount, 2);
        }

        return [
            'total'     => round($total, 2),
            'count'     => $count,
            'by_source' => $bySource,
            'by_mode'   => $byMode,
        ];
    }

    /**
     * Derive logical source bucket from an Order. Public static so the
     * Resource can re-use it without duplicating the heuristic.
     *
     * Order of precedence : delivery > kiosk > pos.
     *  - delivery_boy_id NOT NULL → livreur (covers both DELIVERY and the
     *    livreur retour-cash flow that mints a Transaction on the Order).
     *  - order_type=KIOSK OR source_surface contains 'kiosk' → borne.
     *  - else → caisse (POS direct sale).
     */
    public static function deriveSource(Transaction $tx): string
    {
        $order = $tx->order;
        if (! $order) {
            return 'unknown';
        }
        if (! empty($order->delivery_boy_id)) {
            return 'livreur';
        }
        $surface = strtolower((string) ($order->source_surface ?? ''));
        $orderType = (int) ($order->order_type ?? 0);
        if (str_contains($surface, 'kiosk') || $orderType === \App\Enums\OrderType::KIOSK) {
            return 'borne';
        }
        return 'caisse';
    }

    /**
     * Normalize the heterogeneous `payment_method` strings into 5 stable
     * buckets the UI displays.
     *
     * Observed values in DB (verified Wave X4 grep) :
     *  - POS direct  : 'cash', 'credit', plus gateway slugs ('stripe',
     *                  'paypal', etc.) — PaymentService::deposit L59.
     *  - Counter-collect (kiosk borne) : 'counter_cash', 'counter_card',
     *                  'counter_mobile_banking', 'counter_ticket_restaurant',
     *                  'counter_other' — PaymentService::counterPaymentMethodLabel.
     *  - Livreur return cash : 'cash' (PaymentService closes with cash slug).
     *
     * Bucket mapping (lowercase comparison, all whitespace-trimmed) :
     *  - cash    : *cash* (matches 'cash', 'counter_cash')
     *  - card    : *card*, 'credit', 'stripe', 'paypal' (catch-all bank rails)
     *  - mobile  : *mobile* (matches 'counter_mobile_banking', 'mobile_banking')
     *  - ticket  : *ticket* (matches 'counter_ticket_restaurant', 'ticket_restaurant')
     *  - other   : everything else
     */
    public static function derivePaymentBucket(string $raw): string
    {
        $needle = strtolower(trim($raw));
        if ($needle === '') {
            return 'other';
        }
        if (str_contains($needle, 'cash')) {
            return 'cash';
        }
        if (str_contains($needle, 'mobile')) {
            return 'mobile';
        }
        if (str_contains($needle, 'ticket')) {
            return 'ticket';
        }
        if (
            str_contains($needle, 'card')
            || str_contains($needle, 'credit')
            || str_contains($needle, 'stripe')
            || str_contains($needle, 'paypal')
            || str_contains($needle, 'senangpay')
        ) {
            return 'card';
        }
        return 'other';
    }

    /**
     * SQL LIKE patterns that match all payment_method strings for a given
     * normalized bucket. Used for the `mode` query filter so we don't have
     * to materialize-then-filter in PHP.
     */
    private function paymentMethodPatternsForBucket(string $bucketRaw): array
    {
        $bucket = strtolower(trim($bucketRaw));
        return match ($bucket) {
            'cash'   => ['%cash%'],
            'card'   => ['%card%', '%credit%', '%stripe%', '%paypal%', '%senangpay%'],
            'mobile' => ['%mobile%'],
            'ticket' => ['%ticket%'],
            'other'  => [], // 'other' is non-filterable as it's a complement;
                            // would require NOT LIKE of all above — skipped.
            default  => [],
        };
    }
}
