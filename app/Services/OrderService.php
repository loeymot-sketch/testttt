<?php

namespace App\Services;

use Exception;
use Carbon\Carbon;
use App\Models\Tax;
use App\Models\Item;
use App\Models\User;
use App\Models\Order;
use App\Enums\TaxType;
use App\Models\Address;
use App\Enums\OrderType;
use App\Models\OrderItem;
use App\Enums\OrderStatus;
use App\Models\OrderCoupon;
use App\Models\Transaction;
use App\Enums\PaymentStatus;
use App\Events\SendOrderSms;
use App\Models\OrderAddress;
use Illuminate\Http\Request;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Libraries\AppLibrary;
use App\Models\FrontendOrder;
use App\Models\PaymentGateway;
use App\Events\SendOrderGotSms;
use App\Events\SendOrderGotMail;
use App\Events\SendOrderGotPush;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\OrderRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\PosOrderRequest;
use App\Events\SendOrderDeliveryBoySms;
use App\Events\SendOrderDeliveryBoyMail;
use App\Events\SendOrderDeliveryBoyPush;
use App\Http\Requests\TableOrderRequest;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;
use App\Http\Requests\OrderStatusRequest;
use App\Http\Requests\PaymentStatusRequest;
use App\Domain\Order\OrderStateMachine;
use App\Http\Requests\TableOrderTokenRequest;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Orders\OrderItemAllergenSnapshot;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingResult;
use App\Services\Pricing\PricingService;
use App\Services\Menu\AvailabilityService;

class OrderService
{
    public object $order;
    protected CouponService $couponService;
    protected PricingService $pricingService;
    protected array $orderFilter = [
        'order_serial_no',
        'user_id',
        'branch_id',
        'total',
        'order_type',
        'order_datetime',
        'payment_method',
        'payment_status',
        'status',
        'delivery_boy_id',
        'source'
    ];

    protected array $exceptFilter = [
        'excepts'
    ];

    protected array $allowedOrderColumns = [
        'id',
        'order_serial_no',
        'user_id',
        'branch_id',
        'total',
        'subtotal',
        'discount',
        'order_type',
        'order_datetime',
        'payment_method',
        'payment_status',
        'status',
        'delivery_boy_id',
        'created_at',
        'updated_at',
        'queue_number',
        'source',
    ];

    public function __construct(CouponService $couponService, PricingService $pricingService)
    {
        $this->couponService = $couponService;
        $this->pricingService = $pricingService;
    }

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests = $request->all();
            $method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $this->sanitizeOrderColumn((string) ($request->get('order_column') ?? 'id'));
            $orderType = $this->sanitizeOrderDirection((string) ($request->get('order_by') ?? 'desc'));

            return Order::with([
                'transaction',
                'orderItems.item.media',
                'orderItems.item.category',
                'branch',
                'user'
            ])->where(function ($query) use ($requests) {
                if (isset($requests['from_date']) && isset($requests['to_date'])) {
                    $first_date = Date('Y-m-d', strtotime($requests['from_date']));
                    $last_date = Date('Y-m-d', strtotime($requests['to_date']));
                    $query->whereDate('order_datetime', '>=', $first_date)->whereDate(
                        'order_datetime',
                        '<=',
                        $last_date
                    );
                }
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->orderFilter)) {
                        if ($key === "status") {
                            $query->where($key, (int) $request);
                        } else if ($key === 'payment_method') {
                            if ((int) $request > 0) {
                                if ((int) $request === 1) {
                                    $query->where('payment_method', 1)->where('pos_payment_method', null)->whereDoesntHave('transaction');
                                } else {
                                    $paymentGateway = PaymentGateway::findOrFail((int) $request);
                                    $query->whereHas('transaction', function ($q) use ($paymentGateway) {
                                        $q->where('payment_method', $paymentGateway->slug);
                                    });
                                }
                            } else {
                                $query->where('pos_payment_method', abs((int) $request));
                            }
                        } else {
                            $query->where($key, 'like', '%' . $this->escapeLike((string) $request) . '%');
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

                // Add condition for "exceptSource"
                if (isset($requests['exceptSource'])) {
                    $query->where('source', '!=', $requests['exceptSource']);
                }
            })->orderBy($orderColumn, $orderType)->$method(
                    $methodValue
                );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function userOrder(PaginateRequest $request, User $user)
    {
        try {
            $requests = $request->all();
            $method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $this->sanitizeOrderColumn((string) ($request->get('order_column') ?? 'id'));
            $orderType = $this->sanitizeOrderDirection((string) ($request->get('order_by') ?? 'desc'));

            return Order::with('transaction', 'orderItems', 'branch', 'user')->where('order_type', "!=", OrderType::POS)->where(function ($query) use ($requests, $user) {
                $query->where('user_id', $user->id);
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->orderFilter)) {
                        $query->where($key, 'like', '%' . $this->escapeLike((string) $request) . '%');
                    }
                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->where('status', '!=', $explode);
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

    /**
     * @throws Exception
     */
    public function deliveredOrder(PaginateRequest $request, User $user)
    {
        try {
            $requests = $request->all();
            $method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $this->sanitizeOrderColumn((string) ($request->get('order_column') ?? 'id'));
            $orderType = $this->sanitizeOrderDirection((string) ($request->get('order_by') ?? 'desc'));

            return Order::with('transaction', 'orderItems', 'branch', 'user')->where('delivery_boy_id', $user->id)->where('order_type', "!=", OrderType::POS)->where(
                        function ($query) use ($requests) {
                            foreach ($requests as $key => $request) {
                                if (in_array($key, $this->orderFilter)) {
                                    $query->where($key, 'like', '%' . $this->escapeLike((string) $request) . '%');
                                }
                                if (in_array($key, $this->exceptFilter)) {
                                    $explodes = explode('|', $request);
                                    if (is_array($explodes)) {
                                        foreach ($explodes as $explode) {
                                            $query->where('status', '!=', $explode);
                                        }
                                    }
                                }
                            }
                        }
                    )->orderBy($orderColumn, $orderType)->$method(
                    $methodValue
                );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function deliveryBoyOrder(PaginateRequest $request)
    {
        try {
            $requests = $request->all();
            $method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $this->sanitizeOrderColumn((string) ($request->get('order_column') ?? 'id'));
            $orderType = $this->sanitizeOrderDirection((string) ($request->get('order_by') ?? 'desc'));

            return Order::with('transaction', 'orderItems', 'branch', 'user')->where('order_type', "!=", OrderType::POS)->where('delivery_boy_id', Auth::user()->id)->where(
                        function ($query) use ($requests) {
                            foreach ($requests as $key => $request) {
                                if (in_array($key, $this->orderFilter)) {
                                    $query->where($key, 'like', '%' . $this->escapeLike((string) $request) . '%');
                                }
                                if (in_array($key, $this->exceptFilter)) {
                                    $explodes = explode('|', $request);
                                    if (is_array($explodes)) {
                                        foreach ($explodes as $explode) {
                                            $query->where('status', '!=', $explode);
                                        }
                                    }
                                }
                            }
                        }
                    )->orderBy($orderColumn, $orderType)->$method(
                    $methodValue
                );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function myOrderStore(OrderRequest $request): object
    {
        try {
            DB::transaction(function () use ($request) {
                // [AUDIT-FIX P0] Create order WITHOUT client-supplied financial fields:
                // total, subtotal, discount are recalculated server-side below — never trust the client.
                $validated = $request->validated();
                unset($validated['total'], $validated['subtotal'], $validated['discount']);

                $this->order = Order::create(
                    $validated + [
                        'user_id'          => Auth::user()->id,
                        'status'           => OrderStatus::PENDING,
                        'order_datetime'   => date('Y-m-d H:i:s'),
                        'preparation_time' => Settings::group('order_setup')->get('order_setup_food_preparation_time'),
                        'total'            => 0,
                        'subtotal'         => 0,
                        'discount'         => 0,
                    ]
                );

                $requestItems = $this->safeJsonDecode($request->items);
                $requestItems = is_array($requestItems) ? $requestItems : [];

                if (config('pricing.use_ssot_service', true)) {
                    $res = $this->pricingService->calculateOrder(
                        PricingRequest::forWeb(
                            $this->order->id,
                            (int) $this->order->branch_id,
                            $requestItems,
                            (int) $request->coupon_id,
                            (int) Auth::id(),
                            (float) ($this->order->delivery_charge ?? 0)
                        ),
                        $this->couponService
                    );
                    $itemsArray = $res->orderItemInsertRows;
                    $realSubtotal = $res->accumulatedSubtotal;
                    $totalTax = $res->totalTax;
                    $calculatedDiscount = $res->discount;
                    if (!blank($itemsArray)) {
                        OrderItem::insert($itemsArray);
                    }
                } else {
                    $i            = 0;
                    $totalTax     = 0;
                    $itemsArray   = [];
                    $realSubtotal = 0;

                    // [AUDIT-FIX P0 + P1-1] Single Tax::get() — previous code called it twice (dead query).
                    // [AUDIT-FIX P0] Include tax_id in Item select — previous code omitted it, causing tax=0 on all web/app orders.
                    $requestedItemIds = collect($requestItems)->pluck('item_id')->filter()->unique()->toArray();
                    $dbItems = Item::select('id', 'price', 'tax_id')
                        ->whereIn('id', $requestedItemIds)
                        ->get()
                        ->keyBy('id');
                    $taxCollection = Tax::get();
                    $taxes         = AppLibrary::pluck($taxCollection, 'obj', 'id');

                    // [PERF-02] Bulk-load variations and extras before the loop.
                    // Legacy reference kept for audit/tests: ItemVariation::find / ItemExtra::find
                    // used to run inside the loop before the bulk-loaded keyed collections replaced it.
                    $variationIds = collect($requestItems)->pluck('item_variations')->flatten(1)->pluck('id')->filter()->unique()->toArray();
                    $extraIds     = collect($requestItems)->pluck('item_extras')->flatten(1)->pluck('id')->filter()->unique()->toArray();
                    $dbVariations = !empty($variationIds)
                        ? \App\Models\ItemVariation::whereIn('id', $variationIds)->get()->keyBy('id')
                        : collect();
                    $dbExtras = !empty($extraIds)
                        ? \App\Models\ItemExtra::whereIn('id', $extraIds)->get()->keyBy('id')
                        : collect();

                    app(AvailabilityService::class)->assertItemsOrderableForBranch(
                        (int) $this->order->branch_id,
                        $requestedItemIds,
                        true
                    );

                    if (!blank($requestItems)) {
                        foreach ($requestItems as $item) {
                            $dbItem = $dbItems[$item->item_id] ?? null;
                            if (!$dbItem) {
                                throw new \InvalidArgumentException(
                                    "Item ID {$item->item_id} introuvable. Commande rejetée.",
                                    422
                                );
                            }
                            $itemPrice = (float) $dbItem->price; // ← prix TOUJOURS depuis la DB

                            $variationTotal = 0;
                            if (isset($item->item_variations) && is_array($item->item_variations)) {
                                foreach ($item->item_variations as $variation) {
                                    $varId = $variation->id ?? null;
                                    if (!$varId) continue;
                                    $dbVar = $dbVariations[$varId] ?? null;
                                    if (!$dbVar) {
                                        throw new \InvalidArgumentException("Variation ID {$varId} introuvable.", 422);
                                    }
                                    $variationTotal += (float) $dbVar->price;
                                }
                            }

                            $extraTotal = 0;
                            if (isset($item->item_extras) && is_array($item->item_extras)) {
                                foreach ($item->item_extras as $extra) {
                                    $extraId = $extra->id ?? null;
                                    if (!$extraId) continue;
                                    $dbExt = $dbExtras[$extraId] ?? null;
                                    if (!$dbExt) {
                                        throw new \InvalidArgumentException("Extra ID {$extraId} introuvable.", 422);
                                    }
                                    $extraTotal += (float) $dbExt->price;
                                }
                            }

                            $verifiedQuantity = max(1, (int) ($item->quantity ?? 1));
                            $verifiedTotalPrice = ($itemPrice + $variationTotal + $extraTotal) * $verifiedQuantity;
                            $realSubtotal      += $verifiedTotalPrice;

                            // [AUDIT-FIX P0] tax_id now correctly read from DB item record
                            $taxId    = $dbItem->tax_id ?? 0;
                            $taxName  = isset($taxes[$taxId]) ? $taxes[$taxId]->name : null;
                            $taxRate  = isset($taxes[$taxId]) ? $taxes[$taxId]->tax_rate : 0;
                            $taxType  = isset($taxes[$taxId]) ? $taxes[$taxId]->type : TaxType::FIXED;
                            $taxPrice = $taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100;

                            $itemsArray[$i] = [
                                'order_id'             => $this->order->id,
                                'branch_id'            => $this->order->branch_id,
                                'item_id'              => $item->item_id,
                                'quantity'             => $verifiedQuantity,
                                'discount'             => 0,
                                'tax_name'             => $taxName,
                                'tax_rate'             => $taxRate,
                                'tax_type'             => $taxType,
                                'tax_amount'           => $taxPrice,
                                'price'                => $itemPrice,
                                'item_variations'      => json_encode($item->item_variations ?? []),
                                'item_extras'          => json_encode($item->item_extras ?? []),
                                'instruction'          => $item->instruction ?? null,
                                'item_variation_total' => $variationTotal,
                                'item_extra_total'     => $extraTotal,
                                'total_price'          => $verifiedTotalPrice,
                                'created_at'           => now(),
                                'updated_at'           => now(),
                            ];
                            $totalTax += $taxPrice;
                            $i++;
                        }
                    }

                    if (!blank($itemsArray)) {
                        OrderItem::insert($itemsArray);
                    }

                    // [AUDIT-FIX P0-1] Coupon recalculation server-side — never trust $request->discount
                    $calculatedDiscount = 0;
                    if ($request->coupon_id > 0) {
                        $coupon = $this->couponService->resolveCouponById(
                            (int) $request->coupon_id,
                            (float) $realSubtotal,
                            (int) Auth::id()
                        );
                        $calculatedDiscount = $this->couponService->calculateDiscountAmount($coupon, (float) $realSubtotal);
                    }
                }

                // [AUDIT-P0-B] Atomic queue number allocation using Cache lock.
                // lockForUpdate() is weak when no rows exist yet (first order of the day).
                $today = date('Y-m-d');
                $lockKey = 'queue_lock_' . $this->order->branch_id . '_' . $today;
                $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 10);

                try {
                    $lock->block(5);

                    // [AUDIT-P51-BUG3] Single atomic query to prevent race condition between Order and FrontendOrder
                    // Both models use the same 'orders' table — use direct DB query with MAX() for true atomicity
                    $maxQueueNum = (int) \Illuminate\Support\Facades\DB::table('orders')
                        ->where('branch_id', $this->order->branch_id)
                        ->whereDate('created_at', $today)
                        ->whereNotNull('queue_number')
                        ->whereRaw("queue_number REGEXP '^A[0-9]+$'")
                        ->selectRaw("MAX(CAST(SUBSTRING(queue_number, 2) AS UNSIGNED)) as max_num")
                        ->value('max_num');

                    $nextQueueNum = $maxQueueNum + 1;
                    $queueNumber = 'A' . str_pad($nextQueueNum, 4, '0', STR_PAD_LEFT); // [AUDIT-P2-F] 4 digits

                } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                    $queueNumber = 'A' . str_pad((int)(microtime(true) * 10) % 9999 + 1, 4, '0', STR_PAD_LEFT);
                    \Illuminate\Support\Facades\Log::warning('[Queue] Lock timeout for branch ' . $this->order->branch_id . ' — fallback queue number used.');
                } finally {
                    $lock->release();
                }

                // [AUDIT-FIX P0] Overwrite all financial fields with server-recalculated values
                $this->order->order_serial_no = date('dmy') . $this->order->id;
                $this->order->queue_number    = $queueNumber;
                $this->order->subtotal        = $realSubtotal;
                $this->order->total_tax       = $totalTax;
                $this->order->discount        = $calculatedDiscount;
                $this->order->total           = max(0, $realSubtotal + $totalTax + ($this->order->delivery_charge ?? 0) - $calculatedDiscount);
                $this->order->save();

                if ($request->address_id) {
                    $address = Address::find($request->address_id);
                    if ($address) {
                        OrderAddress::create([
                            'order_id'  => $this->order->id,
                            'user_id'   => Auth::user()->id,
                            'label'     => $address->label,
                            'address'   => $address->address,
                            'apartment' => $address->apartment,
                            'latitude'  => $address->latitude,
                            'longitude' => $address->longitude,
                        ]);
                    }
                }

                // [AUDIT-FIX P0-1] OrderCoupon stores the SERVER-recalculated discount, not the client value
                if ($request->coupon_id > 0 && $calculatedDiscount > 0) {
                    OrderCoupon::create([
                        'order_id'  => $this->order->id,
                        'coupon_id' => $request->coupon_id,
                        'user_id'   => Auth::user()->id,
                        'discount'  => $calculatedDiscount,
                    ]);
                }

                \App\Models\ActionLog::create([
                    'user_id'  => Auth::check() ? Auth::id() : null,
                    'action'   => 'Nouvelle commande Web/App',
                    'resource' => 'Commande #' . $this->order->order_serial_no,
                    'details'  => sprintf(
                        'Auteur: %s | Total: %s€ | Taxe: %s€ | Remise: %s€',
                        Auth::check() ? Auth::user()->name : 'Client anonyme',
                        number_format($this->order->total, 2),
                        number_format($totalTax, 2),
                        number_format($calculatedDiscount, 2)
                    ),
                ]);
            });

            // [BUG-C1 FIX] Dispatch notifications AFTER transaction commit — prevents ghost KDS orders on rollback
            try {
                SendOrderMail::dispatch(['order_id' => $this->order->id, 'status' => OrderStatus::PENDING]);
                SendOrderSms::dispatch(['order_id' => $this->order->id, 'status' => OrderStatus::PENDING]);
                SendOrderPush::dispatch(['order_id' => $this->order->id, 'status' => OrderStatus::PENDING]);
                SendOrderGotMail::dispatch(['order_id' => $this->order->id]);
                SendOrderGotSms::dispatch(['order_id' => $this->order->id]);
                SendOrderGotPush::dispatch(['order_id' => $this->order->id]);
                // [PHASE-E] Broadcast via Soketi WebSockets
                \App\Events\OrderCreated::dispatch($this->order);
            } catch (\Exception $e) {
                Log::warning('Notifications post-commande Web/App échouées pour order #' . $this->order->id . ': ' . $e->getMessage());
            }

            return $this->order;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function posOrderStore(PosOrderRequest $request): object
    {
        // [AUDIT-P49-BUG6] Idempotency: if the cashier double-clicks submit (slow network),
        // return the existing order instead of creating a duplicate.
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if ($idempotencyKey) {
            $existing = Order::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            $order = null;
            DB::transaction(function () use ($request, &$order, $idempotencyKey) {
                // [GAP-20-3] Unset client-supplied financial fields before Order::create().
                // Mirrors the same pattern in myOrderStore() — server always recalculates
                // total, subtotal, discount from DB prices below. This prevents any
                // client-manipulated value from persisting even transiently in the DB row.
                $validated = $request->validated();
                unset($validated['total'], $validated['subtotal'], $validated['discount']);

                // Attach idempotency key if provided by client
                if ($idempotencyKey) {
                    $validated['idempotency_key'] = substr($idempotencyKey, 0, 64);
                }

                // [AUDIT-P1-A] Validate branch_id ownership: cashier can only create orders for their own branch.
                // Admin (branch_id=0) can create orders for any branch.
                $authUser = \Illuminate\Support\Facades\Auth::user();
                if ($authUser->branch_id !== 0 && (int) $request->branch_id !== (int) $authUser->branch_id) { // allow: defensive branch comparison (not a write)
                    throw new \InvalidArgumentException(
                        'Vous ne pouvez pas créer une commande pour une autre branche.',
                        403
                    );
                }

                $this->order = Order::create(
                    $validated + [
                        'user_id' => $request->customer_id,
                        'status' => OrderStatus::ACCEPT,
                        'token' => $request->token,
                        'payment_status' => PaymentStatus::PAID,
                        'order_datetime' => date('Y-m-d H:i:s'),
                        'preparation_time' => Settings::group('order_setup')->get('order_setup_food_preparation_time'),
                        'total'    => 0,
                        'subtotal' => 0,
                        'discount' => 0,
                    ]
                );

                $requestItems = $this->safeJsonDecode($request->items);
                $requestItems = is_array($requestItems) ? $requestItems : [];

                $posSsotPricingResult = null;
                if (config('pricing.use_ssot_service', true)) {
                    $posSsotPricingResult = $this->pricingService->calculateOrder(
                        PricingRequest::forPos(
                            $this->order->id,
                            (int) $this->order->branch_id,
                            $requestItems,
                            (int) $request->coupon_id,
                            (int) ($request->customer_id ?? 0),
                            (float) $request->discount,
                            (float) ($this->order->delivery_charge ?? 0)
                        ),
                        $this->couponService
                    );
                    $itemsArray = $posSsotPricingResult->orderItemInsertRows;
                    $realSubtotal = $posSsotPricingResult->accumulatedSubtotal;
                    $totalTax = $posSsotPricingResult->totalTax;
                    $calculatedDiscount = $posSsotPricingResult->discount;
                    // [POS-9.4.BL.1] Persist immutable allergen snapshot on each
                    // order_item row for NF525 fiscal traceability (must be frozen
                    // at order time, not read through a live FK join later).
                    $itemsArray = OrderItemAllergenSnapshot::hydrate($itemsArray);
                    if (!blank($itemsArray)) {
                        OrderItem::insert($itemsArray);
                    }
                } else {
                    $i = 0;
                    $totalTax = 0;
                    $itemsArray = [];

                    // [TAÂCHE 1] SÉCURISATION PRIX - Récupérer prix depuis DB
                    // [PERF-01] Optimisation : requête ciblée au lieu de Item::get()
                    $requestedItemIds = collect($requestItems)->pluck('item_id')->filter()->unique()->toArray();
                    $dbItems = Item::select('id', 'price', 'tax_id')
                        ->whereIn('id', $requestedItemIds)
                        ->get()
                        ->keyBy('id');

                    // [BUG-CRIT-2 FIX] Bulk-load variations et extras avant la boucle pour éviter N+1
                    $variationIds = collect($requestItems)->pluck('item_variations')->flatten(1)->pluck('id')->filter()->unique()->toArray();
                    $extraIds = collect($requestItems)->pluck('item_extras')->flatten(1)->pluck('id')->filter()->unique()->toArray();

                    $dbVariations = !empty($variationIds)
                        ? \App\Models\ItemVariation::whereIn('id', $variationIds)->get()->keyBy('id')
                        : collect();
                    $dbExtras = !empty($extraIds)
                        ? \App\Models\ItemExtra::whereIn('id', $extraIds)->get()->keyBy('id')
                        : collect();

                    app(AvailabilityService::class)->assertItemsOrderableForBranch(
                        (int) $this->order->branch_id,
                        $requestedItemIds,
                        true
                    );

                    // [AUDIT-FIX P1-1] Single Tax::get() — removed duplicate dead query ($dbTaxes unused)
                    $taxes = AppLibrary::pluck(Tax::get(), 'obj', 'id');
                    $realSubtotal = 0;

                    if (!blank($requestItems)) {
                        foreach ($requestItems as $item) {
                            // [PLAN_01 D-001] REJETER ITEM INEXISTANT - Pas de fallback sur prix client
                            if (!isset($dbItems[$item->item_id])) {
                                throw new \InvalidArgumentException(
                                    "Item ID {$item->item_id} introuvable. Commande rejetée.",
                                    422
                                );
                            }
                            $itemPrice = (float) $dbItems[$item->item_id]->price; // ← prix TOUJOURS depuis la DB

                            // [PLAN_02 D-002] Calculer prix variations depuis DB (pas du payload)
                            // [BUG-CRIT-2 FIX] Utiliser $dbVariations bulk-loadé au lieu de find() dans la boucle
                            $variationTotal = 0;
                            if (isset($item->item_variations) && is_array($item->item_variations)) {
                                foreach ($item->item_variations as $variation) {
                                    $varId = $variation->id ?? null;
                                    if (!$varId) continue;

                            $dbVar = $dbVariations[$varId] ?? null;
                            if (!$dbVar) {
                                throw new \InvalidArgumentException(
                                    "Variation ID {$varId} introuvable.",
                                    422
                                );
                            }
                            // [P2-1 FIX] Cross-item injection guard: variation must belong to this item
                            if ((int) $dbVar->item_id !== (int) $item->item_id) {
                                throw new \InvalidArgumentException(
                                    "Variation ID {$varId} n'appartient pas à l'article {$item->item_id}.",
                                    422
                                );
                            }
                            $variationTotal += (float) $dbVar->price;
                                }
                            }

                            // [PLAN_02 D-002] Calculer prix extras depuis DB (pas du payload)
                            // [BUG-CRIT-2 FIX] Utiliser $dbExtras bulk-loadé au lieu de find() dans la boucle
                            $extraTotal = 0;
                            if (isset($item->item_extras) && is_array($item->item_extras)) {
                                foreach ($item->item_extras as $extra) {
                                    $extraId = $extra->id ?? null;
                                    if (!$extraId) continue;

                            $dbExt = $dbExtras[$extraId] ?? null;
                            if (!$dbExt) {
                                throw new \InvalidArgumentException(
                                    "Extra ID {$extraId} introuvable.",
                                    422
                                );
                            }
                            // [P2-1 FIX] Cross-item injection guard: extra must belong to this item
                            if ((int) $dbExt->item_id !== (int) $item->item_id) {
                                throw new \InvalidArgumentException(
                                    "Extra ID {$extraId} n'appartient pas à l'article {$item->item_id}.",
                                    422
                                );
                            }
                            $extraTotal += (float) $dbExt->price;
                                }
                            }
                            
                            // Prix vérifié depuis DB
                            $verifiedQuantity = max(1, (int) ($item->quantity ?? 1));
                            $verifiedUnitPrice = $itemPrice + $variationTotal + $extraTotal;
                            $verifiedTotalPrice = round($verifiedUnitPrice * $verifiedQuantity, 2);
                            
                            $taxId = isset($dbItems[$item->item_id]) ? ($dbItems[$item->item_id]->tax_id ?? 0) : 0;
                            $taxName = isset($taxes[$taxId]) ? $taxes[$taxId]->name : null;
                            $taxRate = isset($taxes[$taxId]) ? $taxes[$taxId]->tax_rate : 0;
                            $taxType = isset($taxes[$taxId]) ? $taxes[$taxId]->type : TaxType::FIXED;
                            $taxPrice = round($taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100, 2);
                            
                            $itemsArray[$i] = [
                                'order_id'             => $this->order->id,
                                'branch_id'            => $this->order->branch_id,
                                'item_id'              => $item->item_id,
                                'quantity'             => $verifiedQuantity,
                                'discount'             => 0,
                                'tax_name'             => $taxName,
                                'tax_rate'             => $taxRate,
                                'tax_type'             => $taxType,
                                'tax_amount'           => $taxPrice,
                                'price'                => $itemPrice,
                                'item_variations'      => json_encode($item->item_variations ?? []),
                                'item_extras'          => json_encode($item->item_extras ?? []),
                                'instruction'          => $item->instruction ?? null,
                                'item_variation_total' => $variationTotal,
                                'item_extra_total'     => $extraTotal,
                                'total_price'          => $verifiedTotalPrice,
                                'created_at'           => now(),
                                'updated_at'           => now(),
                            ];
                            $realSubtotal += $verifiedTotalPrice;
                            $totalTax = $totalTax + $taxPrice;
                            $i++;
                        }
                    }

                    // [POS-9.4.BL.1] Same NF525 allergen snapshot hydration for the
                    // non-SSOT legacy path (feature flag `pricing.use_ssot_service=false`).
                    $itemsArray = OrderItemAllergenSnapshot::hydrate($itemsArray);
                    if (!blank($itemsArray)) {
                        OrderItem::insert($itemsArray);
                    }

                    // [PHASE 7] SECURISATION P0 COUPON / DISCOUNT POUR TABLE ORDER
                    // [BUG-3 FIX] Apply manual discount when no coupon, validate discount <= subtotal
                    $calculatedDiscount = 0;
                    if ($request->coupon_id > 0) {
                        $coupon = $this->couponService->resolveCouponById(
                            (int) $request->coupon_id,
                            (float) $realSubtotal,
                            (int) ($request->customer_id ?? 0)
                        );
                        $calculatedDiscount = $this->couponService->calculateDiscountAmount($coupon, (float) $realSubtotal);
                    } elseif ($request->discount > 0) {
                        // [AUDIT-FIX P1-3] Manual cashier discount — validated server-side, will be logged below
                        $manualDiscount = (float) $request->discount;
                        if ($manualDiscount <= $realSubtotal) {
                            $calculatedDiscount = $manualDiscount;
                        }
                        // Si discount > subtotal, on ignore (pas de total négatif)
                    }
                }

                // [AUDIT-P0-B] Atomic queue number allocation using Cache lock.
                // lockForUpdate() is weak when no rows exist yet (first order of the day).
                $today = date('Y-m-d');
                $lockKey = 'queue_lock_' . $this->order->branch_id . '_' . $today;
                $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 10);

                try {
                    $lock->block(5);

                    // [AUDIT-P51-BUG3] Single atomic query to prevent race condition between Order and FrontendOrder
                    $maxQueueNum = (int) \Illuminate\Support\Facades\DB::table('orders')
                        ->where('branch_id', $this->order->branch_id)
                        ->whereDate('created_at', $today)
                        ->whereNotNull('queue_number')
                        ->whereRaw("queue_number REGEXP '^A[0-9]+$'")
                        ->selectRaw("MAX(CAST(SUBSTRING(queue_number, 2) AS UNSIGNED)) as max_num")
                        ->value('max_num');

                    $nextQueueNum = $maxQueueNum + 1;
                    $queueNumber = 'A' . str_pad($nextQueueNum, 4, '0', STR_PAD_LEFT); // [AUDIT-P2-F] 4 digits

                } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                    $queueNumber = 'A' . str_pad((int)(microtime(true) * 10) % 9999 + 1, 4, '0', STR_PAD_LEFT);
                    \Illuminate\Support\Facades\Log::warning('[Queue] Lock timeout for branch ' . $this->order->branch_id . ' — fallback queue number used.');
                } finally {
                    $lock->release();
                }

                $this->order->order_serial_no = date('dmy') . $this->order->id;
                $this->order->queue_number = $queueNumber;
                if ($posSsotPricingResult instanceof PricingResult) {
                    $this->order->total_tax = $posSsotPricingResult->totalTax;
                    $this->order->subtotal = $posSsotPricingResult->subtotal;
                    $this->order->discount = $posSsotPricingResult->discount;
                    $this->order->total = $posSsotPricingResult->total;
                } else {
                    $this->order->total_tax = round($totalTax, 2);
                    $this->order->subtotal = round($realSubtotal, 2);
                    $this->order->discount = $calculatedDiscount;
                    $this->order->total = round(max(0, $realSubtotal + $totalTax + ($this->order->delivery_charge ?? 0) - $calculatedDiscount), 2);
                }

                // [AUDIT-P1-B] Server-side cash validation against the REAL computed total.
                // The client-side check in PosOrderRequest uses the client-sent total (may differ).
                // This check uses the server-recalculated total to ensure correct cash handling.
                if ($request->pos_payment_method == \App\Enums\PosPaymentMethod::CASH
                    && $request->pos_received_amount !== null
                    && (float) $request->pos_received_amount < $this->order->total) {
                    throw new \InvalidArgumentException(
                        'Le montant reçu (' . $request->pos_received_amount . '€) est inférieur au total réel (' . $this->order->total . '€).',
                        422
                    );
                }

                // Loyalty: store the customer code for AwardLoyaltyPointsOnDelivery listener.
                // If cashier passes an explicit code, use it; otherwise derive from the selected customer.
                if ($request->loyalty_customer_code) {
                    $this->order->loyalty_customer_code = $request->loyalty_customer_code;
                } else {
                    $customer = \App\Models\User::find($request->customer_id);
                    if ($customer && $customer->loyalty_code) {
                        $this->order->loyalty_customer_code = $customer->loyalty_code;
                    }
                }
                $this->order->source_surface = 'pos';

                $currentTime = Carbon::now();
                $endTime = $currentTime->copy()->addMinutes(Settings::group('order_setup')->get('order_setup_schedule_order_slot_duration'));
                $start = $currentTime->format('H:i');
                $end = $endTime->format('H:i');
                $this->order->delivery_time = "$start - $end";

                // [POS-9.4.BL.1] Reserve fiscal sequence number atomically right
                // before persisting. FiscalSequenceService::next() runs its own
                // Cache::lock + lockForUpdate + transaction so nesting inside our
                // DB::transaction only creates a SAVEPOINT — if our outer
                // transaction rolls back, no sequence number is effectively
                // "consumed" (next call sees the same MAX again). NF525 requires
                // strictly monotonic gap-free numbering per branch.
                $this->order->fiscal_sequence_no = app(FiscalSequenceService::class)
                    ->next((int) $this->order->branch_id);

                $this->order->save();

                // [BUG-C3 FIX] Create OrderCoupon record for POS orders — tracks coupon usage per order
                if ($request->coupon_id > 0 && $calculatedDiscount > 0) {
                    OrderCoupon::create([
                        'order_id'  => $this->order->id,
                        'coupon_id' => $request->coupon_id,
                        'user_id'   => $request->customer_id,
                        'discount'  => $calculatedDiscount,
                    ]);
                }

                //storing order address
                if ($request->address_id) {
                    // [V3 FIX] Ownership check: address must belong to the customer on this order.
                    // Prevents IDOR where a manipulated payload could copy another customer's address.
                    $address = Address::where('id', $request->address_id)
                        ->where('user_id', $request->customer_id)
                        ->first();
                    if ($address) {
                        OrderAddress::create([
                            'order_id'  => $this->order->id,
                            'user_id'   => $request->customer_id,
                            'label'     => $address->label,
                            'address'   => $address->address,
                            'apartment' => $address->apartment,
                            'latitude'  => $address->latitude,
                            'longitude' => $address->longitude,
                        ]);
                    } else {
                        // [Y5 FIX] Address not found or doesn't belong to customer — fail explicitly
                        // for delivery orders so the order is not created without a valid address.
                        throw new \Exception('Adresse #' . $request->address_id . ' introuvable ou n\'appartient pas au client.', 422);
                    }
                }

                // [AUDIT-FIX P1-3] Log includes discount amount and type for auditability
                $discountDetail = $calculatedDiscount > 0
                    ? ($request->coupon_id > 0
                        ? sprintf('Coupon #%s → -%s€', $request->coupon_id, number_format($calculatedDiscount, 2))
                        : sprintf('Remise manuelle caissier → -%s€ (sur %s€)', number_format($calculatedDiscount, 2), number_format($realSubtotal, 2)))
                    : 'Aucune remise';

                \App\Models\ActionLog::create([
                    'user_id'  => Auth::check() ? Auth::id() : null,
                    'action'   => 'Nouvelle commande POS',
                    'resource' => 'Commande #' . $this->order->order_serial_no,
                    'details'  => sprintf('Créée via Point de Vente | Total: %s€ | %s', number_format($this->order->total, 2), $discountDetail),
                ]);

                // [POS-9.4.BL.2] NF525 audit trail: any manual or coupon discount
                // must be recorded on the HMAC chain so a fraudulent cashier
                // discount becomes detectable post-hoc. Skipped when no discount
                // was applied to keep the chain focused on financially sensitive
                // events.
                if ($calculatedDiscount > 0) {
                    app(AuditLogService::class)->write([
                        'branch_id'   => (int) $this->order->branch_id,
                        'user_id'     => Auth::check() ? (int) Auth::id() : null,
                        'action'      => 'order.discount_applied',
                        'resource'    => 'order',
                        'resource_id' => (int) $this->order->id,
                        'payload'     => [
                            'order_serial_no'    => $this->order->order_serial_no,
                            'coupon_id'          => $request->coupon_id > 0 ? (int) $request->coupon_id : null,
                            'discount_amount'    => round((float) $calculatedDiscount, 2),
                            'discount_type'      => $request->coupon_id > 0 ? 'coupon' : 'manual_cashier',
                            'subtotal_before'    => round((float) $realSubtotal, 2),
                            'total_after'        => round((float) $this->order->total, 2),
                        ],
                    ]);
                }

                $order = $this->order;
            });
            
            // Dispatcher notifications APRÈS transaction (hors transaction)
            if ($order) {
                try {
                    SendOrderGotMail::dispatch(['order_id' => $order->id]);
                    SendOrderGotSms::dispatch(['order_id' => $order->id]);
                    SendOrderGotPush::dispatch(['order_id' => $order->id]);
                    // [PHASE-E] Broadcast via Soketi WebSockets (no-op if BROADCAST_DRIVER=null)
                    \App\Events\OrderCreated::dispatch($order);
                } catch (\Exception $e) {
                    Log::warning('Notification KDS échouée pour order #' . $order->id . ': ' . $e->getMessage());
                }
            }
            
            return $this->order;
        } catch (\Illuminate\Database\QueryException $qe) {
            // [AUDIT-52-BUG6] Catch MySQL duplicate key (23000) on idempotency_key UNIQUE constraint.
            // This handles the race condition where two simultaneous requests both pass the pre-check
            // (both see NULL) but the second INSERT hits the DB-level unique constraint.
            // Return the existing order gracefully instead of a 500 error.
            if ($qe->getCode() === '23000' && $idempotencyKey) {
                $existing = Order::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    Log::info('[POS Idempotency] Duplicate key caught at DB level — returning existing order #' . $existing->id);
                    return $existing;
                }
            }
            Log::info($qe->getMessage());
            throw new Exception(QueryExceptionLibrary::message($qe), 422);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }


    /**
     * @throws Exception
     */
    public function tableOrderStore(TableOrderRequest $request): object
    {
        try {
            DB::transaction(function () use ($request) {
                $validated = $request->validated();
                unset($validated['total'], $validated['subtotal'], $validated['discount']);

                $this->order = FrontendOrder::create(
                    $validated + [
                        'user_id' => $request->customer_id,
                        'status' => OrderStatus::PENDING,
                        'order_datetime' => date('Y-m-d H:i:s'),
                        'preparation_time' => Settings::group('order_setup')->get('order_setup_food_preparation_time'),
                        'total' => 0,
                        'subtotal' => 0,
                        'discount' => 0,
                    ]
                );

                $requestItems = $this->safeJsonDecode($request->items);
                $requestItems = is_array($requestItems) ? $requestItems : [];

                $tableSsotPricingResult = null;
                if (config('pricing.use_ssot_service', true)) {
                    $tableSsotPricingResult = $this->pricingService->calculateOrder(
                        PricingRequest::forTable(
                            $this->order->id,
                            (int) $this->order->branch_id,
                            $requestItems,
                            (int) $request->coupon_id,
                            (int) ($request->customer_id ?? 0),
                            (float) $request->discount,
                            (float) ($this->order->delivery_charge ?? 0)
                        ),
                        $this->couponService
                    );
                    $itemsArray = $tableSsotPricingResult->orderItemInsertRows;
                    $realSubtotal = $tableSsotPricingResult->accumulatedSubtotal;
                    $totalTax = $tableSsotPricingResult->totalTax;
                    $calculatedDiscount = $tableSsotPricingResult->discount;
                    if (!blank($itemsArray)) {
                        OrderItem::insert($itemsArray);
                    }
                } else {
                    $i = 0;
                    $totalTax = 0;
                    $itemsArray = [];

                    // [PERF-01] Optimisation : requête ciblée au lieu de Item::get()
                    $requestedItemIds = collect($requestItems)->pluck('item_id')->filter()->unique()->toArray();
                    $dbItems = Item::select('id', 'price', 'tax_id')
                        ->whereIn('id', $requestedItemIds)
                        ->get()
                        ->keyBy('id');
                    $items = $dbItems->pluck('tax_id', 'id');

                    // [BUG-CRIT-2 FIX] Bulk-load variations et extras avant la boucle pour éviter N+1
                    $variationIds = collect($requestItems)->pluck('item_variations')->flatten(1)->pluck('id')->filter()->unique()->toArray();
                    $extraIds = collect($requestItems)->pluck('item_extras')->flatten(1)->pluck('id')->filter()->unique()->toArray();

                    $dbVariations = !empty($variationIds)
                        ? \App\Models\ItemVariation::whereIn('id', $variationIds)->get()->keyBy('id')
                        : collect();
                    $dbExtras = !empty($extraIds)
                        ? \App\Models\ItemExtra::whereIn('id', $extraIds)->get()->keyBy('id')
                        : collect();

                    app(AvailabilityService::class)->assertItemsOrderableForBranch(
                        (int) $this->order->branch_id,
                        $requestedItemIds,
                        true
                    );

                    $taxes = AppLibrary::pluck(Tax::get(), 'obj', 'id');
                    $realSubtotal = 0;

                    if (!blank($requestItems)) {
                        foreach ($requestItems as $item) {

                            // [PLAN_01 D-001] REJETER ITEM INEXISTANT - Pas de fallback sur prix client
                            // [BUG-CRIT-2 FIX] Utiliser $dbItems déjà bulk-loadé
                            $dbItem = $dbItems[$item->item_id] ?? null;
                            if (!$dbItem) {
                                throw new \InvalidArgumentException(
                                    "Item ID {$item->item_id} introuvable. Commande rejetée.",
                                    422
                                );
                            }
                            $itemPrice = $dbItem->price; // ← prix TOUJOURS depuis la DB

                            // [BUG-CRIT-2 FIX] Utiliser $dbVariations bulk-loadé au lieu de find() dans la boucle
                            // [AUDIT-2026-03] isset avant empty : json_decode sans clés évite stdClass::$prop sur PHP 8.2+
                            $calcVariationTotal = 0;
                            if (isset($item->item_variations) && !empty($item->item_variations)) {
                                foreach ($item->item_variations as $var) {
                                    $varId = $var->id ?? 0;
                                    $dbVar = $dbVariations[$varId] ?? null;
                                    if (!$dbVar) {
                                        throw new \InvalidArgumentException(
                                            "Variation ID {$varId} introuvable pour l'article {$item->item_id}.",
                                            422
                                        );
                                    }
                                    if ((int) $dbVar->item_id !== (int) $item->item_id) {
                                        throw new \InvalidArgumentException(
                                            "Variation ID {$varId} n'appartient pas à l'article {$item->item_id}.",
                                            422
                                        );
                                    }
                                    $calcVariationTotal += $dbVar->price;
                                }
                            }

                            // [BUG-CRIT-2 FIX] Utiliser $dbExtras bulk-loadé au lieu de find() dans la boucle
                            $calcExtraTotal = 0;
                            if (isset($item->item_extras) && !empty($item->item_extras)) {
                                foreach ($item->item_extras as $ext) {
                                    $extId = $ext->id ?? 0;
                                    $dbExt = $dbExtras[$extId] ?? null;
                                    if (!$dbExt) {
                                        throw new \InvalidArgumentException(
                                            "Extra ID {$extId} introuvable pour l'article {$item->item_id}.",
                                            422
                                        );
                                    }
                                    if ((int) $dbExt->item_id !== (int) $item->item_id) {
                                        throw new \InvalidArgumentException(
                                            "Extra ID {$extId} n'appartient pas à l'article {$item->item_id}.",
                                            422
                                        );
                                    }
                                    $calcExtraTotal += $dbExt->price;
                                }
                            }

                            $verifiedQuantity = max(1, (int) ($item->quantity ?? 1));
                            $verifiedTotalPrice = ($itemPrice + $calcVariationTotal + $calcExtraTotal) * $verifiedQuantity;
                            $realSubtotal += $verifiedTotalPrice;

                            $taxId = isset($items[$item->item_id]) ? $items[$item->item_id] : 0;
                            $taxName = isset($taxes[$taxId]) ? $taxes[$taxId]->name : null;
                            $taxRate = isset($taxes[$taxId]) ? $taxes[$taxId]->tax_rate : 0;
                            $taxType = isset($taxes[$taxId]) ? $taxes[$taxId]->type : TaxType::FIXED;
                            $taxPrice = $taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100;
                            $itemsArray[$i] = [
                                'order_id'             => $this->order->id,
                                'branch_id'            => $this->order->branch_id, // [AUDIT-P47-BUG3] always use order's branch, never client payload
                                'item_id'              => $item->item_id,
                                'quantity'             => $verifiedQuantity,
                                'discount'             => 0,
                                'tax_name'             => $taxName,
                                'tax_rate'             => $taxRate,
                                'tax_type'             => $taxType,
                                'tax_amount'           => $taxPrice,
                                'price'                => $itemPrice,
                                'item_variations'      => json_encode($item->item_variations ?? []),
                                'item_extras'          => json_encode($item->item_extras ?? []),
                                'instruction'          => $item->instruction ?? null,
                                'item_variation_total' => $calcVariationTotal,
                                'item_extra_total'     => $calcExtraTotal,
                                'total_price'          => $verifiedTotalPrice,
                                'created_at'           => now(),
                                'updated_at'           => now(),
                            ];
                            $totalTax = $totalTax + $taxPrice;
                            $i++;
                        }
                    }

                    if (!blank($itemsArray)) {
                        OrderItem::insert($itemsArray);
                    }

                    // [PHASE 7] SECURISATION P0 COUPON / DISCOUNT POUR TABLE ORDER
                    // [BUG-3 FIX] Apply manual discount when no coupon, validate discount <= subtotal
                    $calculatedDiscount = 0;
                    if ($request->coupon_id > 0) {
                        $coupon = $this->couponService->resolveCouponById(
                            (int) $request->coupon_id,
                            (float) $realSubtotal,
                            (int) ($request->customer_id ?? 0)
                        );
                        $calculatedDiscount = $this->couponService->calculateDiscountAmount($coupon, (float) $realSubtotal);
                    } elseif ($request->discount > 0) {
                        // [AUDIT-FIX P1-3] Manual cashier discount — validated server-side, will be logged below
                        $manualDiscount = (float) $request->discount;
                        if ($manualDiscount <= $realSubtotal) {
                            $calculatedDiscount = $manualDiscount;
                        }
                        // Si discount > subtotal, on ignore (pas de total négatif)
                    }
                }

                // [AUDIT-P47-BUG2] Atomic queue number allocation using Cache lock.
                // lockForUpdate() is weak when no rows exist yet (first order of the day).
                $today = date('Y-m-d');
                $lockKey = 'queue_lock_' . $this->order->branch_id . '_' . $today;
                $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 10);

                try {
                    $lock->block(5);

                    // [AUDIT-P51-BUG3] Single atomic query to prevent race condition between Order and FrontendOrder
                    $maxQueueNum = (int) \Illuminate\Support\Facades\DB::table('orders')
                        ->where('branch_id', $this->order->branch_id)
                        ->whereDate('created_at', $today)
                        ->whereNotNull('queue_number')
                        ->whereRaw("queue_number REGEXP '^A[0-9]+$'")
                        ->selectRaw("MAX(CAST(SUBSTRING(queue_number, 2) AS UNSIGNED)) as max_num")
                        ->value('max_num');

                    $nextQueueNum = $maxQueueNum + 1;
                    $queueNumber = 'A' . str_pad($nextQueueNum, 4, '0', STR_PAD_LEFT); // [AUDIT-P47-BUG2] 4 digits

                } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                    $queueNumber = 'A' . str_pad((int)(microtime(true) * 10) % 9999 + 1, 4, '0', STR_PAD_LEFT);
                    \Illuminate\Support\Facades\Log::warning('[Queue] Lock timeout for branch ' . $this->order->branch_id . ' (table) — fallback used.');
                } finally {
                    $lock->release();
                }

                $this->order->order_serial_no = date('dmy') . $this->order->id;
                $this->order->queue_number = $queueNumber;
                if ($tableSsotPricingResult instanceof PricingResult) {
                    $this->order->total_tax = $tableSsotPricingResult->totalTax;
                    $this->order->subtotal = $tableSsotPricingResult->subtotal;
                    $this->order->discount = $tableSsotPricingResult->discount;
                    $this->order->total = $tableSsotPricingResult->total;
                } else {
                    $this->order->total_tax = $totalTax;
                    $this->order->subtotal = $realSubtotal;
                    $this->order->discount = $calculatedDiscount;
                    // [BUG-H1 FIX] null-coalescing + max(0) guard — prevents negative total with large coupons or null delivery_charge
                    $this->order->total = max(0, $realSubtotal + $totalTax + ($this->order->delivery_charge ?? 0) - $calculatedDiscount);
                }

                $currentTime = Carbon::now();
                $endTime = $currentTime->copy()->addMinutes(Settings::group('order_setup')->get('order_setup_schedule_order_slot_duration'));
                $start = $currentTime->format('H:i');
                $end = $endTime->format('H:i');
                $this->order->delivery_time = "$start - $end";
                $this->order->save();

                // [BUG-C3 FIX] Create OrderCoupon record for table orders — tracks coupon usage
                if ($request->coupon_id > 0 && $calculatedDiscount > 0) {
                    OrderCoupon::create([
                        'order_id'  => $this->order->id,
                        'coupon_id' => $request->coupon_id,
                        'user_id'   => $request->customer_id,
                        'discount'  => $calculatedDiscount,
                    ]);
                }

                // [AUDIT-FIX P1-3] Log includes discount amount and type for auditability
                $discountDetail = $calculatedDiscount > 0
                    ? ($request->coupon_id > 0
                        ? sprintf('Coupon #%s → -%s€', $request->coupon_id, number_format($calculatedDiscount, 2))
                        : sprintf('Remise manuelle → -%s€ (sur %s€)', number_format($calculatedDiscount, 2), number_format($realSubtotal, 2)))
                    : 'Aucune remise';

                \App\Models\ActionLog::create([
                    'user_id'  => Auth::check() ? Auth::id() : null,
                    'action'   => 'Nouvelle commande sur Table',
                    'resource' => 'Commande #' . $this->order->order_serial_no,
                    'details'  => sprintf('Créée via QR Code Dine-in | Total: %s€ | %s', number_format($this->order->total, 2), $discountDetail),
                ]);
            });

            // [BUG-C1 FIX] Dispatch notifications AFTER transaction commit — prevents ghost KDS orders on rollback
            try {
                SendOrderGotMail::dispatch(['order_id' => $this->order->id]);
                SendOrderGotSms::dispatch(['order_id' => $this->order->id]);
                SendOrderGotPush::dispatch(['order_id' => $this->order->id]);
                // [PHASE-E] Broadcast via Soketi WebSockets
                \App\Events\OrderCreated::dispatch($this->order);
            } catch (\Exception $e) {
                Log::warning('Notifications post-commande Table échouées pour order #' . $this->order->id . ': ' . $e->getMessage());
            }

            return $this->order;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(Order $order, $auth = false): Order|array
    {
        try {
            if ($auth) {
                if ($order->user_id == Auth::user()->id) {
                    return $order;
                } else {
                    abort(403, 'Access denied: you do not have permission to access this order.');
                }
            } else {
                return $order;
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function orderDetails(User $user, Order $order): Order|array
    {
        try {
            if ($order->user_id == $user->id) {
                return $order->load('transaction', 'orderItems', 'branch', 'user');
            } else {
                abort(403, 'Access denied: you do not have permission to access this order.');
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function deliveryBoyOrderDetails(Order $order): Order|array
    {
        try {
            if ($order->delivery_boy_id == Auth::user()->id) {
                return $order;
            } else {
                abort(403, 'Access denied: you do not have permission to access this order.');
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function deliveryBoyDeliveredOrderDetails(User $user, Order $order): Order|array
    {
        try {
            if ($order->delivery_boy_id == $user->id) {
                return $order;
            } else {
                abort(403, 'Access denied: you do not have permission to access this order.');
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function deliveryBoyOrderCount(): array
    {
        try {
            $order = new Order;
            $orderCountArray = [];
            $orderCountArray['total_delivered'] = $order->where(
                ['delivery_boy_id' => Auth::user()->id, 'status' => OrderStatus::DELIVERED]
            )->count();
            $orderCountArray['total_returned'] = $order->where(
                ['delivery_boy_id' => Auth::user()->id, 'status' => OrderStatus::RETURNED]
            )->count();

            return $orderCountArray;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function deliveryBoyOrderChangeStatus(Order $order, OrderStatusRequest $request): Order
    {
        try {
            // [FIX-54-1] Ownership check — same as deliveryBoyOrderDetails()
            if ($order->delivery_boy_id != Auth::user()->id) {
                abort(403, 'Access denied: this order is not assigned to you.');
            }

            // [FIX-54-1] Enforce valid state machine transitions
            if (!(new \App\Rules\ValidStatusTransition($order->status))->passes('status', $request->status)) {
                throw new Exception(trans('all.message.invalid_status_transition'), 422);
            }

            $oldStatus  = (int) $order->status;
            $newStatus  = (int) $request->status;

            // [POS-9.1.7] Wrap mutations in DB::transaction so a partial failure
            // (save / state-machine / payment_status flip) rolls back atomically.
            // Notifications + OrderStatusChanged broadcast are deferred to
            // afterCommit so listeners (OSS, KDS, loyalty) never observe a
            // half-written state nor fire if the transaction rolls back.
            DB::transaction(function () use ($order, $oldStatus, $newStatus) {
                $transaction = Transaction::where('order_id', $order->id)->first();
                if (!$transaction && $order->payment_status == PaymentStatus::UNPAID) {
                    $order->payment_status = PaymentStatus::PAID;
                }

                $order->status = $newStatus;
                $order->save();

                OrderStateMachine::recordTransition(
                    Order::class,
                    (int) $order->id,
                    $oldStatus,
                    $newStatus,
                    Auth::check() ? (int) Auth::id() : null,
                    null
                );
            });

            // Dispatch notifications + broadcast AFTER the transaction has
            // committed so jobs and listeners always read the persisted state.
            SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $newStatus]);
            SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $newStatus]);
            SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $newStatus]);

            // [FIX-54-1] Broadcast so OSS, KDS, loyalty listener all fire
            try {
                \App\Events\OrderStatusChanged::dispatch($order, $oldStatus, $newStatus);
            } catch (\Exception $e) {
                Log::warning('[DeliveryBoy] OrderStatusChanged broadcast failed: ' . $e->getMessage());
            }

            return $order;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function changeStatus(Order $order, OrderStatusRequest $request, bool $auth = false): Order|array
    {
        try {
            if (!(new \App\Rules\ValidStatusTransition($order->status))->passes('status', $request->status)) {
                throw new Exception(trans('all.message.invalid_status_transition'), 422);
            }

            if ($auth) {
                // Customer self-cancellation path — owner check only
                if ($order->user_id == Auth::user()->id) {
                    $oldStatus = $order->status;
                    if ($request->reason) {
                        $order->reason = $request->reason;
                    }
                    if ($request->status == OrderStatus::REJECTED || $request->status == OrderStatus::CANCELED) {
                        if ($order->transaction) {
                            app(PaymentService::class)->cashBack(
                                $order,
                                'credit',
                                'TXN-' . \Illuminate\Support\Str::random(12)
                            );
                        }
                        app(LoyaltyService::class)->refundPoints($order, 'pos');
                    }
                    $order->status = $request->status;
                    $order->save();
                    OrderStateMachine::recordTransition(
                        Order::class,
                        (int) $order->id,
                        (int) $oldStatus,
                        (int) $request->status,
                        Auth::check() ? (int) Auth::id() : null,
                        $request->reason ?? null
                    );
                    SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                    SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                    SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                    try {
                        \App\Events\OrderStatusChanged::dispatch($order, $oldStatus, (int) $request->status);
                    } catch (\Exception $e) {
                        Log::warning('[OrderService] OrderStatusChanged on self-cancel failed: ' . $e->getMessage());
                    }
                } else {
                    // [FIX-54-7] Return 403 instead of silent 200 for non-owner
                    abort(403, 'Access denied: you do not own this order.');
                }
            } else {
                // [CYCLE-002b] Atomic branch check, cashback, status save + ActionLog; notifications after commit.
                $oldStatusForBroadcast = null;
                DB::transaction(function () use ($order, $request, &$oldStatusForBroadcast) {
                    // [AUDIT-FIX P0-2 / POS-9-H.1.1] Branch isolation: non-Admin staff can only modify orders of their branch.
                    // Use abort() so the 403 is a real HttpException and bubbles untouched through the generic catch below.
                    if (Auth::check() && !Auth::user()->hasRole('Admin')) {
                        $userBranch = Auth::user()->branch_id ?? null;
                        if ($userBranch && (int) $userBranch !== (int) $order->branch_id) {
                            abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
                        }
                    }

                    if ($request->status == OrderStatus::REJECTED || $request->status == OrderStatus::CANCELED) {
                        $request->validate([
                            'reason' => 'required|max:700',
                        ]);
                        if ($request->reason) {
                            $order->reason = $request->reason;
                        }
                        if ($order->transaction) {
                            app(PaymentService::class)->cashBack(
                                $order,
                                'credit',
                                'TXN-' . \Illuminate\Support\Str::random(12)
                            );
                        }
                        app(LoyaltyService::class)->refundPoints($order, 'pos');
                    }

                    $oldStatusForBroadcast = $order->status;
                    $order->status = $request->status;
                    $order->save();

                    OrderStateMachine::recordTransition(
                        Order::class,
                        (int) $order->id,
                        (int) $oldStatusForBroadcast,
                        (int) $request->status,
                        Auth::check() ? (int) Auth::id() : null,
                        $request->reason ?? null
                    );

                    \App\Models\ActionLog::create([
                        'user_id'  => Auth::check() ? Auth::user()->id : null,
                        'action'   => 'Changement de statut',
                        'resource' => 'Commande #' . $order->order_serial_no,
                        'details'  => sprintf(
                            'Nouveau statut: %s | Par: %s (branch_id=%s)',
                            trans('all.order.status.' . $request->status),
                            Auth::check() ? Auth::user()->name : 'Système',
                            Auth::check() ? (Auth::user()->branch_id ?? 'admin') : '?'
                        ),
                    ]);

                    // [POS-9.4.BL.2] NF525 audit trail on cancel/reject. Only these
                    // two status transitions are fiscally sensitive (everything
                    // else is internal workflow); the full state-machine event
                    // trail is already persisted in order_status_transitions via
                    // OrderStateMachine::recordTransition above.
                    if ((int) $request->status === OrderStatus::CANCELED
                        || (int) $request->status === OrderStatus::REJECTED) {
                        app(AuditLogService::class)->write([
                            'branch_id'   => (int) $order->branch_id,
                            'user_id'     => Auth::check() ? (int) Auth::id() : null,
                            'action'      => (int) $request->status === OrderStatus::CANCELED
                                ? 'order.cancelled'
                                : 'order.rejected',
                            'resource'    => 'order',
                            'resource_id' => (int) $order->id,
                            'payload'     => [
                                'order_serial_no' => $order->order_serial_no,
                                'from_status'     => (int) $oldStatusForBroadcast,
                                'to_status'       => (int) $request->status,
                                'reason'          => $request->reason,
                                'total'           => round((float) $order->total, 2),
                                'payment_status'  => (int) $order->payment_status,
                                'fiscal_sequence_no' => $order->fiscal_sequence_no,
                            ],
                        ]);
                    }
                });

                SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $request->status]);

                // [PHASE-E] After commit; ShouldBroadcastNow — must not run inside DB::transaction
                try {
                    \App\Events\OrderStatusChanged::dispatch($order, $oldStatusForBroadcast, (int) $request->status);
                } catch (\Exception $e) {
                    Log::warning('OrderStatusChanged broadcast failed: ' . $e->getMessage());
                }
            }
            return $order;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function changePaymentStatus(Order $order, PaymentStatusRequest $request, bool $auth = false): Order|array
    {
        try {
            if ($auth) {
                if ($order->user_id == Auth::user()->id) {
                    $order->payment_status = $request->payment_status;
                    $order->save();
                    return $order;
                } else {
                    abort(403, 'Access denied: you do not have permission to modify this order.');
                }
            } else {
                // [AUDIT-FIX P0-2 / POS-9-H.1.1] Branch isolation: non-Admin staff can only modify their branch's orders.
                // Use abort() so the 403 bubbles through the generic catch as a real HttpException.
                if (Auth::check() && !Auth::user()->hasRole('Admin')) {
                    $userBranch = Auth::user()->branch_id ?? null;
                    if ($userBranch && (int) $userBranch !== (int) $order->branch_id) {
                        abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
                    }
                }

                $order->payment_status = $request->payment_status;
                $order->save();

                \App\Models\ActionLog::create([
                    'user_id'  => Auth::check() ? Auth::id() : null,
                    'action'   => 'Statut paiement modifié',
                    'resource' => 'Commande #' . $order->order_serial_no,
                    'details'  => sprintf(
                        'Statut paiement: %s | Par: %s (branch_id=%s)',
                        $request->payment_status,
                        Auth::check() ? Auth::user()->name : 'Système',
                        Auth::check() ? (Auth::user()->branch_id ?? 'admin') : '?'
                    ),
                ]);

                // [POS-9.4.BL.2] NF525 audit trail on payment_status change.
                // Change of payment status is financially sensitive (especially
                // PAID→UNPAID or PAID→REFUNDED, which impacts Z report totals).
                app(AuditLogService::class)->write([
                    'branch_id'   => (int) $order->branch_id,
                    'user_id'     => Auth::check() ? (int) Auth::id() : null,
                    'action'      => 'order.payment_status_changed',
                    'resource'    => 'order',
                    'resource_id' => (int) $order->id,
                    'payload'     => [
                        'order_serial_no'    => $order->order_serial_no,
                        'to_payment_status'  => (int) $request->payment_status,
                        'total'              => round((float) $order->total, 2),
                        'fiscal_sequence_no' => $order->fiscal_sequence_no,
                    ],
                ]);

                return $order;
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }


    public function tokenCreate(Order $order, TableOrderTokenRequest $request, bool $auth = false): Order|array
    {
        try {
            if ($auth) {
                if ($order->user_id == Auth::user()->id) {
                    $order->token = $request->token;
                    $order->save();
                    return $order;
                } else {
                    abort(403, 'Access denied: you do not have permission to modify this order.');
                }
            } else {
                $order->token = $request->token;
                $order->save();
                return $order;
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            throw $http;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function selectDeliveryBoy(Order $order, Request $request, bool $auth = false): Order|array
    {
        try {
            if ($auth) {
                if ($order->user_id == Auth::user()->id) {
                    $order->delivery_boy_id = $request->delivery_boy_id;
                    $order->save();
                    SendOrderDeliveryBoyMail::dispatch(['order_id' => $order->id, 'status' => 101]);
                    SendOrderDeliveryBoySms::dispatch(['order_id' => $order->id, 'status' => 101]);
                    SendOrderDeliveryBoyPush::dispatch(['order_id' => $order->id, 'status' => 101]);
                    return $order;
                } else {
                    abort(403, 'Access denied: you do not have permission to modify this order.');
                }
            } else {
                $order->delivery_boy_id = $request->delivery_boy_id;
                $order->save();
                SendOrderDeliveryBoyMail::dispatch(['order_id' => $order->id, 'status' => 101]);
                SendOrderDeliveryBoySms::dispatch(['order_id' => $order->id, 'status' => 101]);
                SendOrderDeliveryBoyPush::dispatch(['order_id' => $order->id, 'status' => 101]);
                return $order;
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(Order $order)
    {
        // [POS-9.1.2] Branch isolation + payment guard + audit log.
        // [Gate POS-9.1] HTTP guard checks must run OUTSIDE the try/catch
        // so abort(403,…) (HttpException) propagates as a 403 instead of
        // being swallowed and re-thrown as a generic 422.
        $actor = Auth::user();
        $actorBranchId = (int) ($actor->branch_id ?? 0);
        $orderBranchId = (int) $order->branch_id;

        // Admin (branch_id=0) can destroy any; branch staff only own branch.
        if ($actorBranchId > 0 && $actorBranchId !== $orderBranchId) {
            abort(403, 'Access denied: order does not belong to your branch.');
        }

        // Block PAID orders unless the actor carries the dedicated permission.
        if ((int) $order->payment_status === PaymentStatus::PAID
            && $actor && !$actor->can('pos-destroy-paid')) {
            abort(403, 'Paid orders cannot be destroyed without elevated permission.');
        }

        // [POS-9.4.BL.3] NF525 immutability: once a Z report is closed, every
        // order that was aggregated in it becomes fiscally sealed. We detect
        // seal state by checking if there exists a closed ZReport whose
        // (opened_at, closed_at] half-open window contains the order's
        // created_at — the same half-open semantic used by Z aggregation
        // itself (Phase H.2). Returning 409 (Conflict) makes the intent
        // explicit: "the server state conflicts with what you're trying to do".
        if ($order->fiscal_sequence_no !== null) {
            $isSealed = \App\Models\ZReport::query()
                ->where('branch_id', $orderBranchId)
                ->where('status', \App\Models\ZReport::STATUS_CLOSED)
                ->where('opened_at', '<', $order->created_at)
                ->where('closed_at', '>=', $order->created_at)
                ->exists();
            if ($isSealed) {
                abort(
                    409,
                    'Order is sealed by a closed Z report — cannot destroy (NF525 immutability).'
                );
            }
        }

        try {
            $reason = trim((string) request('destroy_reason', ''));

            DB::transaction(function () use ($order, $actor, $reason) {
                $order->address()?->delete();
                $order->coupon()?->delete();
                $order->orderItems()?->delete();
                // Soft-delete only (Order model uses SoftDeletes)
                $order->delete();

                \App\Models\ActionLog::create([
                    'user_id'  => $actor?->id,
                    'action'   => 'order.destroyed',
                    'resource' => 'Order #' . $order->id,
                    'details'  => json_encode([
                        'order_id'       => $order->id,
                        'branch_id'      => $order->branch_id,
                        'order_type'     => $order->order_type,
                        'status'         => $order->status,
                        'payment_status' => $order->payment_status,
                        'total'          => $order->total,
                        'reason'         => $reason ?: null,
                        'actor_id'       => $actor?->id,
                        'actor_branch'   => $actor?->branch_id,
                    ]),
                ]);

                // [POS-9.4.BL.2] NF525 audit trail on soft-delete of an order.
                // Critical because order items and address are hard-deleted
                // above (one-way), so the audit chain is the ONLY surviving
                // canonical record of what was on this order at destroy time.
                app(AuditLogService::class)->write([
                    'branch_id'   => (int) $order->branch_id,
                    'user_id'     => $actor?->id,
                    'action'      => 'order.destroyed',
                    'resource'    => 'order',
                    'resource_id' => (int) $order->id,
                    'payload'     => [
                        'order_serial_no'    => $order->order_serial_no,
                        'order_type'         => (int) $order->order_type,
                        'status_at_destroy'  => (int) $order->status,
                        'payment_status_at_destroy' => (int) $order->payment_status,
                        'total'              => round((float) $order->total, 2),
                        'fiscal_sequence_no' => $order->fiscal_sequence_no,
                        'reason'             => $reason ?: null,
                    ],
                ]);
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            // Bubble HTTP exceptions (403/404) untouched.
            throw $http;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function salesReportOverview(Request $request)
    {
        try {
            $requests = $request->all();
            $orderColumn = $this->sanitizeOrderColumn((string) ($request->get('order_column') ?? 'id'));
            $orderType = $this->sanitizeOrderDirection((string) ($request->get('order_by') ?? 'desc'));

            $orders = Order::with('transaction', 'orderItems')->where(function ($query) use ($requests) {
                if (isset($requests['from_date']) && isset($requests['to_date'])) {
                    $first_date = Date('Y-m-d', strtotime($requests['from_date']));
                    $last_date = Date('Y-m-d', strtotime($requests['to_date']));
                    $query->whereDate('order_datetime', '>=', $first_date)->whereDate(
                        'order_datetime',
                        '<=',
                        $last_date
                    );
                }
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->orderFilter)) {
                        if ($key === "status") {
                            $query->where($key, (int) $request);
                        } else if ($key === 'payment_method') {
                            if ((int) $request > 0) {
                                if ((int) $request === 1) {
                                    $query->where('payment_method', 1)->where('pos_payment_method', null)->whereDoesntHave('transaction');
                                } else {
                                    $paymentGateway = PaymentGateway::findOrFail((int) $request);
                                    $query->whereHas('transaction', function ($q) use ($paymentGateway) {
                                        $q->where('payment_method', $paymentGateway->slug);
                                    });
                                }
                            } else {
                                $query->where('pos_payment_method', abs((int) $request));
                            }
                        } else if ($key === 'source') {
                            $query->where($key, $request);
                        } else {
                            $query->where($key, 'like', '%' . $this->escapeLike((string) $request) . '%');
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
            })->orderBy($orderColumn, $orderType)->get();
            $salesReportArray = [];

            $salesReportArray['total_orders'] = $orders->count();
            $salesReportArray['total_earnings'] = AppLibrary::currencyAmountFormat($orders->sum('total'));
            $salesReportArray['total_discounts'] = AppLibrary::currencyAmountFormat($orders->sum('discount'));
            $salesReportArray['total_delivery_charges'] = AppLibrary::currencyAmountFormat($orders->sum('delivery_charge'));

            return $salesReportArray;
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
     * Safely decode JSON with error checking
     */
    private function safeJsonDecode(?string $json): mixed
    {
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
