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
            'destroy',
            'export',
            'changeStatus',
            'changePaymentStatus',
            'selectDeliveryBoy',
            'reorderItems', // [P2-3 FIX] Explicit permission guard for reorder
            'refundWithCounterEntry' // [P11-FZH] NF525 counter-entry refund
        );
        $this->middleware(['permission:pos-orders|pos'])->only('index', 'show');
    }

    /**
     * [P11-FZH / F-VERIFY-08-02] NF525 counter-entry refund.
     * Creates a mirror order in the current Z window for an order whose
     * created_at is contained in a closed Z report window. The parent
     * stays IMMUTABLE — the mirror carries the negated financial fields
     * + a fresh fiscal_sequence_no + parent_order_id link.
     */
    public function refundWithCounterEntry(
        Order $order,
        Request $request,
        \App\Services\Order\RefundWithCounterEntryService $service
    ): \Illuminate\Http\JsonResponse {
        // [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26] Permission gate.
        // Granted ONLY to Admin (Permission::all()) + Branch Manager (explicit).
        // POS Operator does NOT get this permission by default (mass-refund
        // vector mitigation per PROPOSAL_POS_REFUND_UI_2026-05-25 §8 risk #1).
        // Owner can grant manually via /admin/role/{id}/edit UI if needed.
        // Fail-fast BEFORE validation to surface the authz error cleanly.
        abort_unless(
            \Illuminate\Support\Facades\Auth::user()?->can('pos-refund') ?? false,
            403,
            'Insufficient permission to issue refund.'
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:700'],
        ]);

        // Defense-in-depth — middleware already enforces, but cross-branch is fatal.
        $authUser = \Illuminate\Support\Facades\Auth::user();
        if ($authUser && !$authUser->hasRole('Admin')
            && (int) ($authUser->branch_id ?? 0) !== (int) $order->branch_id) {
            abort(403, 'Cross-branch refund denied.');
        }

        // ─────────────────────────────────────────────────────────────────────
        // [WI-REFUND-PREZ 2026-06-04] Single endpoint, server-side path-selection.
        //
        // The "sealed?" predicate is the SSOT — it lives server-side in
        // SealedOrderGuard, never duplicated on the client. We branch here so the
        // owner's "Rembourser" CTA works for BOTH cases through ONE route:
        //
        //   • Sealed (post-Z, parent inside a CLOSED Z window) → NF525 counter-
        //     entry mirror via RefundWithCounterEntryService (parent immutable).
        //     Response carries the mirror (mode='counter_entry').
        //
        //   • NOT sealed (pre-Z, parent still in the open Z) → the EXISTING,
        //     already-working pre-Z refund: OrderService::changeStatus(RETURNED)
        //     with the reason. This sets status=RETURNED, fires cashBack() (money
        //     returned to the drawer/customer via the order's `transaction`),
        //     refunds loyalty points, and appends an `order.returned` audit row.
        //     The parent is captured in the still-open Z (no fiscal gap, no
        //     mirror). Response carries mode='pre_z' + mirror=null.
        //
        // NOTE on payment_status: we deliberately do NOT flip the parent's
        // payment_status to REFUNDED. PaymentStateMachine defines
        // `PAID => []` (app/Domain/Order/PaymentStateMachine.php:17) — a PAID
        // order CANNOT transition to REFUNDED; changePaymentStatus() would throw
        // InvalidArgumentException(422). This matches the post-Z path, where the
        // parent also stays PAID and only the MIRROR carries REFUNDED. The
        // canonical "refunded" representation of a parent in this codebase is
        // status=RETURNED + cashBack + audit — which is exactly what the pre-Z
        // path produces. Widening the PaymentStateMachine is a fiscal-adjacent
        // owner-gated change, intentionally out of scope here.
        // ─────────────────────────────────────────────────────────────────────
        $isSealed = app(\App\Services\Order\SealedOrderGuard::class)->isSealed($order);

        if (!$isSealed) {
            try {
                return $this->refundPreZ($order, (string) $validated['reason']);
            } catch (HttpException $http) {
                // 403 cross-branch / role guards from OrderService::changeStatus
                // must reach the client intact (multi-tenant security signal).
                throw $http;
            } catch (\Illuminate\Validation\ValidationException $ve) {
                return response()->json([
                    'success' => false,
                    'message' => $ve->getMessage(),
                ], 422);
            } catch (\Throwable $t) {
                // OrderService::changeStatus throws Exception(.., 422) for an
                // invalid status transition; InvalidArgumentException(422) is
                // possible from downstream guards. Surface a clean 422 for those,
                // 500 for anything genuinely unexpected.
                $code = (int) $t->getCode();
                if ($code === 422) {
                    return response()->json([
                        'success' => false,
                        'message' => $t->getMessage(),
                    ], 422);
                }
                \Illuminate\Support\Facades\Log::error('refund-pre-z failed', [
                    'order_id' => $order->id,
                    'error'    => $t->getMessage(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process pre-Z refund: ' . $t->getMessage(),
                ], 500);
            }
        }

        try {
            $mirror = $service->execute($order, (string) $validated['reason']);

            return response()->json([
                'success' => true,
                'mode'    => 'counter_entry',
                'data'    => new OrderDetailsResource($mirror->load('orderItems')),
                'meta'    => [
                    'parent_order_id'           => (int) $order->id,
                    'mirror_fiscal_sequence_no' => (int) $mirror->fiscal_sequence_no,
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (HttpException $http) {
            throw $http;
        } catch (\Illuminate\Database\QueryException $qe) {
            // [HEAL-A.3-bis 2026-05-19 / Z8 P0-1] UNIQUE(parent_order_id) violation.
            // A mirror already exists for this parent — surface as a stable 409
            // with friendly code, NOT a generic 500. The DB-level UNIQUE (heal
            // A.3, migration 2026_05_19_200000) is the primary defense above
            // the dormant RefundWithCounterEntryService:73-78 status-flip guard.
            // Two distinct X-Idempotency-Key values that both pass the
            // idempotency middleware would otherwise produce a double mirror
            // (double Z negative against a single sale) before this catch.
            if (($qe->errorInfo[0] ?? null) === '23000') {
                return response()->json([
                    'success' => false,
                    'code'    => 'MIRROR_ALREADY_EXISTS',
                    'message' => 'Counter-entry already exists for this parent order.',
                ], 409);
            }
            \Illuminate\Support\Facades\Log::error('refund-with-counter-entry db error', [
                'order_id' => $order->id,
                'sqlstate' => $qe->errorInfo[0] ?? null,
                'error'    => $qe->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Database error during counter-entry refund.',
            ], 500);
        } catch (\Throwable $t) {
            \Illuminate\Support\Facades\Log::error('refund-with-counter-entry failed', [
                'order_id' => $order->id,
                'error'    => $t->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create counter-entry refund: ' . $t->getMessage(),
            ], 500);
        }
    }

    /**
     * [WI-REFUND-PREZ 2026-06-04] Pre-Z refund branch of refundWithCounterEntry.
     *
     * Reuses the EXISTING, already-working pre-Z refund path
     * (OrderService::changeStatus → OrderStatus::RETURNED) verbatim — we do NOT
     * reinvent or modify OrderService. The transition DELIVERED→RETURNED (and
     * other terminal→RETURNED edges) is legal in OrderStateMachine; changeStatus
     * validates the reason (required ≤700), runs cashBack() when the order has a
     * `transaction`, refunds loyalty points, and writes the `order.returned`
     * audit row — all guarded by SealedOrderGuard::assertMutable() which permits
     * pre-Z mutation (the inverse of assertSealed). No mirror is produced; the
     * negative is captured in the still-open Z (no fiscal gap).
     *
     * Returns the same envelope shape as the post-Z path so the single frontend
     * handler (PosRefundModal + onRefundCompleted) is tolerant of both modes:
     * `data` = refreshed parent, `meta.mirror_fiscal_sequence_no` = null.
     */
    private function refundPreZ(Order $order, string $reason): \Illuminate\Http\JsonResponse
    {
        // Build a synthetic OrderStatusRequest so OrderService::changeStatus
        // (type-hinted to OrderStatusRequest) resolves $request->status,
        // $request->reason and its inner $request->validate(). authorize() does
        // not run on a manually-created FormRequest — already gated above by
        // abort_unless(can('pos-refund')) + the cross-branch check.
        $statusRequest = OrderStatusRequest::create('/', 'POST', [
            'status' => \App\Enums\OrderStatus::RETURNED,
            'reason' => $reason,
        ]);
        $statusRequest->setContainer(app())->setRedirector(app('redirect'));
        $statusRequest->setUserResolver(fn () => \Illuminate\Support\Facades\Auth::user());

        $refunded = $this->orderService->changeStatus($order, $statusRequest);

        // changeStatus returns Order on success, or array on caught failure.
        if (is_array($refunded)) {
            return response()->json([
                'success' => false,
                'message' => $refunded['message'] ?? 'Pre-Z refund failed.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'mode'    => 'pre_z',
            'data'    => new OrderDetailsResource($refunded->fresh()?->load('orderItems') ?? $refunded),
            'meta'    => [
                'parent_order_id'           => (int) $order->id,
                'mirror_fiscal_sequence_no' => null,
            ],
        ], 200);
    }

    public function index(
        PaginateRequest $request
    ): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        abort_unless(auth()->user()?->can('pos-orders') || auth()->user()?->can('pos'), 403);
        try {
            return SimpleOrderResource::collection($this->orderService->list($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function show(
        int|string $order
    ): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            // RED-team Wave 5I A.1 timing-leak fix 2026-05-18: catch ModelNotFoundException
            // and unify with 403 to prevent existence enumeration (foreign-branch order id
            // returns 403, non-existent id also 403 — single response shape, no info leak).
            // BranchScope + sentinel OrderShowBranchGuardSentinelTest:44 expect 403 baseline.
            try {
                $order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                abort(403, 'Cross-branch access denied');
            }
            abort_unless(
                auth()->user()?->branch_id === 0 || $order->branch_id === auth()->user()?->branch_id,
                403,
                'Cross-branch access denied'
            );
            return new OrderDetailsResource($this->orderService->show($order, false));
        } catch (HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
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
            return $this->jsonError($exception, 422);
        }
    }

    public function export(
        PaginateRequest $request
    ): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return Excel::download(new OrderExport($this->orderService, $request), 'POS-Order.xlsx');
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function changeStatus(
        Order $order,
        OrderStatusRequest $request
    ): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        // [abuse-heal 2026-06-20 W1b POS-REFUND-BYPASS-01] RETURNED is the refund transition
        // (OrderService::changeStatus issues a full cashBack + loyalty refund on it). The frozen
        // OrderStateMachine gates the pre-delivery RETURN edges (ACCEPT/PREPARING/PREPARED→RETURNED)
        // on 'pos-refund', but the DELIVERED→RETURNED edge is UNCONDITIONAL — so a POS Operator
        // without pos-refund could issue a full cash refund through this endpoint, defeating the
        // deliberate mass-refund-vector mitigation that /refund-with-counter-entry enforces (L58).
        // Mirror that gate here (controller layer, non-frozen): the abort sits BEFORE the try/catch
        // so the 403 reaches the client intact (the generic catch would downgrade it to 422).
        // Owner-LOCK followup G-REFUND-GATE: consolidate into OrderStateMachine DELIVERED case for
        // single-source consistency with the other RETURN edges.
        if ((int) $request->input('status') === \App\Enums\OrderStatus::RETURNED) {
            abort_unless(
                \Illuminate\Support\Facades\Auth::user()?->can('pos-refund') ?? false,
                403,
                'Insufficient permission to issue refund.'
            );
        }

        try {
            return new OrderDetailsResource($this->orderService->changeStatus($order, $request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function changePaymentStatus(
        Order $order,
        PaymentStatusRequest $request
    ): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new OrderDetailsResource($this->orderService->changePaymentStatus($order, $request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function selectDeliveryBoy(Order $order, Request $request): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->selectDeliveryBoy($order, $request));
        } catch (HttpException $http) {
            // [GOAL-2026-05-18 P0-LIV-01] OrderService::selectDeliveryBoy now
            // calls abort(403)/abort(422) for cross-branch + role guards. Let
            // the HttpException reach the client intact — masking it as a
            // generic 422 would defeat the multi-tenant security signal.
            throw $http;
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
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
            return $this->jsonError($exception, 422);
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
