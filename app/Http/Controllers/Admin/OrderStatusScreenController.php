<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Enums\Status;
use App\Models\Branch;
use App\Http\Resources\CDSPopularItemResource;
use App\Http\Resources\CDSOrderDetailsResource;
use App\Http\Resources\PosShortcutOrderResource;
use App\Services\OrderStatusScreenOrderService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderStatusScreenController extends AdminController
{
    private OrderStatusScreenOrderService $orderStatusScreenOrderService;

    public function __construct(OrderStatusScreenOrderService $orderStatusScreenOrderService)
    {
        parent::__construct();
        $this->orderStatusScreenOrderService = $orderStatusScreenOrderService;
        $this->middleware(['permission:order-status-screen'])->only('index', 'mostPopularItems');
    }

    public function index(): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            // [test-e2e fix A-001 round-1 2026-05-21] PosShortcutOrderResource
            // exposes `total` so the authenticated POS shortcut widget can
            // render the right amount on Prêt-à-livrer rows. The public wall
            // display (publicIndex) keeps CDSOrderDetailsResource (no PII).
            return PosShortcutOrderResource::collection($this->orderStatusScreenOrderService->list());
        } catch (HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function mostPopularItems(): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return CDSPopularItemResource::collection($this->orderStatusScreenOrderService->mostPopularItems());
        } catch (HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [iter15-mega-fix C-016 2026-05-10] Public-facing OSS read for the
     * customer wall display (`/admin/order-status-screen`).
     *
     * The customer screen is mounted on a public TV/wall. It is NOT an admin
     * dashboard — yet its only data source historically was
     * `GET /api/admin/oss-order` which is auth-gated by `auth:sanctum` +
     * `permission:order-status-screen`. Result on an unauthenticated wall:
     * the XHR returns 401, the component swallows the error, and both
     * "PRÉPARATION" / "PRÊT" columns render empty.
     *
     * This sibling endpoint is mounted under the public `frontend` group
     * (`installed` + `apiKey` + `localization`) so the customer screen can
     * fetch the same payload without a session. The payload is intentionally
     * harmless: `CDSOrderDetailsResource` exposes only `id`, `order_serial_no`,
     * `token`, `queue_number`, `order_type`, `status` — exactly what is shown
     * on the wall display anyway. No PII (customer name/phone/address/total).
     *
     * Branch resolution:
     *   1. `?branch_id=N` query param if N > 0 → that branch (allows multiple
     *      walls per fleet to scope themselves explicitly).
     *   2. Otherwise → the first ACTIVE branch (single-branch fast-food
     *      default; matches what the SPA boot ends up showing on the kiosk).
     *
     * The admin endpoint (`index` above) is unchanged — global Admin still
     * gets unfiltered + branch-override semantics there. The POS dashboard
     * widget that calls `orderStatusScreenOrder/lists` from a logged-in
     * admin session continues to hit `/api/admin/oss-order`.
     */
    public function publicIndex(\Illuminate\Http\Request $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $branchId = (int) $request->query('branch_id', 0);
            if ($branchId <= 0) {
                $defaultBranch = Branch::where('status', Status::ACTIVE)
                    ->orderBy('id')
                    ->first();
                $branchId = $defaultBranch?->id ?? 0;
            }

            // Re-inject the resolved branch_id so the service's existing
            // `request()->query('branch_id')` lookup picks it up. We pass
            // `auth()->user() = null` semantics implicitly because this
            // endpoint is unauthenticated — the service's `resolveBranchScope`
            // path for "non-admin user with branch_id <= 0" would `abort(403)`,
            // so we must bypass the service's auth-aware resolver here.
            // Build the query directly with the resolved branch.
            $rows = $this->orderStatusScreenOrderService->listForBranch($branchId);
            return CDSOrderDetailsResource::collection($rows);
        } catch (HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            // [FP-22] UNAUTHENTICATED public endpoint — never serialize getMessage() (it leaked
            // SQL/table names to any LAN device). Log server-side, return a generic message.
            report($exception);
            return response(['status' => false, 'message' => 'Service momentanément indisponible.'], 422);
        }
    }

    /**
     * [iter15-mega-fix C-016 2026-05-10] Public sibling for the OSS popular-items
     * panel — `PopularItemComponent.vue` mounts on the same wall display as
     * `PreparingAndReadyComponent.vue`, so it must also stop firing
     * `GET /api/admin/oss-order/popular-items` when no admin session is present.
     * Resource (`CDSPopularItemResource`) only exposes id / name / currency_price /
     * thumb — these are already public via `/api/frontend/item/popular-items`,
     * so opening the OSS variant is data-equivalent to existing public surface.
     */
    public function publicMostPopularItems(\Illuminate\Http\Request $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            // [Sprint H5-B Z4-P2-04 2026-05-17] Resolve branch from the same
            // request param the customer-wall poll already uses for the OSS
            // list. Falls back to the first ACTIVE branch — mirrors
            // publicIndex() resolution — so a wall on a single-branch fleet
            // never displays an unrelated branch's top-9.
            $branchId = (int) $request->query('branch_id', 0);
            if ($branchId <= 0) {
                $defaultBranch = Branch::where('status', Status::ACTIVE)
                    ->orderBy('id')
                    ->first();
                $branchId = $defaultBranch?->id ?? 0;
            }

            return CDSPopularItemResource::collection(
                $this->orderStatusScreenOrderService->mostPopularItems($branchId > 0 ? $branchId : null)
            );
        } catch (HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            // [FP-22] UNAUTHENTICATED public endpoint — never serialize getMessage() (it leaked
            // SQL/table names to any LAN device). Log server-side, return a generic message.
            report($exception);
            return response(['status' => false, 'message' => 'Service momentanément indisponible.'], 422);
        }
    }
}
