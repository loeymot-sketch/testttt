<?php

namespace App\Services;

use Exception;
use App\Enums\Ask;
use Carbon\Carbon;
use App\Models\Item;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Order;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Log;
use App\Libraries\QueryExceptionLibrary;
use App\Models\User;
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
        'source'
    ];

    protected array $exceptFilter = [
        'excepts'
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
                ->whereIn('status', [OrderStatus::PREPARING, OrderStatus::PREPARED])
                ->where(function ($q) {
                    $q->where(function ($sub) {
                        // [P3-4 FIX] Align with KDS: today's non-advance orders
                        $sub->whereDate('order_datetime', Carbon::today())->where('is_advance_order', Ask::NO);
                    })->orWhere(function ($sub) {
                        // [AUDIT-52-BUG1] Mirror KDS fix: show ALL overdue advance orders (not just yesterday)
                        // that are still active (not DELIVERED or CANCELED). Prevents zombie disappearance.
                        $sub->where('is_advance_order', Ask::YES)
                            ->whereDate('order_datetime', '<=', Carbon::today())
                            ->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED]);
                    });
                })
                // [Sprint H5-B Z4-P2-03 2026-05-17] Stale prune: orders older
                // than config('oss.stale_window_hours') drop off the wall
                // regardless of status (would otherwise linger as zombies
                // until midnight rollover or manual bump). Combined with the
                // sibling whereDate(today()) filter the effective TTL is
                // min(today, N hours from order_datetime).
                ->where('order_datetime', '>=', now()->subHours((int) config('oss.stale_window_hours', 8)));

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
                ->whereIn('status', [OrderStatus::PREPARING, OrderStatus::PREPARED])
                ->where(function ($q) {
                    $q->where(function ($sub) {
                        $sub->whereDate('order_datetime', Carbon::today())->where('is_advance_order', Ask::NO);
                    })->orWhere(function ($sub) {
                        $sub->where('is_advance_order', Ask::YES)
                            ->whereDate('order_datetime', '<=', Carbon::today())
                            ->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED]);
                    });
                })
                // [Sprint H5-B Z4-P2-03 2026-05-17] Stale prune mirror — see
                // sibling list() comment. Keeps the public customer wall
                // identical to the admin dashboard in shape AND in TTL.
                ->where('order_datetime', '>=', now()->subHours((int) config('oss.stale_window_hours', 8)));

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
