<?php

namespace App\Http\Controllers\Admin;

use App\Events\CouponChanged;
use App\Exports\CouponExport;
use Exception;
use App\Services\CouponService;
use App\Http\Requests\CouponRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use Maatwebsite\Excel\Facades\Excel;


class CouponController extends AdminController
{

    private CouponService $couponService;

    public function __construct(CouponService $coupon)
    {
        parent::__construct();
        $this->couponService = $coupon;
        $this->middleware(['permission:coupons'])->only('index', 'export');
        $this->middleware(['permission:coupons_create'])->only('store');
        $this->middleware(['permission:coupons_edit'])->only('update', 'toggleStatus');
        $this->middleware(['permission:coupons_delete'])->only('destroy');
        $this->middleware(['permission:coupons_show'])->only('show');
    }

    public function index(PaginateRequest $request
    ) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return CouponResource::collection($this->couponService->list($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function store(CouponRequest $request) : CouponResource | \Illuminate\Http\Response
    {
        try {
            $coupon = $this->couponService->store($request);
            $this->dispatchCouponChanged($coupon, CouponChanged::CHANGE_CREATED);
            return new CouponResource($coupon);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }


    public function show(Coupon $coupon
    ) : CouponResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new CouponResource($this->couponService->show($coupon));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }


    public function update(
        CouponRequest $request,
        Coupon $coupon
    ) : CouponResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $updated = $this->couponService->update($request, $coupon);
            $this->dispatchCouponChanged($updated, CouponChanged::CHANGE_UPDATED);
            return new CouponResource($updated);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function destroy(Coupon $coupon
    ) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            // Capture identity before destroy() removes the row.
            $snapshot = clone $coupon;
            $this->couponService->destroy($coupon);
            $this->dispatchCouponChanged($snapshot, CouponChanged::CHANGE_DELETED);
            return response('', 202);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function export(PaginateRequest $request
    ) : \Illuminate\Http\Response | \Symfony\Component\HttpFoundation\BinaryFileResponse | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return Excel::download(new CouponExport($this->couponService, $request), 'Coupons.xlsx');
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    /**
     * [PROMO-DASH-2026-05-06] Toggle status ACTIVE <-> INACTIVE.
     */
    public function toggleStatus(Coupon $coupon
    ) : CouponResource | \Illuminate\Http\Response {
        try {
            $updated = $this->couponService->toggleStatus($coupon);
            $this->dispatchCouponChanged($updated, CouponChanged::CHANGE_STATUS_TOGGLED);
            return new CouponResource($updated);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    private function dispatchCouponChanged(Coupon $coupon, string $changeType): void
    {
        CouponChanged::dispatch(
            (int) $coupon->id,
            $changeType,
            (string) $coupon->code,
            $coupon->status === null ? null : (int) $coupon->status,
            is_array($coupon->branch_scope) ? array_map('intval', $coupon->branch_scope) : [],
            is_array($coupon->surfaces) ? $coupon->surfaces : [],
            []
        );
    }
}
