<?php

namespace App\Services;

use Exception;
use Carbon\Carbon;
use App\Models\Tax;
use App\Models\Item;
use App\Models\User;
use App\Models\Order;
use App\Enums\TaxType;
use App\Enums\Source;
use App\Models\Address;
use App\Enums\OrderType;
use App\Models\OrderDiscountLog;
use App\Models\OrderItem;
use App\Enums\OrderStatus;
use App\Models\OrderCoupon;
use App\Models\Transaction;
use App\Enums\PaymentStatus;
use App\Events\OrderCanceled; // allow: domain event class import — audit log written by ActionLog/AuditLogService at call sites.
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderSms;
use App\Models\OrderAddress;
use Illuminate\Http\Request;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
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
use Illuminate\Support\Facades\Cache;
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
use App\Domain\Order\AutoPrepareOnPaidPolicy;
use App\Domain\Order\OrderStateMachine;
use App\Http\Requests\TableOrderTokenRequest;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Order\OrderQuoteService;
use App\Services\Orders\OrderItemAllergenSnapshot;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingResult;
use App\Services\Pricing\PricingService;
use App\Services\Menu\AvailabilityService;
use App\Services\DiningTableService;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        'source',
        // [GOAL-CAISSE-UNIFIED W-HIST 2026-05-30] Origin filter for the unified
        // /admin/historique page. source_surface ('kiosk'|'pos'|'web'|'app') is
        // the RELIABLE origin signal (order_type carries legacy/dirty values on
        // this deployment, e.g. 30/4, and kiosk orders are TAKEAWAY-typed). Read
        // path only; applied via applyOrderFilter (LIKE) — surfaces are distinct
        // substrings so no cross-match. No write/business-rule change.
        'source_surface'
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
                'orderItems.orderItem.media',
                'orderItems.orderItem.category',
                'branch',
                'user'
            ])->where(function ($query) use ($requests) {
                if (!empty($requests['from_date']) && !empty($requests['to_date'])) {
                    // [GOAL-G2-HEAL-04 2026-05-23] TZ-generation alignment to
                    // Wave T R5 Paris bounds (commit 27d95e066). User-input
                    // dates are Y-m-d Paris-local (front-end picker). The
                    // Wave 3c heal (commit 4905138fa, 2026-05-18) converted
                    // them to UTC ASSUMING MySQL session_tz=UTC — empirically
                    // FALSE on this deployment (session_tz=SYSTEM=Paris
                    // because config/database.php connections.mysql.timezone
                    // is NULL and PDO inherits OS local). UTC bind literals
                    // were re-interpreted as Paris-local under session_tz=
                    // Paris, shifting the report window backward by 2h →
                    // sales report dropped the last ~2h of every Paris day.
                    //
                    // Correct heal: bind Paris-local Carbon directly so
                    // MySQL session_tz=Paris interprets at face value.
                    // Sentinel: SisterServicesTzAwareV2Test (inverted).
                    //
                    // INVARIANT DEPENDENCY: assumes session_tz=OS-local
                    // (Paris). Future connections.mysql.timezone => '+00:00'
                    // MUST re-evaluate.
                    $appTz = config('app.timezone');
                    $fromParis = Carbon::parse($requests['from_date'], $appTz)
                        ->startOfDay();
                    $toParisExclusive = Carbon::parse($requests['to_date'], $appTz)
                        ->addDay()
                        ->startOfDay();
                    $query->where('order_datetime', '>=', $fromParis)
                          ->where('order_datetime', '<', $toParisExclusive);
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
                            // [SALES-PAR-03 heal 2026-06-01] `source` is an int enum — EXACT match
                            // (parity with salesReportOverview), NOT the generic LIKE which would
                            // over-match (e.g. '%5%' matching 5 and 15/50). source_surface stays LIKE.
                            $query->where('source', (int) $request);
                        } else if ($key === 'source_surface' && (string) $request === 'web') {
                            // [TRAP-1 HIST-04 heal 2026-06-04] source_surface=web is the online
                            // sentinel the Historique "En ligne" filter emits — the ONLY value the
                            // UI sends for that button (HistoriqueListComponent.vue:337; 'app'/'mobile'
                            // are never sent by the UI, so they stay literal LIKE matches). Applied as
                            // the generic LIKE '%web%', it SILENTLY DROPPED legacy online orders whose
                            // source_surface is NULL (they predate the surface tag) — yet the badge
                            // labels them "En ligne" via the legacy `source` fallback
                            // (HistoriqueListComponent.vue:304-311: source WEB|APP → En ligne).
                            // Mirror the badge EXACTLY so the filter returns the same online set: any
                            // web/app/mobile surface, OR a NULL surface whose legacy source is WEB/APP.
                            // The web/app/mobile surface set is kept in the predicate body so a future
                            // real app/mobile-surface row is still included when the user clicks
                            // En-ligne. kiosk/pos surfaces (non-NULL, not online tokens) are excluded.
                            $query->where(function ($q) {
                                $q->whereIn('source_surface', ['web', 'app', 'mobile'])
                                  ->orWhere(function ($qq) {
                                      $qq->whereNull('source_surface')
                                         ->whereIn('source', [Source::WEB, Source::APP]);
                                  });
                            });
                        } else {
                            $this->applyOrderFilter($query, $key, $request);
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
                        $this->applyOrderFilter($query, $key, $request);
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
                                    $this->applyOrderFilter($query, $key, $request);
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
            $branchId = (int) (Auth::user()?->branch_id ?? 0);

            // [WAVE-H-2026-05-19 bug_005 heal] Eager-load `orderItems.orderItem`
            // (dotted) so SimpleDeliveryBoyOrderResource::resolveItemsForDriver
            // can read `$line->orderItem?->name` without firing one
            // `SELECT * FROM items WHERE id=?` per order_item line. The
            // resource's `relationLoaded('orderItems')` guard only protected
            // the FIRST hop — the inner belongsTo(Item) was still lazy and
            // produced ~50 extra queries per 10-order × 5-item index page.
            // Guarded by tests/Feature/Sentinels/DeliveryBoyOrderIndexNoN1SentinelTest.
            return Order::with(['transaction', 'orderItems.orderItem', 'branch', 'user'])
                    ->where('order_type', "!=", OrderType::POS)
                    ->where('delivery_boy_id', Auth::user()->id)
                    ->when($branchId > 0, fn ($query) => $query->where('branch_id', $branchId))
                    ->where(
                        function ($query) use ($requests) {
                            foreach ($requests as $key => $request) {
                                if (in_array($key, $this->orderFilter)) {
                                    $this->applyOrderFilter($query, $key, $request);
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
                        'preparation_time' => (int) (Settings::group('order_setup')->get('order_setup_food_preparation_time') ?? 15),
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
                    // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-31 r4] The SSOT branch is
                    // the V1 DEFAULT (pricing.use_ssot_service=true). PricingService
                    // computes a non-zero COUPON discount here from coupon_id; the gate
                    // in the non-SSOT else (line ~519) does NOT cover this path. Without
                    // this call a coupon-discounted web order persists + signs a
                    // fiscally-incorrect NF525 Z at 10% VAT (frozen F1 split). Mirrors
                    // posOrderStore's in-SSOT gate (~813). [round-4 bypass-hunt P0]
                    $this->assertDiscretionaryDiscountAllowed((float) $calculatedDiscount);
                    // [prod-finale 2026-06-17 P3] Trap-safe consistency: this method has zero V1 callers today
                    // (the live web path is FrontendOrderService::myOrderStore, which hydrates), but mirror the
                    // sibling hydrate so a future wiring can't silently persist allergen-blind order_items.
                    $itemsArray = OrderItemAllergenSnapshot::hydrate($itemsArray);
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

                            // [T05] Multi-quantity support: variations may carry optional `quantity` (default 1).
                            $variationTotal = 0;
                            if (isset($item->item_variations) && is_array($item->item_variations)) {
                                foreach ($item->item_variations as $variation) {
                                    $varId = $variation->id ?? null;
                                    if (!$varId) continue;
                                    $dbVar = $dbVariations[$varId] ?? null;
                                    if (!$dbVar) {
                                        throw new \InvalidArgumentException("Variation ID {$varId} introuvable.", 422);
                                    }
                                    $varQuantity = max(1, (int) ($variation->quantity ?? 1));
                                    $variationTotal += (float) $dbVar->price * $varQuantity;
                                }
                            }

                            // [T05] Multi-quantity support: extras may carry optional `quantity` (default 1).
                            $extraTotal = 0;
                            if (isset($item->item_extras) && is_array($item->item_extras)) {
                                foreach ($item->item_extras as $extra) {
                                    $extraId = $extra->id ?? null;
                                    if (!$extraId) continue;
                                    $dbExt = $dbExtras[$extraId] ?? null;
                                    if (!$dbExt) {
                                        throw new \InvalidArgumentException("Extra ID {$extraId} introuvable.", 422);
                                    }
                                    $extraQuantity = max(1, (int) ($extra->quantity ?? 1));
                                    $extraTotal += (float) $dbExt->price * $extraQuantity;
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
                            // [TTC-MODE] config('pricing.tax_inclusive_prices')=true → extract tax from TTC line total.
                            if ((bool) config('pricing.tax_inclusive_prices', false)) {
                                $taxPrice = (new \App\Services\Pricing\TaxCalculator())
                                    ->lineTaxAmountFromTTC((float) $verifiedTotalPrice, (int) $taxType, (float) $taxRate, true);
                            } else {
                                $taxPrice = round($taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100, 2);
                            }

                            // [T07] NF525 immutable composition snapshot — written in same transaction as insert.
                            $compositionSnapshot = (new \App\Services\Pricing\CompositionSnapshotBuilder())->build($item, $dbVariations, $dbExtras);

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
                                'composition_snapshot' => json_encode($compositionSnapshot),
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

                    // [prod-finale 2026-06-17 P3] Trap-safe consistency (dead path; mirror the sibling hydrate).
                    $itemsArray = OrderItemAllergenSnapshot::hydrate($itemsArray);
                    if (!blank($itemsArray)) {
                        OrderItem::insert($itemsArray);
                    }

                    // [AUDIT-FIX P0-1] Coupon recalculation server-side — never trust $request->discount
                    $calculatedDiscount = 0;
                    if ($request->coupon_id > 0) {
                        $coupon = $this->couponService->resolveCouponById(
                            (int) $request->coupon_id,
                            (float) $realSubtotal,
                            (int) Auth::id(),
                            // [abuse-heal 2026-06-18 engines] customer web order → 'web'
                            // surface. Thread branch + surface so a coupon scoped to another
                            // branch/surface (e.g. surfaces=['kiosk']) is rejected here.
                            (int) $this->order->branch_id,
                            'web'
                        );
                        $calculatedDiscount = $this->couponService->calculateDiscountAmount($coupon, (float) $realSubtotal);
                        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Coupon discount
                        // hits the same frozen F1 split at 10% VAT → refuse in V1.
                        $this->assertDiscretionaryDiscountAllowed((float) $calculatedDiscount);
                    }
                }

                $this->saveOrderWithQueueNumber(function () use ($realSubtotal, $totalTax, $calculatedDiscount): void {
                    // [AUDIT-FIX P0] Overwrite all financial fields with server-recalculated values.
                    $this->order->order_serial_no = date('dmy') . $this->order->id;
                    $this->order->subtotal        = $realSubtotal;
                    $this->order->total_tax       = $totalTax;
                    $this->order->discount        = $calculatedDiscount;
                    // [TTC-MODE] In TTC mode, $realSubtotal already contains tax (sum of TTC lines).
                    // Adding $totalTax again would double-count → produce the user-visible
                    // "3€ display becomes 3.60€ payment" bug.
                    if ((bool) config('pricing.tax_inclusive_prices', false)) {
                        $this->order->total = max(0, $realSubtotal + ($this->order->delivery_charge ?? 0) - $calculatedDiscount);
                    } else {
                        $this->order->total = max(0, $realSubtotal + $totalTax + ($this->order->delivery_charge ?? 0) - $calculatedDiscount);
                    }
                }, 'web');

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

                // [F-9 OBS RED-RED1 V1.0.1 quick win — 2026-05-19]
                // Drop the customer name from ActionLog.details (RGPD 5(1)(c)
                // minimisation). user_id is already persisted on this row and
                // is the canonical forensic lookup key.
                // Source: reports/audit/foundation-2026-05-18/round-1/F-9-OBS/STATUS.md §HEAL RED-RED1
                // Sentinel: tests/Feature/Sentinels/ActionLogPiiRedactionSentinelTest.php
                \App\Models\ActionLog::create([
                    'user_id'  => Auth::check() ? Auth::id() : null,
                    'action'   => 'Nouvelle commande Web/App',
                    'resource' => 'Commande #' . $this->order->order_serial_no,
                    'details'  => sprintf(
                        'Total: %s€ | Taxe: %s€ | Remise: %s€',
                        number_format($this->order->total, 2),
                        number_format($totalTax, 2),
                        number_format($calculatedDiscount, 2)
                    ),
                ]);

                // [Wave M / Heal Z2 P1 — 2026-05-19] OrderCreated::dispatch moved
                // INSIDE the closure so the DispatchableAfterCommit trait
                // (`app/Events/Concerns/DispatchableAfterCommit.php:31-39`)
                // engages: transactionLevel()>0 → registers via afterCommit().
                // Net runtime semantics are equivalent to the previous
                // outside-closure pattern (broadcast fires after commit), but
                // they are now ENFORCED by the trait instead of by control
                // flow — locking the rollback-safety guarantee advertised in
                // `app/Events/OrderCreated.php:14-17`. Sentinel:
                // `tests/Feature/Sync/OrderCreatedDispatchPlacementSentinelTest`.
                // Audit reference: RED-Z2 §B P1.
                \App\Events\OrderCreated::dispatch($this->order);
            });

            // Notifications (mail / SMS / push queue jobs) remain post-commit
            // via control flow — their dispatch is dropped if the closure
            // above throws (we never reach this block). The OrderCreated
            // broadcast event is now dispatched INSIDE the closure for
            // afterCommit() enforcement (see Wave M heal note inside).
            try {
                SendOrderMail::dispatch(['order_id' => $this->order->id, 'status' => OrderStatus::PENDING]);
                SendOrderSms::dispatch(['order_id' => $this->order->id, 'status' => OrderStatus::PENDING]);
                SendOrderPush::dispatch(['order_id' => $this->order->id, 'status' => OrderStatus::PENDING]);
                SendOrderGotMail::dispatch(['order_id' => $this->order->id]);
                SendOrderGotSms::dispatch(['order_id' => $this->order->id]);
                SendOrderGotPush::dispatch(['order_id' => $this->order->id]);
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
        //
        // [W9-AUDIT PROD-2] Tenant-scope the lookup: previously the query ignored
        // BranchScope for Admin (branch_id=0), which means the same idempotency key
        // forwarded by two different branches would incorrectly resolve to the first
        // matching order across the whole tenant — leaking an order from branch A
        // to a cashier on branch B as their "duplicate". The intent of idempotency
        // is per-(branch, key), not per-tenant. We resolve the target branch from
        // the request payload (already validated against the cashier's own branch
        // a few lines below for non-admin users), so the lookup is now safe across
        // both Admin (branch_id=0) and cashier flows.
        $idempotencyKey = $request->header('X-Idempotency-Key');
        $idempotencyLock = null;
        if ($idempotencyKey) {
            $targetBranchId = (int) ($request->branch_id ?: 0); // allow: idempotency PROD-2 scoped lookup (not order-create)
            // [AUDIT-F-006 Option A] Cache::lock préventif pour parité stricte POS↔Kiosk
            // (HANDOFF invariant #5 : "POS et Kiosk ont le même comportement face à un
            // duplicate idempotency_key"). Sans ce lock, un double-clic intense génère
            // N INSERT dont N-1 échouent et catch QueryException 23000 → bruit logs +
            // churn DB. Avec le lock, 1 INSERT + N-1 retournent l'existing depuis
            // findExistingOrderForIdempotencyRecovery. Mirror Kiosk myOrderStore:141-145.
            $idempotencyLock = Cache::lock(
                'pos_order_idempotency_' . sha1($targetBranchId . '|' . $idempotencyKey),
                10
            );
            $idempotencyLock->block(5);
            // [H2-HEAL-01] Pass customer_id as $userId so recovery is scoped
            // (branch, customer, key) — closes cross-customer collision case.
            // Empty/0 → null so anonymous walk-ins keep (branch, key) recovery.
            $recoveryCustomerId = ((int) ($request->customer_id ?? 0)) ?: null;
            $existing = $this->findExistingOrderForIdempotencyRecovery($idempotencyKey, $targetBranchId, $recoveryCustomerId);
            if ($existing) {
                $idempotencyLock?->release();
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

                // [GOAL-CAISSE-UNIFIED delta-(B) 2026-05-30] Walk-in → counter
                // collection routing. When pos.walkin_route_to_counter is ON (or
                // the request opts in per-order), the POS walk-in order is created
                // DEFERRED — identical markers to the kiosk Plan B cash-at-counter
                // path (FrontendOrderService:266-267): PENDING_COUNTER +
                // COUNTER_DEFERRED + CASH_ON_DELIVERY. It then joins the unified
                // /admin/encaissement queue and is sealed (PAID + fiscal_sequence_no
                // allocated, gap-free) ONLY via PaymentService::confirmCounterPayment
                // at collection — NEVER here. Default OFF preserves inline-paid POS.
                $deferToCounter = config('pos.walkin_route_to_counter') === true
                    || $request->boolean('defer_to_counter');
                if ($deferToCounter) {
                    $validated['payment_method'] = \App\Enums\PaymentGateway::CASH_ON_DELIVERY;
                    $validated['pos_payment_method'] = \App\Enums\PosPaymentMethod::COUNTER_DEFERRED;
                }

                // [AUDIT-P1-A] Validate branch_id ownership: cashier can only create orders for their own branch.
                // Only a real global Admin (Admin role + branch_id=0) can create orders for any branch.
                $authUser = \Illuminate\Support\Facades\Auth::user();
                $authBranchId = (int) ($authUser->branch_id ?? 0);
                if (! $this->isGlobalAdmin($authUser)
                    && ($authBranchId <= 0 || (int) $request->branch_id !== $authBranchId)) { // allow: defensive branch comparison (not a write)
                    throw new \InvalidArgumentException(
                        'Vous ne pouvez pas créer une commande pour une autre branche.',
                        403
                    );
                }

                // [Wave S-1 — P-OWNER 2026-05-20] POS direct sale (cash or
                // TPE) is always paid + created at the same moment, so the
                // legacy `status = ACCEPT` is the perfect hook for the
                // owner's "auto-prepare on paid" decision. The policy
                // resolves to PREPARING when `pos.auto_prepare_on_paid=true`
                // (default), or ACCEPT when env-overridden to false for
                // emergency rollback. No S-5 exception applies here —
                // POS direct sales never use the kiosk cash-at-counter
                // (counter-deferred) path.
                //
                // Setting the final status at creation avoids an extra
                // save() + UPDATE round-trip and ensures OrderCreated
                // (dispatched at line 1088 inside the same tx) encodes
                // the correct status payload for KDS.
                // [GOAL-CAISSE-UNIFIED delta-(B) 2026-05-30] When deferring to the
                // counter, this is a counter-collect order (kitchen prepares before
                // pay, per W-D1) — same policy input the kiosk cash-at-counter path
                // uses, so the order still auto-promotes to PREPARING.
                $posInitialStatus = AutoPrepareOnPaidPolicy::shouldPromote(
                    surface: 'pos',
                    posPaymentMethod: null,
                    isCounterCollect: $deferToCounter,
                )
                    ? AutoPrepareOnPaidPolicy::nextStatus()
                    : OrderStatus::ACCEPT;

                $this->order = Order::create(
                    $validated + [
                        'user_id' => $request->customer_id,
                        // [H.1 P1 AMBER 2026-05-24 / H2-HEAL-02] NF525 6-year
                        // traceability: orders.user_id stores the customer
                        // (Walking Customer id=2 for anonymous POS), NOT the
                        // cashier. Persist the operator on creator_id so
                        // "which cashier opened order X?" is answerable from
                        // a persisted column (in addition to the audit_logs
                        // 'order.created.pos' event written below for the
                        // tamper-evident HMAC chain).
                        'creator_id' => Auth::check() ? (int) Auth::id() : null,
                        'status' => $posInitialStatus,
                        'token' => $request->token,
                        // [GOAL-CAISSE-UNIFIED delta-(B)] PENDING_COUNTER when the
                        // walk-in is routed to the unified collection queue; PAID
                        // for the legacy inline-paid-at-creation flow (default).
                        'payment_status' => $deferToCounter ? PaymentStatus::PENDING_COUNTER : PaymentStatus::PAID,
                        'order_datetime' => date('Y-m-d H:i:s'),
                        'preparation_time' => (int) (Settings::group('order_setup')->get('order_setup_food_preparation_time') ?? 15),
                        'total'    => 0,
                        'subtotal' => 0,
                        'discount' => 0,
                    ]
                );

                // [Wave S-1] Record the auto-prepare transition for the
                // OrderStatusTransition audit trail. The order was created
                // directly in PREPARING (no intermediate ACCEPT row), so we
                // mirror the conceptual PENDING → ACCEPT → PREPARING ladder
                // by recording PENDING → PREPARING — the same single-row
                // collapsing pattern used at line ~599 of myOrderStore for
                // the auto-accept variant (PENDING → ACCEPT). The reason
                // field tags this as auto-prepare so downstream reconciliation
                // can distinguish from a chef-tap PREPARING. Best-effort:
                // recordTransition swallows its own DB exceptions (see
                // OrderStateMachine line 156-158) and is safe to call here.
                if ($posInitialStatus === OrderStatus::PREPARING) {
                    OrderStateMachine::recordTransition(
                        Order::class,
                        (int) $this->order->id,
                        OrderStatus::PENDING,
                        OrderStatus::PREPARING,
                        Auth::check() ? (int) Auth::id() : null,
                        'auto_prepare_on_paid (Wave S-1 POS direct sale)',
                    );
                }

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
                    if ((int) $request->coupon_id <= 0) {
                        $this->assertPosManualDiscountAllowed(
                            (float) $request->discount,
                            (float) $posSsotPricingResult->subtotal,
                            Auth::user(),
                            (string) $request->discount_reason
                        );
                    }
                    // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Catch the COUPON
                    // discount path too (it skips assertPosManualDiscountAllowed
                    // above). ANY non-zero discount at 10% VAT triggers the frozen
                    // F1 split defect → fiscally-incorrect signed Z. The V1 gate
                    // refuses every discretionary discount source.
                    $this->assertDiscretionaryDiscountAllowed((float) $calculatedDiscount);
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
                            // [T05] Multi-quantity support: variations may carry optional `quantity` (default 1).
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
                            $varQuantity = max(1, (int) ($variation->quantity ?? 1));
                            $variationTotal += (float) $dbVar->price * $varQuantity;
                                }
                            }

                            // [PLAN_02 D-002] Calculer prix extras depuis DB (pas du payload)
                            // [BUG-CRIT-2 FIX] Utiliser $dbExtras bulk-loadé au lieu de find() dans la boucle
                            // [T05] Multi-quantity support: extras may carry optional `quantity` (default 1).
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
                            $extraQuantity = max(1, (int) ($extra->quantity ?? 1));
                            $extraTotal += (float) $dbExt->price * $extraQuantity;
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
                            // [TTC-MODE] config('pricing.tax_inclusive_prices')=true → extract tax from TTC line total.
                            if ((bool) config('pricing.tax_inclusive_prices', false)) {
                                $taxPrice = (new \App\Services\Pricing\TaxCalculator())
                                    ->lineTaxAmountFromTTC((float) $verifiedTotalPrice, (int) $taxType, (float) $taxRate, true);
                            } else {
                                $taxPrice = round($taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100, 2);
                            }
                            
                            // [T07] NF525 immutable composition snapshot — written in same transaction as insert.
                            $compositionSnapshot = (new \App\Services\Pricing\CompositionSnapshotBuilder())->build($item, $dbVariations, $dbExtras);

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
                                'composition_snapshot' => json_encode($compositionSnapshot),
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
                            (int) ($request->customer_id ?? 0),
                            // [abuse-heal 2026-06-18 engines] thread branch + surface so a
                            // coupon scoped to another branch/surface (e.g. surfaces=['kiosk'])
                            // is rejected on POS instead of silently applied (revenue leak).
                            (int) $this->order->branch_id,
                            'pos'
                        );
                        $calculatedDiscount = $this->couponService->calculateDiscountAmount($coupon, (float) $realSubtotal);
                        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Coupon discount
                        // → same frozen F1 split at 10% VAT → refuse in V1.
                        $this->assertDiscretionaryDiscountAllowed((float) $calculatedDiscount);
                    } elseif ($request->discount > 0) {
                        // [AUDIT-FIX P1-3] Manual cashier discount — validated server-side, will be logged below
                        $manualDiscount = (float) $request->discount;
                        $this->assertPosManualDiscountAllowed(
                            $manualDiscount,
                            (float) $realSubtotal,
                            Auth::user(),
                            (string) $request->discount_reason
                        );
                        if ($manualDiscount <= $realSubtotal) {
                            $calculatedDiscount = $manualDiscount;
                        }
                        // Si discount > subtotal, on ignore (pas de total négatif)
                    }
                }

                $this->saveOrderWithQueueNumber(function () use ($request, $posSsotPricingResult, $totalTax, $realSubtotal, $calculatedDiscount, $idempotencyKey): void {
                    $this->order->order_serial_no = date('dmy') . $this->order->id;
                    if ($posSsotPricingResult instanceof PricingResult) {
                        $this->order->total_tax = $posSsotPricingResult->totalTax;
                        $this->order->subtotal = $posSsotPricingResult->subtotal;
                        $this->order->discount = $posSsotPricingResult->discount;
                        $this->order->total = $posSsotPricingResult->total;
                    } else {
                        $this->order->total_tax = round($totalTax, 2);
                        $this->order->subtotal = round($realSubtotal, 2);
                        $this->order->discount = $calculatedDiscount;
                        // [TTC-MODE] In TTC mode, $realSubtotal already contains tax.
                        if ((bool) config('pricing.tax_inclusive_prices', false)) {
                            $this->order->total = round(max(0, $realSubtotal + ($this->order->delivery_charge ?? 0) - $calculatedDiscount), 2);
                        } else {
                            $this->order->total = round(max(0, $realSubtotal + $totalTax + ($this->order->delivery_charge ?? 0) - $calculatedDiscount), 2);
                        }
                    }

                    app(OrderQuoteService::class)->sealForCommit(
                        $request,
                        'pos',
                        (int) $this->order->id,
                        (float) $this->order->total
                    );

                    app(\App\Services\Stock\StockService::class)->decrementForOrder($this->order, $idempotencyKey);

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
                    //
                    // [GOAL-CAISSE-UNIFIED delta-(B) 2026-05-30] DEFER the allocation
                    // for the counter-collect path: a deferred walk-in (PENDING_COUNTER
                    // + COUNTER_DEFERRED) is NOT a sale yet, so it must NOT consume a
                    // fiscal number here — the seq is allocated once, at collection,
                    // by PaymentService::confirmCounterPayment (mirrors kiosk Plan B).
                    // Allocating here would burn a number for a sale that may be
                    // cancelled before payment → NF525 gap. Recomputed locally from
                    // the same config/request signal used above (inner closure).
                    $deferToCounterFiscal = config('pos.walkin_route_to_counter') === true
                        || $request->boolean('defer_to_counter');
                    if (! $deferToCounterFiscal) {
                        $this->order->fiscal_sequence_no = app(FiscalSequenceService::class)
                            ->next((int) $this->order->branch_id);
                    }
                }, 'pos');

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
                        'action'      => OrderDiscountLog::ACTION,
                        'resource'    => 'order',
                        'resource_id' => (int) $this->order->id,
                        'payload'     => [
                            'order_serial_no'    => $this->order->order_serial_no,
                            'actor_id'           => Auth::check() ? (int) Auth::id() : null,
                            'coupon_id'          => $request->coupon_id > 0 ? (int) $request->coupon_id : null,
                            'discount_reason'    => $request->coupon_id > 0 ? null : trim((string) $request->discount_reason),
                            'requested_discount' => round((float) $request->discount, 2),
                            'discount_amount'    => round((float) $calculatedDiscount, 2),
                            'discount_type'      => $request->coupon_id > 0 ? 'coupon' : 'manual_cashier',
                            'subtotal_before'    => round((float) $realSubtotal, 2),
                            'backend_subtotal'   => round((float) $realSubtotal, 2),
                            'total_after'        => round((float) $this->order->total, 2),
                        ],
                    ]);
                }

                // [H.1 P1 AMBER 2026-05-24 / H2-HEAL-02] NF525 6-year traceability:
                // append cashier attribution event on the HMAC chain. Phase H.1
                // audit caught audit_logs delta=0 after POS order create POSTs —
                // making it impossible to answer "which cashier opened order X?"
                // tamper-evidently. Written INSIDE the same DB::transaction as
                // Order::create + OrderItem::insert so either everything commits
                // (order + chain row in lockstep) or everything rolls back. Same
                // call-site shape as the discount audit above. Frozen AuditLogService
                // is called via the public write() API — its code is unchanged.
                app(AuditLogService::class)->write([
                    'branch_id'   => (int) $this->order->branch_id,
                    'user_id'     => Auth::check() ? (int) Auth::id() : null,
                    'action'      => 'order.created.pos',
                    'resource'    => 'order',
                    'resource_id' => (int) $this->order->id,
                    'payload'     => [
                        'order_id'           => (int) $this->order->id,
                        'order_serial_no'    => (string) $this->order->order_serial_no,
                        'cashier_id'         => Auth::check() ? (int) Auth::id() : null,
                        'cashier_name'       => Auth::check() ? (string) (Auth::user()->name ?? '') : null,
                        'branch_id'          => (int) $this->order->branch_id,
                        'customer_id'        => (int) ($this->order->user_id ?? 0),
                        'order_type'         => (int) $this->order->order_type,
                        'pos_payment_method' => (int) ($this->order->pos_payment_method ?? 0),
                        'payment_status'     => (int) $this->order->payment_status,
                        'status'             => (int) $this->order->status,
                        'total'              => round((float) $this->order->total, 2),
                        'subtotal'           => round((float) $this->order->subtotal, 2),
                        'discount'           => round((float) $this->order->discount, 2),
                    ],
                ]);

                // [F-SPLIT-PAYMENT-001] Persist multi-tender breakdown when provided.
                //
                // - Strictly additive : legacy `pos_payment_method` + `pos_received_amount`
                //   restent autoritaires pour les receipts/reports tant que le flag est OFF.
                // - Tourne à L'INTÉRIEUR de la `DB::transaction` parente : si le service
                //   throw une `ValidationException` (somme < total ou tranche malformée),
                //   la création de la commande est rollback (atomicité).
                // - Le total utilisé pour la validation est `$this->order->total` (SSOT
                //   serveur), JAMAIS la valeur client.
                $breakdown = (array) $request->input('payment_breakdown', []);
                $splitActive = ! empty($breakdown) && config('split_payment.enabled', false);
                if ($splitActive) {
                    app(\App\Services\Payments\SplitPaymentService::class)
                        ->persistTranches($this->order, $breakdown);
                }

                // [Sprint 1B 2026-05-16] NF525 cash trail — write cash_movement
                // INSIDE the same DB::transaction, AFTER fiscal_sequence_no is
                // allocated by saveOrderWithQueueNumber. Strict mode = throw
                // CashDrawerSessionNotOpenException → rollback order + 0
                // movement if cashier has no open session.
                //
                // Three paths:
                //   - split active + at least one CASH tranche → SplitPaymentService
                //     already wrote one movement per CASH tranche.
                //   - split active + no CASH tranche → 0 movement here.
                //   - legacy single-tender, pos_payment_method=CASH → 1 movement
                //     for $order->total here.
                //
                // Kiosk counter-collect path (PaymentService::confirmCounterPayment)
                // is untouched — still calls recordCashOrderMovement($strict=false).
                if (! $splitActive
                    && (int) $request->pos_payment_method === \App\Enums\PosPaymentMethod::CASH) {
                    app(\App\Services\PaymentService::class)->recordCashOrderMovement(
                        order: $this->order,
                        note: null,
                        strict: true,
                    );
                }

                $order = $this->order;

                // [Wave M / Heal Z2 P1 — 2026-05-19] OrderCreated::dispatch moved
                // INSIDE the closure so DispatchableAfterCommit engages
                // (transactionLevel()>0 → afterCommit). On rollback the
                // broadcast is dropped — KDS never observes a ghost order
                // (POS cash close path). Sentinel:
                // `tests/Feature/Sync/OrderCreatedDispatchPlacementSentinelTest`.
                \App\Events\OrderCreated::dispatch($order);
            });

            // Notifications (mail / SMS / push queue jobs) remain post-commit
            // via control flow. OrderCreated broadcast was moved INSIDE the
            // closure (Wave M heal) for trait-mediated afterCommit().
            if ($order) {
                try {
                    SendOrderGotMail::dispatch(['order_id' => $order->id]);
                    SendOrderGotSms::dispatch(['order_id' => $order->id]);
                    SendOrderGotPush::dispatch(['order_id' => $order->id]);
                } catch (\Exception $e) {
                    Log::warning('Notification KDS échouée pour order #' . $order->id . ': ' . $e->getMessage());
                }
                // [MEGA 2.J / F-16] Dine-in: free floorplan table when this order is paid
                // and still holds the table. SYMMETRY_NOTE: kiosk has no parallel dine-in
                // table bind — FrontendOrderService unchanged.
                try {
                    app(DiningTableService::class)->tryReleaseTableAfterPosOrderPaid($order);
                } catch (\Throwable $e) {
                    Log::warning('[posOrderStore] floorplan table release: ' . $e->getMessage());
                }
            }
            
            return $this->order;
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (HttpException $exception) {
            throw $exception;
        } catch (\Illuminate\Database\QueryException $qe) {
            // [AUDIT-52-BUG6] Catch MySQL duplicate key (23000) on idempotency_key UNIQUE constraint.
            // This handles the race condition where two simultaneous requests both pass the pre-check
            // (both see NULL) but the second INSERT hits the DB-level unique constraint.
            // Return the existing order gracefully instead of a 500 error.
            if ($qe->getCode() === '23000' && $idempotencyKey) {
                $targetBranchId = (int) ($request->branch_id ?: 0); // allow: idempotency recovery scope only
                // [H2-HEAL-01] Pass customer_id as $userId — matches the new
                // (branch_id, user_id, idempotency_key) UNIQUE so the race
                // recovery resolves to the correct row when a different
                // customer collided on the same key.
                $recoveryCustomerId = ((int) ($request->customer_id ?? 0)) ?: null;
                $existing = $this->findExistingOrderForIdempotencyRecovery($idempotencyKey, $targetBranchId, $recoveryCustomerId);
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
        // [GOAL-2026-05-29 SEC-P1 QR-DISCOUNT] The table-order endpoint
        // (POST /api/.../dining-order/) is UNAUTHENTICATED (QR code, apiKey only).
        // The POS paths gate manual discounts via assertPosManualDiscountAllowed
        // (which fails-closed without an authenticated staff user), but the table
        // path had NO such gate — an anonymous customer could self-apply an
        // arbitrary manual discount up to 100% of subtotal (pricing-SSOT
        // authorization bypass; the under-priced total can reach the signed
        // NF525 Z-report if the order is later counter-paid). A QR customer can
        // never authorize a staff discount, so we neutralize the request-supplied
        // manual discount at the source. Coupons (coupon_id, server-validated by
        // CouponService) are unaffected — only the staff-only manual discount.
        $request->merge(['discount' => 0]);

        try {
            DB::transaction(function () use ($request) {
                $validated = $request->validated();
                unset($validated['total'], $validated['subtotal'], $validated['discount']);

                $this->order = FrontendOrder::create(
                    $validated + [
                        'user_id' => $request->customer_id,
                        'status' => OrderStatus::PENDING,
                        'order_datetime' => date('Y-m-d H:i:s'),
                        'preparation_time' => (int) (Settings::group('order_setup')->get('order_setup_food_preparation_time') ?? 15),
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
                    // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-31 r4] SSOT branch = V1
                    // DEFAULT. The manual discount is zeroed at method entry, but a
                    // COUPON discount (coupon_id) is still computed here and was NOT
                    // gated — the gate at line ~1514 lives in the non-SSOT else. Refuse
                    // it so a coupon-discounted table order cannot sign a fiscally-
                    // incorrect Z. Mirrors posOrderStore's in-SSOT gate (~813). [round-4 P0]
                    $this->assertDiscretionaryDiscountAllowed((float) $calculatedDiscount);
                    // [prod-finale 2026-06-17 P1 food-safety] Freeze the allergen snapshot on the QR-table
                    // dine-in path too. posOrderStore (:852) and FrontendOrderService hydrate before insert;
                    // tableOrderStore did NOT in either branch, so KDS rendered gluten/allergen items with NO
                    // warning line / no orange border for table orders. Mirror the POS sibling exactly.
                    $itemsArray = OrderItemAllergenSnapshot::hydrate($itemsArray);
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
                            // [T05] Multi-quantity support: variations may carry optional `quantity` (default 1).
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
                                    $varQuantity = max(1, (int) ($var->quantity ?? 1));
                                    $calcVariationTotal += (float) $dbVar->price * $varQuantity;
                                }
                            }

                            // [BUG-CRIT-2 FIX] Utiliser $dbExtras bulk-loadé au lieu de find() dans la boucle
                            // [T05] Multi-quantity support: extras may carry optional `quantity` (default 1).
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
                                    $extraQuantity = max(1, (int) ($ext->quantity ?? 1));
                                    $calcExtraTotal += (float) $dbExt->price * $extraQuantity;
                                }
                            }

                            $verifiedQuantity = max(1, (int) ($item->quantity ?? 1));
                            $verifiedTotalPrice = ($itemPrice + $calcVariationTotal + $calcExtraTotal) * $verifiedQuantity;
                            $realSubtotal += $verifiedTotalPrice;

                            $taxId = isset($items[$item->item_id]) ? $items[$item->item_id] : 0;
                            $taxName = isset($taxes[$taxId]) ? $taxes[$taxId]->name : null;
                            $taxRate = isset($taxes[$taxId]) ? $taxes[$taxId]->tax_rate : 0;
                            $taxType = isset($taxes[$taxId]) ? $taxes[$taxId]->type : TaxType::FIXED;
                            // [TTC-MODE] config('pricing.tax_inclusive_prices')=true → extract tax from TTC line total.
                            if ((bool) config('pricing.tax_inclusive_prices', false)) {
                                $taxPrice = (new \App\Services\Pricing\TaxCalculator())
                                    ->lineTaxAmountFromTTC((float) $verifiedTotalPrice, (int) $taxType, (float) $taxRate, true);
                            } else {
                                $taxPrice = round($taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100, 2);
                            }

                            // [T07] NF525 immutable composition snapshot — written in same transaction as insert.
                            $compositionSnapshot = (new \App\Services\Pricing\CompositionSnapshotBuilder())->build($item, $dbVariations, $dbExtras);

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
                                'composition_snapshot' => json_encode($compositionSnapshot),
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

                    // [prod-finale 2026-06-17 P1 food-safety] hydrate the allergen snapshot on the legacy
                    // (non-SSOT) table branch too — same fix as the SSOT branch above + posOrderStore:852.
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
                            (int) ($request->customer_id ?? 0),
                            // [abuse-heal 2026-06-18 engines] QR dine-in table order is an
                            // in-house counter surface → 'pos'. Pass branch + surface so a
                            // coupon scoped elsewhere (e.g. surfaces=['kiosk']) is rejected.
                            (int) $this->order->branch_id,
                            'pos'
                        );
                        $calculatedDiscount = $this->couponService->calculateDiscountAmount($coupon, (float) $realSubtotal);
                        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Coupon discount
                        // → same frozen F1 split at 10% VAT → refuse in V1.
                        $this->assertDiscretionaryDiscountAllowed((float) $calculatedDiscount);
                    } elseif ($request->discount > 0) {
                        // [AUDIT-FIX P1-3] Manual cashier discount — validated server-side, will be logged below
                        $manualDiscount = (float) $request->discount;
                        if ($manualDiscount <= $realSubtotal) {
                            $calculatedDiscount = $manualDiscount;
                        }
                        // Si discount > subtotal, on ignore (pas de total négatif)
                    }
                }

                $this->saveOrderWithQueueNumber(function () use ($tableSsotPricingResult, $totalTax, $realSubtotal, $calculatedDiscount): void {
                    $this->order->order_serial_no = date('dmy') . $this->order->id;
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
                        // [TTC-MODE] In TTC mode, $realSubtotal already contains tax.
                        if ((bool) config('pricing.tax_inclusive_prices', false)) {
                            $this->order->total = max(0, $realSubtotal + ($this->order->delivery_charge ?? 0) - $calculatedDiscount);
                        } else {
                            $this->order->total = max(0, $realSubtotal + $totalTax + ($this->order->delivery_charge ?? 0) - $calculatedDiscount);
                        }
                    }

                    $currentTime = Carbon::now();
                    $endTime = $currentTime->copy()->addMinutes(Settings::group('order_setup')->get('order_setup_schedule_order_slot_duration'));
                    $start = $currentTime->format('H:i');
                    $end = $endTime->format('H:i');
                    $this->order->delivery_time = "$start - $end";
                }, 'table');

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

                // [abuse-2026-06-17 P2 — QR-table oversell] In-transaction stock decrement, mirroring
                // posOrderStore:1066 and the kiosk path (FrontendOrderService). Without this the QR-table
                // path created the order, then the POST-COMMIT OrderCreated listeners attempted the decrement
                // and SWALLOWED StockUnavailableException (log + no-op) → the order stood while stock was NOT
                // decremented = silent oversell. Decrementing IN-TX makes an oversell throw and roll back the
                // whole QR order atomically (no ghost order, no oversell). Display/quota-neutral when stock
                // is untracked (decrementForOrder is a no-op for items with no StockLevel).
                app(\App\Services\Stock\StockService::class)->decrementForOrder($this->order, $this->order->idempotency_key);

                // [Wave M / Heal Z2 P1 — 2026-05-19] OrderCreated::dispatch moved
                // INSIDE the closure so DispatchableAfterCommit engages
                // (transactionLevel()>0 → afterCommit). On rollback the
                // broadcast is dropped — KDS never observes a ghost order
                // (dine-in QR path). Sentinel:
                // `tests/Feature/Sync/OrderCreatedDispatchPlacementSentinelTest`.
                \App\Events\OrderCreated::dispatch($this->order);
            });

            // Notifications (mail / SMS / push queue jobs) remain post-commit
            // via control flow. OrderCreated broadcast was moved INSIDE the
            // closure (Wave M heal) for trait-mediated afterCommit().
            try {
                SendOrderGotMail::dispatch(['order_id' => $this->order->id]);
                SendOrderGotSms::dispatch(['order_id' => $this->order->id]);
                SendOrderGotPush::dispatch(['order_id' => $this->order->id]);
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
                $this->assertOrderBranchVisible($order);
                return $order;
            }
        } catch (HttpException $exception) {
            throw $exception;
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
            $user = Auth::user();
            $userBranchId = (int) ($user?->branch_id ?? 0);
            if ($order->delivery_boy_id == $user?->id
                && ($userBranchId <= 0 || (int) $order->branch_id === $userBranchId)) {
                return $order;
            } else {
                abort(403, 'Access denied: you do not have permission to access this order.');
            }
        } catch (HttpException $exception) {
            throw $exception;
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
        } catch (HttpException $exception) {
            throw $exception;
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
            $branchId = (int) (Auth::user()?->branch_id ?? 0);
            $orderCountArray = [];
            $orderCountArray['total_delivered'] = $order->newQuery()
                ->where(['delivery_boy_id' => Auth::user()->id, 'status' => OrderStatus::DELIVERED])
                ->when($branchId > 0, fn ($query) => $query->where('branch_id', $branchId))
                ->count();
            $orderCountArray['total_returned'] = $order->newQuery()
                ->where(['delivery_boy_id' => Auth::user()->id, 'status' => OrderStatus::RETURNED])
                ->when($branchId > 0, fn ($query) => $query->where('branch_id', $branchId))
                ->count();

            return $orderCountArray;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function deliveryBoyOrderChangeStatus(Order $order, Request $request): Order
    {
        try {
            // [FIX-54-1] Ownership check — same as deliveryBoyOrderDetails()
            $user = Auth::user();
            if ($order->delivery_boy_id != $user?->id) {
                abort(403, 'Access denied: this order is not assigned to you.');
            }

            $userBranchId = (int) ($user?->branch_id ?? 0);
            if ($userBranchId > 0 && (int) $order->branch_id !== $userBranchId) {
                abort(403, 'Access denied: this order is outside your branch.');
            }

            // [FIX-54-1] Enforce valid state machine transitions
            if (!(new \App\Rules\ValidStatusTransition($order->status))->passes('status', $request->status)) {
                throw new Exception(trans('all.message.invalid_status_transition'), 422);
            }

            $oldStatus  = (int) $order->status;
            $newStatus  = (int) $request->status;

            // [POS-9.1.7] Wrap mutations in DB::transaction so a partial failure
            // (save / state-machine / payment_status flip) rolls back atomically.
            // [ultra-goal A5 heal 2026-05-13] Add lockForUpdate (BRAIN P0-12
            // family — legacy delivery-boy caller previously raced with
            // changeStatus and admin updates, duplicating audit rows and
            // corrupting state machine. Mirrors the locked pattern at line
            // 1549-1568 (changeStatus) and OrderStateMachine::apply:185-210.).
            // Notifications + OrderStatusChanged broadcast are deferred to
            // afterCommit so listeners (OSS, KDS, loyalty) never observe a
            // half-written state nor fire if the transaction rolls back.
            //
            // [GOAL-2026-05-18 P0-LIV-02 / P0-LIV-03] Cash collection at the
            // doorstep MUST leave an NF525 audit_log trail, and a corrupt
            // `payment_method` MUST NOT silently flip the row to PAID (double
            // charge risk). Both guards execute INSIDE the locked transaction
            // so the row read is the truth-of-record and the audit row commits
            // atomically with the status mutation.
            $cashEscrowMeta    = null;
            DB::transaction(function () use (&$order, $oldStatus, $newStatus, &$cashEscrowMeta) {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                // Idempotent: if a concurrent caller already applied the
                // target status, exit silently (no double recordTransition).
                if ((int) $locked->status === (int) $newStatus) {
                    $order = $locked;
                    return;
                }

                // [GOAL-2026-05-29 F3] Race guard: re-validate the transition against
                // the FRESH locked status. The pre-lock check (line 1670) used the
                // possibly-stale $order->status; a concurrent transition could have
                // moved the row, making the originally-valid edge illegal.
                if (!(new \App\Rules\ValidStatusTransition((int) $locked->status))->passes('status', $newStatus)) {
                    throw new Exception(trans('all.message.invalid_status_transition'), 422);
                }

                // [abuse-heal 2026-06-19 deliv-admin-twin] COD/late-card PAID flip +
                // fiscal allocation, extracted to a SHARED method now also reused by
                // the admin changeStatus twin. Behaviour-equivalent to the prior inline
                // block (WH-2 bug_002 anchor logic + GENIE Wave0 FISCAL-DELIV-COD-01
                // fiscal alloc) — returns the cash-escrow meta to record post-commit.
                $cashEscrowMeta = $this->finalizeDeliveryPaymentInTx($locked, $newStatus);

                $locked->status = $newStatus;
                $locked->save();

                OrderStateMachine::recordTransition(
                    Order::class,
                    (int) $locked->id,
                    $oldStatus,
                    $newStatus,
                    Auth::check() ? (int) Auth::id() : null,
                    null
                );

                $order = $locked;
            });

            // [abuse-heal 2026-06-19 deliv-admin-twin] COD cash-collection NF525 audit
            // row + DeliveryBoyCashMovement, extracted to a SHARED post-commit method
            // now also reused by the admin changeStatus twin. Best-effort inside.
            if (is_array($cashEscrowMeta)) {
                $this->recordDeliveryCashEscrowPostCommit($cashEscrowMeta);
            }

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
     * [abuse-heal 2026-06-19 deliv-admin-twin] SHARED delivery-payment finalization,
     * executed INSIDE a locked DB::transaction by BOTH the driver-app path
     * (deliveryBoyOrderChangeStatus) and the admin path (changeStatus). Extracted
     * verbatim from the driver path so that path stays behaviour-equivalent (its
     * existing tests must remain green) while the admin twin gains the same
     * NF525-correct semantics instead of leaving COD orders off-book.
     *
     * On the transition into the given $newStatus it:
     *  - flips an UNPAID, untransacted order to PAID — for COD only AT DELIVERED
     *    (the legal cash-collection anchor), for non-COD methods that somehow
     *    reached the driver still UNPAID at OFD-or-DELIVERED (legacy late-card);
     *  - allocates the next gap-free fiscal_sequence_no (idempotent — skipped if
     *    already allocated) so a PAID order can never escape the Z; the alloc runs
     *    in the caller's locked tx so an alloc failure rolls the whole transition
     *    back (never an off-book PAID);
     *  - returns the cash-collection escrow meta for COD-at-DELIVERED (consumed
     *    post-commit by recordDeliveryCashEscrowPostCommit), or null otherwise.
     *
     * MUST be called only after the row is locked (lockForUpdate) and only for
     * DELIVERY orders — the caller is responsible for that scoping.
     *
     * @param  Order  $locked     the row already fetched under lockForUpdate
     * @param  int    $newStatus  the target status being applied in this tx
     * @return array|null         escrow meta to record post-commit, or null
     */
    private function finalizeDeliveryPaymentInTx(Order $locked, int $newStatus): ?array
    {
        $cashEscrowMeta = null;

        $transaction = Transaction::where('order_id', $locked->id)->first();
        $wasUnpaidCash = (! $transaction)
            && (int) $locked->payment_status === (int) PaymentStatus::UNPAID;

        if ($wasUnpaidCash) {
            // [P0-LIV-03] Guard payment_method against silent double-charge.
            // Only the recognised PaymentGateway constants (1..5) may
            // trigger the auto-flip to PAID. A corrupt / null / out-of-
            // range value is treated as a data-integrity failure and the
            // whole transition aborts (HttpException 422 so the caller
            // surfaces the actual problem instead of silently corrupting
            // financials).
            $pm = $locked->payment_method;
            $allowed = [
                \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
                \App\Enums\PaymentGateway::E_WALLET,
                \App\Enums\PaymentGateway::PAYPAL,
                \App\Enums\PaymentGateway::CARD,
                \App\Enums\PaymentGateway::TICKET_RESTAURANT,
            ];
            if ($pm === null || ! is_numeric($pm) || ! in_array((int) $pm, $allowed, true)) {
                abort(422, 'Refusing to auto-mark order PAID: payment_method is missing or out of the allowed enum range.');
            }

            // [WH-2 bug_002 / 2026-05-19] For CASH_ON_DELIVERY we delay the
            // PAID flip until newStatus === DELIVERED — the legally-correct
            // anchor (cash collected at the doorstep, not at pickup) — so the
            // escrow audit row downstream fires on the SAME locked transaction.
            // Non-COD methods preserve the legacy flip-at-OFD-or-DELIVERED.
            $isCod = ((int) $pm === (int) \App\Enums\PaymentGateway::CASH_ON_DELIVERY);
            $atDelivered = ((int) $newStatus === (int) OrderStatus::DELIVERED);

            if ($isCod) {
                if ($atDelivered) {
                    $locked->payment_status = PaymentStatus::PAID;

                    // [P0-LIV-02] Record the cash-collection event for NF525.
                    // The escrow row is the audit-trail anchor between
                    // "collected at doorstep" and "deposited at branch".
                    //
                    // [abuse-heal 2026-06-19 deliv-admin-twin] The cash custodian is the
                    // order's ASSIGNED delivery_boy_id (who physically holds the cash),
                    // not the acting user. On the driver-app path these are guaranteed
                    // identical (deliveryBoyOrderChangeStatus aborts 403 unless
                    // delivery_boy_id === Auth::id()), so this is byte-equivalent there;
                    // on the admin path the operator (admin) is NOT the cash holder, so
                    // the escrow + DeliveryBoyCashMovement must be attributed to the
                    // driver's session. Fallback to Auth::id() only for an unassigned order.
                    $cashHolderId = (int) ($locked->delivery_boy_id ?? 0);
                    if ($cashHolderId <= 0) {
                        $cashHolderId = Auth::check() ? (int) Auth::id() : 0;
                    }
                    $cashEscrowMeta = [
                        'branch_id' => (int) $locked->branch_id,
                        'order_id'  => (int) $locked->id,
                        'driver_id' => $cashHolderId > 0 ? $cashHolderId : null,
                        'amount'    => round((float) $locked->total, 2),
                        'serial'    => $locked->order_serial_no,
                    ];
                }
                // CASH_ON_DELIVERY at OFD: leave payment_status = UNPAID. The
                // flip + audit row will fire on the next call (OFD → DELIVERED).
            } else {
                // Non-COD methods preserve the legacy flip semantics
                // (flip at OFD-or-DELIVERED, no escrow row).
                $locked->payment_status = PaymentStatus::PAID;
            }
        }

        // [GENIE Wave0 FISCAL-DELIV-COD-01 / -LATECARD-01 2026-06-16] NF525 exhaustivity,
        // sibling of FISCAL-CPS-01. The PAID flip above must never leave the order off-book:
        // allocate the fiscal sequence here, INSIDE the caller's locked tx so an alloc
        // failure rolls the whole transition back, idempotent on a pre-allocated order.
        if ((int) $locked->payment_status === PaymentStatus::PAID
            && $locked->fiscal_sequence_no === null
        ) {
            $locked->fiscal_sequence_no = app(FiscalSequenceService::class)->next((int) $locked->branch_id);
        }

        return $cashEscrowMeta;
    }

    /**
     * [abuse-heal 2026-06-19 deliv-admin-twin] SHARED post-commit recording of the
     * COD cash-collection — NF525 audit row + DeliveryBoyCashMovement — called by
     * BOTH the driver-app and admin paths AFTER their locked transaction commits,
     * when finalizeDeliveryPaymentInTx returned escrow meta. Extracted verbatim from
     * the driver path. Best-effort: an audit / cash-session failure is logged but
     * never cascades into a billing rollback (the transition already committed).
     */
    private function recordDeliveryCashEscrowPostCommit(array $cashEscrowMeta): void
    {
        // [P0-LIV-02] Append AFTER the transaction commits so the chain
        // tail is read against the persisted state. AuditLogService has
        // its own Cache::lock + DB::transaction inside write() (see
        // POS-9-H.2.2 / F-C3 doc-block), so calling it post-commit
        // keeps the HMAC chain ordering deterministic.
        try {
            app(AuditLogService::class)->write([
                'branch_id'   => $cashEscrowMeta['branch_id'],
                'user_id'     => $cashEscrowMeta['driver_id'],
                'action'      => 'delivery.cash_collected_escrow',
                'resource'    => 'order',
                'resource_id' => $cashEscrowMeta['order_id'],
                'payload'     => [
                    'order_id'           => $cashEscrowMeta['order_id'],
                    'order_serial_no'    => $cashEscrowMeta['serial'],
                    'amount_collected'   => $cashEscrowMeta['amount'],
                    'delivery_boy_id'    => $cashEscrowMeta['driver_id'],
                    'payment_method'     => (int) \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
                    'collected_at'       => now()->toIso8601String(),
                    'event'              => 'doorstep_cash_collection',
                ],
            ]);
        } catch (\Throwable $auditError) {
            // Never let an audit write error cascade into a billing
            // rollback — NF525 chain breakage is surfaced via
            // verifyChain() + ops alerting, not via a customer-facing
            // 5xx mid-delivery. Log + continue.
            Log::warning('[DeliveryBoy] cash-collection audit_log write failed: ' . $auditError->getMessage(), [
                'order_id' => $cashEscrowMeta['order_id'],
            ]);
        }

        // [R2-P1-LIV-DELIVERED-HOOK 2026-05-28] Mirror cash-collection
        // into DeliveryBoyCashSession when driver has an open shift.
        // ZReportCashEnrichmentService:489 cross-checks audit_logs
        // action='cash.delivery.movement.recorded' against
        // delivery_boy_cash_movements rows; without this call the
        // count_mismatch / movement_missing_audit_row drift surfaces
        // on every COD DELIVERED. Best-effort (non-strict) — if no
        // open shift, skip silently so DELIVERED stays unblocked.
        try {
            if (! empty($cashEscrowMeta['driver_id'])) {
                $svc = app(\App\Services\Delivery\DeliveryBoyCashSessionService::class);
                $openSession = $svc->findOpenSessionForDeliveryBoy(
                    (int) $cashEscrowMeta['branch_id'],
                    (int) $cashEscrowMeta['driver_id'],
                );
                if ($openSession) {
                    $svc->recordMovement(
                        (int) $openSession->id,
                        \App\Models\DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
                        (float) $cashEscrowMeta['amount'],
                        \App\Models\DeliveryBoyCashMovement::DIRECTION_IN,
                        (int) $cashEscrowMeta['order_id'],
                        null,
                        false,
                    );
                }
            }
        } catch (\Throwable $movementError) {
            // [R3-RD-03 2026-05-28] Severity bumped warning→error per
            // RED-team dispute: 422 race (session closed between find
            // + recordMovement) silently drifts audit_logs vs
            // delivery_boy_cash_movements. ZReportCashEnrichmentService
            // cross-check surfaces it end-of-day; error log + payload
            // give ops earlier signal without blocking DELIVERED.
            Log::error('[DeliveryBoy] cash-session recordMovement drift (non-blocking): ' . $movementError->getMessage(), [
                'order_id'  => $cashEscrowMeta['order_id'],
                'driver_id' => $cashEscrowMeta['driver_id'],
                'branch_id' => $cashEscrowMeta['branch_id'],
                'amount'    => $cashEscrowMeta['amount'],
            ]);
        }
    }

    /**
     * [abuse-heal 2026-06-19 livreur FINDING-1] Reverse a driver-collected COD when
     * the order is later RETURNED (the fiscal counter-entry / refund transition).
     *
     * The collect path (recordDeliveryCashEscrowPostCommit) recorded a
     * TYPE_ORDER_COLLECT / IN movement into the driver's open session. Without a
     * compensating entry the session keeps showing that cash as owed → false
     * shortage (the driver looks like they pocketed the refunded cash). This records
     * a TYPE_ADJUSTMENT / DIRECTION_OUT of the exact collected amount into the SAME
     * session so Σ(movements) for that order nets to 0.
     *
     * Keyed off the ACTUAL recorded IN movement (not merely "COD + RETURNED"):
     *   - if no order_collect IN movement exists for the order (e.g. delivered with
     *     no open session), there is nothing to reverse → no-op;
     *   - idempotent: a reversal is skipped if an adjustment OUT already exists for
     *     that order in the session, so a repeated RETURNED never doubles it.
     *
     * Best-effort post-commit, mirroring the collect path: a failure here is logged
     * but never cascades into a refund rollback (the RETURNED already committed).
     */
    private function reverseDeliveryCashCollectPostCommit(int $orderId, int $branchId): void
    {
        try {
            $svc = app(\App\Services\Delivery\DeliveryBoyCashSessionService::class);

            // Find the original collection IN movement(s) for this order. The order
            // carries the session id, so we reverse into the exact session that
            // received the cash (which may differ from the driver's current shift).
            $collected = \App\Models\DeliveryBoyCashMovement::query()
                ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('order_id', $orderId)
                ->where('type', \App\Models\DeliveryBoyCashMovement::TYPE_ORDER_COLLECT)
                ->where('direction', \App\Models\DeliveryBoyCashMovement::DIRECTION_IN)
                ->get();

            if ($collected->isEmpty()) {
                return; // nothing was collected into a session — no reversal owed.
            }

            foreach ($collected->groupBy('delivery_boy_cash_session_id') as $sessionId => $rows) {
                $sessionId = (int) $sessionId;

                // Idempotency: skip if a reversal adjustment OUT already exists for
                // this order in this session.
                $alreadyReversed = \App\Models\DeliveryBoyCashMovement::query()
                    ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('order_id', $orderId)
                    ->where('delivery_boy_cash_session_id', $sessionId)
                    ->where('type', \App\Models\DeliveryBoyCashMovement::TYPE_ADJUSTMENT)
                    ->where('direction', \App\Models\DeliveryBoyCashMovement::DIRECTION_OUT)
                    ->exists();

                if ($alreadyReversed) {
                    continue;
                }

                $amount = round((float) $rows->sum(fn ($m) => (float) $m->amount), 2);
                if ($amount <= 0) {
                    continue;
                }

                // Non-strict: if the session is no longer OPEN (closed/reconciled),
                // recordMovement degrades to a logged no-op rather than blocking the
                // refund — the variance is then surfaced by the Z enrichment cross-check.
                $svc->recordMovement(
                    $sessionId,
                    \App\Models\DeliveryBoyCashMovement::TYPE_ADJUSTMENT,
                    $amount,
                    \App\Models\DeliveryBoyCashMovement::DIRECTION_OUT,
                    $orderId,
                    'refund_reversal_for_order_' . $orderId,
                    false,
                );
            }
        } catch (\Throwable $reversalError) {
            // Never let the reversal cascade into a refund rollback — the RETURNED
            // transition already committed. Log for ops; Z enrichment cross-check
            // surfaces any residual movement/audit drift end-of-day.
            Log::error('[DeliveryBoy] cash-collect reversal on RETURNED failed (non-blocking): ' . $reversalError->getMessage(), [
                'order_id'  => $orderId,
                'branch_id' => $branchId,
            ]);
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

            $targetStatus = (int) $request->status;

            if ($auth) {
                // Customer self-cancellation path — owner check only
                if ($order->user_id == Auth::user()->id) {
                    // [iter13 P1 LOCKFORUPDATE 2026-05-09] Self-cancel race fix.
                    //
                    // Two simultaneous mobile cancels could read the same status and
                    // both write a final state — leading to duplicated cashback/loyalty
                    // refund + corrupted state machine audit trail. Wrap mutate path
                    // in DB::transaction + lockForUpdate so a single transition wins;
                    // the second tx sees the new status and exits via the idempotent
                    // early-return.
                    [$order, $oldStatus, $skipped] = DB::transaction(function () use ($order, $request, $targetStatus) {
                        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                        if ((int) $locked->status === $targetStatus) {
                            return [$locked, (int) $locked->status, true];
                        }
                        $previousStatus = (int) $locked->status;
                        // [GOAL-2026-05-29 F3] Race guard: re-validate the transition
                        // against the FRESH locked status (the pre-lock check at line
                        // 1909 used the possibly-stale route-bound $order->status; a
                        // concurrent transition could have moved the row, making the
                        // originally-valid edge illegal — e.g. DELIVERED->CANCELED).
                        if (!(new \App\Rules\ValidStatusTransition($previousStatus))->passes('status', $targetStatus)) {
                            throw new Exception(trans('all.message.invalid_status_transition'), 422);
                        }
                        if ($request->reason) {
                            $locked->reason = $request->reason;
                        }
                        if ($targetStatus === OrderStatus::REJECTED || $targetStatus === OrderStatus::CANCELED) {
                            if ($locked->transaction) {
                                app(PaymentService::class)->cashBack(
                                    $locked,
                                    'credit',
                                    'TXN-' . \Illuminate\Support\Str::random(12)
                                );
                            }
                            app(LoyaltyService::class)->refundPoints($locked, 'pos');
                        }
                        $locked->status = $request->status;
                        $locked->save();
                        OrderStateMachine::recordTransition(
                            Order::class,
                            (int) $locked->id,
                            $previousStatus,
                            (int) $request->status,
                            Auth::check() ? (int) Auth::id() : null,
                            $request->reason ?? null
                        );
                        return [$locked, $previousStatus, false];
                    });
                    if ($skipped) {
                        return $order;
                    }
                    SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                    SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                    SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                    try {
                        \App\Events\OrderStatusChanged::dispatch($order, $oldStatus, (int) $request->status);
                    } catch (\Exception $e) {
                        Log::warning('[OrderService] OrderStatusChanged on self-cancel failed: ' . $e->getMessage());
                    }
                    // [F-01] Compensating release of branch-scoped stock counters when an order
                    // is cancelled (self-cancel path). Idempotent via the `released_qty` ledger
                    // — safe even if dispatched more than once or paired with a future refund.
                    if (in_array($targetStatus, [OrderStatus::CANCELED, OrderStatus::REJECTED], true)) {
                        try {
                            OrderCanceled::dispatch($order); // allow: stock-release dispatch; ActionLog already recorded by self-cancel branch caller.
                        } catch (\Exception $e) {
                            Log::warning('[OrderService] OrderCanceled on self-cancel failed: ' . $e->getMessage()); // allow: warning only
                        }
                    }
                } else {
                    // [FIX-54-7] Return 403 instead of silent 200 for non-owner
                    abort(403, 'Access denied: you do not own this order.');
                }
            } else {
                // [CYCLE-002b] Atomic branch check, cashback, status save + ActionLog; notifications after commit.
                // [GOAL-K2-HEAL-02 2026-05-24 K.2 H5 P1] Re-fetch order with
                // lockForUpdate inside the transaction to prevent multi-cashier
                // POS Livré race. Without this, 2 cashiers clicking Livré on
                // the same PREPARED order both read the same in-memory
                // route-bound model, both passed the idempotent gate, and
                // both wrote `order_status_transitions` + `action_logs` rows
                // → ambiguous "who delivered" attribution. Mirrors the
                // existing self-cancel pattern at lines 1871-1901 in this
                // same method + PaymentService::confirmCounterPayment:219-249
                // + KitchenDisplaySystemOrderService::changeStatus:257-261.
                //
                // After the closure mutates $locked, we sync attributes back
                // to the route-bound $order via setRawAttributes() so the
                // post-tx SendOrderMail/Sms/Push + OrderStatusChanged +
                // OrderCanceled dispatches at lines 2049-2068 read fresh
                // persisted state, not stale pre-lock attributes.
                $oldStatusForBroadcast = null;
                // [abuse-heal 2026-06-19 deliv-admin-twin] Threads the COD cash-collection
                // escrow meta out of the locked tx so it can be recorded post-commit —
                // mirror of the driver-app path. Stays null for non-COD / non-delivery.
                $cashEscrowMeta = null;
                // [abuse-heal 2026-06-19 livreur FINDING-1] Threads the order id out of
                // the locked tx when a DELIVERY order is RETURNED, so the driver's
                // collected COD cash can be reversed post-commit. Null otherwise.
                $cashReversalOrderId = null;
                DB::transaction(function () use ($order, $request, $targetStatus, &$oldStatusForBroadcast, &$cashEscrowMeta, &$cashReversalOrderId) {
                    $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                    // [AUDIT-FIX P0-2 / POS-9-H.1.1] Branch isolation: non-Admin staff can only modify orders of their branch.
                    // Use abort() so the 403 is a real HttpException and bubbles untouched through the generic catch below.
                    if (Auth::check() && !Auth::user()->hasRole('Admin')) {
                        $userBranch = Auth::user()->branch_id ?? null;
                        if ($userBranch && (int) $userBranch !== (int) $locked->branch_id) {
                            abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
                        }
                    }

                    $toStatus = $targetStatus;
                    if ((int) $locked->status === $toStatus) {
                        // Idempotent: another concurrent request already won.
                        // Sync route-bound model so caller observes the
                        // already-persisted state and post-tx code short-
                        // circuits via the $oldStatusForBroadcast===null guard.
                        $order->setRawAttributes($locked->getAttributes(), true);
                        return;
                    }

                    // [GOAL-2026-05-29 F3] Race guard: re-validate the transition
                    // against the FRESH locked status. The pre-lock check (line 1909)
                    // used the route-bound $order->status; a concurrent transition may
                    // have superseded it before we acquired the lock — persisting the
                    // originally-valid transition could write an illegal state-machine
                    // edge (e.g. CANCELED->DELIVERED, DELIVERED->OUT_FOR_DELIVERY).
                    if (!(new \App\Rules\ValidStatusTransition((int) $locked->status))->passes('status', $toStatus)) {
                        throw new Exception(trans('all.message.invalid_status_transition'), 422);
                    }

                    // [abuse-heal 2026-06-19 deliv-admin-twin] ORPHAN guard — twin of the
                    // driver-app assignment check. A DELIVERY order must never go
                    // OUT_FOR_DELIVERY while unassigned (delivery_boy_id = NULL), i.e. "on
                    // the road" with no driver. The driver-app path can only be reached by
                    // the assigned driver; the admin path had no equivalent gate, so it let
                    // an admin push a driverless delivery OFD (HTTP 200 observed). Scoped
                    // STRICTLY to order_type === DELIVERY so TAKEAWAY / DINE_IN / POS are
                    // untouched. 422 invalid-transition: a driver is required first.
                    if ($toStatus === OrderStatus::OUT_FOR_DELIVERY
                        && (int) $locked->order_type === (int) OrderType::DELIVERY
                        && empty($locked->delivery_boy_id)
                    ) {
                        throw new Exception(trans('all.message.invalid_status_transition'), 422);
                    }

                    // [P3] RETURNED — même barrière motif / contrepartie que CANCELED & REJECTED.
                    if (in_array($toStatus, [OrderStatus::REJECTED, OrderStatus::CANCELED, OrderStatus::RETURNED], true)) {
                        $request->validate([
                            'reason' => 'required|max:700',
                        ]);

                        // [P11-FZH / F-VERIFY-08-02] Sealed-Z guard for RETURNED only.
                        // RETURNED is the fiscal counter-entry transition (CANCELED
                        // & REJECTED are operational pre-payment). Refuse if order
                        // is contained in closed Z window — caller must use
                        // POST /api/admin/pos-order/{order}/refund-with-counter-entry.
                        if ($toStatus === OrderStatus::RETURNED) {
                            try {
                                app(\App\Services\Order\SealedOrderGuard::class)
                                    ->assertMutable($locked, 'changeStatus → RETURNED');
                            } catch (\App\Exceptions\OrderSealedException $sealedEx) {
                                try {
                                    app(\App\Services\Fiscal\AuditLogService::class)->write([
                                        'branch_id'   => (int) $locked->branch_id,
                                        'user_id'     => Auth::check() ? (int) Auth::id() : null,
                                        'action'      => 'pos.refund.post_z_blocked',
                                        'resource'    => 'order',
                                        'resource_id' => (int) $locked->id,
                                        'payload'     => [
                                            'attempted_transition' => 'RETURNED',
                                            'sealed_by_z_id'       => $sealedEx->sealedByZReportId,
                                            'reason_supplied'      => (string) $request->input('reason'),
                                        ],
                                    ]);
                                } catch (\Throwable) {
                                    // best-effort audit; never block on audit failure
                                }
                                throw $sealedEx;
                            }

                            // [abuse-heal 2026-06-19 livreur FINDING-1] Flag a DELIVERY
                            // RETURNED so the driver's collected COD cash is reversed
                            // post-commit. Scoped to order_type === DELIVERY (the only
                            // path with a driver cash session); the reversal itself is a
                            // no-op when nothing was collected into a session.
                            if ((int) $locked->order_type === (int) OrderType::DELIVERY) {
                                $cashReversalOrderId = (int) $locked->id;
                            }
                        }

                        if ($request->reason) {
                            $locked->reason = $request->reason;
                        }
                        if ($locked->transaction) {
                            app(PaymentService::class)->cashBack(
                                $locked,
                                'credit',
                                'TXN-' . \Illuminate\Support\Str::random(12)
                            );
                        }
                        app(LoyaltyService::class)->refundPoints($locked, 'pos');
                    }

                    // [abuse-heal 2026-06-19 deliv-admin-twin] DELIVERY finalization — twin
                    // of the driver-app path, now SHARED. For a DELIVERY order this flips an
                    // UNPAID COD order to PAID at DELIVERED (the legal cash anchor) and
                    // allocates the gap-free fiscal_sequence_no INSIDE this locked tx, so an
                    // admin-finalized COD delivery enters the Z instead of becoming an
                    // off-book orphan (NF525 exhaustivity).
                    //
                    // Scoped STRICTLY to order_type === DELIVERY AND to the FORWARD delivery
                    // anchors (OUT_FOR_DELIVERY / DELIVERED) — exactly the transitions the
                    // driver-app path is ever invoked with. This deliberately EXCLUDES the
                    // cancel-like edges (CANCELED / REJECTED / RETURNED): the shared method's
                    // non-COD `else` branch would otherwise auto-flip an UNPAID late-card
                    // delivery to PAID + allocate a sequence on cancel — a sale that never
                    // happened. TAKEAWAY / DINE_IN / POS are excluded by the order_type fence.
                    if ((int) $locked->order_type === (int) OrderType::DELIVERY
                        && in_array($toStatus, [OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED], true)
                    ) {
                        $cashEscrowMeta = $this->finalizeDeliveryPaymentInTx($locked, $toStatus);
                    }

                    $oldStatusForBroadcast = $locked->status;
                    $locked->status = $request->status;
                    $locked->save();

                    OrderStateMachine::recordTransition(
                        Order::class,
                        (int) $locked->id,
                        (int) $oldStatusForBroadcast,
                        (int) $request->status,
                        Auth::check() ? (int) Auth::id() : null,
                        $request->reason ?? null
                    );

                    \App\Models\ActionLog::create([
                        'user_id'  => Auth::check() ? Auth::user()->id : null,
                        'action'   => 'Changement de statut',
                        'resource' => 'Commande #' . $locked->order_serial_no,
                        'details'  => sprintf(
                            'Nouveau statut: %s | Par: %s (branch_id=%s)',
                            trans('all.order.status.' . $request->status),
                            Auth::check() ? Auth::user()->name : 'Système',
                            Auth::check() ? (Auth::user()->branch_id ?? 'admin') : '?'
                        ),
                    ]);

                    // [POS-9.4.BL.2] NF525 — cancel / reject / return (contrepartie comptable ou clôture client).
                    if (in_array((int) $request->status, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED], true)) {
                        $action = (int) $request->status === OrderStatus::CANCELED
                            ? 'order.cancelled'
                            : ((int) $request->status === OrderStatus::REJECTED
                                ? 'order.rejected'
                                : 'order.returned');
                        app(AuditLogService::class)->write([
                            'branch_id'   => (int) $locked->branch_id,
                            'user_id'     => Auth::check() ? (int) Auth::id() : null,
                            'action'      => $action,
                            'resource'    => 'order',
                            'resource_id' => (int) $locked->id,
                            'payload'     => [
                                'order_serial_no' => $locked->order_serial_no,
                                'from_status'     => (int) $oldStatusForBroadcast,
                                'to_status'       => (int) $request->status,
                                'reason'          => $request->reason,
                                'total'           => round((float) $locked->total, 2),
                                'payment_status'  => (int) $locked->payment_status,
                                'fiscal_sequence_no' => $locked->fiscal_sequence_no,
                            ],
                        ]);
                    }

                    // [GOAL-K2-HEAL-02] Sync route-bound model so post-tx
                    // broadcasts (SendOrderMail/Sms/Push + OrderStatusChanged
                    // + OrderCanceled dispatched at lines 2049-2068) read
                    // the persisted state — not the pre-lock attributes.
                    $order->setRawAttributes($locked->getAttributes(), true);
                });

                if ($oldStatusForBroadcast === null) {
                    return $order;
                }

                // [abuse-heal 2026-06-19 deliv-admin-twin] Record the COD cash-collection
                // (NF525 audit row + DeliveryBoyCashMovement) AFTER commit, via the SHARED
                // method also used by the driver-app path. Non-null only for a COD DELIVERY
                // order that just reached DELIVERED on this admin transition.
                if (is_array($cashEscrowMeta)) {
                    $this->recordDeliveryCashEscrowPostCommit($cashEscrowMeta);
                }

                // [abuse-heal 2026-06-19 livreur FINDING-1] Reverse the driver's
                // collected COD cash when a DELIVERY order was RETURNED, so the
                // session reconciles to the real cash owed (no false shortage).
                if ($cashReversalOrderId !== null) {
                    $this->reverseDeliveryCashCollectPostCommit($cashReversalOrderId, (int) $order->branch_id);
                }

                SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $targetStatus]);
                SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $targetStatus]);
                SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $targetStatus]);

                // [PHASE-E] After commit; ShouldBroadcastNow — must not run inside DB::transaction
                try {
                    \App\Events\OrderStatusChanged::dispatch($order, $oldStatusForBroadcast, $targetStatus);
                } catch (\Exception $e) {
                    Log::warning('OrderStatusChanged broadcast failed: ' . $e->getMessage());
                }
                // [F-01] Compensating release of branch-scoped stock counters when an order
                // is cancelled or rejected by admin / POS / branch staff. Idempotent ledger
                // (order_items.released_qty) makes this safe to dispatch unconditionally.
                if (in_array($targetStatus, [OrderStatus::CANCELED, OrderStatus::REJECTED], true)) {
                    try {
                        OrderCanceled::dispatch($order); // allow: stock-release dispatch; AuditLogService::write already called above for order.cancelled / order.rejected.
                    } catch (\Exception $e) {
                        Log::warning('[OrderService] OrderCanceled on admin cancel failed: ' . $e->getMessage()); // allow: warning only
                    }
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
            $targetPaymentStatus = (int) $request->payment_status;

            if ($auth) {
                // Branche customer self-service.
                if ($order->user_id == Auth::user()->id) {
                    // [iter13 P1 LOCKFORUPDATE 2026-05-09] Customer payment race fix.
                    //
                    // Rapid double-click on "Pay" could let two requests both observe
                    // UNPAID and both transition to PAID, dispatching duplicate
                    // webhook/notification side effects. Wrap in tx + lockForUpdate
                    // so the second request sees the new state and exits idempotent.
                    return DB::transaction(function () use ($order, $request, $targetPaymentStatus) {
                        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                        if ((int) $locked->payment_status === $targetPaymentStatus) {
                            return $locked;
                        }
                        $locked->payment_status = $request->payment_status;
                        // [GENIE Wave0 FISCAL-CPS-01] Same NF525 allocation on the customer self-service
                        // PAID transition — a self-paid order must also enter the Z (idempotent + in-tx).
                        if ($targetPaymentStatus === \App\Enums\PaymentStatus::PAID
                            && $locked->fiscal_sequence_no === null
                        ) {
                            $locked->fiscal_sequence_no = app(FiscalSequenceService::class)->next((int) $locked->branch_id);
                        }
                        $locked->save();
                        return $locked;
                    });
                } else {
                    abort(403, 'Access denied: you do not have permission to modify this order.');
                }
            }

            // [AUDIT-FIX P0-2 / POS-9-H.1.1] Branch isolation: non-Admin staff
            // can only modify their branch's orders. abort() so the 403 bubbles
            // through the generic catch as a real HttpException.
            if (Auth::check() && !Auth::user()->hasRole('Admin')) {
                $userBranch = Auth::user()->branch_id ?? null;
                if ($userBranch && (int) $userBranch !== (int) $order->branch_id) {
                    abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
                }
            }

            // [F-VERIFY-09-01 P13] Idempotency-Key replay protection (defense-
            // in-depth — the HTTP IdempotencyKeyMiddleware short-circuits at
            // the route layer when `idempotency.enabled=true`). The service-
            // level cache covers the flag=false rollout window without
            // re-applying ActionLog / AuditLog / domain-event side effects.
            $idempotencyKey = request()?->header('X-Idempotency-Key');
            $cacheKey       = null;
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $cacheKey = sprintf(
                    'change_payment_status:%d:%d:%s',
                    (int) $order->branch_id,
                    (int) $order->id,
                    substr($idempotencyKey, 0, 64)
                );
                if (Cache::get($cacheKey) !== null) {
                    return $order->fresh();
                }
            }

            $oldPaymentStatus = (int) $order->payment_status;
            if ($oldPaymentStatus === $targetPaymentStatus) {
                return $order; // No-op early-return.
            }

            // [P11-FZH / F-VERIFY-08-02] Sealed-Z guard for REFUNDED only.
            // REFUNDED is a fiscal counter-entry — refused if order is in a
            // closed Z window. Caller must use refund-with-counter-entry.
            if ($targetPaymentStatus === \App\Enums\PaymentStatus::REFUNDED) {
                try {
                    app(\App\Services\Order\SealedOrderGuard::class)
                        ->assertMutable($order, 'changePaymentStatus → REFUNDED');
                } catch (\App\Exceptions\OrderSealedException $sealedEx) {
                    try {
                        app(\App\Services\Fiscal\AuditLogService::class)->write([
                            'branch_id'   => (int) $order->branch_id,
                            'user_id'     => Auth::check() ? (int) Auth::id() : null,
                            'action'      => 'pos.refund.post_z_blocked',
                            'resource'    => 'order',
                            'resource_id' => (int) $order->id,
                            'payload'     => [
                                'attempted_transition' => 'REFUNDED',
                                'sealed_by_z_id'       => $sealedEx->sealedByZReportId,
                            ],
                        ]);
                    } catch (\Throwable) {
                        // best-effort audit
                    }
                    throw $sealedEx;
                }
            }

            // [F-VERIFY-09-01 P13] State machine guard. Throws
            // \InvalidArgumentException(422) for illegal transitions
            // (e.g. PAID → anything under Option B).
            \App\Domain\Order\PaymentStateMachine::assertCanTransition(
                $oldPaymentStatus,
                $targetPaymentStatus
            );

            // [F-VERIFY-09-01 P13] Atomic Order save + ActionLog + AuditLog +
            // domain event dispatch. DispatchableAfterCommit defers the actual
            // event firing until COMMIT (gate C9 — KI-001).
            DB::transaction(function () use (
                $order,
                $request,
                $targetPaymentStatus
            ): void {
                // [GOAL-2026-05-29 F2] Re-fetch the row WITH lockForUpdate so two
                // concurrent staff requests (distinct idempotency keys) cannot BOTH
                // flip the same order — which previously produced a double-PAID effect
                // plus duplicate ActionLog + AuditLog + OrderPaymentStatusChanged
                // (double outbox/KDS/Z impact). The route-bound $order is stale; we
                // serialize on the locked row. Mirrors the auth self-service path
                // above + changeStatus/deliveryBoyOrderChangeStatus.
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $freshOld = (int) $locked->payment_status;

                // Idempotent: a concurrent request already reached the target. Sync the
                // route model so the caller observes the persisted state, and skip the
                // (already-emitted) side effects — no double log/audit/dispatch.
                if ($freshOld === $targetPaymentStatus) {
                    $order->setRawAttributes($locked->getAttributes(), true);
                    return;
                }

                // Re-validate the transition against the FRESH locked status — the
                // pre-lock guard used the possibly-superseded route status.
                \App\Domain\Order\PaymentStateMachine::assertCanTransition($freshOld, $targetPaymentStatus);

                $locked->payment_status = $request->payment_status;

                // [GENIE Wave0 FISCAL-CPS-01 2026-06-16] NF525 exhaustivity P0. An order flipped into
                // PAID here previously persisted with fiscal_sequence_no=NULL → excluded from every Z
                // (ZReportService aggregate whereNotNull('fiscal_sequence_no')) and unreachable by the
                // kiosk-only retry cron → permanent off-book orphan. Sibling of the delivery-COD hole.
                // Allocate a gap-free sequence at the PAID transition, INSIDE this locked tx so a
                // FiscalSequenceService failure rolls the whole transition back (the order stays in its
                // prior state rather than becoming an off-book PAID — never a silent orphan). Idempotent:
                // skip if a sequence already exists (counter/kiosk pre-allocation). Mirrors PaymentService.
                if ($targetPaymentStatus === \App\Enums\PaymentStatus::PAID
                    && $locked->fiscal_sequence_no === null
                ) {
                    $locked->fiscal_sequence_no = app(FiscalSequenceService::class)->next((int) $locked->branch_id);
                }

                $locked->save();

                \App\Models\ActionLog::create([
                    'user_id'  => Auth::check() ? Auth::id() : null,
                    'action'   => 'Statut paiement modifié',
                    'resource' => 'Commande #' . $locked->order_serial_no,
                    'details'  => sprintf(
                        'Statut paiement: %d → %d | Par: %s (branch_id=%s)',
                        $freshOld,
                        $targetPaymentStatus,
                        Auth::check() ? Auth::user()->name : 'Système',
                        Auth::check() ? (Auth::user()->branch_id ?? 'admin') : '?'
                    ),
                ]);

                // [POS-9.4.BL.2] NF525 audit trail on payment_status change.
                // Financially sensitive (PAID→UNPAID / PAID→REFUNDED would
                // impact Z report totals — but blocked under Option B by the
                // state machine guard above).
                app(AuditLogService::class)->write([
                    'branch_id'   => (int) $locked->branch_id,
                    'user_id'     => Auth::check() ? (int) Auth::id() : null,
                    'action'      => 'order.payment_status_changed',
                    'resource'    => 'order',
                    'resource_id' => (int) $locked->id,
                    'payload'     => [
                        'order_serial_no'      => $locked->order_serial_no,
                        'from_payment_status'  => $freshOld,
                        'to_payment_status'    => $targetPaymentStatus,
                        'total'                => round((float) $locked->total, 2),
                        'fiscal_sequence_no'   => $locked->fiscal_sequence_no,
                    ],
                ]);

                // [F-VERIFY-09-10 P13] Domain event for outbox / KDS / Z-report.
                // DispatchableAfterCommit defers the dispatch until commit, so
                // a rollback of any earlier statement above drops the event.
                \App\Events\OrderPaymentStatusChanged::dispatch(
                    $locked,
                    $freshOld,
                    $targetPaymentStatus
                );

                // Sync the route-bound model so the outer `return $order` + cache
                // marker reflect the persisted state.
                $order->setRawAttributes($locked->getAttributes(), true);
            });

            // [F-VERIFY-09-01 P13] Persist Idempotency-Key replay marker (TTL 24h).
            if ($cacheKey !== null) {
                Cache::put($cacheKey, $order->id, now()->addHours(24));
            }

            return $order;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            throw $http;
        } catch (\InvalidArgumentException $invalid) {
            // [F-VERIFY-09-01 P13] State machine guard rejects illegal
            // transitions — surface as proper HTTP 422.
            throw new Exception($invalid->getMessage(), 422);
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

    public function collectKioskCash(Order $order): Order
    {
        return app(PaymentService::class)->confirmCounterPayment(
            $order,
            \App\Enums\PosPaymentMethod::CASH,
            (float) $order->total,
            'Kiosk cash collected at POS.'
        );
    }

    /**
     * @throws Exception
     *
     * [GOAL-2026-05-18 P0-LIV-01] Multi-tenant + role guard.
     *
     * Before any assignment, the target user is verified to (a) actually have
     * Role::DELIVERY_BOY and (b) belong to the same branch as the order. The
     * check runs OUTSIDE the try/catch so abort(403)/abort(422) propagate as
     * HttpException instead of being swallowed and re-thrown as a generic 422
     * (codebase-wide pattern, cf. selectDeliveryBoy callers PosOrderController
     * + OnlineOrderController). Without this guard a branch-A admin could
     * silently assign a branch-B driver, breaking BranchScope semantics on the
     * livreur's `index` query at line 262-298.
     */
    public function selectDeliveryBoy(Order $order, Request $request, bool $auth = false): Order|array
    {
        // ─── Authz preflight (HttpException must propagate, NOT be wrapped) ───

        if ($auth) {
            // Customer self-service path — ownership check first.
            if ($order->user_id != Auth::user()?->id) {
                abort(403, 'Access denied: you do not have permission to modify this order.');
            }
        }

        $targetId = $request->delivery_boy_id ?? null;
        if (! is_numeric($targetId) || (int) $targetId <= 0) {
            abort(422, 'delivery_boy_id is required and must be a positive integer.');
        }

        // Spatie role scope + branch fence. Use withoutGlobalScope so an Admin
        // calling from branch_id=0 still sees the target row in its own branch.
        //
        // [WAVE-H bug_001 heal — 2026-05-19] Mirror the 5 sibling heals
        // (DeliveryBoyService:45, AdministratorService:46, ChefService:43,
        // CustomerService:43, WaiterService:44). Spatie's `->role($int)` calls
        // `findById($int, $guard)` (HasRoles trait L84). Passing
        // EnumRole::DELIVERY_BOY (=3) breaks on fresh-seeded envs where the
        // roles table AUTO_INCREMENT has skipped past 3 — `findById(3, ...)`
        // then throws RoleDoesNotExist and every legitimate driver assignment
        // 500s. The stable identity is the role NAME + guard, not the legacy
        // enum integer. See `database/seeders/SpatieRoleLookup.php` for the
        // same rationale.
        $target = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->role('Delivery Boy', 'sanctum')
            ->where('id', (int) $targetId)
            ->first();

        if ($target === null) {
            abort(403, 'Target user is not a delivery boy.');
        }

        if ((int) $target->branch_id !== (int) $order->branch_id) {
            abort(403, 'Cross-branch delivery boy assignment denied.');
        }

        // ─── Mutation (wrapped — generic Exception is acceptable here) ───

        try {
            $order->delivery_boy_id = (int) $targetId;
            $order->save();

            // [P0-LIV-01 trace] Audit-log the assignment so the same chain
            // also records WHO assigned WHICH driver to WHICH order. Mirrors
            // the cash escrow trace symmetry. Best-effort — never cascade an
            // audit failure into a 5xx for the operator.
            try {
                app(AuditLogService::class)->write([
                    'branch_id'   => (int) $order->branch_id,
                    'user_id'     => Auth::check() ? (int) Auth::id() : null,
                    'action'      => 'order.delivery_boy_assigned',
                    'resource'    => 'order',
                    'resource_id' => (int) $order->id,
                    'payload'     => [
                        'order_id'        => (int) $order->id,
                        'order_serial_no' => $order->order_serial_no,
                        'delivery_boy_id' => (int) $targetId,
                        'order_branch_id' => (int) $order->branch_id,
                        'actor_id'        => Auth::check() ? (int) Auth::id() : null,
                        'path'            => $auth ? 'customer_self_service' : 'admin_assign',
                        'assigned_at'     => now()->toIso8601String(),
                    ],
                ]);
            } catch (\Throwable $auditError) {
                Log::warning('[DeliveryBoy] driver-assignment audit_log write failed: ' . $auditError->getMessage(), [
                    'order_id' => $order->id,
                ]);
            }

            SendOrderDeliveryBoyMail::dispatch(['order_id' => $order->id, 'status' => 101]);
            SendOrderDeliveryBoySms::dispatch(['order_id' => $order->id, 'status' => 101]);
            SendOrderDeliveryBoyPush::dispatch(['order_id' => $order->id, 'status' => 101]);
            return $order;
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

        // Only a real global Admin (Admin role + branch_id=0) can destroy across branches; branch staff only own branch.
        if (! $this->isGlobalAdmin($actor) && ($actorBranchId <= 0 || $actorBranchId !== $orderBranchId)) {
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

            // [GENIE Wave4 B6 2026-06-16 + audit-360 W6 perf] Aggregate in SQL, not ->get()->filter()->sum().
            // Previously this loaded EVERY matching order (the entire orders table when no date filter is
            // set — the default report page-load) into PHP just to sum 3 scalar columns + count. Now we
            // build the filtered query ONCE and count/sum in the DB. The realized-revenue SQL scope
            // (Order::scopeRealizedRevenue) is byte-equivalent to isRealizedRevenueRow (verified; locked by
            // SalesReportNetTotalSentinelTest), so the totals are unchanged — only the work moves to SQL.
            $baseQuery = Order::where(function ($query) use ($requests) {
                if (!empty($requests['from_date']) && !empty($requests['to_date'])) {
                    // [GOAL-G2-HEAL-04 2026-05-23] TZ-generation alignment to
                    // Wave T R5 Paris bounds — sibling of list() above, keep
                    // byte-identical. See list() comment for full rationale.
                    $appTz = config('app.timezone');
                    $fromParis = Carbon::parse($requests['from_date'], $appTz)
                        ->startOfDay();
                    $toParisExclusive = Carbon::parse($requests['to_date'], $appTz)
                        ->addDay()
                        ->startOfDay();
                    $query->where('order_datetime', '>=', $fromParis)
                          ->where('order_datetime', '<', $toParisExclusive);
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
                            $this->applyOrderFilter($query, $key, $request);
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

                // [SALES-PAR-05 heal 2026-06-01] Honour exceptSource for parity with list() —
                // the overview cards previously ignored it, diverging from the table/PDF/Excel.
                if (isset($requests['exceptSource'])) {
                    $query->where('source', '!=', $requests['exceptSource']);
                }
            });
            $salesReportArray = [];

            // [GOAL-2026-05-30 H-03] Revenue figures must be PAID-only so the sales report
            // agrees with cash-overview (paid-only) and the signed Z. Previously total_earnings
            // summed `total` over ALL orders incl. UNPAID/PENDING_COUNTER (now common since the
            // kitchen prepares before encashment) → over-reported revenue. total_orders stays the
            // placed-volume count (a separate metric); only the MONEY figures filter to PAID.
            // [SALES-NET-01 heal 2026-06-01, owner "net, agree with the Z"] Money figures use the
            // net realized set (Order::isRealizedRevenueRow): drop cancelled-but-paid orders and
            // include the negative refund counter-entry mirrors so a refunded sale nets to ~0 —
            // agrees with the dashboard (scopeRealizedRevenue) and the signed Z. total_orders stays
            // the placed-volume count.
            // [audit-360 2026-06-21 P2 + W6 perf] total_orders = REAL placed orders only (exclude
            // refund counter-entry mirrors, parent_order_id set), matching the dashboard headline
            // (DashboardService::orderQuery()->whereNull('parent_order_id')->count()). Both cards are
            // titled "Total Commandes"; this previously INCLUDED the refund mirrors while the dashboard
            // excluded them → unexplained N-order gap. SQL count — no rows loaded into PHP.
            $salesReportArray['total_orders'] = (clone $baseQuery)->whereNull('parent_order_id')->count();
            // [SALES-NET-01 + W6 perf] Money = NET realized revenue via Order::scopeRealizedRevenue —
            // the SQL byte-equivalent of isRealizedRevenueRow (PAID-&-not-terminal OR refund-mirror),
            // verified identical + locked by SalesReportNetTotalSentinelTest. SQL SUM over the scope:
            // refund mirrors carry negative totals so a refunded sale nets to ~0, agreeing with the
            // dashboard + signed Z. COALESCE → 0 on the empty set. Replaces ->get()->filter()->sum()
            // which loaded every matching order into PHP just to sum 3 scalar columns.
            $realized = (clone $baseQuery)->realizedRevenue()
                ->selectRaw('COALESCE(SUM(total),0) as e, COALESCE(SUM(discount),0) as d, COALESCE(SUM(delivery_charge),0) as dc')
                ->first();
            $salesReportArray['total_earnings'] = AppLibrary::currencyAmountFormat($realized->e ?? 0);
            $salesReportArray['total_discounts'] = AppLibrary::currencyAmountFormat($realized->d ?? 0);
            $salesReportArray['total_delivery_charges'] = AppLibrary::currencyAmountFormat($realized->dc ?? 0);

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

    private function applyOrderFilter($query, string $key, $value): void
    {
        if ($key === 'branch_id') {
            $query->where('branch_id', '=', (int) $value);
            return;
        }

        $query->where($key, 'like', '%' . $this->escapeLike((string) $value) . '%');
    }

    private function isGlobalAdmin(?User $user): bool
    {
        return $user !== null
            && $user->branch_id !== null
            && (int) $user->branch_id === 0
            && method_exists($user, 'hasRole')
            && $user->hasRole('Admin');
    }

    private function assertOrderBranchVisible(Order $order): void
    {
        $user = Auth::user();
        if ($this->isGlobalAdmin($user)) {
            return;
        }

        $userBranchId = (int) ($user?->branch_id ?? 0);
        if ($userBranchId <= 0 || $userBranchId !== (int) $order->branch_id) {
            abort(403, 'Access denied: order does not belong to your branch.');
        }
    }

    /**
     * [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Single fiscal-correctness gate
     * for ANY discretionary discount (manual, coupon, loyalty redeem). At a
     * non-zero VAT rate the frozen PricingService/ZReportService compute per-line
     * TVA on the PRE-discount base, so a discounted order signs a fiscally-
     * incorrect NF525 Z (the F1 defect, dormant only at 0% VAT). Until F1 is
     * fixed under a lock-plan, every discount source is refused in V1
     * (pos.manual_discount_enabled=false — the master discretionary-discount
     * flag, covering manual/coupon/loyalty). Non-discounted orders are unaffected.
     */
    private function assertDiscretionaryDiscountAllowed(float $discount): void
    {
        if ($discount > 0.0 && config('pos.manual_discount_enabled') !== true) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => "Les remises (manuelle, coupon, fidélité) sont désactivées en V1 (correction fiscale TVA/HT en attente). Contactez le responsable.",
            ]);
        }
    }

    private function assertPosManualDiscountAllowed(float $discount, float $backendSubtotal, ?User $user, ?string $reason = null): void
    {
        if ($discount <= 0.0) {
            return;
        }

        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] V1 manual-discount gate.
        // At 10% VAT the discount→HT/TVA split in the FROZEN ZReportService/
        // PricingService is wrong (TVA computed on the PRE-discount base → the
        // signed Z is fiscally incorrect — the F1 defect, dormant only at 0%
        // VAT). Until F1 is fixed under a lock-plan, ANY non-zero manual POS
        // discount is refused so no discounted order can sign a wrong Z. The
        // customer's paid TOTAL on a non-discounted order is already correct.
        // Default OFF for V1 (config/pos.php manual_discount_enabled=false).
        // Re-enable only after F1 is fixed + a behavioral Z test proves the
        // discounted-order TVA is computed on the NET base.
        if (config('pos.manual_discount_enabled') !== true) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => "Les remises manuelles sont désactivées en V1 (correction fiscale TVA/HT en attente). Contactez le responsable.",
            ]);
        }

        if (strlen(trim((string) $reason)) < 3) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount_reason' => 'A reason is required for any POS discount (min 3 characters).',
            ]);
        }

        if ($backendSubtotal <= 0.0 || $discount > $backendSubtotal) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => 'Cannot apply discount without a valid backend subtotal.',
            ]);
        }

        if (!$user) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => 'Authentication required to apply a discount.',
            ]);
        }

        $pct = ($discount / $backendSubtotal) * 100.0;

        if ($pct > 50.0 && !$user->can('pos-discount-unlimited')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => 'Only an owner can apply a discount above 50%.',
            ]);
        }

        if ($pct > 10.0
            && !$user->can('pos-discount-over-10-requires-manager')
            && !$user->can('pos-discount-unlimited')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => 'Discount above 10% requires manager approval.',
            ]);
        }

        if (!$user->can('pos-discount-up-to-10')
            && !$user->can('pos-discount-over-10-requires-manager')
            && !$user->can('pos-discount-unlimited')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => 'You do not have permission to apply POS discounts.',
            ]);
        }
    }

    private function saveOrderWithQueueNumber(callable $applyFields, string $context): void
    {
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $businessDate = $this->resolveBusinessDate($this->order->order_datetime ?? null);
            $this->order->business_date = $businessDate;
            $this->order->queue_number = $this->allocateQueueNumber(
                (int) $this->order->branch_id,
                $businessDate,
                $context
            );
            $applyFields();
            $this->order->business_date = $businessDate;

            try {
                $this->order->save();
                return;
            } catch (QueryException $exception) {
                if (!$this->isQueueNumberUniqueViolation($exception) || $attempt >= $maxAttempts) {
                    throw $exception;
                }

                Log::warning(sprintf(
                    '[Queue] Duplicate queue_number %s for branch %s on business_date %s during %s save; retrying allocation once.',
                    (string) $this->order->queue_number,
                    (string) $this->order->branch_id,
                    (string) $this->order->business_date,
                    $context
                ));
            }
        }
    }

    private function allocateQueueNumber(int $branchId, string $businessDate, string $context): string
    {
        $lockKey = 'queue_lock_' . $branchId . '_' . $businessDate;
        $lock = Cache::lock($lockKey, 30);
        $acquired = false;

        try {
            $lock->block(15);
            $acquired = true;

            $queueNumbers = DB::table('orders')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->whereNotNull('queue_number')
                ->where('queue_number', 'like', 'A%')
                ->pluck('queue_number');

            $maxQueueNum = (int) $queueNumbers
                ->filter(static fn ($queueNumber): bool => preg_match('/^A\d+$/', (string) $queueNumber) === 1)
                ->map(static fn ($queueNumber): int => (int) substr((string) $queueNumber, 1))
                ->max();

            return 'A' . str_pad($maxQueueNum + 1, 4, '0', STR_PAD_LEFT);
        } catch (LockTimeoutException $exception) {
            Log::warning(sprintf(
                '[Queue] Lock timeout for branch %s on business_date %s during %s order creation; queue number fallback disabled by D-M13.',
                $branchId,
                $businessDate,
                $context
            ));

            throw new HttpException(409, 'Queue number allocation is busy. Please retry.', $exception);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    private function resolveBusinessDate(mixed $orderDatetime): string
    {
        if ($orderDatetime instanceof \DateTimeInterface) {
            return Carbon::instance($orderDatetime)->toDateString();
        }

        if (blank($orderDatetime)) {
            return Carbon::now()->toDateString();
        }

        return Carbon::parse((string) $orderDatetime)->toDateString();
    }

    private function isQueueNumberUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return ($exception->getCode() === '23000' || str_contains($message, 'UNIQUE constraint failed'))
            && (
                str_contains($message, 'orders_branch_business_date_queue_unique')
                || str_contains($message, 'orders_branch_queue_number_unique')
                || (
                    str_contains($message, 'orders.branch_id')
                    && str_contains($message, 'orders.business_date')
                    && str_contains($message, 'orders.queue_number')
                )
                || (
                    str_contains($message, 'branch_id')
                    && str_contains($message, 'business_date')
                    && str_contains($message, 'queue_number')
                )
            );
    }

    /**
     * [H2-HEAL-01 / H.1 P0 RED 2026-05-24] Branch + user scoped idempotency
     * recovery (defense-in-depth backstop for FROZEN §7 HTTP middleware).
     *
     * Scope MUST mirror CLAUDE.md §9 IdempotencyKey contract:
     * (branch_id, user_id, hash(key)).
     *
     * Note: `orders.user_id` semantically stores the CUSTOMER id (see
     * posOrderStore: `'user_id' => $request->customer_id`). Passing a
     * customer_id here CLOSES the cross-customer collision case (two
     * different customers sharing a key on the same branch). For anonymous
     * walk-ins (customer = null/0) the caller passes null so the filter
     * is skipped and (branch, key) recovery still rescues legitimate
     * retries — anonymous protection is the L7 middleware's job.
     *
     * $userId is OPTIONAL (default null) for backward compatibility with
     * existing sentinels (IdempotencyRecoveryBranchScoped,
     * IdempotencyMiddlewareSentinel, F006PosIdempotencyParity) that invoke
     * the method with 2 args. The WHERE clause order is preserved
     * (idempotency_key → branch_id → user_id) to satisfy the F-006
     * regex sentinel.
     */
    protected function findExistingOrderForIdempotencyRecovery(?string $idempotencyKey, int $branchId, ?int $userId = null): ?Order
    {
        if (blank($idempotencyKey) || $branchId <= 0) {
            return null;
        }

        return Order::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('branch_id', $branchId)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->first();
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
