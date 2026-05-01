<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\Order;
use App\Exports\OrderExport;
use Illuminate\Http\Request;
use App\Services\OrderService;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\OrderStatusRequest;
use App\Http\Requests\PaymentStatusRequest;
use App\Http\Resources\SimpleOrderResource;
use App\Http\Resources\OrderDetailsResource;
use App\Models\Scopes\BranchScope;
use Symfony\Component\HttpKernel\Exception\HttpException;


class PosOrderController extends AdminController
{
    private OrderService $orderService;

    public function __construct(OrderService $order)
    {
        parent::__construct();
        $this->orderService = $order;
        $this->middleware(['permission:pos-orders'])->only(
            'index',
            'destroy',
            'export',
            'changeStatus',
            'changePaymentStatus',
            'selectDeliveryBoy',
            'reorderItems' // [P2-3 FIX] Explicit permission guard for reorder
        );
        $this->middleware(['permission:pos-orders|pos'])->only('show');
    }

    public function index(
        PaginateRequest $request
    ): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        abort_unless(auth()->user()?->can('pos-orders'), 403);
        try {
            return SimpleOrderResource::collection($this->orderService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(
        int|string $order
    ): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);
            return new OrderDetailsResource($this->orderService->show($order, false));
        } catch (HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(
        Order $order
    ): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->orderService->destroy($order);
            return response('', 202);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            // [POS-9.1.2] Do NOT mask 403/404 as 422: security-critical
            // HTTP status codes from abort() must reach the client intact.
            throw $http;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(
        PaginateRequest $request
    ): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return Excel::download(new OrderExport($this->orderService, $request), 'POS-Order.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changeStatus(
        Order $order,
        OrderStatusRequest $request
    ): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new OrderDetailsResource($this->orderService->changeStatus($order, $request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changePaymentStatus(
        Order $order,
        PaymentStatusRequest $request
    ): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new OrderDetailsResource($this->orderService->changePaymentStatus($order, $request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function selectDeliveryBoy(Order $order, Request $request): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->selectDeliveryBoy($order, $request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/v1/admin/pos-order/{order}/reorder-items
    // Returns the structured cart payload of a past order for instant re-import
    // by the POS front-end (e.g. Vue/React cart state).
    // ─────────────────────────────────────────────────────────────────────────
    public function reorderItems(Order $order): \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
    {
        try {
            $order->load(['orderItems.orderItem']);

            $cartItems = $order->orderItems->map(function ($orderItem) {
                return [
                    'item_id' => $orderItem->item_id,
                    'item_name' => $orderItem->orderItem?->name,
                    'item_image' => $orderItem->orderItem?->thumb,
                    'quantity' => $orderItem->quantity,
                    'unit_price' => $orderItem->price,
                    'total_price' => $orderItem->total_price,
                    'variations' => $this->reorderVariations($orderItem),
                    'extras' => $this->reorderExtras($orderItem),
                    'note' => $orderItem->note ?? '',
                ];
            });

            return response()->json([
                'status' => true,
                'order_id' => $order->id,
                'order_type' => $order->order_type,
                'note' => $order->note,
                'cart_items' => $cartItems,
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function reorderVariations(\App\Models\OrderItem $orderItem): array
    {
        $snapshot = $this->decodedOrderItemArray($orderItem->composition_snapshot);
        $lines = isset($snapshot['lines']) && is_array($snapshot['lines']) ? $snapshot['lines'] : [];

        if ($lines !== []) {
            return collect($lines)
                ->map(fn($line) => [
                    'id' => $line['variation_id'] ?? $line['id'] ?? null,
                    'name' => $line['variation_name'] ?? $line['name'] ?? null,
                    'attribute_name' => $line['attribute_name'] ?? null,
                    'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                    'price' => (float) ($line['unit_price'] ?? $line['price'] ?? 0),
                ])
                ->filter(fn($line) => ! blank($line['id']))
                ->values()
                ->all();
        }

        return collect($this->decodedOrderItemArray($orderItem->item_variations))
            ->map(fn($line) => [
                'id' => $line['variation_id'] ?? $line['id'] ?? null,
                'name' => $line['variation_name'] ?? $line['name'] ?? null,
                'attribute_name' => $line['attribute_name'] ?? null,
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'price' => (float) ($line['unit_price'] ?? $line['price'] ?? 0),
            ])
            ->filter(fn($line) => ! blank($line['id']))
            ->values()
            ->all();
    }

    private function reorderExtras(\App\Models\OrderItem $orderItem): array
    {
        $snapshot = $this->decodedOrderItemArray($orderItem->composition_snapshot);
        $extras = isset($snapshot['extras']) && is_array($snapshot['extras']) ? $snapshot['extras'] : [];

        if ($extras !== []) {
            return collect($extras)
                ->map(fn($extra) => [
                    'id' => $extra['extra_id'] ?? $extra['id'] ?? null,
                    'name' => $extra['extra_name'] ?? $extra['name'] ?? null,
                    'quantity' => max(1, (int) ($extra['quantity'] ?? 1)),
                    'price' => (float) ($extra['unit_price'] ?? $extra['price'] ?? 0),
                ])
                ->filter(fn($extra) => ! blank($extra['id']))
                ->values()
                ->all();
        }

        return collect($this->decodedOrderItemArray($orderItem->item_extras))
            ->map(fn($extra) => [
                'id' => $extra['extra_id'] ?? $extra['id'] ?? null,
                'name' => $extra['extra_name'] ?? $extra['name'] ?? null,
                'quantity' => max(1, (int) ($extra['quantity'] ?? 1)),
                'price' => (float) ($extra['unit_price'] ?? $extra['price'] ?? 0),
            ])
            ->filter(fn($extra) => ! blank($extra['id']))
            ->values()
            ->all();
    }

    private function decodedOrderItemArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
