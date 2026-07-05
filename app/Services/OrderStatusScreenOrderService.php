<?php

namespace App\Services;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Libraries\QueryExceptionLibrary;
use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderStatusScreenOrderService
{
    public object $order;

    protected array $orderFilter = [
        'order_serial_no',
        'branch_id',
        'order_type',
        'status',
        'kitchen_status',
        'source',
    ];

    protected array $exceptFilter = [
        'excepts',
    ];

    /**
     * @throws Exception
     */
    public function list()
    {
        try {
            $branchScope = $this->resolveBranchScope(request()->query('branch_id'), auth()->user());

            // [AUDIT-P0-A] Include kiosk TAKEAWAY orders (order_type=10, token=null) that have a queue_number.
            // Previously only KIOSK (25=sur place) and token-bearing orders were shown.
            // Kiosk "à emporter" orders use order_type=TAKEAWAY but still have queue_number and must appear on OSS.
            $query = Order::where(function ($q) {
                $q->whereNotNull('token')
                    ->orWhere('order_type', \App\Enums\OrderType::KIOSK)
                    ->orWhere(function ($sub) {
                        $sub->where('order_type', \App\Enums\OrderType::TAKEAWAY)
                            ->whereNotNull('queue_number');
                    });
            })
                // [2026-05-18 RED R-3 heal] Fail-closed allowlist — only KIOSK
                // and TAKEAWAY ever reach the customer wall. The previous
                // `!= DELIVERY` blacklist still let POS=15 / DINING_TABLE=20
                // leak through the `whereNotNull('token')` OR-branch above
                // if they ever happened to carry a token. Sister coverage:
                // tests/Feature/OSS/OssCustomerScreenFilterTest.php.
                ->whereIn('order_type', [
                \App\Enums\OrderType::KIOSK,
                \App\Enums\OrderType::TAKEAWAY,
            ])
                ->whereIn('status', [OrderStatus::PREPARING, OrderStatus::PREPARED]);

            // [ULTRA PARITÉ BOARD-RELEASE 2026-07-04] Même SSOT que TOUTES les surfaces cuisine
            // (KDS list/orderItems/sync + guard changeStatus) : le mur client ne doit montrer
            // que ce que la cuisine voit (PAID | PENDING_COUNTER | POS-cash). Sans ce filtre,
            // une commande UNPAID non-cash forcée en PREPARING (action admin) s'affichait
            // « En préparation » au client alors que le board cuisine l'exclut.
            // Sentinel : tests/Feature/OSS/OssBoardReleaseParityTest.php.
            \App\Domain\Kds\KitchenReleaseRule::applyBoardReleaseFilter($query);

            // [RED-team P1 perf 2026-05-17] whereDate non-sargable → range query (uses idx_orders_datetime)
            //
            // [Wave T R5 OSS Adversarial P0 2026-05-20 KDS-T-R5-03] CORRECTION
            // of Wave 3b heal. The previous code converted Paris-local day
            // bounds to UTC strings, ASSUMING MySQL session_tz=UTC. Empirical
            // inspection (`SELECT @@session.time_zone`) returns 'SYSTEM' →
            // OS-local (Europe/Paris) because config/database.php
            // connections.mysql.timezone is NULL. UTC strings bound under
            // session_tz=Paris are re-interpreted as Paris-local datetimes,
            // shifting the wall backward by 2h and silently dropping the
            // last ~2h of every Paris day (22h-minuit) from the customer
            // OSS wall (validated empirically against the sister KDS
            // service: 1 row served out of 11 DB rows at 23:51 Paris).
            //
            // CORRECT: use Paris-local Carbon bounds directly so MySQL
            // session_tz=Paris interprets them at face value, matching the
            // semantic intent "all of TODAY in Paris" and aligning with
            // how orders.order_datetime stored TIMESTAMPs are displayed/
            // compared. INVARIANT DEPENDENCY: this heal assumes session_tz
            // is OS-local; any future `connections.mysql.timezone => '+00:00'`
            // must re-evaluate. Sentinel: SisterServicesTzAwareTest.
            $appTz = config('app.timezone');
            $todayStart = Carbon::today($appTz);
            $todayEnd = Carbon::today($appTz)->endOfDay();
            $tomorrowStart = Carbon::tomorrow($appTz);

            // [SELF-AUDIT A 2026-07-05 — divergence fenêtre OSS↔KDS] Le plancher glissant 8h est hoisté
            // ICI et injecté DANS la seule sous-clause non-advance (exactement comme KDS), et NON plus en
            // AND top-level. En top-level il contraignait AUSSI la branche advance → une précommande en
            // retard (>8h, order_datetime < now-8h) DISPARAISSAIT du mur client alors que les 3 chemins
            // cuisine (KDS list/orderItems, KdsSync) la GARDENT (leur branche advance est sans plancher,
            // contrat AUDIT-52-BUG1). Résultat : le chef prépare, le client ne voit plus sa commande. Le
            // commentaire d'origine revendiquait « Miroir fenêtre KDS (parité 4 chemins) » — c'était faux.
            // now($appTz) = Paris-local (session_tz MySQL = SYSTEM = Paris ; voir KDS::list). INVARIANT :
            // si connections.mysql.timezone passe à '+00:00', ré-évaluer.
            $staleFloor = now($appTz)->subHours((int) config('oss.stale_window_hours', 8));

            $query->where(function ($q) use ($tomorrowStart, $staleFloor) {
                $q->where(function ($sub) use ($tomorrowStart, $staleFloor) {
                    // Non-advance : fenêtre glissante active [staleFloor, tomorrow) — plancher ICI
                    // (borne basse) + borne haute anti-futur. Le Cayenne opère après minuit (23h-02h).
                    // Miroir EXACT KDS::list L140-144. Sentinel : OssKdsMidnightStraddleTest.
                    $sub->where('order_datetime', '>=', $staleFloor)
                        ->where('order_datetime', '<', $tomorrowStart)
                        ->where('is_advance_order', Ask::NO);
                })->orWhere(function ($sub) use ($tomorrowStart) {
                    // [AUDIT-52-BUG1] Mirror KDS fix: show ALL overdue advance orders (SANS plancher 8h,
                    // comme KDS::list L146-150) tant qu'actives (ni DELIVERED ni CANCELED). Empêche la
                    // disparition zombie d'une précommande en retard encore en préparation.
                    $sub->where('is_advance_order', Ask::YES)
                        ->where('order_datetime', '<', $tomorrowStart)
                        ->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED]);
                });
            });

            // [M-09] Branch filter: only global Admin may request branch_id=0/global OSS.
            if ($branchScope !== null) {
                $query->where('branch_id', $branchScope);
            }

            // [Sprint 5C Z4-P1-02 2026-05-16] Deterministic order — FIFO by
            // queue_number with id as tiebreaker. Without this, polls
            // re-shuffled the customer wall between fetches (Mongo-style
            // unordered result set) and customers saw their queue token
            // jump positions.
            $query->orderBy('queue_number', 'asc')->orderBy('id', 'asc');

            return $query->get();
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Top 9 most-popular items by order frequency.
     *
     * [Sprint H5-B Z4-P2-04 2026-05-17] Branch-scoped variant. Previously
     * `withCount('orders')` ran UNSCOPED — Branch A's wall would surface items
     * popular at Branch B (or even Branch C's deleted dataset), a SaaS
     * multi-tenant correctness violation. The popularity count is now
     * constrained by branch_id on the order_items pivot, falling back to the
     * caller's branch (auth user for admin, query param for public wall).
     *
     * `$branchId = null` preserves legacy global behaviour for global Admin
     * dashboards (branch_id=0) that intentionally want the org-wide top 9.
     * Tests should pass an explicit int to assert per-branch scoping.
     */
    public function mostPopularItems(?int $branchId = null)
    {
        try {
            // Resolve from auth context if not provided. Admin (branch_id=0)
            // explicitly returns null → unscoped (global view); staff with a
            // positive branch_id scopes to their branch.
            if ($branchId === null) {
                $user = auth()->user();
                $resolved = $user?->branch_id;
                $branchId = ($resolved !== null && (int) $resolved > 0) ? (int) $resolved : null;
            }

            $ordersCount = $branchId !== null
                ? ['orders' => fn ($q) => $q->where('branch_id', $branchId)]
                : 'orders';

            return Item::with('media', 'category', 'offer')
                ->withCount($ordersCount)
                ->where(['status' => Status::ACTIVE])
                ->orderBy('orders_count', 'desc')
                ->limit(9)
                ->get();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [iter15-mega-fix C-016 2026-05-10] Branch-scoped OSS list without the
     * auth-aware branch resolver. Used by the public customer-wall endpoint
     * (`OrderStatusScreenController::publicIndex`) where there is no
     * `auth()->user()` to consult — branch comes from the caller (query
     * param or first-active-branch fallback). The query body MUST stay
     * byte-identical to `list()` above so the customer wall and the admin
     * dashboard show the same set of orders for the same branch.
     */
    public function listForBranch(int $branchId)
    {
        try {
            $query = Order::where(function ($q) {
                $q->whereNotNull('token')
                    ->orWhere('order_type', \App\Enums\OrderType::KIOSK)
                    ->orWhere(function ($sub) {
                        $sub->where('order_type', \App\Enums\OrderType::TAKEAWAY)
                            ->whereNotNull('queue_number');
                    });
            })
                // [2026-05-18 RED R-3 heal] Sister of list() — keep query body
                // byte-identical per service docstring (line 144). Fail-closed
                // allowlist excludes POS / DELIVERY / DINING_TABLE.
                ->whereIn('order_type', [
                \App\Enums\OrderType::KIOSK,
                \App\Enums\OrderType::TAKEAWAY,
            ])
                ->whereIn('status', [OrderStatus::PREPARING, OrderStatus::PREPARED]);

            // [ULTRA PARITÉ BOARD-RELEASE 2026-07-04] Même SSOT que TOUTES les surfaces cuisine
            // (KDS list/orderItems/sync + guard changeStatus) : le mur client ne doit montrer
            // que ce que la cuisine voit (PAID | PENDING_COUNTER | POS-cash). Sans ce filtre,
            // une commande UNPAID non-cash forcée en PREPARING (action admin) s'affichait
            // « En préparation » au client alors que le board cuisine l'exclut.
            // Sentinel : tests/Feature/OSS/OssBoardReleaseParityTest.php.
            \App\Domain\Kds\KitchenReleaseRule::applyBoardReleaseFilter($query);

            // [RED-team P1 perf 2026-05-17] whereDate non-sargable → range query (uses idx_orders_datetime)
            //
            // [Wave T R5 OSS Adversarial P0 2026-05-20 KDS-T-R5-04] Sister-of
            // list() — keep query body byte-identical per service docstring
            // (line 144). CORRECTION of Wave 3b heal: see list() above for
            // full rationale (empirical session_tz=Paris-local invalidates
            // the prior UTC-conversion approach). Sentinel:
            // tests/Feature/Services/SisterServicesTzAwareTest.php.
            $appTz = config('app.timezone');
            $todayStart = Carbon::today($appTz);
            $todayEnd = Carbon::today($appTz)->endOfDay();
            $tomorrowStart = Carbon::tomorrow($appTz);

            // [SELF-AUDIT A 2026-07-05] Sister-of list() — MÊME correction : plancher 8h dans la seule
            // sous-clause non-advance (parité KDS), plus en AND top-level (qui pruneait à tort la branche
            // advance → précommande en retard disparue du mur client mais gardée en cuisine). Voir list().
            $staleFloor = now($appTz)->subHours((int) config('oss.stale_window_hours', 8));

            $query->where(function ($q) use ($tomorrowStart, $staleFloor) {
                $q->where(function ($sub) use ($tomorrowStart, $staleFloor) {
                    // Non-advance : fenêtre glissante [staleFloor, tomorrow) — miroir EXACT KDS.
                    $sub->where('order_datetime', '>=', $staleFloor)
                        ->where('order_datetime', '<', $tomorrowStart)
                        ->where('is_advance_order', Ask::NO);
                })->orWhere(function ($sub) use ($tomorrowStart) {
                    // Advance : SANS plancher (comme KDS) tant qu'actives — pas de disparition zombie.
                    $sub->where('is_advance_order', Ask::YES)
                        ->where('order_datetime', '<', $tomorrowStart)
                        ->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED]);
                });
            });

            if ($branchId > 0) {
                $query->where('branch_id', $branchId);
            }

            // [Sprint 5C Z4-P1-02 2026-05-16] Deterministic FIFO order — see
            // sibling list() comment.
            $query->orderBy('queue_number', 'asc')->orderBy('id', 'asc');

            return $query->get();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    private function resolveBranchScope($requestedBranchId, ?User $user): ?int
    {
        if ($this->isGlobalAdmin($user)) {
            return $requestedBranchId !== null && (int) $requestedBranchId > 0
                ? (int) $requestedBranchId
                : null;
        }

        $userBranchId = (int) ($user?->branch_id ?? 0);
        if ($userBranchId <= 0) {
            abort(403, 'Access denied: invalid OSS branch scope.');
        }

        if ($requestedBranchId !== null && (int) $requestedBranchId !== $userBranchId) {
            abort(403, 'Access denied: OSS scope does not belong to your branch.');
        }

        return $userBranchId;
    }

    private function isGlobalAdmin(?User $user): bool
    {
        return $user !== null
            && $user->branch_id !== null
            && (int) $user->branch_id === 0
            && method_exists($user, 'hasRole')
            && $user->hasRole('Admin');
    }
}
