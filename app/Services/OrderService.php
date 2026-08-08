<?php

namespace App\Services;

use App\Domain\Order\AutoPrepareOnPaidPolicy;
use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\TaxType;
use App\Events\OrderCanceled;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderDeliveryBoyMail;
use App\Events\SendOrderDeliveryBoyPush;
use App\Events\SendOrderDeliveryBoySms;
use App\Events\SendOrderGotMail;
use App\Events\SendOrderGotPush;
use App\Events\SendOrderGotSms;
use App\Events\SendOrderMail; // allow: domain event class import — audit log written by ActionLog/AuditLogService at call sites.
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Http\Requests\OrderRequest;
use App\Http\Requests\OrderStatusRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\PaymentStatusRequest;
use App\Http\Requests\PosOrderRequest;
use App\Http\Requests\TableOrderRequest;
use App\Http\Requests\TableOrderTokenRequest;
use App\Libraries\AppLibrary;
use App\Libraries\QueryExceptionLibrary;
use App\Models\Address;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderCoupon;
use App\Models\OrderDiscountLog;
use App\Models\OrderItem;
use App\Models\PaymentGateway;
use App\Models\Tax;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Menu\AvailabilityService;
use App\Services\Order\OrderQuoteService;
use App\Services\Orders\OrderItemAllergenSnapshot;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingResult;
use App\Services\Pricing\PricingService;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smartisan\Settings\Facades\Settings;
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
        'source_surface',
    ];

    protected array $exceptFilter = [
        'excepts',
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

            // [POSPERF-07-tracker-unbounded 2026-07-22] Opt-in lean eager-load set
            // for the POS tracker poll (60s cadence, SimpleOrderResource). The
            // tracker reads ONLY transaction.payment_method, user.name/phone and
            // orderItems.orderItem.name — the media/category/roles/branch/
            // transaction.order eager-loads exist for OrderResource + OrderExport
            // and were pure waste (and, combined with the unbounded ->get('*') path,
            // amplified an already-heavy fetch). Callers WITHOUT `lean` (Historique
            // list, export) keep the full set byte-for-byte — zero behaviour change.
            $relations = $request->get('lean', 0) == 1
                ? [
                    'transaction',
                    'orderItems.orderItem',
                    'user',
                ]
                : [
                    'transaction',
                    'transaction.order',
                    'orderItems.orderItem.media',
                    'orderItems.orderItem.category',
                    'branch',
                    'user',
                    // [TERRAIN-HEAL 2026-07-16 · ORDER-RES-N1] user.roles + user.media eager-loadés ici
                    // → OrderResource::toArray() (loadMissing) devient un no-op au lieu de ~20 requêtes
                    // parasites par page de 10 (roles+media re-query par ligne).
                    'user.roles',
                    'user.media',
                ];

            return Order::with($relations)->where(function ($query) use ($requests) {
                if (! empty($requests['from_date']) && ! empty($requests['to_date'])) {
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
                        if ($key === 'status') {
                            $query->where($key, (int) $request);
                        } elseif ($key === 'payment_method') {
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
                        } elseif ($key === 'source') {
                            // [SALES-PAR-03 heal 2026-06-01] `source` is an int enum — EXACT match
                            // (parity with salesReportOverview), NOT the generic LIKE which would
                            // over-match (e.g. '%5%' matching 5 and 15/50). source_surface stays LIKE.
                            $query->where('source', (int) $request);
                        } elseif ($key === 'source_surface' && (string) $request === 'web') {
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

            return Order::with('transaction', 'orderItems', 'branch', 'user')->where('order_type', '!=', OrderType::POS)->where(function ($query) use ($requests, $user) {
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

            return Order::with('transaction', 'transaction.order', 'orderItems', 'branch', 'user', 'user.roles', 'user.media')->where('delivery_boy_id', $user->id)->where('order_type', '!=', OrderType::POS)->where(
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
                ->where('order_type', '!=', OrderType::POS)
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
                        'user_id' => Auth::user()->id,
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
                    if (! blank($itemsArray)) {
                        OrderItem::insert($itemsArray);
                    }
                } else {
                    $i = 0;
                    $totalTax = 0;
                    $itemsArray = [];
                    $realSubtotal = 0;

                    // [AUDIT-FIX P0 + P1-1] Single Tax::get() — previous code called it twice (dead query).
                    // [AUDIT-FIX P0] Include tax_id in Item select — previous code omitted it, causing tax=0 on all web/app orders.
                    $requestedItemIds = collect($requestItems)->pluck('item_id')->filter()->unique()->toArray();
                    $dbItems = Item::select('id', 'price', 'tax_id')
                        ->whereIn('id', $requestedItemIds)
                        ->get()
                        ->keyBy('id');
                    $taxCollection = Tax::get();
                    $taxes = AppLibrary::pluck($taxCollection, 'obj', 'id');

                    // [PERF-02] Bulk-load variations and extras before the loop.
                    // Legacy reference kept for audit/tests: ItemVariation::find / ItemExtra::find
                    // used to run inside the loop before the bulk-loaded keyed collections replaced it.
                    $variationIds = collect($requestItems)->pluck('item_variations')->flatten(1)->pluck('id')->filter()->unique()->toArray();
                    $extraIds = collect($requestItems)->pluck('item_extras')->flatten(1)->pluck('id')->filter()->unique()->toArray();
                    $dbVariations = ! empty($variationIds)
                        ? \App\Models\ItemVariation::whereIn('id', $variationIds)->get()->keyBy('id')
                        : collect();
                    $dbExtras = ! empty($extraIds)
                        ? \App\Models\ItemExtra::whereIn('id', $extraIds)->get()->keyBy('id')
                        : collect();

                    // [CAISSE-LOGIC-HEAL SYNC-P1 2026-07-11] Inclure les composants de menu
                    // (addon_item_id) : un composant en rupture restait commandable (seuls les
                    // item_id de 1er niveau étaient testés — asymétrie avec le chemin de lecture).
                    app(AvailabilityService::class)->assertItemsOrderableForBranch(
                        (int) $this->order->branch_id,
                        array_merge($requestedItemIds, app(AvailabilityService::class)->componentItemIdsFor($requestItems)),
                        true
                    );

                    if (! blank($requestItems)) {
                        foreach ($requestItems as $item) {
                            $dbItem = $dbItems[$item->item_id] ?? null;
                            if (! $dbItem) {
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
                                    if (! $varId) {
                                        continue;
                                    }
                                    $dbVar = $dbVariations[$varId] ?? null;
                                    if (! $dbVar) {
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
                                    if (! $extraId) {
                                        continue;
                                    }
                                    $dbExt = $dbExtras[$extraId] ?? null;
                                    if (! $dbExt) {
                                        throw new \InvalidArgumentException("Extra ID {$extraId} introuvable.", 422);
                                    }
                                    $extraQuantity = max(1, (int) ($extra->quantity ?? 1));
                                    $extraTotal += (float) $dbExt->price * $extraQuantity;
                                }
                            }

                            $verifiedQuantity = max(1, (int) ($item->quantity ?? 1));
                            $verifiedTotalPrice = ($itemPrice + $variationTotal + $extraTotal) * $verifiedQuantity;
                            $realSubtotal += $verifiedTotalPrice;

                            // [AUDIT-FIX P0] tax_id now correctly read from DB item record
                            $taxId = $dbItem->tax_id ?? 0;
                            $taxName = isset($taxes[$taxId]) ? $taxes[$taxId]->name : null;
                            $taxRate = isset($taxes[$taxId]) ? $taxes[$taxId]->tax_rate : 0;
                            $taxType = isset($taxes[$taxId]) ? $taxes[$taxId]->type : TaxType::FIXED;
                            // [TTC-MODE] config('pricing.tax_inclusive_prices')=true → extract tax from TTC line total.
                            if ((bool) config('pricing.tax_inclusive_prices', false)) {
                                $taxPrice = (new \App\Services\Pricing\TaxCalculator)
                                    ->lineTaxAmountFromTTC((float) $verifiedTotalPrice, (int) $taxType, (float) $taxRate, true);
                            } else {
                                $taxPrice = round($taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100, 2);
                            }

                            // [T07] NF525 immutable composition snapshot — written in same transaction as insert.
                            $compositionSnapshot = (new \App\Services\Pricing\CompositionSnapshotBuilder)->build($item, $dbVariations, $dbExtras);

                            $itemsArray[$i] = [
                                'order_id' => $this->order->id,
                                'branch_id' => $this->order->branch_id,
                                'item_id' => $item->item_id,
                                'quantity' => $verifiedQuantity,
                                'discount' => 0,
                                'tax_name' => $taxName,
                                'tax_rate' => $taxRate,
                                'tax_type' => $taxType,
                                'tax_amount' => $taxPrice,
                                'price' => $itemPrice,
                                'item_variations' => json_encode($item->item_variations ?? []),
                                'item_extras' => json_encode($item->item_extras ?? []),
                                'composition_snapshot' => json_encode($compositionSnapshot),
                                'instruction' => $item->instruction ?? null,
                                'item_variation_total' => $variationTotal,
                                'item_extra_total' => $extraTotal,
                                'total_price' => $verifiedTotalPrice,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                            $totalTax += $taxPrice;
                            $i++;
                        }
                    }

                    if (! blank($itemsArray)) {
                        OrderItem::insert($itemsArray);
                    }

                    // [AUDIT-FIX P0-1] Coupon recalculation server-side — never trust $request->discount
                    $calculatedDiscount = 0;
                    if ($request->coupon_id > 0) {
                        $coupon = $this->couponService->resolveCouponById(
                            (int) $request->coupon_id,
                            (float) $realSubtotal,
                            (int) Auth::id(),
                            // [S3 coupon surface/branch enforced-at-commit 2026-07-18]
                            // POS commit: pass the order's REAL branch + surface='pos'
                            // so surface/branch-restricted coupons are enforced here.
                            (int) $this->order->branch_id,
                            'pos'
                        );
                        $calculatedDiscount = $this->couponService->calculateDiscountAmount($coupon, (float) $realSubtotal);
                        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Coupon discount
                        // hits the same frozen F1 split at 10% VAT → refuse in V1.
                        $this->assertDiscretionaryDiscountAllowed((float) $calculatedDiscount);
                    }
                }

                $this->saveOrderWithQueueNumber(function () use ($realSubtotal, $totalTax, $calculatedDiscount): void {
                    // [AUDIT-FIX P0] Overwrite all financial fields with server-recalculated values.
                    $this->order->order_serial_no = date('dmy').$this->order->id;
                    $this->order->subtotal = $realSubtotal;
                    $this->order->total_tax = $totalTax;
                    $this->order->discount = $calculatedDiscount;
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
                            'order_id' => $this->order->id,
                            'user_id' => Auth::user()->id,
                            'label' => $address->label,
                            'address' => $address->address,
                            'apartment' => $address->apartment,
                            'latitude' => $address->latitude,
                            'longitude' => $address->longitude,
                        ]);
                    }
                }

                // [AUDIT-FIX P0-1] OrderCoupon stores the SERVER-recalculated discount, not the client value
                if ($request->coupon_id > 0 && $calculatedDiscount > 0) {
                    OrderCoupon::create([
                        'order_id' => $this->order->id,
                        'coupon_id' => $request->coupon_id,
                        'user_id' => Auth::user()->id,
                        'discount' => $calculatedDiscount,
                    ]);
                }

                // [F-9 OBS RED-RED1 V1.0.1 quick win — 2026-05-19]
                // Drop the customer name from ActionLog.details (RGPD 5(1)(c)
                // minimisation). user_id is already persisted on this row and
                // is the canonical forensic lookup key.
                // Source: reports/audit/foundation-2026-05-18/round-1/F-9-OBS/STATUS.md §HEAL RED-RED1
                // Sentinel: tests/Feature/Sentinels/ActionLogPiiRedactionSentinelTest.php
                \App\Models\ActionLog::create([
                    'user_id' => Auth::check() ? Auth::id() : null,
                    'action' => 'Nouvelle commande Web/App',
                    'resource' => 'Commande #'.$this->order->order_serial_no,
                    'details' => sprintf(
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
                Log::warning('Notifications post-commande Web/App échouées pour order #'.$this->order->id.': '.$e->getMessage());
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
                'pos_order_idempotency_'.sha1($targetBranchId.'|'.$idempotencyKey),
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
                // [C4-CAISSE-TELEPHONE 2026-07-07] Une « commande téléphone » (phone_order) est,
                // par définition owner, prise à l'avance et encaissée SEULEMENT à l'arrivée du
                // client → elle emprunte le MÊME chemin différé que le walk-in routé au comptoir
                // (COUNTER_DEFERRED + PENDING_COUNTER, fiscal alloué à l'encaissement). Le seul
                // delta est le tag source_surface='phone' (voir plus bas) pour la file/historique/KDS.
                $deferToCounter = config('pos.walkin_route_to_counter') === true
                    || $request->boolean('defer_to_counter')
                    || $request->boolean('phone_order');
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
                        // [C2-CAISSE 2026-07-05] Nom du client (optionnel) → imprimé sur le ticket.
                        'pos_customer_name' => $request->filled('pos_customer_name')
                            ? mb_substr(trim((string) $request->input('pos_customer_name')), 0, 60)
                            : null,
                        // [C4-CAISSE-TELEPHONE 2026-07-07] Téléphone du client (optionnel) → noté sur
                        // la commande téléphone pour la rappeler/reconnaître, imprimé sur le ticket.
                        'pos_customer_phone' => $request->filled('pos_customer_phone')
                            ? mb_substr(trim((string) $request->input('pos_customer_phone')), 0, 30)
                            : null,
                        // [GOAL-CAISSE-UNIFIED delta-(B)] PENDING_COUNTER when the
                        // walk-in is routed to the unified collection queue; PAID
                        // for the legacy inline-paid-at-creation flow (default).
                        'payment_status' => $deferToCounter ? PaymentStatus::PENDING_COUNTER : PaymentStatus::PAID,
                        'order_datetime' => date('Y-m-d H:i:s'),
                        'preparation_time' => (int) (Settings::group('order_setup')->get('order_setup_food_preparation_time') ?? 15),
                        'total' => 0,
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

                    // [SELF-AUDIT R6 P2 2026-07-05 — « livraison offerte ≥ seuil » non appliquée en POS] La
                    // règle owner (Settings delivery.free_delivery_above, défaut 30€) vivait UNIQUEMENT dans
                    // FrontendOrderService → une commande POS DELIVERY facturait les frais même au-dessus du
                    // seuil (client surfacturé, puis collectés en COD). On l'applique sur le MÊME moteur SSOT :
                    // si DELIVERY + sous-total serveur ≥ seuil + frais > 0 → frais=0 + RE-CALCUL du total sans
                    // frais (le sous-total vient du SSOT, jamais du client → pas de contournement).
                    $freeAbove = (float) (\Smartisan\Settings\Facades\Settings::group('delivery')->get('free_delivery_above', 30) ?? 30);
                    if ((int) $this->order->order_type === \App\Enums\OrderType::DELIVERY
                        && $freeAbove > 0
                        && (float) $posSsotPricingResult->accumulatedSubtotal >= $freeAbove
                        && (float) ($this->order->delivery_charge ?? 0) > 0) {
                        $this->order->delivery_charge = 0;
                        $posSsotPricingResult = $this->pricingService->calculateOrder(
                            PricingRequest::forPos(
                                $this->order->id,
                                (int) $this->order->branch_id,
                                $requestItems,
                                (int) $request->coupon_id,
                                (int) ($request->customer_id ?? 0),
                                (float) $request->discount,
                                0.0
                            ),
                            $this->couponService
                        );
                    }

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
                    if (! blank($itemsArray)) {
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

                    $dbVariations = ! empty($variationIds)
                        ? \App\Models\ItemVariation::whereIn('id', $variationIds)->get()->keyBy('id')
                        : collect();
                    $dbExtras = ! empty($extraIds)
                        ? \App\Models\ItemExtra::whereIn('id', $extraIds)->get()->keyBy('id')
                        : collect();

                    // [CAISSE-LOGIC-HEAL SYNC-P1 2026-07-11] Inclure les composants de menu
                    // (addon_item_id) : un composant en rupture restait commandable (seuls les
                    // item_id de 1er niveau étaient testés — asymétrie avec le chemin de lecture).
                    app(AvailabilityService::class)->assertItemsOrderableForBranch(
                        (int) $this->order->branch_id,
                        array_merge($requestedItemIds, app(AvailabilityService::class)->componentItemIdsFor($requestItems)),
                        true
                    );

                    // [AUDIT-FIX P1-1] Single Tax::get() — removed duplicate dead query ($dbTaxes unused)
                    $taxes = AppLibrary::pluck(Tax::get(), 'obj', 'id');
                    $realSubtotal = 0;

                    if (! blank($requestItems)) {
                        foreach ($requestItems as $item) {
                            // [PLAN_01 D-001] REJETER ITEM INEXISTANT - Pas de fallback sur prix client
                            if (! isset($dbItems[$item->item_id])) {
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
                                    if (! $varId) {
                                        continue;
                                    }

                                    $dbVar = $dbVariations[$varId] ?? null;
                                    if (! $dbVar) {
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
                                    if (! $extraId) {
                                        continue;
                                    }

                                    $dbExt = $dbExtras[$extraId] ?? null;
                                    if (! $dbExt) {
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
                                $taxPrice = (new \App\Services\Pricing\TaxCalculator)
                                    ->lineTaxAmountFromTTC((float) $verifiedTotalPrice, (int) $taxType, (float) $taxRate, true);
                            } else {
                                $taxPrice = round($taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100, 2);
                            }

                            // [T07] NF525 immutable composition snapshot — written in same transaction as insert.
                            $compositionSnapshot = (new \App\Services\Pricing\CompositionSnapshotBuilder)->build($item, $dbVariations, $dbExtras);

                            $itemsArray[$i] = [
                                'order_id' => $this->order->id,
                                'branch_id' => $this->order->branch_id,
                                'item_id' => $item->item_id,
                                'quantity' => $verifiedQuantity,
                                'discount' => 0,
                                'tax_name' => $taxName,
                                'tax_rate' => $taxRate,
                                'tax_type' => $taxType,
                                'tax_amount' => $taxPrice,
                                'price' => $itemPrice,
                                'item_variations' => json_encode($item->item_variations ?? []),
                                'item_extras' => json_encode($item->item_extras ?? []),
                                'composition_snapshot' => json_encode($compositionSnapshot),
                                'instruction' => $item->instruction ?? null,
                                'item_variation_total' => $variationTotal,
                                'item_extra_total' => $extraTotal,
                                'total_price' => $verifiedTotalPrice,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                            $realSubtotal += $verifiedTotalPrice;
                            $totalTax = $totalTax + $taxPrice;
                            $i++;
                        }
                    }

                    // [POS-9.4.BL.1] Same NF525 allergen snapshot hydration for the
                    // non-SSOT legacy path (feature flag `pricing.use_ssot_service=false`).
                    $itemsArray = OrderItemAllergenSnapshot::hydrate($itemsArray);
                    if (! blank($itemsArray)) {
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
                            // [S3 coupon surface/branch enforced-at-commit 2026-07-18]
                            // POS commit: pass the order's REAL branch + surface='pos'
                            // so surface/branch-restricted coupons are enforced here.
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
                    $this->order->order_serial_no = date('dmy').$this->order->id;
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
                    // [ULTRA-AUDIT Wave 3 2026-07-04] Garde single-tender UNIQUEMENT : en split multi-tender
                    // le frontend envoie pos_received_amount = tendered de la SEULE tranche cash (montant
                    // PARTIEL), pas le total → comparer ce partiel au total complet rejetait à tort tout split
                    // cash-dominant. La somme des tranches ≥ total (et tendered ≥ amount par tranche cash) est
                    // déjà garantie par SplitPaymentService::validateBreakdown.
                    if (empty($request->input('payment_breakdown'))
                        && $request->pos_payment_method == \App\Enums\PosPaymentMethod::CASH
                        && $request->pos_received_amount !== null
                        && (float) $request->pos_received_amount < $this->order->total) {
                        throw new \InvalidArgumentException(
                            // [F-CASH-MSG-FORMAT 2026-07-15 / P3] Montants formatés (le decimal cast
                            // 6 chiffres affichait « 7.000000€ » au caissier au lieu de « 7,00 € »).
                            'Le montant reçu ('.number_format((float) $request->pos_received_amount, 2, ',', ' ').' €) est inférieur au total réel ('.number_format((float) $this->order->total, 2, ',', ' ').' €).',
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
                    // [C4-CAISSE-TELEPHONE 2026-07-07] Distingue le canal « commande téléphone »
                    // ('phone') du walk-in caisse standard ('pos') pour la file d'encaissement, le
                    // KDS (badge « Tél ») et l'historique. Les deux passent par le sceau
                    // counter-collect (assertCounterDeferredOrder accepte 'phone' aussi).
                    $this->order->source_surface = $request->boolean('phone_order') ? 'phone' : 'pos';

                    $currentTime = Carbon::now();
                    $endTime = $currentTime->copy()->addMinutes(Settings::group('order_setup')->get('order_setup_schedule_order_slot_duration'));
                    $start = $currentTime->format('H:i');
                    $end = $endTime->format('H:i');
                    $this->order->delivery_time = "$start - $end";

                    // [W4-E5 SCHEDULED 2026-07-20] Commande programmée (intake caisse/
                    // téléphone) : si la requête porte un scheduled_at (validé par
                    // PosOrderRequest : format strict + min now+lead cuisine + max 7 j),
                    // on le pose EN PLUS du stamp legacy delivery_time ci-dessus (créneau
                    // texte immédiat conservé — aucun consommateur cassé). Sinon la
                    // colonne reste NULL = ASAP. La visibilité cuisine à T-lead est
                    // pilotée par KitchenReleaseRule (SSOT, fondations 1cde5bad7).
                    if ($request->filled('scheduled_at')) {
                        $this->order->scheduled_at = Carbon::parse((string) $request->input('scheduled_at'));
                    }

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
                        || $request->boolean('defer_to_counter')
                        || $request->boolean('phone_order');
                    if (! $deferToCounterFiscal) {
                        $this->order->fiscal_sequence_no = app(FiscalSequenceService::class)
                            ->next((int) $this->order->branch_id);
                    }
                }, 'pos');

                // [BUG-C3 FIX] Create OrderCoupon record for POS orders — tracks coupon usage per order
                if ($request->coupon_id > 0 && $calculatedDiscount > 0) {
                    OrderCoupon::create([
                        'order_id' => $this->order->id,
                        'coupon_id' => $request->coupon_id,
                        'user_id' => $request->customer_id,
                        'discount' => $calculatedDiscount,
                    ]);
                }

                // storing order address
                if ($request->address_id) {
                    // [V3 FIX] Ownership check: address must belong to the customer on this order.
                    // Prevents IDOR where a manipulated payload could copy another customer's address.
                    $address = Address::where('id', $request->address_id)
                        ->where('user_id', $request->customer_id)
                        ->first();
                    if ($address) {
                        OrderAddress::create([
                            'order_id' => $this->order->id,
                            'user_id' => $request->customer_id,
                            'label' => $address->label,
                            'address' => $address->address,
                            'apartment' => $address->apartment,
                            'latitude' => $address->latitude,
                            'longitude' => $address->longitude,
                        ]);
                    } else {
                        // [Y5 FIX] Address not found or doesn't belong to customer — fail explicitly
                        // for delivery orders so the order is not created without a valid address.
                        throw new \Exception('Adresse #'.$request->address_id.' introuvable ou n\'appartient pas au client.', 422);
                    }
                }

                // [AUDIT-FIX P1-3] Log includes discount amount and type for auditability
                $discountDetail = $calculatedDiscount > 0
                    ? ($request->coupon_id > 0
                        ? sprintf('Coupon #%s → -%s€', $request->coupon_id, number_format($calculatedDiscount, 2))
                        : sprintf('Remise manuelle caissier → -%s€ (sur %s€)', number_format($calculatedDiscount, 2), number_format($realSubtotal, 2)))
                    : 'Aucune remise';

                \App\Models\ActionLog::create([
                    'user_id' => Auth::check() ? Auth::id() : null,
                    'action' => 'Nouvelle commande POS',
                    'resource' => 'Commande #'.$this->order->order_serial_no,
                    'details' => sprintf('Créée via Point de Vente | Total: %s€ | %s', number_format($this->order->total, 2), $discountDetail),
                ]);

                // [POS-9.4.BL.2] NF525 audit trail: any manual or coupon discount
                // must be recorded on the HMAC chain so a fraudulent cashier
                // discount becomes detectable post-hoc. Skipped when no discount
                // was applied to keep the chain focused on financially sensitive
                // events.
                if ($calculatedDiscount > 0) {
                    app(AuditLogService::class)->write([
                        'branch_id' => (int) $this->order->branch_id,
                        'user_id' => Auth::check() ? (int) Auth::id() : null,
                        'action' => OrderDiscountLog::ACTION,
                        'resource' => 'order',
                        'resource_id' => (int) $this->order->id,
                        'payload' => [
                            'order_serial_no' => $this->order->order_serial_no,
                            'actor_id' => Auth::check() ? (int) Auth::id() : null,
                            'coupon_id' => $request->coupon_id > 0 ? (int) $request->coupon_id : null,
                            'discount_reason' => $request->coupon_id > 0 ? null : trim((string) $request->discount_reason),
                            'requested_discount' => round((float) $request->discount, 2),
                            'discount_amount' => round((float) $calculatedDiscount, 2),
                            'discount_type' => $request->coupon_id > 0 ? 'coupon' : 'manual_cashier',
                            'subtotal_before' => round((float) $realSubtotal, 2),
                            'backend_subtotal' => round((float) $realSubtotal, 2),
                            'total_after' => round((float) $this->order->total, 2),
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
                    'branch_id' => (int) $this->order->branch_id,
                    'user_id' => Auth::check() ? (int) Auth::id() : null,
                    'action' => 'order.created.pos',
                    'resource' => 'order',
                    'resource_id' => (int) $this->order->id,
                    'payload' => [
                        'order_id' => (int) $this->order->id,
                        'order_serial_no' => (string) $this->order->order_serial_no,
                        'cashier_id' => Auth::check() ? (int) Auth::id() : null,
                        'cashier_name' => Auth::check() ? (string) (Auth::user()->name ?? '') : null,
                        'branch_id' => (int) $this->order->branch_id,
                        'customer_id' => (int) ($this->order->user_id ?? 0),
                        'order_type' => (int) $this->order->order_type,
                        'pos_payment_method' => (int) ($this->order->pos_payment_method ?? 0),
                        'payment_status' => (int) $this->order->payment_status,
                        'status' => (int) $this->order->status,
                        'total' => round((float) $this->order->total, 2),
                        'subtotal' => round((float) $this->order->subtotal, 2),
                        'discount' => round((float) $this->order->discount, 2),
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
                // [SELF-AUDIT P1 2026-07-05 — double cash-in NF525] Gate `! $deferToCounter`, MIROIR
                // du sibling legacy-cash (l.1274). Une commande DIFFÉRÉE (PENDING_COUNTER, non payée)
                // ne doit persister AUCUN paiement à la création : sinon SplitPaymentService écrit un
                // cash_movement par tranche cash À LA CRÉATION, puis confirmCounterPayment en écrit un
                // 2e (total) à l'encaissement → double cash-in / tiroir corrompu (piste NF525 §8).
                // confirmCounterPayment est mono-tender → un split différé est de toute façon
                // sémantiquement invalide (rejeté au niveau requête, PosOrderRequest). Mon unblock
                // Wave 3 (3184e5768) a armé ce chemin en cessant de 422 les splits.
                if ($splitActive && ! $deferToCounter) {
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
                //
                // [ULTRA-AUDIT V2 2026-07-02 — P2 cash-trail] Gate `! $deferToCounter`.
                // BUG : `$request->pos_payment_method` = valeur BRUTE client (reste CASH même
                // quand defer_to_counter=1 ; le serveur n'écrase que $validated l.725). Sans ce
                // gate, une commande DIFFÉRÉE (PENDING_COUNTER, non payée) enregistrait un
                // cash-in à la CRÉATION, puis un 2e à l'encaissement (confirmCounterPayment)
                // → double/phantom mouvement de tiroir (corruption piste NF525, réconciliation).
                // La commande différée reçoit son mouvement UNIQUEMENT à l'encaissement.
                if (! $splitActive
                    && ! $deferToCounter
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
                    Log::warning('Notification KDS échouée pour order #'.$order->id.': '.$e->getMessage());
                }
                // [MEGA 2.J / F-16] Dine-in: free floorplan table when this order is paid
                // and still holds the table. SYMMETRY_NOTE: kiosk has no parallel dine-in
                // table bind — FrontendOrderService unchanged.
                try {
                    app(DiningTableService::class)->tryReleaseTableAfterPosOrderPaid($order);
                } catch (\Throwable $e) {
                    Log::warning('[posOrderStore] floorplan table release: '.$e->getMessage());
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
                    Log::info('[POS Idempotency] Duplicate key caught at DB level — returning existing order #'.$existing->id);

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
                    if (! blank($itemsArray)) {
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

                    $dbVariations = ! empty($variationIds)
                        ? \App\Models\ItemVariation::whereIn('id', $variationIds)->get()->keyBy('id')
                        : collect();
                    $dbExtras = ! empty($extraIds)
                        ? \App\Models\ItemExtra::whereIn('id', $extraIds)->get()->keyBy('id')
                        : collect();

                    // [CAISSE-LOGIC-HEAL SYNC-P1 2026-07-11] Inclure les composants de menu
                    // (addon_item_id) : un composant en rupture restait commandable (seuls les
                    // item_id de 1er niveau étaient testés — asymétrie avec le chemin de lecture).
                    app(AvailabilityService::class)->assertItemsOrderableForBranch(
                        (int) $this->order->branch_id,
                        array_merge($requestedItemIds, app(AvailabilityService::class)->componentItemIdsFor($requestItems)),
                        true
                    );

                    $taxes = AppLibrary::pluck(Tax::get(), 'obj', 'id');
                    $realSubtotal = 0;

                    if (! blank($requestItems)) {
                        foreach ($requestItems as $item) {

                            // [PLAN_01 D-001] REJETER ITEM INEXISTANT - Pas de fallback sur prix client
                            // [BUG-CRIT-2 FIX] Utiliser $dbItems déjà bulk-loadé
                            $dbItem = $dbItems[$item->item_id] ?? null;
                            if (! $dbItem) {
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
                            if (isset($item->item_variations) && ! empty($item->item_variations)) {
                                foreach ($item->item_variations as $var) {
                                    $varId = $var->id ?? 0;
                                    $dbVar = $dbVariations[$varId] ?? null;
                                    if (! $dbVar) {
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
                            if (isset($item->item_extras) && ! empty($item->item_extras)) {
                                foreach ($item->item_extras as $ext) {
                                    $extId = $ext->id ?? 0;
                                    $dbExt = $dbExtras[$extId] ?? null;
                                    if (! $dbExt) {
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
                                $taxPrice = (new \App\Services\Pricing\TaxCalculator)
                                    ->lineTaxAmountFromTTC((float) $verifiedTotalPrice, (int) $taxType, (float) $taxRate, true);
                            } else {
                                $taxPrice = round($taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100, 2);
                            }

                            // [T07] NF525 immutable composition snapshot — written in same transaction as insert.
                            $compositionSnapshot = (new \App\Services\Pricing\CompositionSnapshotBuilder)->build($item, $dbVariations, $dbExtras);

                            $itemsArray[$i] = [
                                'order_id' => $this->order->id,
                                'branch_id' => $this->order->branch_id, // [AUDIT-P47-BUG3] always use order's branch, never client payload
                                'item_id' => $item->item_id,
                                'quantity' => $verifiedQuantity,
                                'discount' => 0,
                                'tax_name' => $taxName,
                                'tax_rate' => $taxRate,
                                'tax_type' => $taxType,
                                'tax_amount' => $taxPrice,
                                'price' => $itemPrice,
                                'item_variations' => json_encode($item->item_variations ?? []),
                                'item_extras' => json_encode($item->item_extras ?? []),
                                'composition_snapshot' => json_encode($compositionSnapshot),
                                'instruction' => $item->instruction ?? null,
                                'item_variation_total' => $calcVariationTotal,
                                'item_extra_total' => $calcExtraTotal,
                                'total_price' => $verifiedTotalPrice,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                            $totalTax = $totalTax + $taxPrice;
                            $i++;
                        }
                    }

                    if (! blank($itemsArray)) {
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
                            // [S3 coupon surface/branch enforced-at-commit 2026-07-18]
                            // POS commit: pass the order's REAL branch + surface='pos'
                            // so surface/branch-restricted coupons are enforced here.
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
                    $this->order->order_serial_no = date('dmy').$this->order->id;
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
                        'order_id' => $this->order->id,
                        'coupon_id' => $request->coupon_id,
                        'user_id' => $request->customer_id,
                        'discount' => $calculatedDiscount,
                    ]);
                }

                // [AUDIT-FIX P1-3] Log includes discount amount and type for auditability
                $discountDetail = $calculatedDiscount > 0
                    ? ($request->coupon_id > 0
                        ? sprintf('Coupon #%s → -%s€', $request->coupon_id, number_format($calculatedDiscount, 2))
                        : sprintf('Remise manuelle → -%s€ (sur %s€)', number_format($calculatedDiscount, 2), number_format($realSubtotal, 2)))
                    : 'Aucune remise';

                \App\Models\ActionLog::create([
                    'user_id' => Auth::check() ? Auth::id() : null,
                    'action' => 'Nouvelle commande sur Table',
                    'resource' => 'Commande #'.$this->order->order_serial_no,
                    'details' => sprintf('Créée via QR Code Dine-in | Total: %s€ | %s', number_format($this->order->total, 2), $discountDetail),
                ]);

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
                Log::warning('Notifications post-commande Table échouées pour order #'.$this->order->id.': '.$e->getMessage());
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

                // [PERF N+1 2026-07-31] Eager-load des relations lues par OrderDetailsResource
                // (detail/impression caisse via PosOrderController) — sinon ~9 requetes lazy par affichage.
                return $order->loadMissing(['orderItems.orderItem', 'user', 'address', 'branch', 'deliveryBoy', 'coupon', 'transaction', 'diningTable', 'payments']);
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
            if (! (new \App\Rules\ValidStatusTransition($order->status))->passes('status', $request->status)) {
                throw new Exception(trans('all.message.invalid_status_transition'), 422);
            }

            $oldStatus = (int) $order->status;
            $newStatus = (int) $request->status;

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
            $cashEscrowWritten = false;
            $cashEscrowMeta = null;
            DB::transaction(function () use (&$order, $oldStatus, $newStatus, &$cashEscrowWritten, &$cashEscrowMeta) {
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
                if (! (new \App\Rules\ValidStatusTransition((int) $locked->status))->passes('status', $newStatus)) {
                    throw new Exception(trans('all.message.invalid_status_transition'), 422);
                }

                $transaction = Transaction::where('order_id', $locked->id)->first();
                // [P1-3 2026-07-18] SYNC-WEB-KDS-01 (OnlineOrderController) bascule une commande
                // WEB UNPAID→PENDING_COUNTER à l'acceptation (visibilité cuisine). Une LIVRAISON
                // web COD encaissée au doorstep tombait alors HORS de ce sceau (payment_status≠
                // UNPAID) → le flip PAID + l'escrow audit + l'allocation fiscale ne firaient JAMAIS
                // → vente livraison COD OFF-BOOK NF525. On étend donc la détection au PENDING_COUNTER
                // issu d'un flip web-LIVRAISON (source_surface='web' + DELIVERY), sans toucher la
                // logique fiscale en aval (allocation seq/escrow inchangées). Restreint à la livraison :
                // le takeaway web s'encaisse au comptoir (counter-collect), jamais au doorstep.
                $isWebDeliveryDeferred = (int) $locked->payment_status === (int) PaymentStatus::PENDING_COUNTER
                    && $locked->source_surface === 'web'
                    && (int) $locked->order_type === (int) \App\Enums\OrderType::DELIVERY;
                $wasUnpaidCash = (! $transaction)
                    && ((int) $locked->payment_status === (int) PaymentStatus::UNPAID || $isWebDeliveryDeferred);

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

                    // [WH-2 bug_002 / 2026-05-19] Gate the payment_status →
                    // PAID flip on the canonical legal anchor.
                    //
                    // BEFORE: $locked->payment_status flipped to PAID
                    // UNCONDITIONALLY on every transition that hit
                    // $wasUnpaidCash. On the canonical 2-step driver flow
                    // (PREPARED→OFD then OFD→DELIVERED) the flip fired at
                    // call 1 (OFD), so on call 2 (DELIVERED) the order was
                    // already PAID, $wasUnpaidCash was false, and the
                    // inner `if (CASH_ON_DELIVERY && DELIVERED)` block —
                    // which contains the audit row capture — was skipped
                    // entirely. Net: ZERO `delivery.cash_collected_escrow`
                    // rows on the canonical 2-step flow, violating NF525.
                    //
                    // AFTER: for CASH_ON_DELIVERY we delay the PAID flip
                    // until newStatus === DELIVERED. This is the
                    // legally-correct anchor — cash is collected at the
                    // doorstep, not at pickup — AND it ensures the audit
                    // row capture downstream fires on the SAME locked
                    // transaction that performs the flip, so the chain
                    // gains exactly one row per cash delivery regardless
                    // of single-jump or 2-step path.
                    //
                    // For non-COD methods (E_WALLET / PAYPAL / CARD /
                    // TICKET_RESTAURANT) that somehow reached the driver
                    // still UNPAID (an unusual but observed edge case —
                    // e.g. card auth captured late), the prior flip-at-
                    // OFD-or-DELIVERED behaviour is preserved verbatim.
                    // Those flows never wrote an escrow row anyway.
                    $isCod = ((int) $pm === (int) \App\Enums\PaymentGateway::CASH_ON_DELIVERY);
                    $atDelivered = ((int) $newStatus === (int) OrderStatus::DELIVERED);

                    if ($isCod) {
                        if ($atDelivered) {
                            $locked->payment_status = PaymentStatus::PAID;

                            // [P0-LIV-02] Record the cash-collection event
                            // for NF525. The escrow row is the audit-trail
                            // anchor between "collected at doorstep" and
                            // "deposited at branch".
                            $cashEscrowWritten = true;
                            $cashEscrowMeta = [
                                'branch_id' => (int) $locked->branch_id,
                                'order_id' => (int) $locked->id,
                                'driver_id' => Auth::check() ? (int) Auth::id() : null,
                                'amount' => round((float) $locked->total, 2),
                                'serial' => $locked->order_serial_no,
                            ];
                        }
                        // CASH_ON_DELIVERY at OFD: leave payment_status =
                        // UNPAID. The flip + audit row will fire on the
                        // next call (OFD → DELIVERED), which re-enters
                        // this branch with $wasUnpaidCash still true.
                    } else {
                        // Non-COD methods preserve the legacy flip semantics
                        // (flip at OFD-or-DELIVERED, no escrow row).
                        $locked->payment_status = PaymentStatus::PAID;
                    }
                }

                $locked->status = $newStatus;

                // [SELF-AUDIT R2 P1 2026-07-05 — vente livraison COD off-book NF525] Miroir EXACT du fix
                // Wave-2 (changePaymentStatus, OrderService:2492-2497) : l'arête UNPAID→PAID de CE chemin
                // (flip au doorstep livraison — l.1908 COD@DELIVERED / l.1930 non-COD) scellait la commande
                // en PAID SANS allouer de fiscal_sequence_no → vente PAYÉE hors chaîne NF525 (exclue du Z
                // signé car ZReportService filtre whereNotNull(fiscal_sequence_no), jamais rattrapée par
                // RetryFiscalAllocCommand car fiscal_alloc_error_at reste NULL). On alloue si le flip a bien
                // eu lieu (payment_status===PAID) + fiscal null + statut non terminal + hors Uber. next() est
                // nested dans CETTE tx (Cache::lock+lockForUpdate = savepoint) ; l'unique
                // orders_branch_fiscal_seq garantit gap-free/no-double. Le cash_movement doorstep reste géré
                // par le bloc cashEscrow (l.1949) — ceci ne touche QUE le scellage fiscal.
                if ((int) $locked->payment_status === PaymentStatus::PAID
                    && $locked->fiscal_sequence_no === null
                    && ! in_array((int) $locked->status, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED], true)
                    && $locked->source_surface !== 'uber_eats') {
                    $locked->fiscal_sequence_no = app(FiscalSequenceService::class)->next((int) $locked->branch_id);
                    // [P1 fiscal_dated_at — LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT 2026-07-07]
                    // DEFERRED seal: this UNPAID→PAID edge (delivery COD@doorstep) fires
                    // LATER than creation, possibly in a subsequent Z. Stamp the fiscal
                    // allocation instant so ZReportService keys Z-membership on it (not
                    // created_at) → the sale lands in the Z open at settlement, never
                    // outside every signed Z.
                    if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'fiscal_dated_at')) {
                        $locked->fiscal_dated_at = now();
                    }
                }

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

            if ($cashEscrowWritten && is_array($cashEscrowMeta)) {
                // [P0-LIV-02] Append AFTER the transaction commits so the chain
                // tail is read against the persisted state. AuditLogService has
                // its own Cache::lock + DB::transaction inside write() (see
                // POS-9-H.2.2 / F-C3 doc-block), so calling it post-commit
                // keeps the HMAC chain ordering deterministic.
                try {
                    app(AuditLogService::class)->write([
                        'branch_id' => $cashEscrowMeta['branch_id'],
                        'user_id' => $cashEscrowMeta['driver_id'],
                        'action' => 'delivery.cash_collected_escrow',
                        'resource' => 'order',
                        'resource_id' => $cashEscrowMeta['order_id'],
                        'payload' => [
                            'order_id' => $cashEscrowMeta['order_id'],
                            'order_serial_no' => $cashEscrowMeta['serial'],
                            'amount_collected' => $cashEscrowMeta['amount'],
                            'delivery_boy_id' => $cashEscrowMeta['driver_id'],
                            'payment_method' => (int) \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
                            'collected_at' => now()->toIso8601String(),
                            'event' => 'doorstep_cash_collection',
                        ],
                    ]);
                } catch (\Throwable $auditError) {
                    // Never let an audit write error cascade into a billing
                    // rollback — NF525 chain breakage is surfaced via
                    // verifyChain() + ops alerting, not via a customer-facing
                    // 5xx mid-delivery. Log + continue.
                    Log::warning('[DeliveryBoy] cash-collection audit_log write failed: '.$auditError->getMessage(), [
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
                    Log::error('[DeliveryBoy] cash-session recordMovement drift (non-blocking): '.$movementError->getMessage(), [
                        'order_id' => $cashEscrowMeta['order_id'],
                        'driver_id' => $cashEscrowMeta['driver_id'],
                        'branch_id' => $cashEscrowMeta['branch_id'],
                        'amount' => $cashEscrowMeta['amount'],
                    ]);
                }
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
                Log::warning('[DeliveryBoy] OrderStatusChanged broadcast failed: '.$e->getMessage());
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
     * [D-1 HEAL 2026-07-24 · reports/audit-sync-gestion-2026-07-23 §D-1] Defense-in-depth
     * guard against RESURRECTING a terminal order. OrderStateMachine::allows()
     * (FROZEN §7, lines 79-86) grants an Admin a blanket terminal→ANY override, so an
     * Admin could otherwise move CANCELED/REJECTED/RETURNED back to an ACTIVE status
     * (e.g. CANCELED→ACCEPT, RETURNED→DELIVERED), bringing a dead order back to life.
     * No legitimate un-cancel/reopen flow exists in V1 — Order::restoring() is
     * hard-disabled (app/Models/Order.php:157-165, "To reopen an order, create a new
     * one"). This NON-frozen guard narrows the frozen Admin override so a terminal→
     * ACTIVE transition is refused for EVERY actor, mirroring the SealedOrderGuard
     * defense already applied to terminal edges. Terminal→terminal stays out of scope
     * (owned by SealedOrderGuard + the reason gate); idempotent same-status is
     * unaffected (from===to short-circuits upstream in allows()/apply()).
     */
    private function assertNotResurrectingTerminalOrder(int $from, int $to): void
    {
        $terminal = [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED];
        if (in_array($from, $terminal, true) && ! in_array($to, $terminal, true)) {
            // abort() → HttpException, preserved untouched by this method's
            // `catch (HttpException)` re-throw (same pattern as the branch-isolation
            // abort(403) guards already in changeStatus).
            abort(422, 'Transition interdite : une commande terminée (annulée, refusée ou remboursée) ne peut pas être réactivée.');
        }
    }

    /**
     * @throws Exception
     */
    public function changeStatus(Order $order, OrderStatusRequest $request, bool $auth = false): Order|array
    {
        try {
            if (! (new \App\Rules\ValidStatusTransition($order->status))->passes('status', $request->status)) {
                throw new Exception(trans('all.message.invalid_status_transition'), 422);
            }

            $targetStatus = (int) $request->status;

            // [D-1 HEAL 2026-07-24] Fast-fail terminal→active resurrection up-front
            // (before opening a transaction). Re-checked in-lock below against the
            // FRESH locked status for the concurrent-move case.
            $this->assertNotResurrectingTerminalOrder((int) $order->status, $targetStatus);

            // [P1-3 SÉCU 2026-08-04] Garde R1 CENTRALISÉ (chokepoint partagé) : une commande
            // CARTE WEB dont le paiement en ligne n'a pas abouti (UNPAID) ne doit JAMAIS être
            // acceptée en caisse — sinon annulation 3DS → zombie ACCEPT+UNPAID invisible cuisine.
            // Le garde du contrôleur online-order était contournable par la route sœur pos-order ;
            // ici il couvre TOUTES les routes (pos/online/table/futures). Carte web PAYÉE = déjà
            // promue par le webhook → ne passe pas ce garde.
            // [PROCUREUR cycle 7 — 2026-08-05 · P1 F-E] Cette garde SÉCU ne connaissait que la
            // surface 'web' : une commande LIVRAISON (surface forcée à 'delivery' par
            // FrontendOrder::creating) y échappait entièrement. A/B exécuté par l'agent :
            // surface 'web' → BLOQUÉE 422 ; surface 'delivery' → ACCEPTÉE, laissant un zombie
            // ACCEPT + UNPAID. L'équivalence web≡delivery introduite ailleurs n'avait pas été
            // propagée ici — le motif dominant de cette campagne : un correctif appliqué à la
            // surface regardée, pas à ses jumelles. Latent (livraison désactivée), P0 à son activation.
            if ($targetStatus === \App\Enums\OrderStatus::ACCEPT
                && in_array(strtolower((string) $order->source_surface), ['web', 'delivery'], true)
                && (int) $order->payment_method === \App\Enums\PaymentGateway::CARD
                && (int) $order->payment_status === \App\Enums\PaymentStatus::UNPAID) {
                throw new Exception('Paiement en ligne en cours : cette commande carte sera acceptée automatiquement une fois payée.', 422);
            }

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
                        if (! (new \App\Rules\ValidStatusTransition($previousStatus))->passes('status', $targetStatus)) {
                            throw new Exception(trans('all.message.invalid_status_transition'), 422);
                        }
                        if ($request->reason) {
                            $locked->reason = $request->reason;
                        }
                        if ($targetStatus === OrderStatus::REJECTED || $targetStatus === OrderStatus::CANCELED) {
                            if ($locked->transaction) {
                                // [F-CASH-REFUND-DRAWER 2026-07-15 / P1] slug = origine du paiement
                                // (cf. site jumeau ci-dessus) : cash → 'cash' (sortie tiroir), sinon 'credit'.
                                $refundGateway = ((int) $locked->pos_payment_method === \App\Enums\PosPaymentMethod::CASH) ? 'cash' : 'credit';
                                app(PaymentService::class)->cashBack(
                                    $locked,
                                    $refundGateway,
                                    'TXN-'.\Illuminate\Support\Str::random(12)
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
                        Log::warning('[OrderService] OrderStatusChanged on self-cancel failed: '.$e->getMessage());
                    }
                    // [F-01] Compensating release of branch-scoped stock counters when an order
                    // is cancelled (self-cancel path). Idempotent via the `released_qty` ledger
                    // — safe even if dispatched more than once or paired with a future refund.
                    if (in_array($targetStatus, [OrderStatus::CANCELED, OrderStatus::REJECTED], true)) {
                        try {
                            OrderCanceled::dispatch($order); // allow: stock-release dispatch; ActionLog already recorded by self-cancel branch caller.
                        } catch (\Exception $e) {
                            Log::warning('[OrderService] OrderCanceled on self-cancel failed: '.$e->getMessage()); // allow: warning only
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
                DB::transaction(function () use ($order, $request, $targetStatus, &$oldStatusForBroadcast) {
                    $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                    // [AUDIT-FIX P0-2 / POS-9-H.1.1] Branch isolation: non-Admin staff can only modify orders of their branch.
                    // Use abort() so the 403 is a real HttpException and bubbles untouched through the generic catch below.
                    if (Auth::check() && ! Auth::user()->hasRole('Admin')) {
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
                    if (! (new \App\Rules\ValidStatusTransition((int) $locked->status))->passes('status', $toStatus)) {
                        throw new Exception(trans('all.message.invalid_status_transition'), 422);
                    }

                    // [D-1 HEAL 2026-07-24] Race-safe re-check against the FRESH locked
                    // status: allows() lets an Admin resurrect a terminal order, so a
                    // concurrent move to CANCELED/REJECTED/RETURNED between route-binding
                    // and the lock could otherwise persist a terminal→active edge.
                    $this->assertNotResurrectingTerminalOrder((int) $locked->status, $toStatus);

                    // [P3] RETURNED — même barrière motif / contrepartie que CANCELED & REJECTED.
                    if (in_array($toStatus, [OrderStatus::REJECTED, OrderStatus::CANCELED, OrderStatus::RETURNED], true)) {
                        $request->validate([
                            'reason' => 'required|max:700',
                        ]);

                        // [P11-FZH / F-VERIFY-08-02] + [SELF-AUDIT R6 P1 2026-07-05] Sealed-Z guard pour TOUT
                        // état terminal (RETURNED, CANCELED, REJECTED). L'ancien commentaire « CANCELED &
                        // REJECTED = opérationnel pré-paiement » était FAUX pour une vente SCELLÉE PAYÉE :
                        // une commande fiscalisée dans un Z clos pouvait être (a) remboursée via le miroir
                        // counter-entry (qui laisse le parent ACCEPT par design) PUIS (b) annulée en place
                        // (CANCELED non gardé) → ZReportService retranchait le total DEUX FOIS (bloc miroir
                        // + bloc postZAdjustment) → total signé sous-évalué d'un total complet + double
                        // cashBack possible. assertMutable ne bloque QUE les commandes fiscalisées
                        // (fiscal_sequence_no != null) dans un Z clos → les brouillons/non-fiscalisés
                        // passent (pas de régression). Un void post-Z DOIT passer par le miroir counter-entry.
                        if (in_array($toStatus, [OrderStatus::RETURNED, OrderStatus::CANCELED, OrderStatus::REJECTED], true)) {
                            try {
                                app(\App\Services\Order\SealedOrderGuard::class)
                                    ->assertMutable($locked, 'changeStatus → terminal('.$toStatus.')');
                            } catch (\App\Exceptions\OrderSealedException $sealedEx) {
                                try {
                                    app(\App\Services\Fiscal\AuditLogService::class)->write([
                                        'branch_id' => (int) $locked->branch_id,
                                        'user_id' => Auth::check() ? (int) Auth::id() : null,
                                        'action' => 'pos.refund.post_z_blocked',
                                        'resource' => 'order',
                                        'resource_id' => (int) $locked->id,
                                        'payload' => [
                                            'attempted_transition' => (int) $toStatus,
                                            'sealed_by_z_id' => $sealedEx->sealedByZReportId,
                                            'reason_supplied' => (string) $request->input('reason'),
                                        ],
                                    ]);
                                } catch (\Throwable) {
                                    // best-effort audit; never block on audit failure
                                }
                                throw $sealedEx;
                            }
                        }

                        if ($request->reason) {
                            $locked->reason = $request->reason;
                        }
                        if ($locked->transaction) {
                            // [F-CASH-REFUND-DRAWER 2026-07-15 / P1] Le slug de remboursement
                            // dérive de l'ORIGINE du paiement : cash → 'cash' (sortie tiroir via
                            // recordCashBackMovement), sinon 'credit' (carte/en-ligne → avoir, pas de
                            // tiroir). Corrige le 'credit' codé en dur qui masquait la sortie tiroir
                            // des ventes cash COMPTOIR (Plan B borne + walk-in différé — elles portent
                            // une Transaction, donc passaient ici et non par le recordCashRefundMovement
                            // ci-dessous, réservé aux ventes cash SANS Transaction).
                            $refundGateway = ((int) $locked->pos_payment_method === \App\Enums\PosPaymentMethod::CASH) ? 'cash' : 'credit';
                            app(PaymentService::class)->cashBack(
                                $locked,
                                $refundGateway,
                                'TXN-'.\Illuminate\Support\Str::random(12)
                            );
                        } elseif ((int) $locked->payment_status === PaymentStatus::PAID) {
                            // [SELF-AUDIT R2 P2 2026-07-05 — sortie tiroir sur remboursement DIRECT pré-Z]
                            // Une vente POS directe (posOrderStore) est PAYÉE mais n'a AUCUNE ligne Transaction
                            // → le cashBack ci-dessus (gardé sur $locked->transaction) est sauté. Gardé sur PAID
                            // pour ne JAMAIS agir sur une commande jamais encaissée (RETURNED d'un UNPAID →
                            // géré par la libération stock).
                            //
                            // [TERRAIN-HEAL 2026-07-16 · LOYALTY-REFUND-NONCASH — P1] La condition était gardée
                            // sur pos_payment_method===CASH : une vente PAYÉE en CARTE / MOBILE / TICKET / SPLIT
                            // (tender non-cash, toujours sans Transaction en inline) tombait donc dans le vide au
                            // refund → RefundCreated JAMAIS dispatché → (a) points GAGNÉS jamais repris
                            // (double-dip fidélité = fuite monétaire répétable), (b) payment_status jamais repassé
                            // à REFUNDED (commande remboursée affichée PAID). Le fix R4 P1 (cash-direct) ne
                            // couvrait QUE le cash. On élargit à TOUT tender PAID sans Transaction.
                            //
                            // La sortie tiroir reste correcte quel que soit le tender : recordCashRefundMovement
                            // ne sort QUE le cash réellement encaissé (portion CASH d'un split, total d'une vente
                            // mono cash, 0 pour une vente carte/mobile). Les listeners de RefundCreated sont
                            // idempotents (clawback user+order+manual_deduct ; release stock released_qty ;
                            // flip statut) → sûrs même si RefundCreated est aussi émis par le chemin cashBack.
                            app(PaymentService::class)->recordCashRefundMovement($locked, round((float) $locked->total, 2));
                            \App\Events\RefundCreated::dispatch($locked);
                        }

                        // [TERRAIN-HEAL 2026-07-16 · LOYALTY-UNPAID-AWARD-CLAWBACK — P1 intersection KDS↔Loyalty↔Caisse]
                        // Une commande borne Plan B (PENDING_COUNTER, IMPAYÉE) est board-released et créditée en
                        // points GAGNÉS dès PREPARED : AwardLoyaltyPointsOnDelivery ne teste PAS payment_status, et
                        // le paiement comptoir (confirmCounterPayment) ne re-fire pas d'événement PREPARED → guarder
                        // l'award sur PAID casserait le crédit légitime du flux Plan B (payé APRÈS préparation).
                        // Le trou : si la commande est ensuite annulée SANS avoir été payée, le clawback via
                        // RefundCreated (bloc ci-dessus, gardé sur PAID) ne se déclenche pas → points gagnés jamais
                        // repris = exploit répétable (scanner QR + faire préparer + repartir sans payer). On reprend
                        // donc explicitement les points GAGNÉS sur TOUT état terminal d'annulation, quel que soit le
                        // paiement. Idempotent : clawbackEarnedPoints NOOP si déjà repris (manual_deduct user+order)
                        // → sûr même quand RefundCreated l'a déjà fait pour le cas PAID. -1 (sentinelle in-flight) et
                        // 0 sont ignorés par le garde > 0.
                        $awardedPts = (int) $locked->loyalty_points_awarded;
                        if ($awardedPts > 0) {
                            $loyaltyUser = null;
                            if (!empty($locked->loyalty_customer_code)) {
                                $loyaltyUser = \App\Models\User::where('loyalty_code', $locked->loyalty_customer_code)->first();
                            }
                            if (!$loyaltyUser && $locked->user_id) {
                                $candidate = \App\Models\User::find($locked->user_id);
                                if ($candidate && $candidate->loyalty_code) {
                                    $loyaltyUser = $candidate;
                                }
                            }
                            if ($loyaltyUser) {
                                app(LoyaltyService::class)->clawbackEarnedPoints(
                                    $loyaltyUser->id,
                                    $awardedPts,
                                    (int) $locked->id,
                                    'Clawback fidélité — commande annulée'
                                );
                            }
                        }
                        app(LoyaltyService::class)->refundPoints($locked, 'pos');
                    }

                    $oldStatusForBroadcast = $locked->status;
                    $locked->status = $request->status;
                    // [KITCHEN-TIMING 2026-07-04] Horodatage cuisine CENTRALISÉ dans le hook saving du modèle
                    // Order (couvre AUSSI les flux auto-prepare qui ne passent pas par changeStatus) → plus de
                    // stamp explicite ici.
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
                        'user_id' => Auth::check() ? Auth::user()->id : null,
                        'action' => 'Changement de statut',
                        'resource' => 'Commande #'.$locked->order_serial_no,
                        'details' => sprintf(
                            'Nouveau statut: %s | Par: %s (branch_id=%s)',
                            trans('all.order.status.'.$request->status),
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
                            'branch_id' => (int) $locked->branch_id,
                            'user_id' => Auth::check() ? (int) Auth::id() : null,
                            'action' => $action,
                            'resource' => 'order',
                            'resource_id' => (int) $locked->id,
                            'payload' => [
                                'order_serial_no' => $locked->order_serial_no,
                                'from_status' => (int) $oldStatusForBroadcast,
                                'to_status' => (int) $request->status,
                                'reason' => $request->reason,
                                'total' => round((float) $locked->total, 2),
                                'payment_status' => (int) $locked->payment_status,
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

                SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $targetStatus]);
                SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $targetStatus]);
                SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $targetStatus]);

                // [PHASE-E] After commit; ShouldBroadcastNow — must not run inside DB::transaction
                try {
                    \App\Events\OrderStatusChanged::dispatch($order, $oldStatusForBroadcast, $targetStatus);
                } catch (\Exception $e) {
                    Log::warning('OrderStatusChanged broadcast failed: '.$e->getMessage());
                }
                // [F-01] Compensating release of branch-scoped stock counters when an order
                // is cancelled or rejected by admin / POS / branch staff. Idempotent ledger
                // (order_items.released_qty) makes this safe to dispatch unconditionally.
                // [SELF-AUDIT R2 P3 2026-07-05 — fuite stock sur RETURNED non payé] RETURNED était
                // ABSENT de cette liste : sa seule libération passait par cashBack→RefundCreated (gardé
                // sur $locked->transaction). Une commande décrémentée à la création puis RETURNED en étant
                // UNPAID/sans Transaction (retour staff) ne libérait NI stock NI disponibilité → déplétion
                // fantôme (faux 86). Le ledger released_qty rend un double-release inoffensif (prouvé
                // StockReleaseTest::test_order_canceled_then_full_refund_second_delivery_is_no_op), donc
                // ajouter RETURNED ne double-compte pas pour les commandes payées.
                if (in_array($targetStatus, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED], true)) {
                    try {
                        OrderCanceled::dispatch($order); // allow: stock-release dispatch; AuditLogService::write already called above for order.cancelled / order.rejected.
                    } catch (\Exception $e) {
                        Log::warning('[OrderService] OrderCanceled on admin cancel failed: '.$e->getMessage()); // allow: warning only
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
            if (Auth::check() && ! Auth::user()->hasRole('Admin')) {
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
            $cacheKey = null;
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
                            'branch_id' => (int) $order->branch_id,
                            'user_id' => Auth::check() ? (int) Auth::id() : null,
                            'action' => 'pos.refund.post_z_blocked',
                            'resource' => 'order',
                            'resource_id' => (int) $order->id,
                            'payload' => [
                                'attempted_transition' => 'REFUNDED',
                                'sealed_by_z_id' => $sealedEx->sealedByZReportId,
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

            // [CASH-01 2026-07-15] Flag by-ref remonté hors closure (miroir du pattern
            // &$paid de confirmCounterPayment) : TRUE seulement si CET encaissement est un
            // encaissement ESPÈCES-COMPTOIR d'une commande online TAKEAWAY (garde stricte
            // posée dans la tx). Le mouvement tiroir + pos_payment_method=CASH sont ensuite
            // écrits POST-COMMIT, conditionnés à l'écriture réelle de la ligne tiroir.
            $didCounterCash = false;

            // [F-VERIFY-09-01 P13] Atomic Order save + ActionLog + AuditLog +
            // domain event dispatch. DispatchableAfterCommit defers the actual
            // event firing until COMMIT (gate C9 — KI-001).
            DB::transaction(function () use (
                $order,
                $request,
                $targetPaymentStatus,
                &$didCounterCash
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

                // [ULTRA-AUDIT V4 2026-07-02 — P1 vente off-book NF525] Une commande DIFFÉRÉE
                // (borne Plan B, PENDING_COUNTER) NE DOIT PAS passer PAID via change-payment-status :
                // ce chemin n'alloue NI `fiscal_sequence_no` NI `cash_movement` → vente PAID hors
                // chaîne fiscale NF525 (exclue du Z, `ZReportService` filtre `whereNotNull`) + hors
                // trail de caisse (invisible à la réconciliation) = orphelin PERMANENT (jamais rattrapé
                // par RetryFiscalAllocCommand). Le SEUL chemin correct pour sceller une commande
                // différée = l'ENCAISSEMENT (`PaymentService::confirmCounterPayment`, qui alloue la
                // séquence fiscale + enregistre le mouvement de tiroir). On rejette donc ici (zéro
                // allocation depuis ce chemin = zéro risque de corruption de chaîne).
                if ($targetPaymentStatus === \App\Enums\PaymentStatus::PAID
                    && $freshOld === \App\Enums\PaymentStatus::PENDING_COUNTER
                    && $locked->fiscal_sequence_no === null) {
                    abort(422, "Une commande différée (borne Plan B) doit être marquée payée via l'encaissement (allocation fiscale NF525) — le passage direct en « payé » est interdit.");
                }

                // Re-validate the transition against the FRESH locked status — the
                // pre-lock guard used the possibly-superseded route status.
                \App\Domain\Order\PaymentStateMachine::assertCanTransition($freshOld, $targetPaymentStatus);

                // [ULTRA-AUDIT Wave 2 2026-07-04 — P1 vente off-book NF525] Sceller une commande en
                // PAID DOIT allouer un `fiscal_sequence_no` (invariant §8, appliqué aux 3 autres
                // points de scellage : POS create OrderService:1117, confirmCounterPayment
                // PaymentService:335-337, kiosk TPE FrontendOrderService:1232). L'arête UNPAID→PAID
                // de ce chemin (« marquer payé » sur commandes livraison/en-ligne/table) l'OUBLIAIT
                // → commande PAID sans numéro fiscal = vente HORS chaîne NF525 (exclue du Z signé,
                // jamais rattrapée par RetryFiscalAllocCommand car fiscal_alloc_error_at reste NULL).
                // Miroir EXACT de confirmCounterPayment : l'appel next() est nested dans CETTE tx →
                // Cache::lock + lockForUpdate participent au savepoint, l'unique orders_branch_fiscal_seq
                // garantit zéro gap/double. Exclusions : (a) PENDING_COUNTER→PAID reste BLOQUÉ plus
                // haut (doit passer par l'encaissement pour le cash_movement) ; (b) statut terminal =
                // vente void, pas de séquence (miroir PaymentService:323, le Z exclut déjà ces
                // statuts) ; (c) Uber (source_surface='uber_eats', uber.fiscalize=false → facturé
                // séparément par l'agrégateur, pas de Z FR).
                if ($targetPaymentStatus === \App\Enums\PaymentStatus::PAID
                    && $locked->fiscal_sequence_no === null
                    && ! in_array((int) $locked->status, [\App\Enums\OrderStatus::CANCELED, \App\Enums\OrderStatus::REJECTED, \App\Enums\OrderStatus::RETURNED], true)
                    && $locked->source_surface !== 'uber_eats') {
                    $locked->fiscal_sequence_no = app(\App\Services\Fiscal\FiscalSequenceService::class)->next((int) $locked->branch_id);
                    // [P1 fiscal_dated_at — LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT 2026-07-07]
                    // DEFERRED seal: "marquer payé" (delivery/online/table) fires later
                    // than creation → same gap risk. Stamp the allocation instant so the
                    // Z window keys on it, sealing the sale in the Z open at settlement.
                    if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'fiscal_dated_at')) {
                        $locked->fiscal_dated_at = now();
                    }
                }

                // [CASH-01 2026-07-15 / P1 cash-trail] Détermine si CET encaissement est un
                // encaissement ESPÈCES-COMPTOIR d'une commande ONLINE à emporter — auquel cas
                // une ligne de tiroir (CashMovement IN) + pos_payment_method=CASH seront écrits
                // POST-COMMIT (conditionnés à l'écriture réelle du tiroir). Sans ce fix, ce chemin
                // scellait la vente dans le Z (fiscal_seq) mais n'enregistrait AUCUN mouvement
                // tiroir → variance de réconciliation + ventilation tender du Z corrompue.
                // Garde POSITIVE ultra-stricte (allowlist) — plan adversaire durci :
                //   - booléen d'INTENTION `collect_counter_cash` émis UNIQUEMENT par le bouton
                //     « Encaisser & Valider » online (jamais le dropdown générique « marquer payé »)
                //     → aucune valeur tender injectable (contrairement à un champ pos_payment_method brut) ;
                //   - order_type===TAKEAWAY (exclut DELIVERY=5/légataire/POS=15/table=20 → aucune
                //     mis-attribution du cash livreur au tiroir comptoir) ;
                //   - transaction null (exclut toute vente carte/en-ligne) ;
                //   - UNPAID→PAID (exclut les paiements déjà encaissés / PENDING_COUNTER) ;
                //   - source ∉ [pos, uber_eats]. On NE touche PAS pos_payment_method ici.
                $didCounterCash = $targetPaymentStatus === \App\Enums\PaymentStatus::PAID
                    && $freshOld === \App\Enums\PaymentStatus::UNPAID
                    && request()?->boolean('collect_counter_cash') === true
                    && (int) $locked->payment_method === \App\Enums\PaymentGateway::CASH_ON_DELIVERY
                    && ! $locked->transaction
                    && $locked->pos_payment_method === null
                    && (int) $locked->order_type === \App\Enums\OrderType::TAKEAWAY
                    && ! in_array($locked->source_surface, ['pos', 'uber_eats'], true);

                $locked->payment_status = $request->payment_status;
                $locked->save();

                // [SYNC-WEB-KDS-02 2026-07-15 / P1 parité cuisine] Une commande online/livraison
                // encaissée au comptoir (UNPAID→PAID via CE chemin) restait en ACCEPT(4) → présente
                // dans la liste KDS mais JAMAIS rendue comme carte cuisine active (le board rend
                // PREPARING/PREPARED), alors qu'une commande borne/POS payée passe direct en
                // PREPARING via AutoPrepareOnPaidPolicy. Ce chemin d'encaissement online était le
                // 4e site d'intégration MANQUANT. Transition ACCEPT→PREPARING déjà légale (status-only),
                // fiscal_seq déjà alloué ci-dessus → NF525 inchangé. Résout aussi OSS-01/KDS-01(payé).
                if ($targetPaymentStatus === \App\Enums\PaymentStatus::PAID
                    && (int) $locked->status === \App\Enums\OrderStatus::ACCEPT
                    && \App\Domain\Order\AutoPrepareOnPaidPolicy::shouldPromote(
                        surface: (string) ($locked->source_surface ?? 'web'),
                        posPaymentMethod: $locked->pos_payment_method !== null ? (int) $locked->pos_payment_method : null,
                        isCounterCollect: false,
                    )) {
                    $locked->status = \App\Domain\Order\AutoPrepareOnPaidPolicy::nextStatus();
                    $locked->save();
                    \App\Domain\Order\OrderStateMachine::recordTransition(
                        \App\Models\Order::class,
                        (int) $locked->id,
                        \App\Enums\OrderStatus::ACCEPT,
                        \App\Enums\OrderStatus::PREPARING,
                        Auth::check() ? (int) Auth::id() : null,
                        'auto_prepare_on_paid (SYNC-WEB-KDS-02 encaissement online)',
                    );

                    // [SYNC cross-surface P2 2026-08-04] Diffuser AUSSI OrderStatusChanged pour la
                    // transition auto-prepare ACCEPT→PREPARING. Avant : seul OrderPaymentStatusChanged
                    // (sans abonné client) était émis → KDS/OSS/tracker ne voyaient la carte cuisine
                    // devenir active qu'au prochain POLL (5-60 s). OrderStatusChanged pousse l'outbox
                    // (KDS/OSS) + le FCM cuisine « en préparation », comme toute transition PREPARING
                    // via changeStatus (OrderService:2573). DispatchableAfterCommit → diffusé au commit
                    // (fiscal déjà scellé). Best-effort, jamais bloquant (miroir du pattern existant).
                    try {
                        OrderStatusChanged::dispatch(
                            $locked,
                            \App\Enums\OrderStatus::ACCEPT,
                            \App\Enums\OrderStatus::PREPARING
                        );
                    } catch (\Throwable $e) {
                        Log::warning('[OrderService] auto-prepare OrderStatusChanged broadcast failed: '.$e->getMessage());
                    }
                }

                \App\Models\ActionLog::create([
                    'user_id' => Auth::check() ? Auth::id() : null,
                    'action' => 'Statut paiement modifié',
                    'resource' => 'Commande #'.$locked->order_serial_no,
                    'details' => sprintf(
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
                    'branch_id' => (int) $locked->branch_id,
                    'user_id' => Auth::check() ? (int) Auth::id() : null,
                    'action' => 'order.payment_status_changed',
                    'resource' => 'order',
                    'resource_id' => (int) $locked->id,
                    'payload' => [
                        'order_serial_no' => $locked->order_serial_no,
                        'from_payment_status' => $freshOld,
                        'to_payment_status' => $targetPaymentStatus,
                        'total' => round((float) $locked->total, 2),
                        'fiscal_sequence_no' => $locked->fiscal_sequence_no,
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

            // [CASH-01 2026-07-15 / P1 cash-trail] Encaissement ESPÈCES-COMPTOIR d'une
            // commande online à emporter → enregistrer la ligne de TIROIR (CashMovement IN)
            // POST-COMMIT (miroir de confirmCounterPayment:503). L'ordre est : (1) garde
            // d'EXISTENCE (recordMovement n'a aucun dédup order_id → anti-double au niveau DB) ;
            // (2) recordCashOrderMovement best-effort (strict=false → NO-CRASH sans session
            // tiroir : la ligne est simplement sautée + [F-003] loggé) ; (3) pos_payment_method
            // =CASH persisté UNIQUEMENT si la ligne IN a RÉELLEMENT été écrite → jamais de
            // ventilation Espèces sans contrepartie tiroir (zéro variance Z↔tiroir NOUVELLE),
            // et symétrie refund (OrderService:2333 ne sort du tiroir que si une entrée existe).
            // Hors tx fiscale (le fiscal_seq est déjà scellé) ; try/catch = totalement non-bloquant.
            if ($didCounterCash) {
                try {
                    $alreadyIn = \App\Models\CashMovement::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                        ->where('order_id', (int) $order->id)
                        ->where('type', \App\Models\CashMovement::TYPE_ORDER_PAYMENT)
                        ->where('direction', \App\Models\CashMovement::DIRECTION_IN)
                        ->exists();
                    if (! $alreadyIn) {
                        app(PaymentService::class)->recordCashOrderMovement($order, 'Encaissement comptoir (commande en ligne)');
                        $writtenIn = \App\Models\CashMovement::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                            ->where('order_id', (int) $order->id)
                            ->where('type', \App\Models\CashMovement::TYPE_ORDER_PAYMENT)
                            ->where('direction', \App\Models\CashMovement::DIRECTION_IN)
                            ->exists();
                        if ($writtenIn) {
                            $order->pos_payment_method = \App\Enums\PosPaymentMethod::CASH;
                            $order->save();
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('[F-CASH-01] hook tiroir commande en ligne non-bloquant', [
                        'order_id' => $order->id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

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
                    'branch_id' => (int) $order->branch_id,
                    'user_id' => Auth::check() ? (int) Auth::id() : null,
                    'action' => 'order.delivery_boy_assigned',
                    'resource' => 'order',
                    'resource_id' => (int) $order->id,
                    'payload' => [
                        'order_id' => (int) $order->id,
                        'order_serial_no' => $order->order_serial_no,
                        'delivery_boy_id' => (int) $targetId,
                        'order_branch_id' => (int) $order->branch_id,
                        'actor_id' => Auth::check() ? (int) Auth::id() : null,
                        'path' => $auth ? 'customer_self_service' : 'admin_assign',
                        'assigned_at' => now()->toIso8601String(),
                    ],
                ]);
            } catch (\Throwable $auditError) {
                Log::warning('[DeliveryBoy] driver-assignment audit_log write failed: '.$auditError->getMessage(), [
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
            && $actor && ! $actor->can('pos-destroy-paid')) {
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

            // [SELF-AUDIT R5 P2 2026-07-05 — fuite quota/stock à la destruction] destroy soft-supprime la
            // commande + ses lignes SANS dispatcher OrderCanceled/RefundCreated → le décrément
            // stock/disponibilité posé à la création (OrderCreated) n'était JAMAIS compensé → faux auto-86
            // (article marqué épuisé sans vente, jusqu'au reset du lendemain). Asymétrique avec le chemin
            // CANCEL qui, lui, libère. On dispatche la libération compensatoire AVANT le soft-delete (les
            // lignes doivent encore exister pour que releaseForOrderItems lise qty/item_id). Idempotent via
            // released_qty (double-release avec un cancel antérieur = no-op). On saute les états déjà
            // terminaux (déjà libérés + hors KDS) pour éviter un broadcast d'annulation parasite.
            // [F-DESTROY-RELEASE-ATOMIC 2026-07-15 / P2] La libération compensatoire est
            // désormais dispatchée DANS la transaction de suppression : OrderCanceled est
            // DispatchableAfterCommit → elle ne tire qu'au COMMIT durable. AVANT, le dispatch
            // précédait la tx (transactionLevel()==0 → tir IMMÉDIAT) → les listeners synchrones
            // (Release{Stock,Availability}OnOrderCanceled) relâchaient le stock/quota AVANT le
            // delete ; si la tx échouait ensuite (throw AuditLogService::write lock/QueryException),
            // la commande restait VIVANTE avec son stock déjà relâché (sur-disponibilité, faux
            // niveau). loadMissing garde les lignes en mémoire → les listeners post-commit lisent
            // qty/item_id même après le delete des lignes. Idempotent via released_qty.
            $shouldReleaseOnDestroy = ! in_array((int) $order->status, [\App\Enums\OrderStatus::CANCELED, \App\Enums\OrderStatus::REJECTED, \App\Enums\OrderStatus::RETURNED], true);
            if ($shouldReleaseOnDestroy) {
                $order->loadMissing('orderItems');
            }

            DB::transaction(function () use ($order, $actor, $reason, $shouldReleaseOnDestroy) {
                if ($shouldReleaseOnDestroy) {
                    // After-commit : ne tire que si TOUTE la suppression (delete + audit NF525) commit.
                    OrderCanceled::dispatch($order);
                }
                $order->address()?->delete();
                $order->coupon()?->delete();
                $order->orderItems()?->delete();
                // Soft-delete only (Order model uses SoftDeletes)
                $order->delete();

                \App\Models\ActionLog::create([
                    'user_id' => $actor?->id,
                    'action' => 'order.destroyed',
                    'resource' => 'Order #'.$order->id,
                    'details' => json_encode([
                        'order_id' => $order->id,
                        'branch_id' => $order->branch_id,
                        'order_type' => $order->order_type,
                        'status' => $order->status,
                        'payment_status' => $order->payment_status,
                        'total' => $order->total,
                        'reason' => $reason ?: null,
                        'actor_id' => $actor?->id,
                        'actor_branch' => $actor?->branch_id,
                    ]),
                ]);

                // [POS-9.4.BL.2] NF525 audit trail on soft-delete of an order.
                // Critical because order items and address are hard-deleted
                // above (one-way), so the audit chain is the ONLY surviving
                // canonical record of what was on this order at destroy time.
                app(AuditLogService::class)->write([
                    'branch_id' => (int) $order->branch_id,
                    'user_id' => $actor?->id,
                    'action' => 'order.destroyed',
                    'resource' => 'order',
                    'resource_id' => (int) $order->id,
                    'payload' => [
                        'order_serial_no' => $order->order_serial_no,
                        'order_type' => (int) $order->order_type,
                        'status_at_destroy' => (int) $order->status,
                        'payment_status_at_destroy' => (int) $order->payment_status,
                        'total' => round((float) $order->total, 2),
                        'fiscal_sequence_no' => $order->fiscal_sequence_no,
                        'reason' => $reason ?: null,
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
                if (! empty($requests['from_date']) && ! empty($requests['to_date'])) {
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
                        if ($key === 'status') {
                            $query->where($key, (int) $request);
                        } elseif ($key === 'payment_method') {
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
                        } elseif ($key === 'source') {
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
            })->orderBy($orderColumn, $orderType)->get();
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
            $paidOrders = $orders->filter(fn ($o) => \App\Models\Order::isRealizedRevenueRow($o));
            // [SELF-AUDIT R3 P2 2026-07-05 — double comptage des miroirs de remboursement] total_orders =
            // « placed-volume » (cf. commentaire ci-dessus), mais comptait AUSSI les miroirs RETURNED
            // (parent_order_id non nul) → une vente + son remboursement post-Z = 2, alors que TOUS les
            // compteurs Dashboard excluent les miroirs via whereNull('parent_order_id'). On aligne.
            $salesReportArray['total_orders'] = $orders->reject(fn ($o) => $o->parent_order_id !== null)->count();
            $salesReportArray['total_earnings'] = AppLibrary::currencyAmountFormat($paidOrders->sum('total'));
            $salesReportArray['total_discounts'] = AppLibrary::currencyAmountFormat($paidOrders->sum('discount'));
            $salesReportArray['total_delivery_charges'] = AppLibrary::currencyAmountFormat($paidOrders->sum('delivery_charge'));

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

        $query->where($key, 'like', '%'.$this->escapeLike((string) $value).'%');
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
                'discount' => 'Les remises (manuelle, coupon, fidélité) sont désactivées en V1 (correction fiscale TVA/HT en attente). Contactez le responsable.',
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
                'discount' => 'Les remises manuelles sont désactivées en V1 (correction fiscale TVA/HT en attente). Contactez le responsable.',
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

        if (! $user) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => 'Authentication required to apply a discount.',
            ]);
        }

        $pct = ($discount / $backendSubtotal) * 100.0;

        if ($pct > 50.0 && ! $user->can('pos-discount-unlimited')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => 'Remise au-delà de 50 % : seul le responsable peut la valider.',
            ]);
        }

        if ($pct > 10.0
            && ! $user->can('pos-discount-over-10-requires-manager')
            && ! $user->can('pos-discount-unlimited')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => 'Remise au-delà de 10 % : demande la validation d\'un responsable.',
            ]);
        }

        if (! $user->can('pos-discount-up-to-10')
            && ! $user->can('pos-discount-over-10-requires-manager')
            && ! $user->can('pos-discount-unlimited')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => 'Ton compte ne peut pas appliquer de remise en caisse.',
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
                if (! $this->isQueueNumberUniqueViolation($exception) || $attempt >= $maxAttempts) {
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
        $lockKey = 'queue_lock_'.$branchId.'_'.$businessDate;
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

            // [owner 2026-07-07] Le compteur quotidien démarre à kiosk.queue_start_number
            // (32 par défaut) : 1er ordre du jour = A0032, puis suit le max existant.
            $startNumber = max(1, (int) config('kiosk.queue_start_number', 1));

            return 'A'.str_pad(max($maxQueueNum + 1, $startNumber), 4, '0', STR_PAD_LEFT);
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
