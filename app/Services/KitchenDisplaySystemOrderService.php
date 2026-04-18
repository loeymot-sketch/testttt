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
use App\Domain\Order\OrderStateMachine;
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
        'source',
        'payment_method', // [GAP-29-3] Allow filtering by payment method (e.g. cash=1 for kiosk cash panel)
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
            $allowedColumns = ['id', 'order_datetime', 'queue_number', 'order_serial_no', 'status', 'created_at'];
            $requestedColumn = (string) ($request->get('order_column') ?? 'id');
            $orderColumn = in_array($requestedColumn, $allowedColumns, true) ? $requestedColumn : 'id';
            $requestedType = strtolower((string) ($request->get('order_by') ?? 'asc'));
            $orderType = in_array($requestedType, ['asc', 'desc'], true) ? $requestedType : 'desc';

            $userBranchId = auth()->user()->branch_id ?? 0;

            $query = Order::with('orderItems')
                ->whereIn('status', [OrderStatus::ACCEPT, OrderStatus::PREPARING, OrderStatus::PREPARED]);

            // [FIX BUG-KDS-SYNC] Admin users have branch_id=0 → show all branches.
            // Branch-specific staff see only their own branch.
            if ($userBranchId > 0) {
                $query->where('branch_id', $userBranchId);
            }

            // [FIX-FRONT-05] Pagination KDS: limiter à 50 commandes actives maximum
            // [AUDIT-P51-BUG1] Fix: include advance orders scheduled for today OR overdue from yesterday+
            // Previously only showed yesterday's advance orders, causing "zombie" orders to persist unseen.
            return $query->where(function ($query) {
                // Standard orders: placed today (non-advance)
                $query->where(function ($subQuery) {
                    $subQuery->whereDate('order_datetime', Carbon::today())
                             ->where('is_advance_order', Ask::NO);
                })
                // Advance orders: scheduled for today OR overdue from yesterday/past
                ->orWhere(function ($subQuery) {
                    $subQuery->where('is_advance_order', Ask::YES)
                             ->whereDate('order_datetime', '<=', Carbon::today()) // Today or overdue past dates
                             ->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED]); // Not already completed
                });
            })->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->orderFilter)) {
                        if ($key === "status" && $request) {
                            $query->where($key, (int) $request);
                        } else if ($key === "payment_method" && $request !== null && $request !== '') {
                            $query->where($key, (int) $request);
                        } else if (in_array($key, ['branch_id', 'order_type', 'source'], true)) {
                            // [POS-9.1.5] LIKE → = on integer-ID columns to prevent
                            // cross-branch substring leakage. Using LIKE '%1%' on branch_id
                            // matched rows 1, 10, 11, 12, 21, 100… a real data leak.
                            if ($request !== null && $request !== '') {
                                $query->where($key, (int) $request);
                            }
                        } else {
                            $query->where($key, 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $request) . '%');
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

            // [GAP-21-1 + GAP-21-4] Wrap in DB::transaction so that if save() fails,
            // no notifications are dispatched with a stale status.
            // Notifications are dispatched AFTER the transaction commits (post-commit block)
            // to mirror the same pattern used in FrontendOrderService::myOrderStore().
            \Illuminate\Support\Facades\DB::transaction(function () use ($order, $request) {
                $order->status = $request->status;
                $order->save();
            });

            OrderStateMachine::recordTransition(
                Order::class,
                (int) $order->id,
                (int) $oldStatus,
                (int) $request->status,
                auth()->check() ? (int) auth()->id() : null,
                null
            );

            // Post-commit: dispatch notifications and broadcast now that DB is consistent.
            SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $request->status]);
            SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $request->status]);
            SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $request->status]);

            // Broadcast status change so OSS and POS update in real-time
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
    public function orderItems()
    {
        try {
            $userBranchId = auth()->user()->branch_id ?? 0;

            // [P3-2 FIX] Include ACCEPT orders so new POS orders appear on items board immediately
            // without waiting for chef to click "Start Preparing"
            $query = Order::with('orderItems')
                ->whereIn('status', [OrderStatus::ACCEPT, OrderStatus::PREPARING]);

            // Admin bypass: branch_id=0 sees all branches
            if ($userBranchId > 0) {
                $query->where('branch_id', $userBranchId);
            }

            // [FIX-53-2] Mirror the same fix applied to list() in Phase 51:
            // orderItems() was still using Carbon::yesterday() for advance orders,
            // causing overdue orders to vanish from the items board after 24h.
            $orders = $query->where(function ($query) {
                $query->where(function ($subQuery) {
                    $subQuery->whereDate('order_datetime', Carbon::today())->where('is_advance_order', Ask::NO);
                })->orWhere(function ($subQuery) {
                    $subQuery->where('is_advance_order', Ask::YES)
                             ->whereDate('order_datetime', '<=', Carbon::today())
                             ->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED]);
                });
            })->get();

            $allItems = $orders->pluck('orderItems')->flatten();
            $mergedItems = $allItems->groupBy(function ($item) {
                $variations = empty($item['item_variations']) ? '[]' : collect($item['item_variations'])->sortKeys()->toJson();
                $extras = empty($item['item_extras']) ? '[]' : collect($item['item_extras'])->sortKeys()->toJson();
                // [L2 FIX] Normalize instruction: trim whitespace and lowercase to avoid
                // spurious KDS splits caused by minor formatting differences
                $instruction = mb_strtolower(trim($item['instruction'] ?? ''));

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