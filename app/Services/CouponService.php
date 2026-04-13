<?php

namespace App\Services;



use Exception;
use Carbon\Carbon;
use App\Models\Coupon;
use App\Enums\DiscountType;
use App\Models\OrderCoupon;
use App\Libraries\AppLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\CouponRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\CouponCheckRequest;

class CouponService
{
    public $coupon;
    protected array $allowedOrderColumns = [
        'id',
        'name',
        'code',
        'discount',
        'discount_type',
        'start_date',
        'end_date',
        'minimum_order',
        'maximum_discount',
        'limit_per_user',
        'created_at',
    ];
    protected $couponFilter = [
        'name',
        'code',
        'discount',
        'discount_type',
        'start_date',
        'end_date',
        'minimum_order',
        'maximum_discount',
        'limit_per_user',
    ];

    protected $exceptFilter = [
        'excepts'
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $this->sanitizeOrderColumn((string) ($request->get('order_column') ?? 'id'));
            $orderType   = $this->sanitizeOrderDirection((string) ($request->get('order_by') ?? $request->get('order_type') ?? 'desc'));

            return Coupon::where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->couponFilter)) {
                        if ($key == "start_date") {
                            $start_date  = Date('Y-m-d', strtotime($request));
                            $query->whereDate($key, '=', $start_date);
                        } else if ($key == "end_date") {
                            $end_date  = Date('Y-m-d', strtotime($request));
                            $query->whereDate($key, '=', $end_date);
                        } else {
                            $query->where($key, 'like', '%' . $this->escapeLike((string) $request) . '%');
                        }
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->where('id', '!=', $explode);
                            }
                        }
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    private function sanitizeOrderColumn(string $requestedColumn): string
    {
        return in_array($requestedColumn, $this->allowedOrderColumns, true) ? $requestedColumn : 'id';
    }

    private function sanitizeOrderDirection(string $requestedDirection): string
    {
        $requestedDirection = strtolower($requestedDirection);

        return in_array($requestedDirection, ['asc', 'desc'], true) ? $requestedDirection : 'desc';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @throws Exception
     */
    public function store(CouponRequest $request)
    {
        try {
            $this->coupon = Coupon::create([
                'name'             => $request->name,
                'description'      => $request->description,
                'code'             => $request->code,
                'discount'         => $request->discount,
                'discount_type'    => $request->discount_type,
                'start_date'       => !blank($request->start_date) ? date(
                    'Y-m-d H:i:s',
                    strtotime($request->start_date)
                ) : null,
                'end_date'         => !blank($request->end_date) ? date(
                    'Y-m-d H:i:s',
                    strtotime($request->end_date)
                ) : null,
                'minimum_order'    => $request->minimum_order,
                'maximum_discount' => $request->maximum_discount,
                'limit_per_user'   => $request->limit_per_user,
            ]);
            if ($request->image) {
                $this->coupon->addMedia($request->image)->toMediaCollection('coupon');
            }
            return $this->coupon;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(CouponRequest $request, Coupon $coupon)
    {
        try {
            DB::transaction(function () use ($request, $coupon) {
                $this->coupon             = $coupon;
                $coupon->name             = $request->name;
                $coupon->description      = $request->description;
                $coupon->code             = $request->code;
                $coupon->discount         = $request->discount;
                $coupon->discount_type    = $request->discount_type;
                $coupon->start_date       = !blank($request->start_date) ? date(
                    'Y-m-d H:i:s',
                    strtotime($request->start_date)
                ) : null;
                $coupon->end_date         = !blank($request->end_date) ? date(
                    'Y-m-d H:i:s',
                    strtotime($request->end_date)
                ) : null;
                $coupon->minimum_order    = $request->minimum_order;
                $coupon->maximum_discount = $request->maximum_discount;
                $coupon->limit_per_user   = $request->limit_per_user;
                $coupon->save();
                if ($request->image) {
                    $coupon->media()->delete();
                    $coupon->addMedia($request->image)->toMediaCollection('coupon');
                }
            });
            return $this->coupon;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(Coupon $coupon)
    {
        try {
            $coupon->delete();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(Coupon $coupon): Coupon
    {
        try {
            return $coupon;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function couponDateWise(): \Illuminate\Database\Eloquent\Collection
    {
        try {
            return Coupon::all()->filter(function ($item) {
                // [AUDIT-FIX-K4] Guard against null dates to prevent Carbon crash
                if ($item->start_date && $item->end_date) {
                    if (Carbon::now()->between($item->start_date, $item->end_date)) {
                        return $item;
                    }
                }
            });
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function couponChecking(CouponCheckRequest $request)
    {
        try {
            return $this->resolveCouponByCode((string) $request->code, (float) $request->total, (int) auth()->id());
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Resolve and validate a coupon selected by ID for an order flow.
     *
     * @throws Exception
     */
    public function resolveCouponById(int $couponId, float $subtotal, int $userId): Coupon
    {
        $coupon = Coupon::find($couponId);
        return $this->validateCouponForOrder($coupon, $subtotal, $userId);
    }

    /**
     * Resolve and validate a coupon selected by code for public/frontend checks.
     *
     * @throws Exception
     */
    public function resolveCouponByCode(string $code, float $subtotal, int $userId): Coupon
    {
        $coupon = Coupon::where(['code' => trim($code)])->first();
        return $this->validateCouponForOrder($coupon, $subtotal, $userId);
    }

    /**
     * Calculate the monetary discount for a validated coupon.
     */
    public function calculateDiscountAmount(Coupon $coupon, float $subtotal): float
    {
        $amount = $coupon->discount_type == DiscountType::PERCENTAGE
            ? ($subtotal * (float) $coupon->discount) / 100
            : (float) $coupon->discount;

        $maximumDiscount = (float) ($coupon->maximum_discount ?? 0);
        if ($maximumDiscount > 0 && $amount > $maximumDiscount) {
            $amount = $maximumDiscount;
        }

        return round(max(0, min($amount, $subtotal)), 2);
    }

    /**
     * Shared validation rules used by frontend checks and order creation.
     *
     * @throws Exception
     */
    private function validateCouponForOrder(?Coupon $coupon, float $subtotal, int $userId): Coupon
    {
        if (!$coupon) {
            throw new Exception(trans('all.message.coupon_not_exist'), 422);
        }

        if ((float) $coupon->minimum_order > $subtotal) {
            throw new Exception(
                trans('all.message.minimum_order_amount') . AppLibrary::currencyAmountFormat($coupon->minimum_order),
                422
            );
        }

        $now = Carbon::now();
        if ($coupon->start_date && $now->lt(Carbon::parse($coupon->start_date))) {
            throw new Exception(trans('all.message.coupon_not_yet_active'), 422);
        }
        if ($coupon->end_date && $now->gt(Carbon::parse($coupon->end_date))) {
            throw new Exception(trans('all.message.coupon_date_expired'), 422);
        }

        $limitPerUser = (int) ($coupon->limit_per_user ?? 0);
        if ($limitPerUser > 0) {
            $orderedCouponCount = OrderCoupon::where([
                'user_id' => $userId,
                'coupon_id' => $coupon->id,
            ])->count();

            if ($orderedCouponCount >= $limitPerUser) {
                throw new Exception(trans('all.message.coupon_limit_exceeded'), 422);
            }
        }

        return $coupon;
    }
}