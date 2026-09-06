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
            // [AUDIT-SUPERVISEUR 2026-08-25 · P0] `whereHas` est une jointure INTERNE :
            // un encaissement dont la commande n'existe plus disparaissait PUREMENT du
            // total, sans un mot. Mesuré sur 23-24/08 : 27 lignes / 247,70 € tombaient à
            // 17 / 222,70 €, et les 10 lignes perdues étaient exactement les 25,00 €
            // d'espèces que le bandeau de réconciliation, lui, continuait d'afficher —
            // d'où la contradiction qu'on a d'abord imputée au bandeau, à tort.
            //
            // Un écran d'argent n'a pas le droit de perdre une ligne en silence. On garde
            // donc les orphelins : ils sont comptés dans le total ET annoncés à part
            // (`orphan_payments` ci-dessous), pour qu'ils soient VUS au lieu d'être
            // absorbés sans bruit. Le filtre de branche ne s'applique qu'aux lignes qui
            // ont une commande — un orphelin n'a aucune branche à comparer, et
            // l'escamoter reviendrait à recréer le défaut pour les gestionnaires de
            // branche.
            ->where(function ($outer) use ($isGlobalAdmin, $branchFilter) {
                $outer
                    ->whereHas('order', function ($q) use ($isGlobalAdmin, $branchFilter) {
                        if ($isGlobalAdmin) {
                            $q->withoutGlobalScope(BranchScope::class);
                        }
                        if ($branchFilter !== null) {
                            $q->where('branch_id', $branchFilter);
                        }
                    })
                    ->orWhereDoesntHave('order', function ($q) {
                        $q->withoutGlobalScope(BranchScope::class);
                    });
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
        //
        // [FIX-3 2026-08-25] The resolver is now BOUND TO THE REQUESTED PERIOD
        // (see resolveOpenCashSession) — it used to return the most-recently
        // OPENED drawer across all time, which on this DB (11 concurrent
        // `open` sessions) surfaced a drawer abandoned 50 days earlier and
        // labelled its figures « aujourd'hui ».
        $cashSession = $this->resolveOpenCashSession(
            $user,
            $branchFilter,
            $isGlobalAdmin,
            $startBound,
            $endBound
        );

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
            // [CASH-JOIN-01 + CASH-SEM-02 heal 2026-06-01] Expected cash = opening_amount
            // + Σ signed CashMovements SCOPED TO THIS SESSION — the SAME authoritative
            // source as CashDrawerService::reconcileSession. The previous per-branch+day
            // positive Transaction sum (a) leaked cash from OTHER sessions of the same day
            // into this drawer's expected (CASH-JOIN-01) and (b) summed only positive
            // order-payments, ignoring cashback/cash-OUT movements (CASH-SEM-02) → it
            // overstated the physical drawer the cashier reconciles against.
            $movements = \App\Models\CashMovement::query()
                ->where('cash_drawer_session_id', $cashSession->id)
                ->get();
            $movementsSum = (float) $movements
                ->sum(fn (\App\Models\CashMovement $m) => $m->signedAmount());
            $expectedCash = round((float) $cashSession->opening_amount + $movementsSum, 2);

            // [FIX-3 2026-08-25] Net cash movement of this drawer RESTRICTED TO
            // THE DISPLAYED PERIOD. `cash_collected` (session lifetime) is what
            // the physical drawer holds and must stay lifetime-wide, but the UI
            // used to render it under the label « Espèces encaissées
            // aujourd'hui » — so a 45-day-old 8,50 € was read as today's cash,
            // contradicting the « Répartition par mode » block on the SAME page.
            // The period figure is the one the banner shows next to the period
            // filter; the lifetime figure keeps its own, explicitly-dated label.
            $inPeriodSum = (float) $movements
                ->filter(fn (\App\Models\CashMovement $m) => $m->created_at !== null
                    && $m->created_at->between($startBound, $endBound))
                ->sum(fn (\App\Models\CashMovement $m) => $m->signedAmount());

            $openedAt = $cashSession->opened_at
                ? $cashSession->opened_at->copy()->setTimezone($tz)
                : null;
            $ageDays = $openedAt
                ? (int) $openedAt->copy()->startOfDay()->diffInDays(Carbon::today($tz))
                : null;

            $cashSessionPayload = [
                'id'               => (int) $cashSession->id,
                'branch_id'        => $sessionBranchId,
                'opened_at'        => optional($cashSession->opened_at)->toIso8601String(),
                'opening_amount'   => round((float) $cashSession->opening_amount, 2),
                'expected_cash'    => $expectedCash,
                // Net cash movement since opening (IN minus OUT), the reconciliation delta.
                'cash_collected'   => round($movementsSum, 2),
                // [FIX-3] Truth-telling fields the banner MUST use so it can
                // never again print « aujourd'hui » over a stale drawer.
                'cash_collected_in_period' => round($inPeriodSum, 2),
                'opened_today'     => $openedAt ? $openedAt->isSameDay(Carbon::today($tz)) : false,
                'opened_in_period' => $openedAt ? $openedAt->between($startBound, $endBound) : false,
                'age_days'         => $ageDays,
                'period_from'      => $startBound->toIso8601String(),
                'period_to'        => $endBound->toIso8601String(),
            ];
        }

        // [FIX-3 2026-08-25] Drawers left `open` with no activity in the window.
        // On this DB there are 11 concurrent `open` sessions (one per cashier —
        // the partial UNIQUE index is (branch_id, opened_by_user_id) WHERE
        // status='open', so nothing prevents them piling up). Silently hiding
        // them would trade one lie for an omission: the owner mandate is
        // « détecter écarts (cash manquant) », and an abandoned drawer still
        // holds its fond de caisse. READ-ONLY: nothing is closed or mutated.
        $staleOpenDrawers = $this->summarizeStaleOpenDrawers(
            $user,
            $branchFilter,
            $isGlobalAdmin,
            $startBound,
            $endBound,
            $cashSession?->id
        );

        // [TRAP-3 2026-06-04] Durable cash-trail gap surfacing. The counter-collect
        // CASH path is best-effort: when no drawer session was open at collection,
        // the order goes PAID + a `payment` Transaction is written but NO
        // cash_movement row → end-of-day reconciliation silently under-counts.
        // The collect-time modal toast is ephemeral (a busy cashier misses it),
        // so the discrepancy MUST also surface HERE, where the variance actually
        // manifests. This is a pure read-side computation (no schema change): count
        // + total of cash `payment` Transactions in the window whose order has no
        // linked order_payment/in cash_movement. Owner mandate « détecter écarts
        // (cash manquant) ».
        $unrecordedCash = $this->summarizeUnrecordedCash(
            $startBound,
            $endBound,
            $branchFilter,
            $isGlobalAdmin
        );

        return response()->json([
            'status' => true,
            'data'   => CashOverviewTransactionResource::collection($transactions),
            'summary'      => $summary,
            'cash_session' => $cashSessionPayload,
            // Drawers left open with zero activity over the displayed window.
            'stale_open_drawers' => $staleOpenDrawers,
            // Flagged discrepancy block — non-null `count` > 0 means cash was
            // collected with no drawer session and is unaccounted in any session.
            'unrecorded_cash' => $unrecordedCash,
            // [AUDIT-SUPERVISEUR 2026-08-25 · P0] Encaissements dont la commande
            // n'existe plus. Ils sont COMPTÉS dans le total (les corriger en les
            // excluant était précisément le défaut) mais annoncés ici, pour qu'un
            // écart de rapprochement ait une explication au lieu d'un trou.
            'orphan_payments' => $this->summarizeOrphanPayments($startBound, $endBound),
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
     * [TRAP-3 2026-06-04] Read-side detection of cash collected with NO drawer
     * session (the cash-trail hole on the primary counter-collect path).
     *
     * A counter-collect CASH sale writes a `payment` Transaction
     * (payment_method LIKE %cash%) regardless of whether a drawer session was
     * open. When none was open, PaymentService::recordCashOrderMovement skips
     * the CashMovement → the order is PAID but its cash never lands in any
     * session's reconciliation. We surface exactly those orders: cash `payment`
     * Transactions in the window whose order has NO order_payment/in
     * cash_movement row.
     *
     * Pure aggregation, no schema change. `count == 0` is the healthy case.
     *
     * @return array{count:int, total:float, total_currency_price:string, order_ids:array<int,int>, message:?string}
     */
    /**
     * [AUDIT-SUPERVISEUR 2026-08-25 · P0] Encaissements orphelins : la transaction
     * existe, sa commande n'existe plus.
     *
     * Ces lignes étaient AVALÉES par le `whereHas('order')` du constructeur de
     * requête — une jointure interne. Elles ne faisaient pas baisser un compteur
     * visible : elles n'apparaissaient nulle part, ce qui est pire. C'est ce qui
     * a produit la contradiction entre le bandeau de réconciliation et la
     * répartition par mode de la même page.
     *
     * Elles sont désormais comptées dans le total ET listées ici. Un rapprochement
     * de caisse doit pouvoir EXPLIQUER un écart ; un écran qui escamote la cause
     * transforme un problème comptable en mystère.
     *
     * @return array{count:int, total:float, total_currency_price:string, order_ids:array<int,int>}
     */
    private function summarizeOrphanPayments(Carbon $startBound, Carbon $endBound): array
    {
        $orphelins = Transaction::query()
            ->whereBetween('created_at', [$startBound, $endBound])
            ->where('type', 'payment')
            ->whereDoesntHave('order', function ($q) {
                $q->withoutGlobalScope(BranchScope::class);
            })
            ->get(['id', 'order_id', 'amount']);

        $total = round((float) $orphelins->sum('amount'), 2);

        return [
            'count'                => $orphelins->count(),
            'total'                => $total,
            'total_currency_price' => \App\Libraries\AppLibrary::currencyAmountFormat($total),
            'order_ids'            => $orphelins->pluck('order_id')->filter()->unique()->values()->all(),
        ];
    }

    private function summarizeUnrecordedCash(
        Carbon $startBound,
        Carbon $endBound,
        ?int $branchFilter,
        bool $isGlobalAdmin
    ): array {
        $query = Transaction::query()
            ->whereBetween('created_at', [$startBound, $endBound])
            ->where('type', 'payment')
            ->where('payment_method', 'like', '%cash%')
            // No order_payment/in cash_movement linked to this transaction's order.
            // Order has no `cashMovements` relation defined, so we correlate on
            // the cash_movements table directly (indexed on ['order_id','type']).
            ->whereNotNull('order_id')
            ->whereNotExists(function ($sub) {
                $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('cash_movements')
                    ->whereColumn('cash_movements.order_id', 'transactions.order_id')
                    ->where('cash_movements.type', \App\Models\CashMovement::TYPE_ORDER_PAYMENT)
                    ->where('cash_movements.direction', \App\Models\CashMovement::DIRECTION_IN);
            })
            ->whereHas('order', function ($q) use ($isGlobalAdmin, $branchFilter) {
                if ($isGlobalAdmin) {
                    $q->withoutGlobalScope(BranchScope::class);
                }
                if ($branchFilter !== null) {
                    $q->where('branch_id', $branchFilter);
                }
                // [TRAP-3 2026-06-04] EXCLUDE delivery-boy cash. Livreur cash is
                // reconciled through a SEPARATE system (delivery_boy_cash_movements
                // + DeliveryBoyCashSession — OrderService.php:1964), never the
                // drawer's cash_movements. Without this guard every livreur cash
                // order would be falsely flagged as "cash manquant" (cry-wolf →
                // cashier ignores the banner → defeats the surfacing). This
                // detector is strictly about the DRAWER cash trail.
                $q->whereNull('delivery_boy_id');
            });

        $rows = $query->with(['order' => function ($q) use ($isGlobalAdmin) {
            if ($isGlobalAdmin) {
                $q->withoutGlobalScope(BranchScope::class);
            }
            $q->select(['id', 'branch_id']);
        }])->get(['id', 'order_id', 'amount']);

        $count    = $rows->count();
        $total    = round((float) $rows->sum(fn ($t) => (float) $t->amount), 2);
        $orderIds = $rows->pluck('order_id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        return [
            'count'                => $count,
            'total'                => $total,
            'total_currency_price' => \App\Libraries\AppLibrary::currencyAmountFormat($total),
            'order_ids'            => $orderIds,
            'message'              => $count > 0
                ? "{$count} encaissement(s) espèces sans session caisse — montant non rattaché à un fond de caisse (à régulariser)"
                : null,
        ];
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
     * Branch-scoped base query over `open` drawer sessions. Returns null when
     * the caller has no branch we may legally read.
     *
     * [Wave X-C round-1 2026-05-21] Fix C-003 — when admin is browsing
     * without an explicit `?branch_id=` query param we do not force a branch,
     * so the reconciliation card can still mount for the admin login,
     * matching the owner mandate « détecter écarts (cash manquant) ».
     */
    private function openCashSessionBaseQuery($user, ?int $branchFilter, bool $isGlobalAdmin)
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

        return $query;
    }

    /**
     * `whereExists`-style constraint: this drawer moved cash inside the window.
     */
    private function movementExistsInWindow(Carbon $startBound, Carbon $endBound): \Closure
    {
        return function ($sub) use ($startBound, $endBound) {
            $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                ->from('cash_movements')
                ->whereColumn('cash_movements.cash_drawer_session_id', 'cash_drawer_sessions.id')
                ->whereBetween('cash_movements.created_at', [$startBound, $endBound]);
        };
    }

    /**
     * Resolve the CashDrawerSession the reconciliation banner is allowed to
     * describe FOR THE REQUESTED PERIOD.
     *
     * [FIX-3 2026-08-25] Root-cause fix. The previous implementation ended with
     * `orderByDesc('opened_at')->first()` filtered on `status = open` ONLY —
     * no date bound whatsoever. With 11 concurrent `open` sessions in this DB
     * (the partial UNIQUE index is per (branch_id, opened_by_user_id), so every
     * cashier who never closes leaves one behind forever), the banner locked
     * onto session #38 — opened 2026-07-06, single movement 2026-07-11 — and
     * rendered « Espèces encaissées aujourd'hui : 8,50 € / attendues : 58,50 € »
     * on a page dated 2026-08-25, unchanged even when the period filter was set
     * to a window containing zero transaction. Worse, the cash actually taken
     * that day belonged to a DIFFERENT session (#36): the drawer on screen was
     * not the drawer collecting.
     *
     * Selection is now period-bound and activity-first :
     *   0. Never a drawer opened AFTER the window — it cannot explain it.
     *   1. The drawer that actually MOVED CASH inside the window (most recent
     *      movement wins). This is the drawer the cashier is really using, even
     *      when another one was opened later and abandoned.
     *   2. Otherwise a drawer OPENED inside the window (service started, no sale
     *      yet) — legitimately shown with 0 collected.
     *   3. Otherwise null: no drawer is relevant to this period and the banner
     *      says so rather than borrowing another month's figures.
     *
     * A drawer opened before the window but still collecting inside it is kept
     * (case 1, night shift / never-closed drawer) — the payload then carries
     * `opened_today=false` + `age_days` so the UI DATES it instead of implying
     * "today".
     */
    private function resolveOpenCashSession(
        $user,
        ?int $branchFilter,
        bool $isGlobalAdmin,
        Carbon $startBound,
        Carbon $endBound
    ): ?CashDrawerSession {
        $base = $this->openCashSessionBaseQuery($user, $branchFilter, $isGlobalAdmin);
        if ($base === null) {
            return null;
        }

        // (0) A drawer opened after the window ends can never explain it.
        $base->where('opened_at', '<=', $endBound);

        // (1) The drawer that actually collected cash during the window.
        $lastMovementInWindow = \Illuminate\Support\Facades\DB::table('cash_movements')
            ->selectRaw('MAX(created_at)')
            ->whereColumn('cash_movements.cash_drawer_session_id', 'cash_drawer_sessions.id')
            ->whereBetween('cash_movements.created_at', [$startBound, $endBound]);

        $withActivity = (clone $base)
            ->select('cash_drawer_sessions.*')
            ->addSelect(['last_movement_at' => $lastMovementInWindow])
            ->whereExists($this->movementExistsInWindow($startBound, $endBound))
            ->orderByDesc('last_movement_at')
            ->orderByDesc('opened_at')
            ->first();

        if ($withActivity) {
            return $withActivity;
        }

        // (2) Otherwise a drawer whose service STARTED inside the window.
        return (clone $base)
            ->where('opened_at', '>=', $startBound)
            ->orderByDesc('opened_at')
            ->first();
    }

    /**
     * [FIX-3 2026-08-25] Drawers left `open` with zero activity over the window
     * (and opened before it). Read-only surfacing — nothing is closed here.
     *
     * Rationale: bounding the banner to the period (above) is the honest fix,
     * but on its own it would silently hide the 10 other abandoned drawers that
     * still hold their fond de caisse. Owner mandate « détecter écarts (cash
     * manquant) » → we name them, dated, and leave the closing decision to a
     * human.
     *
     * @return array{count:int, oldest_opened_at:?string, ids:array<int,int>, message:?string}
     */
    private function summarizeStaleOpenDrawers(
        $user,
        ?int $branchFilter,
        bool $isGlobalAdmin,
        Carbon $startBound,
        Carbon $endBound,
        ?int $excludeSessionId
    ): array {
        $empty = ['count' => 0, 'oldest_opened_at' => null, 'ids' => [], 'message' => null];

        $base = $this->openCashSessionBaseQuery($user, $branchFilter, $isGlobalAdmin);
        if ($base === null) {
            return $empty;
        }

        if ($excludeSessionId !== null) {
            $base->where('id', '!=', $excludeSessionId);
        }

        $rows = $base
            ->where('opened_at', '<', $startBound)
            ->whereNotExists($this->movementExistsInWindow($startBound, $endBound))
            ->orderBy('opened_at')
            ->limit(50)
            ->get(['id', 'opened_at', 'opening_amount']);

        if ($rows->isEmpty()) {
            return $empty;
        }

        $oldest = $rows->first();
        $count  = $rows->count();
        $date   = optional($oldest->opened_at)->format('d/m/Y');

        return [
            'count'            => $count,
            'oldest_opened_at' => optional($oldest->opened_at)->toIso8601String(),
            'ids'              => $rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'message'          => $count.' tiroir(s) resté(s) ouvert(s) sans aucune activité sur la période'
                .($date ? " (le plus ancien depuis le {$date})" : '')
                .' — fond de caisse non clôturé, à régulariser',
        ];
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
