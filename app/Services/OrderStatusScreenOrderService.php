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
            $userBranchId = auth()->user()->branch_id ?? 0;

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

            // [P3-3 FIX] Branch filter: admin (branch_id=0) sees all, staff sees own branch
            if ($userBranchId > 0) {
                $query->where('branch_id', $userBranchId);
            }

            return $query->get();
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
}