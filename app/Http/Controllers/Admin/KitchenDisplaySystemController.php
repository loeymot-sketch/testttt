<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Http\Requests\Kds\KdsOrderRecallRequest;
use App\Http\Requests\Kds\KdsOrderStatusRequest;
use App\Http\Resources\KDSOrderItemsResource;
use App\Http\Resources\KDSOrderDetailsResource;
use App\Services\KitchenDisplaySystemOrderService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KitchenDisplaySystemController extends AdminController
{
    private KitchenDisplaySystemOrderService $kitchenDisplaySystemOrderService;

    public function __construct(KitchenDisplaySystemOrderService $kitchenDisplaySystemOrderService)
    {
        parent::__construct();
        $this->kitchenDisplaySystemOrderService = $kitchenDisplaySystemOrderService;
        $this->middleware(['permission:kitchen-display-system'])->only('index', 'changeStatus', 'orderItems', 'historyToday', 'recall');
    }

    public function index(Request $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $orders = $this->kitchenDisplaySystemOrderService->list($request);

            return KDSOrderDetailsResource::collection($orders)->additional([
                'meta' => [
                    'overflow' => $this->kitchenDisplaySystemOrderService->lastListOverflow(),
                    'limit' => 50,
                ],
            ]);
        } catch (HttpException $e) {
            // [KDS-02] preserve a real 403/throttle status instead of flattening it to a 422 "data
            // error" — matches changeStatus()/recall() in this file + the sibling OSS controller.
            return response(['status' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changeStatus(Order $order, KdsOrderStatusRequest $request): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->kitchenDisplaySystemOrderService->changeStatus($order, $request);
            return response('', 202);
        } catch (HttpException $e) {
            return response(['status' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function orderItems(): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return KDSOrderItemsResource::collection($this->kitchenDisplaySystemOrderService->orderItems());
        } catch (HttpException $e) {
            return response(['status' => false, 'message' => $e->getMessage()], $e->getStatusCode()); // [KDS-02]
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [Wave X3 2026-05-21] KDS "Historique du jour" — read-only V1.
     *
     * Owner mandate: chef sees all PREPARED/OUT/DELIVERED orders today to
     * verify content if a customer reports an error. Read-only — revert
     * (PREPARED → PREPARING) deferred to V1.0.2 because OrderStateMachine
     * (frozen §7) forbids reverse transitions and a LOCK plan + owner
     * countersign is required.
     */
    public function historyToday(): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return KDSOrderDetailsResource::collection($this->kitchenDisplaySystemOrderService->historyToday());
        } catch (HttpException $e) {
            return response(['status' => false, 'message' => $e->getMessage()], $e->getStatusCode()); // [KDS-02]
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
     *
     * Owner mandate verbatim: « écran de cuisine, je peux pas y accéder aux
     * archives parce que je peux par exemple avoir fait valider une commande
     * par erreur avec rapidité, je vais revenir pour la corriger ».
     *
     * Chef recalls a PREPARED order within a 60-second grace window.
     * Compensating-action pattern — `orders.status` is NEVER mutated; we
     * append a row to `order_status_transitions` with `reason='kitchen_recall'`
     * and broadcast `KdsOrderRecalled` on `private-branch.{branchId}` so other
     * stations re-inject the card with a RAPPELÉ badge for 60s.
     *
     * Status codes:
     *  - 200 success → `{ status: true, transition_id, recalled_at, queue_number }`
     *  - 401 unauthenticated (no actor)
     *  - 403 cross-branch attempt
     *  - 409 already recalled (cap N=1)
     *  - 422 wrong state (not PREPARED) OR window expired (>60s)
     *
     * Route: `POST /api/admin/kds-order/recall/{order}`
     * Middleware: idempotency + throttle:kds-bump (mirrors change-status).
     */
    public function recall(Order $order, KdsOrderRecallRequest $request): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $result = $this->kitchenDisplaySystemOrderService->recall($order);

            return response([
                'status'        => true,
                'message'       => trans('all.message.kds_recall_success'),
                'transition_id' => $result['transition_id'],
                'recalled_at'   => $result['recalled_at'],
                'queue_number'  => $result['queue_number'],
            ], 200);
        } catch (HttpException $e) {
            return response(['status' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
