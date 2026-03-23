<?php

namespace App\Services;

use Exception;
use App\Enums\Ask;
use Carbon\Carbon;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Events\SendOrderSms;
use Illuminate\Http\Request;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\OrderStatusChanged;
use Illuminate\Support\Facades\Log;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\OrderStatusRequest;

class KitchenDisplaySystemOrderService
{
    public object $order;
    protected array $orderFilter = [
        'order_serial_no',
        'branch_id',
        'order_type',
        'status',
        'source'
    ];

    protected array $exceptFilter = [
        'excepts'
    ];

    /**
     * @throws Exception
     */
    public function list(Request $request)
    {
        try {
            $requests = $request->all();
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType = $request->get('order_by') ?? 'desc';

            $userBranchId = auth()->user()->branch_id ?? 0;

            $query = Order::with('orderItems')
                ->whereIn('status', [OrderStatus::ACCEPT, OrderStatus::PREPARING, OrderStatus::PREPARED]);

            // [FIX BUG-KDS-SYNC] Admin users have branch_id=0 → show all branches.
            // Branch-specific staff see only their own branch.
            if ($userBranchId > 0) {
                $query->where('branch_id', $userBranchId);
            }

            // [FIX-FRONT-05] Pagination KDS: limiter à 50 commandes actives maximum
            return $query->where(function ($query) {
                $query->where(function ($subQuery) {
                    $subQuery->whereDate('order_datetime', Carbon::today())->where('is_advance_order', Ask::NO);
                })->orWhere(function ($subQuery) {
                    $subQuery->where('is_advance_order', Ask::YES)->whereDate('order_datetime', Carbon::yesterday());
                });
            })->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->orderFilter)) {
                        if ($key === "status" && $request) {
                            $query->where($key, (int) $request);
                        } else {
                            $query->where($key, 'like', '%' . $request . '%');
                        }
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->where('order_type', '!=', $explode);
                            }
                        }
                    }
                }
            })->orderBy($orderColumn, $orderType)
            ->limit(50)  // Max 50 commandes actives sur un KDS
            ->get();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function changeStatus(Order $order, OrderStatusRequest $request)
    {
        try {
            if (!(new \App\Rules\ValidStatusTransition($order->status))->passes('status', $request->status)) {
                throw new Exception(trans('all.message.invalid_status_transition'), 422);
            }

            $oldStatus = $order->status;

            SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $request->status]);
            SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $request->status]);
            SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $request->status]);
            $order->status = $request->status;
            $order->save();

            // [BUG-C1 FIX] Broadcast status change so OSS and POS update in real-time
            // No-op if BROADCAST_DRIVER=null; safe to call unconditionally
            try {
                OrderStatusChanged::dispatch($order, $oldStatus, (int) $request->status);
            } catch (\Exception $e) {
                Log::warning('[KDS] OrderStatusChanged broadcast failed: ' . $e->getMessage());
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function OrderItems()
    {
        try {
            $userBranchId = auth()->user()->branch_id ?? 0;

            $query = Order::with('orderItems')
                ->where('status', OrderStatus::PREPARING);

            // Admin bypass: branch_id=0 sees all branches
            if ($userBranchId > 0) {
                $query->where('branch_id', $userBranchId);
            }

            $orders = $query->where(function ($query) {
                $query->where(function ($subQuery) {
                    $subQuery->whereDate('order_datetime', Carbon::today())->where('is_advance_order', Ask::NO);
                })->orWhere(function ($subQuery) {
                    $subQuery->where('is_advance_order', Ask::YES)->whereDate('order_datetime', Carbon::yesterday());
                });
            })->get();

            $allItems = $orders->pluck('orderItems')->flatten();
            $mergedItems = $allItems->groupBy(function ($item) {
                $variations = empty($item['item_variations']) ? '[]' : collect($item['item_variations'])->sortKeys()->toJson();
                $extras = empty($item['item_extras']) ? '[]' : collect($item['item_extras'])->sortKeys()->toJson();
                $instruction = $item['instruction'] ?? '';

                return json_encode([
                    'item_id' => $item['item_id'],
                    'item_variations' => $variations,
                    'item_extras' => $extras,
                    'instruction' => $instruction,
                ]);
            })->map(function ($groupedItems) {
                $firstItem = $groupedItems->first();
                // [B-2 FIX] Always sum quantities — items with same instruction are already grouped separately
                $firstItem['quantity'] = $groupedItems->sum('quantity');
                return $firstItem;
            })->values();
            return $mergedItems;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}