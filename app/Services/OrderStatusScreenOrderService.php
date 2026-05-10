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
                });

            // [M-09] Branch filter: only global Admin may request branch_id=0/global OSS.
            if ($branchScope !== null) {
                $query->where('branch_id', $branchScope);
            }

            return $query->get();
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function mostPopularItems()
    {
        try {
            return Item::with('media', 'category', 'offer')->withCount('orders')->where(['status' => Status::ACTIVE])->orderBy('orders_count', 'desc')->limit(9)->get();
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
                });

            if ($branchId > 0) {
                $query->where('branch_id', $branchId);
            }

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
