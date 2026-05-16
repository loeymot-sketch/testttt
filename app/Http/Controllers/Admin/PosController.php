<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Enums\PosPaymentMethod;
use App\Exceptions\CashDrawerSessionNotOpenException;
use App\Services\Cash\CashDrawerService;
use App\Services\OrderService;
use App\Http\Requests\PosOrderRequest;
use App\Http\Resources\OrderDetailsResource;
use Illuminate\Http\JsonResponse;
use App\Rules\ValidJsonOrder;
use App\Services\Delivery\DeliveryFeeService;
use App\Services\Order\OrderQuoteService;
use App\Services\Pos\WalkInCustomerResolver;
use App\Enums\OrderType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;


class PosController extends AdminController
{
    private OrderService $orderService;
    private OrderQuoteService $orderQuoteService;
    private WalkInCustomerResolver $walkInCustomerResolver;
    private DeliveryFeeService $deliveryFeeService;

    public function __construct(
        OrderService $order,
        OrderQuoteService $orderQuoteService,
        WalkInCustomerResolver $walkInCustomerResolver,
        DeliveryFeeService $deliveryFeeService
    )
    {
        parent::__construct();
        $this->orderService = $order;
        $this->orderQuoteService = $orderQuoteService;
        $this->walkInCustomerResolver = $walkInCustomerResolver;
        $this->deliveryFeeService = $deliveryFeeService;
        $this->middleware(['permission:pos'])->only('store');
    }

    public function store(PosOrderRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->normalizePosRuntimePayload($request);
            $this->assertCashDrawerSessionOpenIfCashInvolved($request);
            return new OrderDetailsResource($this->orderService->posOrderStore($request));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (CashDrawerSessionNotOpenException $exception) {
            // [Sprint 1B 2026-05-16] CASH sale w/o open drawer → 422 with i18n
            // label so the POS UI can show a remediation hint.
            return response([
                'status' => false,
                'message' => __('all.label.cash_no_open_session_blocks_sale'),
                'code'    => 'CASH_NO_OPEN_SESSION',
            ], $exception->getStatusCode());
        } catch (HttpException $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [Sprint 1B 2026-05-16] NF525 cash trail guard — block at controller
     * level before the order is created if any CASH involvement (single
     * tender or any tranche) and no CashDrawerSession OPEN for the cashier
     * on the target branch.
     *
     * This is a defense-in-depth check: OrderService + SplitPaymentService
     * also throw the same exception inside the transaction, but stopping
     * here avoids partial work (fiscal seq alloc, stock decrement, etc.).
     *
     * @throws CashDrawerSessionNotOpenException when CASH is present and no
     *                                           open session exists.
     */
    private function assertCashDrawerSessionOpenIfCashInvolved(PosOrderRequest $request): void
    {
        $needsCashSession = false;

        // Single-tender legacy path
        if ((int) $request->input('pos_payment_method', 0) === PosPaymentMethod::CASH) {
            $needsCashSession = true;
        }

        // Multi-tender split path — only if feature flag is ON, else
        // payment_breakdown is silently stripped and ignored downstream.
        if (! $needsCashSession && config('split_payment.enabled', false)) {
            $breakdown = (array) $request->input('payment_breakdown', []);
            foreach ($breakdown as $tranche) {
                if (is_array($tranche)
                    && (int) ($tranche['mode'] ?? 0) === PosPaymentMethod::CASH) {
                    $needsCashSession = true;
                    break;
                }
            }
        }

        if (! $needsCashSession) {
            return;
        }

        if (! Auth::check()) {
            throw new CashDrawerSessionNotOpenException();
        }

        $branchId = (int) $request->input('branch_id', 0);
        if ($branchId <= 0) {
            $authBranchId = (int) (Auth::user()->branch_id ?? 0);
            $branchId = $authBranchId;
        }
        if ($branchId <= 0) {
            throw new CashDrawerSessionNotOpenException();
        }

        $session = app(CashDrawerService::class)->findOpenSessionForUser(
            $branchId,
            (int) Auth::id(),
        );
        if (! $session) {
            throw new CashDrawerSessionNotOpenException();
        }
    }

    /**
     * Resolve the canonical walk-in customer (DB-first) for POS checkout when the
     * operator lacks CustomerController create/list permissions.
     */
    public function walkInCustomer(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('pos'), 403);
        $user = $this->walkInCustomerResolver->resolve();

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function quote(Request $request): \Illuminate\Http\JsonResponse
    {
        // [Sprint 5B Z1-NEW-002 / Sister POS-A3] Gate `/api/admin/pos/quote`
        // on permission:pos (alongside the constructor middleware which
        // only covers `store`). The kiosk surface uses
        // `/api/frontend/order/quote` (auth:sanctum + kiosk:order ability)
        // which lives in a different route group — bypass perm check there
        // so kiosk pricing checks keep working.
        if (! $request->is('api/frontend/*')) {
            abort_unless($request->user()?->can('pos'), 403);
        }

        $request->validate([
            'branch_id' => ['nullable', 'numeric'],
            'customer_id' => ['nullable', 'numeric'],
            'coupon_id' => ['nullable', 'numeric'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'delivery_distance_km' => ['nullable', 'numeric', 'min:0'],
            'order_type' => ['nullable', 'numeric'],
            'source' => ['nullable', 'numeric'],
            'payment_method' => ['nullable', 'numeric'],
            'pos_payment_method' => ['nullable', 'numeric'],
            'quote_token' => ['nullable', 'string', 'max:64'],
            'quote_signature' => ['nullable', 'string', 'size:64'],
            'consume' => ['nullable', 'boolean'],
            'items' => ['required', 'json', new ValidJsonOrder],
        ]);

        try {
            $this->normalizePosRuntimePayload($request);
            $surface = $request->is('api/frontend/*') ? 'kiosk' : (string) $request->input('surface', 'pos');
            $quote = $this->orderQuoteService->quote($request, $surface);

            return response()->json([
                'status' => true,
                'data' => $this->orderQuoteService->response($quote),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (HttpException $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        } catch (Exception $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    private function normalizePosRuntimePayload(Request $request): void
    {
        $surface = $request->is('api/frontend/*') ? 'kiosk' : (string) $request->input('surface', 'pos');
        if ($surface !== 'pos') {
            return;
        }

        if ((int) $request->input('customer_id', 0) <= 0) {
            $request->merge([
                'customer_id' => $this->walkInCustomerResolver->resolve()->id,
            ]);
        }

        if ((int) $request->input('order_type', 0) === OrderType::DELIVERY && $request->filled('delivery_distance_km')) {
            $request->merge([
                'delivery_charge' => $this->deliveryFeeService->fromDistanceKm($request->input('delivery_distance_km')),
            ]);
        }
    }
}
