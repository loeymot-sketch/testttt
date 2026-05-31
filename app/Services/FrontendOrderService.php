<?php

namespace App\Services;


use Carbon\Carbon;
use Exception;
use App\Models\Tax;
use App\Models\Item;
use App\Enums\TaxType;
use App\Models\Address;
use App\Enums\OrderType;
use App\Models\OrderItem;
use App\Enums\OrderStatus;
use App\Models\OrderCoupon;
use App\Events\SendOrderSms;
use App\Models\OrderAddress;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Enums\PaymentStatus;
use App\Models\Coupon;
use App\Libraries\AppLibrary;
use App\Models\FrontendOrder;
use App\Events\SendOrderGotSms;
use App\Events\SendOrderGotMail;
use App\Events\SendOrderGotPush;
use App\Events\OrderCanceled; // allow: domain event class import — release listener writes its own audit trail via Log warnings on mismatch.
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Enums\PaymentGateway;
use App\Enums\PosPaymentMethod;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Http\Requests\OrderRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;
use App\Http\Requests\OrderStatusRequest;
use App\Domain\Order\AutoPrepareOnPaidPolicy;
use App\Domain\Order\OrderStateMachine;
use App\Services\CouponService;
use App\Services\Pricing\DiscountCalculator;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use App\Services\Menu\AvailabilityService;
use App\Services\Order\OrderQuoteService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontendOrderService
{
    public function __construct(
        protected CouponService $couponService,
        protected PricingService $pricingService,
        protected DiscountCalculator $discountCalculator,
    ) {
    }

    public object $frontendOrder;
    // [AUDIT-P2] Flag set to true when loyalty discount is successfully applied server-side.
    // Exposed in the API response so the kiosk can show a toast if points were silently dropped.
    public bool $loyaltyApplied = false;
    protected array $frontendOrderFilter = [
        'order_serial_no',
        'user_id',
        'branch_id',
        'total',
        'order_type',
        'order_datetime',
        'payment_method',
        'payment_status',
        'status',
        'delivery_boy_id'
    ];

    protected array $exceptFilter = [
        'excepts'
    ];

    /**
     * @throws Exception
     */
    public function myOrder(PaginateRequest $request)
    {
        try {
            $requests = $request->all();
            $method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            // [SECURITY] Whitelist sortable columns to prevent SQL manipulation via order_column
            $allowedColumns = ['id', 'order_serial_no', 'total', 'order_datetime', 'status', 'created_at'];
            $requestedColumn = $request->get('order_column', 'id');
            $frontendOrderColumn = in_array($requestedColumn, $allowedColumns, true) ? $requestedColumn : 'id';
            $requestedType = strtolower($request->get('order_by', 'desc'));
            $frontendOrderType = in_array($requestedType, ['asc', 'desc'], true) ? $requestedType : 'desc';

            return FrontendOrder::with('transaction', 'orderItems', 'branch', 'user')->where('order_type', "!=", OrderType::POS)->where(function ($query) use ($requests) {
                $query->where('user_id', auth()->user()->id);
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->frontendOrderFilter)) {
                        if ($key === "status") {
                            $query->where($key, (int) $request);
                        } elseif ($key === 'branch_id') {
                            $query->where('branch_id', '=', (int) $request);
                        } else {
                            $query->where($key, 'like', '%' . $request . '%');
                        }
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
            })->orderBy($frontendOrderColumn, $frontendOrderType)->$method(
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
        $this->loyaltyApplied = false;
        $idempotencyLock = null;
        // [AUDIT-F-007] Resolve branch context for idempotency lock namespace.
        // Decision orchestrateur 2026-05-08: route /api/frontend/order is dual-purpose
        // (kiosk + web/mobile users). Plan F-007 littéral (hard-fail 403 si KioskMachine
        // absent) casserait web/mobile users régression P0. Option (b) refinée:
        //   1. Préférer KioskMachine.branch_id si présent (kiosk flow)
        //   2. Sinon Auth user.branch_id (web/mobile users)
        //   3. Si toujours 0 → HttpException 422 (ferme leak idempotency cross-branch
        //      identifié comme bug original — 2 keys identiques sur branches différentes
        //      collisionnaient via fallback `?? 0`).
        $kioskMachine = \App\Models\KioskMachine::where('user_id', Auth::id())->first();
        $lockBranchId = (int) ($kioskMachine?->branch_id ?? Auth::user()?->branch_id ?? 0);
        if ($lockBranchId <= 0) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(
                422,
                'Order request has no resolvable branch context (kiosk machine missing or user has no branch).'
            );
        }
        // [SPLASH SECURITY] Idempotency: if the kiosk sends the same key twice (network retry,
        // double-tap), return the existing order instead of creating a duplicate.
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if ($idempotencyKey) {
            $idempotencyLock = Cache::lock(
                'frontend_order_idempotency_' . sha1($lockBranchId . '|' . $idempotencyKey),
                10
            );
            $idempotencyLock->block(5);
            // [SIM-MP] Read must match DB unique (branch_id, idempotency_key) — not key alone.
            $existing = $this->findExistingFrontendOrderForIdempotencyRecovery($idempotencyKey, $lockBranchId);
            if ($existing) {
                $this->frontendOrder = $existing;
                // [AUDIT-P47-BUG10] Restore loyaltyApplied based on existing order's discount
                // so the kiosk shows the correct toast on retry (idempotency hit).
                $this->loyaltyApplied = ($existing->discount > 0);
                return $this->frontendOrder;
            }
        }

        try {
            $shouldAutoAcceptAfterCreate = false;
            $shouldDispatchNewOrderSignals = true;
            $statusChangedAfterCreate = false;
            DB::transaction(function () use (
                $request,
                $idempotencyKey,
                &$shouldAutoAcceptAfterCreate,
                &$shouldDispatchNewOrderSignals,
                &$statusChangedAfterCreate
            ) {
                $validatedRequest = $request->validated();
                $kiosk = \App\Models\KioskMachine::where('user_id', Auth::user()->id)->first();
                $isKioskPaymentMethod = in_array(
                    (int) ($validatedRequest['payment_method'] ?? 0),
                    [PaymentGateway::CASH_ON_DELIVERY, PaymentGateway::CARD, PaymentGateway::TICKET_RESTAURANT],
                    true
                );
                if ($kiosk) {
                    $validatedRequest['branch_id'] = $kiosk->branch_id;
                    // [GAP-22-1] Allow kiosk to send TAKEAWAY (10) or KIOSK (25).
                    // Only force KIOSK if the client sent neither of these valid kiosk types.
                    $clientOrderType = (int) ($validatedRequest['order_type'] ?? 0);
                    if (!in_array($clientOrderType, [OrderType::KIOSK, OrderType::TAKEAWAY], true)) {
                        $validatedRequest['order_type'] = OrderType::KIOSK;
                    }
                }
                $isKioskMachineOrder = (bool) $kiosk;
                $isKioskOrderType = $isKioskMachineOrder && in_array(
                    (int) ($validatedRequest['order_type'] ?? 0),
                    [OrderType::KIOSK, OrderType::TAKEAWAY],
                    true
                );
                $isCounterDeferredKioskCash = $isKioskOrderType
                    && (int) ($validatedRequest['payment_method'] ?? 0) === PaymentGateway::CASH_ON_DELIVERY;
                $shouldAutoAcceptAfterCreate = $isCounterDeferredKioskCash;

                // [F-002 round-3 2026-05-10] OrderCreated dispatch gate.
                //
                // Truth table — when does dispatchNewOrderSignals fire from this
                // method (versus being deferred to finalizePaidKioskOrder)?
                //
                //   Surface             | order_type | pm    | gate | dispatched here?
                //   ------------------- | ---------- | ----- | ---- | ----------------
                //   Web/mobile (no km)  | DELIVERY   | any   | TRUE | YES
                //   Kiosk + cash        | KIOSK      | CASH  | TRUE | YES (auto-accept,
                //                                                       order goes
                //                                                       PENDING_COUNTER
                //                                                       — KDS query
                //                                                       includes that
                //                                                       status so
                //                                                       kitchen starts
                //                                                       prepping while
                //                                                       customer queues
                //                                                       at counter)
                //   Kiosk + card        | KIOSK      | CARD  | FALSE| NO — deferred to
                //                                                    finalizePaidKioskOrder
                //                                                    after TPE confirms
                //   Kiosk + ticket-resto| KIOSK      | TR    | FALSE| NO — same as card
                //
                // The gate is intentional: kiosk card/TR orders sit at PENDING +
                // UNPAID until the TPE callback flips ps=PAID, then OrderCreated
                // fires from finalizePaidKioskOrder line 1151. Firing earlier would
                // expose ghost orders to KDS that may never be paid.
                $shouldDispatchNewOrderSignals = !$isKioskOrderType || $isCounterDeferredKioskCash || !$isKioskPaymentMethod;

                Log::debug('[FrontendOrderService] dispatch gate decision', [
                    'is_kiosk_machine_order'             => $isKioskMachineOrder,
                    'is_kiosk_order_type'                => $isKioskOrderType,
                    'is_kiosk_payment_method'            => $isKioskPaymentMethod,
                    'is_counter_deferred_kiosk_cash'     => $isCounterDeferredKioskCash,
                    'should_auto_accept_after_create'    => $shouldAutoAcceptAfterCreate,
                    'should_dispatch_new_order_signals'  => $shouldDispatchNewOrderSignals,
                    'payment_method'                     => (int) ($validatedRequest['payment_method'] ?? 0),
                    'order_type'                         => (int) ($validatedRequest['order_type'] ?? 0),
                ]);

                // Attach idempotency key if provided by client
                if ($idempotencyKey) {
                    $validatedRequest['idempotency_key'] = substr($idempotencyKey, 0, 64);
                }

                // [GAP-21-2] Unset client-supplied financial fields before FrontendOrder::create().
                // The server recalculates total, subtotal, discount from DB prices below.
                // Prevents any client-manipulated value from persisting even transiently.
                unset($validatedRequest['total'], $validatedRequest['subtotal'], $validatedRequest['discount']);

                $this->frontendOrder = FrontendOrder::create(
                    $validatedRequest + [
                        'user_id'          => Auth::user()->id,
                        'status'           => OrderStatus::PENDING,
                        'order_datetime'   => date('Y-m-d H:i:s'),
                        'preparation_time' => (int) (Settings::group('order_setup')->get('order_setup_food_preparation_time') ?? 15),
                        'payment_status'   => $isCounterDeferredKioskCash ? PaymentStatus::PENDING_COUNTER : PaymentStatus::UNPAID,
                        'pos_payment_method' => $isCounterDeferredKioskCash ? PosPaymentMethod::COUNTER_DEFERRED : null,
                        'total'            => 0,
                        'subtotal'         => 0,
                        'discount'         => 0,
                    ]
                );

                $requestItems = $this->safeJsonDecode($request->items);
                $requestItems = is_array($requestItems) ? $requestItems : [];

                if (config('pricing.use_ssot_service', true)) {
                    $kioskSsot = $this->pricingService->calculateOrder(
                        PricingRequest::forKiosk(
                            $this->frontendOrder->id,
                            (int) $this->frontendOrder->branch_id,
                            $requestItems,
                            (int) $request->coupon_id,
                            (int) Auth::id(),
                            (float) ($this->frontendOrder->delivery_charge ?? 0)
                        ),
                        $this->couponService
                    );
                    $itemsArray = $kioskSsot->orderItemInsertRows;
                    $itemsArray = $this->hydrateAllergenSnapshots($itemsArray);
                    $realSubtotal = $kioskSsot->accumulatedSubtotal;
                    $totalTax = $kioskSsot->totalTax;
                    $calculatedDiscount = $kioskSsot->discount;
                    if (!blank($itemsArray)) {
                        OrderItem::insert($itemsArray);
                    }
                } else {
                    $i = 0;
                    $totalTax = 0;
                    $itemsArray = [];
                    $taxes = AppLibrary::pluck(Tax::get(), 'obj', 'id');

                    $realSubtotal = 0;
                    
                    // [PERF-02] Bulk-load toutes les items, variations et extras avant la boucle
                    $requestedItemIds = collect($requestItems)->pluck('item_id')->filter()->unique()->toArray();
                    $dbItems = Item::select('id', 'price', 'tax_id')
                        ->whereIn('id', $requestedItemIds)
                        ->get()
                        ->keyBy('id');
                    
                    // Extraire tax_id pour compatibilité avec code existant
                    $items = $dbItems->pluck('tax_id', 'id');
                    
                    $variationIds = collect($requestItems)
                        ->pluck('item_variations')
                        ->flatten(1)
                        ->pluck('id')
                        ->filter()
                        ->unique()
                        ->toArray();
                    
                    $extraIds = collect($requestItems)
                        ->pluck('item_extras')
                        ->flatten(1)
                        ->pluck('id')
                        ->filter()
                        ->unique()
                        ->toArray();
                    
                    $dbVariations = !empty($variationIds)
                        ? \App\Models\ItemVariation::whereIn('id', $variationIds)->get()->keyBy('id')
                        : collect();
                    
                    $dbExtras = !empty($extraIds)
                        ? \App\Models\ItemExtra::whereIn('id', $extraIds)->get()->keyBy('id')
                        : collect();

                    app(AvailabilityService::class)->assertItemsOrderableForBranch(
                        (int) $this->frontendOrder->branch_id,
                        $requestedItemIds,
                        true
                    );

                    if (!blank($requestItems)) {
                        foreach ($requestItems as $item) {
                            // [PLAN_01 D-001] REJETER ITEM INEXISTANT - Pas de fallback sur prix client
                            $dbItem = $dbItems[$item->item_id] ?? null;
                            if (!$dbItem) {
                                throw new \InvalidArgumentException(
                                    "Item ID {$item->item_id} introuvable. Commande rejetée.",
                                    422
                                );
                            }
                            $itemPrice = $dbItem->price; // ← prix TOUJOURS depuis la DB

                            // [PERF-02] Calculer prix variations depuis collection pre-chargée
                            // [T05] Multi-quantity support: variations may carry optional `quantity` (default 1).
                            $calcVariationTotal = 0;
                            if (!empty($item->item_variations)) {
                                foreach ($item->item_variations as $var) {
                                    $varId = $var->id ?? 0;
                                    $dbVar = $dbVariations[$varId] ?? null;
                                    if (!$dbVar) {
                                        throw new \InvalidArgumentException(
                                            "Variation ID {$varId} introuvable pour l'article {$item->item_id}.",
                                            422
                                        );
                                    }
                                    // [GAP-21-3] Cross-item injection guard: reject variation that
                                    // belongs to a different item — prevents price manipulation via
                                    // a cheap item's variation applied to an expensive item.
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
                            
                            // [PERF-02] Calculer prix extras depuis collection pre-chargée
                            // [T05] Multi-quantity support: extras may carry optional `quantity` (default 1).
                            $calcExtraTotal = 0;
                            if (!empty($item->item_extras)) {
                                foreach ($item->item_extras as $ext) {
                                    $extId = $ext->id ?? 0;
                                    $dbExt = $dbExtras[$extId] ?? null;
                                    if (!$dbExt) {
                                        throw new \InvalidArgumentException(
                                            "Extra ID {$extId} introuvable pour l'article {$item->item_id}.",
                                            422
                                        );
                                    }
                                    // [GAP-21-3] Cross-item injection guard: reject extra that
                                    // belongs to a different item.
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
                            $verifiedTotalPrice = round(($itemPrice + $calcVariationTotal + $calcExtraTotal) * $verifiedQuantity, 2);
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
                                'order_id' => $this->frontendOrder->id,
                                'branch_id' => $this->frontendOrder->branch_id,
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

                    $itemsArray = $this->hydrateAllergenSnapshots($itemsArray);

                    if (!blank($itemsArray)) {
                        OrderItem::insert($itemsArray);
                    }
                }

                // [PHASE 7] SECURISATION P0 COUPON / DISCOUNT (legacy path recalc; SSOT keeps totals from PricingService)
                $validatedCoupon = null;
                if (!config('pricing.use_ssot_service', true)) {
                    $calculatedDiscount = 0;
                    if ($request->coupon_id > 0) {
                        $validatedCoupon = $this->couponService->resolveCouponById(
                            (int) $request->coupon_id,
                            (float) $realSubtotal,
                            (int) Auth::id()
                        );
                        $calculatedDiscount = $this->couponService->calculateDiscountAmount(
                            $validatedCoupon,
                            (float) $realSubtotal
                        );
                    }
                } elseif ($request->coupon_id > 0) {
                    $validatedCoupon = $this->couponService->resolveCouponById(
                        (int) $request->coupon_id,
                        (float) $realSubtotal,
                        (int) Auth::id()
                    );
                }

                $this->applyKioskLoyaltyDiscount(
                    $request,
                    $validatedCoupon,
                    (float) $realSubtotal,
                    $calculatedDiscount
                );

                // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Single fiscal gate
                // for the customer-facing kiosk/web path. After coupon (SSOT or
                // legacy) + kiosk loyalty redeem, $calculatedDiscount holds the
                // total discretionary discount. At a non-zero VAT rate the frozen
                // PricingService/ZReportService compute per-line TVA on the
                // PRE-discount base → a discounted order signs a fiscally-incorrect
                // NF525 Z (the F1 defect). Loyalty AUTO-accrues, so this path is
                // reachable with zero admin action — it MUST be gated by code, not
                // data. Refuse any non-zero discount until F1 is fixed under a
                // lock-plan (same master flag as POS/admin: pos.manual_discount_enabled).
                $this->assertDiscretionaryDiscountAllowed((float) $calculatedDiscount);

                $this->saveFrontendOrderWithQueueNumber(function () use ($request, $totalTax, $realSubtotal, $calculatedDiscount, $isKioskMachineOrder, $idempotencyKey): void {
                    $this->frontendOrder->order_serial_no = date('dmy') . $this->frontendOrder->id;
                    $this->frontendOrder->total_tax = round($totalTax, 2);
                    $this->frontendOrder->subtotal = round($realSubtotal, 2);
                    $this->frontendOrder->discount = $calculatedDiscount;
                    // [TTC-MODE] In TTC mode, $realSubtotal already contains tax.
                    if ((bool) config('pricing.tax_inclusive_prices', false)) {
                        $this->frontendOrder->total = round(max(0, $realSubtotal + $this->frontendOrder->delivery_charge - $calculatedDiscount), 2);
                    } else {
                        $this->frontendOrder->total = round(max(0, $realSubtotal + $totalTax + $this->frontendOrder->delivery_charge - $calculatedDiscount), 2);
                    }
                    if ($isKioskMachineOrder) {
                        app(OrderQuoteService::class)->sealForCommit(
                            $request,
                            'kiosk',
                            (int) $this->frontendOrder->id,
                            (float) $this->frontendOrder->total
                        );
                    }

                    app(\App\Services\Stock\StockService::class)->decrementForOrder($this->frontendOrder, $idempotencyKey);

                    // [SPLASH LOYALTY] Store the loyalty customer code so the AwardLoyaltyPointsOnDelivery
                    // listener can credit the right customer even on kiosk orders (user_id = machine, not customer)
                    if ($request->loyalty_code) {
                        $this->frontendOrder->loyalty_customer_code = $request->loyalty_code;
                    }
                    // Track which surface generated this order for loyalty analytics
                    if (!$this->frontendOrder->source_surface) {
                        $orderType = (int) ($this->frontendOrder->order_type ?? 0);
                        $isKiosk = in_array($orderType, [\App\Enums\OrderType::KIOSK, \App\Enums\OrderType::TAKEAWAY], true);
                        $this->frontendOrder->source_surface = $isKiosk ? 'kiosk' : 'web';
                    }
                }, $isKioskMachineOrder ? 'kiosk' : 'frontend');

                if ($request->address_id) {
                    // [SECURITY-IDOR / Sprint 2B DEL-2] Ensure the address belongs to the
                    // authenticated user. Without this check, any user could reference
                    // another user's address_id and snapshot their private address data
                    // onto an order.
                    //
                    // Prior implementation silently skipped OrderAddress::create when the
                    // ownership check failed — leaving DELIVERY orders persisted in the
                    // database with no attached shipping address. Wave E audit flagged
                    // this as P0: a forged address_id would be rejected (no snapshot
                    // written) but the order itself was still created, leaving the
                    // kitchen / delivery boy with no destination AND no audit trail of
                    // the IDOR attempt.
                    //
                    // The fix throws OrderAddressOwnershipException (HTTP 403) which
                    // bubbles through catch (HttpException) at line 590 to Laravel's
                    // exception handler, and exits this DB::transaction closure WITHOUT
                    // a commit, so the FrontendOrder + OrderItem + OrderCoupon rows and
                    // the StockService decrement that share the same transaction all
                    // roll back atomically.
                    $address = Address::where('id', $request->address_id)
                        ->where('user_id', Auth::user()->id)
                        ->first();
                    if (! $address) {
                        Log::warning('[FrontendOrder] OrderAddress IDOR refused', [
                            'address_id' => (int) $request->address_id,
                            'user_id'    => (int) Auth::id(),
                            'order_id'   => (int) ($this->frontendOrder->id ?? 0),
                        ]);
                        throw new \App\Exceptions\OrderAddressOwnershipException();
                    }
                    OrderAddress::create([
                        'order_id'  => $this->frontendOrder->id,
                        'user_id'   => Auth::user()->id,
                        'label'     => $address->label,
                        'address'   => $address->address,
                        'apartment' => $address->apartment,
                        'latitude'  => $address->latitude,
                        'longitude' => $address->longitude,
                    ]);
                }

                if ($validatedCoupon instanceof Coupon) {
                    OrderCoupon::create([
                        'order_id' => $this->frontendOrder->id,
                        'coupon_id' => $validatedCoupon->id,
                        'user_id' => Auth::user()->id,
                        'discount' => $calculatedDiscount
                    ]);
                }

                if ($shouldAutoAcceptAfterCreate) {
                    $this->frontendOrder->status = OrderStatus::ACCEPT;
                    $this->frontendOrder->save();
                    $statusChangedAfterCreate = true;
                }

                // [Wave M / Heal Z2 P1 — 2026-05-19] OrderCreated::dispatch
                // moved INSIDE the closure (was previously fired via helper
                // `dispatchNewOrderSignals` AFTER `});` at line ~605). With
                // transactionLevel()>0 the DispatchableAfterCommit trait
                // (`app/Events/Concerns/DispatchableAfterCommit.php:31-39`)
                // registers via afterCommit() — broadcast fires after
                // outermost commit, is DROPPED on rollback. Mail/SMS/Push
                // queue jobs remain post-`});` via control flow (their own
                // queue dedupe story is sufficient). Sentinel:
                // `tests/Feature/Sync/OrderCreatedDispatchPlacementSentinelTest`.
                if ($shouldDispatchNewOrderSignals) {
                    OrderCreated::dispatch($this->frontendOrder);
                }
            });

            if ($statusChangedAfterCreate) {
                OrderStateMachine::recordTransition(
                    FrontendOrder::class,
                    (int) $this->frontendOrder->id,
                    OrderStatus::PENDING,
                    OrderStatus::ACCEPT,
                    null,
                    null
                );
                $this->dispatchOrderStatusSignals($this->frontendOrder, OrderStatus::PENDING, OrderStatus::ACCEPT);
            }

            // [BUG-C1 FIX] Dispatch notifications AFTER transaction commit
            // Prevents ghost KDS orders if the transaction rolls back after these dispatches
            // [FEAT] OrderCreated broadcast enables real-time KDS/OSS updates via Soketi
            try {
                $notifStatus = $this->frontendOrder->status; // ACCEPT for kiosk, PENDING for others
                SendOrderMail::dispatch(['order_id' => $this->frontendOrder->id, 'status' => $notifStatus]);
                SendOrderSms::dispatch(['order_id' => $this->frontendOrder->id, 'status' => $notifStatus]);
                SendOrderPush::dispatch(['order_id' => $this->frontendOrder->id, 'status' => $notifStatus]);
                if ($shouldDispatchNewOrderSignals) {
                    $this->dispatchNewOrderSignals($this->frontendOrder);
                }
            } catch (\Exception $e) {
                Log::warning('[FrontendOrder] Post-commit notifications failed for order #' . $this->frontendOrder->id . ': ' . $e->getMessage());
            }

            return $this->frontendOrder;
        } catch (HttpException $exception) {
            throw $exception;
        } catch (\Illuminate\Database\QueryException $qe) {
            // [FIX-54-6] Catch MySQL duplicate key on idempotency_key UNIQUE constraint.
            // Same recovery logic as OrderService::posOrderStore() for consistency.
            if ($qe->getCode() === '23000' && $idempotencyKey) {
                $existing = $this->findExistingFrontendOrderForIdempotencyRecovery($idempotencyKey, $lockBranchId);
                if ($existing) {
                    Log::info('[Kiosk Idempotency] Duplicate key caught at DB level — returning existing order #' . $existing->id);
                    return $existing;
                }
            }
            Log::info($qe->getMessage());
            throw new Exception(QueryExceptionLibrary::message($qe), 422);
        } catch (Exception $exception) {
            // Note: DB::transaction() already rolls back on exception.
            // Calling DB::rollBack() here is redundant and can interfere with nested transactions.
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        } finally {
            if ($idempotencyLock) {
                optional($idempotencyLock)->release();
            }
        }
    }

    /**
     * @throws Exception
     */
    public function show(FrontendOrder $frontendOrder): FrontendOrder|array
    {
        try {
            if ((int) $frontendOrder->user_id === (int) Auth::id()) {
                return $frontendOrder;
            }
            abort(403, 'Access denied: you do not own this order.');
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    protected function findExistingFrontendOrderForIdempotencyRecovery(?string $idempotencyKey, int $branchId): ?FrontendOrder
    {
        if (blank($idempotencyKey) || $branchId <= 0) {
            return null;
        }

        return FrontendOrder::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('branch_id', $branchId)
            ->first();
    }

    /**
     * @throws Exception
     */
    public function changeStatus(FrontendOrder $frontendOrder, OrderStatusRequest $request): FrontendOrder
    {
        try {
            if (!(new \App\Rules\ValidStatusTransition($frontendOrder->status))->passes('status', $request->status)) {
                throw new Exception(trans('all.message.invalid_status_transition'), 422);
            }
            if ((int) $frontendOrder->user_id === (int) Auth::id()) {
                $targetStatus = (int) $request->status;

                if ((int) $frontendOrder->status === $targetStatus) {
                    return $frontendOrder;
                }

                if ($targetStatus !== (int) OrderStatus::CANCELED) {
                    throw new Exception(trans('all.message.invalid_status_transition'), 422);
                }

                if ($targetStatus === (int) OrderStatus::CANCELED) {
                    // [FIX] Both KIOSK (25) and TAKEAWAY (10) from kiosk machine follow the same
                    // cancel threshold: allow cancel until PREPARING starts.
                    $isKioskOrder = in_array(
                        (int) $frontendOrder->order_type,
                        [OrderType::KIOSK, OrderType::TAKEAWAY],
                        true
                    );
                    $cancelableThreshold = $isKioskOrder ? OrderStatus::PREPARING : OrderStatus::ACCEPT;

                    if ($frontendOrder->status >= $cancelableThreshold) {
                        throw new Exception(trans('all.message.order_accept'), 422);
                    }

                    if ($frontendOrder->transaction) {
                        app(PaymentService::class)->cashBack(
                            $frontendOrder,
                            'credit',
                            'TXN-' . \Illuminate\Support\Str::random(12)
                        );
                    }
                    app(LoyaltyService::class)->refundPoints($frontendOrder, 'kiosk');
                    $oldStatus = $frontendOrder->status;
                    // [AUDIT-F-004] Propagate caller-supplied reason into the transition row.
                    // OrderStatusRequest enforces non-empty reason on terminal transitions
                    // (kiosk: enum whitelist; admin/staff: free-text). Persisting NULL here
                    // would silently break the ORDER_FLOW.md §49 audit invariant.
                    $cancelReason = $request->input('reason');
                    if (is_string($cancelReason)) {
                        $cancelReason = trim($cancelReason);
                        if ($cancelReason === '') {
                            $cancelReason = null;
                        }
                    }
                    if ($cancelReason !== null && $frontendOrder->isFillable('reason')) {
                        $frontendOrder->reason = $cancelReason;
                    }
                    $frontendOrder->status = $request->status;
                    $frontendOrder->save();
                    OrderStateMachine::recordTransition(
                        FrontendOrder::class,
                        (int) $frontendOrder->id,
                        (int) $oldStatus,
                        (int) $request->status,
                        Auth::check() ? (int) Auth::id() : null,
                        $cancelReason
                    );
                    // [BUG-1 FIX] Notify KDS/OSS that order is cancelled so it disappears from screens.
                    // Use OrderStatusChanged::dispatch (DispatchableAfterCommit) — not event(new …), which
                    // bypasses the trait and can fire before DB commit.
                    try {
                        OrderStatusChanged::dispatch(
                            $frontendOrder,
                            $oldStatus,
                            (int) $request->status
                        );
                    } catch (\Exception $e) {
                        Log::warning('[FrontendOrder] OrderStatusChanged on cancel failed: ' . $e->getMessage());
                    }
                    SendOrderMail::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
                    SendOrderSms::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
                    SendOrderPush::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
                    // [F-01] Compensating release of branch-scoped stock counters on customer
                    // self-cancel of a kiosk / takeaway order. Idempotent via released_qty.
                    try {
                        OrderCanceled::dispatch($frontendOrder); // allow: stock-release dispatch; OrderStateMachine::recordTransition already wrote the canonical state-transition audit row above.
                    } catch (\Exception $e) {
                        Log::warning('[FrontendOrder] OrderCanceled on cancel failed: ' . $e->getMessage()); // allow: warning only
                    }
                }
            } else {
                abort(403, 'Access denied: you do not own this order.');
            }
            return $frontendOrder;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Customer-facing (kiosk/web)
     * fiscal-correctness gate for ANY discretionary discount (coupon + kiosk
     * loyalty redeem). Mirrors OrderService::assertDiscretionaryDiscountAllowed.
     * At a non-zero VAT rate the frozen PricingService/ZReportService compute
     * per-line TVA on the PRE-discount base → a discounted order signs a
     * fiscally-incorrect NF525 Z (F1, dormant only at 0% VAT). Refused in V1
     * until F1 is fixed under a lock-plan (pos.manual_discount_enabled = the
     * discretionary-discount master flag).
     */
    private function assertDiscretionaryDiscountAllowed(float $discount): void
    {
        if ($discount > 0.0 && config('pos.manual_discount_enabled') !== true) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => "Les remises (coupon, fidélité) sont désactivées en V1 (correction fiscale TVA/HT en attente).",
            ]);
        }
    }

    /**
     * Apply kiosk loyalty redemption exactly once inside the order transaction.
     */
    private function applyKioskLoyaltyDiscount(
        OrderRequest $request,
        ?Coupon $validatedCoupon,
        float $realSubtotal,
        float &$calculatedDiscount
    ): void {
        $loyaltyCode = trim((string) $request->input('loyalty_code', ''));
        $requestedDiscount = (float) $request->input('discount', 0);

        if ($loyaltyCode === '' || $requestedDiscount <= 0.0) {
            return;
        }

        if ($validatedCoupon instanceof Coupon || (int) $request->input('coupon_id', 0) > 0) {
            Log::info('[Loyalty] Loyalty discount skipped because coupon takes priority on frontend order.');
            return;
        }

        // Lock the customer row before deciding whether to consume a pending kiosk redemption
        // or create a new ledger entry. This keeps points and ledger in the same DB transaction.
        $loyaltyUser = \App\Models\User::where('loyalty_code', $loyaltyCode)
            ->where('status', 1)
            ->lockForUpdate()
            ->first();

        if (!$loyaltyUser) {
            return;
        }

        $redemption = $this->discountCalculator->kioskLoyaltyRedemption(
            $validatedCoupon,
            $loyaltyCode,
            $requestedDiscount,
            $realSubtotal,
            $loyaltyUser
        );
        $maxDiscount = (float) $redemption['discount'];
        $pointsRequired = (int) $redemption['points'];

        if ($pointsRequired <= 0 || $maxDiscount <= 0.0) {
            Log::warning('[Loyalty] Redemption skipped after locked balance check', [
                'user_id' => $loyaltyUser->id,
                'order_id' => $this->frontendOrder->id,
                'requested_discount' => $requestedDiscount,
                'available' => $loyaltyUser->loyalty_points,
            ]);
            return;
        }

        $pendingRedeem = \App\Models\LoyaltyTransaction::query()
            ->where('user_id', $loyaltyUser->id)
            ->where('loyalty_code', $loyaltyUser->loyalty_code)
            ->where('type', 'redeem')
            ->where('source_surface', 'kiosk')
            ->whereNull('order_id')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if ($pendingRedeem) {
            if (abs((int) $pendingRedeem->points) !== $pointsRequired) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'loyalty_code' => 'A pending loyalty redemption exists for a different discount amount.',
                ]);
            }

            $pendingRedeem->order_id = $this->frontendOrder->id;
            $pendingRedeem->description = 'Reduction fidelite kiosk rattachee a la commande';
            $pendingRedeem->save();

            $calculatedDiscount += $maxDiscount;
            $this->loyaltyApplied = true;

            Log::info('[Loyalty] Pending kiosk redeem attached without second deduction', [
                'user_id' => $loyaltyUser->id,
                'order_id' => $this->frontendOrder->id,
                'transaction_id' => $pendingRedeem->id,
            ]);
            return;
        }

        $balanceAfter = (int) $loyaltyUser->loyalty_points - $pointsRequired;

        DB::table('users')
            ->where('id', $loyaltyUser->id)
            ->update([
                'loyalty_points' => $balanceAfter,
                'updated_at' => now(),
            ]);

        $this->createKioskLoyaltyRedeemLedger($loyaltyUser, $pointsRequired, $balanceAfter);

        $calculatedDiscount += $maxDiscount;
        $this->loyaltyApplied = true;

        Log::info("[Loyalty] {$pointsRequired} pts redeemed for user #{$loyaltyUser->id} (-{$maxDiscount} EUR)");
    }

    private function createKioskLoyaltyRedeemLedger(
        \App\Models\User $loyaltyUser,
        int $pointsRequired,
        int $balanceAfter
    ): \App\Models\LoyaltyTransaction {
        try {
            return \App\Models\LoyaltyTransaction::create([
                'user_id'        => $loyaltyUser->id,
                'loyalty_code'   => $loyaltyUser->loyalty_code,
                'order_id'       => $this->frontendOrder->id,
                'type'           => 'redeem',
                'points'         => -$pointsRequired,
                'balance_after'  => $balanceAfter,
                'source_surface' => 'kiosk',
                'description'    => 'Reduction fidelite appliquee sur commande kiosk',
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23000') {
                throw $exception;
            }

            $existing = \App\Models\LoyaltyTransaction::query()
                ->where('user_id', $loyaltyUser->id)
                ->where('order_id', $this->frontendOrder->id)
                ->where('type', 'redeem')
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                throw $exception;
            }

            return $existing;
        }
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

    /**
     * @param  array<int, array<string, mixed>>  $itemsArray
     * @return array<int, array<string, mixed>>
     */
    private function hydrateAllergenSnapshots(array $itemsArray): array
    {
        // [W3.A — gate "tout vert"] Delegates to the shared helper so the
        // Kiosk path (FrontendOrderService) and the POS path (OrderService)
        // emit the SAME allergen snapshot — including allergens carried by
        // item_extras (resolves OrderAllergenSnapshotComposedTest sentinel).
        // Helper is idempotent and falls back gracefully when the
        // item_extra_allergens pivot is absent.
        return \App\Services\Orders\OrderItemAllergenSnapshot::hydrate($itemsArray);
    }

    private function saveFrontendOrderWithQueueNumber(callable $applyFields, string $context): void
    {
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $businessDate = $this->resolveBusinessDate($this->frontendOrder->order_datetime ?? null);
            $this->frontendOrder->business_date = $businessDate;
            $this->frontendOrder->queue_number = $this->allocateQueueNumber(
                (int) $this->frontendOrder->branch_id,
                $businessDate,
                $context
            );
            $applyFields();
            $this->frontendOrder->business_date = $businessDate;

            try {
                $this->frontendOrder->save();
                return;
            } catch (QueryException $exception) {
                if (!$this->isQueueNumberUniqueViolation($exception) || $attempt >= $maxAttempts) {
                    throw $exception;
                }

                Log::warning(sprintf(
                    '[Queue] Duplicate queue_number %s for branch %s on business_date %s during %s save; retrying allocation once.',
                    (string) $this->frontendOrder->queue_number,
                    (string) $this->frontendOrder->branch_id,
                    (string) $this->frontendOrder->business_date,
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
     * @return array<int, string>
     */
    private function resolveAllergenSnapshot(?Item $item): array
    {
        if (!$item) {
            return [];
        }

        $pivotCodes = $item->relationLoaded('allergens')
            ? $item->allergens->pluck('code')->filter()->values()->all()
            : $item->allergens()->orderBy('sort')->pluck('code')->filter()->values()->all();

        if ($pivotCodes !== []) {
            return $pivotCodes;
        }

        if (!method_exists(AllergenService::class, 'projectFlags')) {
            // Legacy fallback only for environments that have not yet shipped AllergenService::projectFlags.
            return collect($item->allergen_flags ?? [])
                ->filter(fn ($code): bool => is_string($code) && $code !== '')
                ->values()
                ->all();
        }

        return [];
    }

    public function finalizePaidKioskOrder(FrontendOrder $frontendOrder): bool
    {
        $isKioskMachineOrder = \App\Models\KioskMachine::where('user_id', $frontendOrder->user_id)->exists();
        $isKioskOrderType = $isKioskMachineOrder && in_array(
            (int) $frontendOrder->order_type,
            [OrderType::KIOSK, OrderType::TAKEAWAY],
            true
        );
        $isDeferredPaymentMethod = in_array(
            (int) $frontendOrder->payment_method,
            [PaymentGateway::CARD, PaymentGateway::TICKET_RESTAURANT],
            true
        );

        if (!$isKioskOrderType || !$isDeferredPaymentMethod) {
            return false;
        }

        $promoted = false;
        // [Wave M / Heal Z5 P1-C — 2026-05-19] Captures state required by
        // the post-transaction flag write. The flag MUST be persisted
        // OUTSIDE the parent DB::transaction (see comment at the raw
        // DB::table('orders') call below) — so we collect the intent
        // inside the closure and act on it once the transaction has
        // settled. Audit reference: RED-Z5 §B F-Z5-P1-C.
        $allocFailed = false;
        $allocFailureError = null;

        DB::transaction(function () use ($frontendOrder, &$promoted, &$allocFailed, &$allocFailureError) {
            $locked = FrontendOrder::where('id', $frontendOrder->id)
                ->lockForUpdate()
                ->first();

            // [AUDIT-F-013] Whitelist explicit PENDING — only PENDING (1) may be
            // promoted to ACCEPT here. The previous `>= ACCEPT` check was
            // numerically equivalent today (PENDING=1 is the only status < 4) but
            // would silently break if a future intermediate status (e.g. fraud
            // hold) were inserted between PENDING and ACCEPT. Whitelist makes
            // intent explicit and forces a deliberate state-machine review for
            // any new intermediate status. See plan
            // .claude/worktrees/blissful-mclean-c915c2/plans/PLAN_AUDIT_F013_FINALIZE_STATE_GUARD_2026-05-07.md
            if (! in_array((int) $locked->status, [OrderStatus::PENDING], true)) {
                return;
            }

            // [F-21] Defense in depth — never advance to ACCEPT without confirmed payment.
            // Re-check inside the lock to prevent race / misuse from any caller path
            // (controller already pre-checks, but service must guarantee invariant on
            // its own — see tasks/gates/GATE_FROZEN_F21_FINALIZE_PAID_KIOSK_2026-04-23.md).
            if ((int) $locked->payment_status !== PaymentStatus::PAID) {
                Log::warning('finalizePaidKioskOrder called without confirmed payment', [
                    'order_id'       => $locked->id,
                    'payment_status' => $locked->payment_status,
                    'order_type'     => $locked->order_type,
                ]);
                return;
            }

            // [P-K11-FZH / KR1] M-08 OPTION B SUPERSEDED — auto-allocate
            // fiscal_sequence_no for kiosk direct TPE so orders enter Z
            // aggregation immediately, not only when manually POS-collected.
            // Without this, a kiosk-paid card order could remain unsealed
            // indefinitely (NF525 fiscal gap).
            //
            // Feature flag `fiscal.kiosk_auto_allocate_sequence` (default true)
            // gates the auto-allocation. Override via
            // FISCAL_KIOSK_AUTO_ALLOCATE_SEQUENCE=false for emergency rollback
            // to legacy M-08 Option B behaviour.
            //
            // Allocation runs INSIDE the same DB::transaction so any failure
            // rolls back the status promotion as well — order stays PENDING
            // until a future retry or manual POS collection.
            if ($locked->fiscal_sequence_no === null
                && config('fiscal.kiosk_auto_allocate_sequence', true)
            ) {
                try {
                    $newSeq = app(\App\Services\Fiscal\FiscalSequenceService::class)
                        ->next((int) $locked->branch_id);
                    $locked->fiscal_sequence_no = $newSeq;

                    // [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY]
                    // Clear the error flag — a previous failed attempt may
                    // have set it, and a successful retry brings the row
                    // back to the happy invariant.
                    if (!is_null($locked->fiscal_alloc_error_at)) {
                        $locked->fiscal_alloc_error_at = null;
                    }

                    Log::channel('fiscal')->info('kiosk.fiscal_sequence_auto_allocated', [
                        'event'              => 'kiosk.fiscal_sequence_auto_allocated',
                        'order_id'           => $locked->id,
                        'branch_id'          => $locked->branch_id,
                        'fiscal_sequence_no' => $newSeq,
                        'payment_method'     => $locked->payment_method,
                        'source_surface'     => $locked->source_surface ?? null,
                    ]);
                } catch (\Throwable $e) {
                    // [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY] +
                    // [Wave M / Heal Z5 P1-C — 2026-05-19 deferred flag write]
                    //
                    // History:
                    //   - iter13 ORDER-PATH P1 (pre-iter14): catch re-threw,
                    //     entire tx rolled back, row stayed PAID+PENDING+seq=NULL
                    //     with NO marker — unrecoverable orphan if the caller
                    //     also crashed.
                    //   - iter14 (commit 3150992a7): catch set
                    //     `$locked->fiscal_alloc_error_at = now(); $locked->save();`
                    //     INSIDE this tx. Fixed the dominant case but if the
                    //     save() itself threw (trigger, FK, DB hiccup) the
                    //     throw bubbled out of the closure, the parent tx
                    //     rolled back, flag lost — pre-iter14 orphan
                    //     reproduced for a narrow nested-failure edge case
                    //     (RED-Z5 §B F-Z5-P1-C).
                    //   - Wave M: capture failure intent here (no in-tx save)
                    //     and persist the flag via a raw DB::table()->update()
                    //     OUTSIDE this transaction so the flag write cannot
                    //     be rolled back together with the failed alloc tx.
                    //     The raw update has its own try/catch so even a DB
                    //     hiccup at flag-write time degrades to a log + no
                    //     orphan (the row is in the same observable state
                    //     as iter13-pre-fix, but the audit trail is intact).
                    $allocFailed = true;
                    $allocFailureError = $e;

                    // promoted stays false — caller sees no exception, KDS
                    // does not pick the order up (status still PENDING),
                    // retry cron will retry once the flag is set (below).
                    return;
                }
            }

            $locked->status = OrderStatus::ACCEPT;
            $locked->save();
            $promoted = true;

            // [Wave S-1 — P-OWNER 2026-05-20] Auto-transition ACCEPT → PREPARING
            // for kiosk orders settled online via TPE (CARD / TICKET_RESTAURANT)
            // — the kitchen receives the ticket already "en cours" without a
            // second tap. Kiosk cash-at-counter never reaches this path
            // (it stays UNPAID at creation and is collected via
            // PaymentService::confirmCounterPayment), so no S-5 exception
            // needed here.
            //
            // The transition lives INSIDE the same DB::transaction so an
            // outer rollback (e.g. broadcast registration glitch) discards
            // both the ACCEPT promotion and the PREPARING flip atomically —
            // status never observed half-applied. OrderCreated::dispatch
            // below picks up the fresh `$locked->status` (PREPARING when the
            // policy fires) so PersistOrderCreatedToOutbox encodes the
            // correct payload for KDS.
            //
            // OrderStateMachine::allows(ACCEPT, PREPARING) is true (line 45
            // of OrderStateMachine.php). We use the historical
            // `$locked->status = NEXT; ->save();` pattern per CLAUDE.md §7
            // frozen-zone rule for FrontendOrderService — recordTransition
            // is best-effort and called after save() to keep the audit
            // chain consistent.
            $sourceSurface = (string) ($locked->source_surface ?? 'kiosk');
            if (AutoPrepareOnPaidPolicy::shouldPromote(
                surface: $sourceSurface,
                posPaymentMethod: $locked->pos_payment_method !== null
                    ? (int) $locked->pos_payment_method
                    : null,
                isCounterCollect: false,
            )) {
                $locked->status = AutoPrepareOnPaidPolicy::nextStatus();
                $locked->save();

                OrderStateMachine::recordTransition(
                    FrontendOrder::class,
                    (int) $locked->id,
                    OrderStatus::ACCEPT,
                    OrderStatus::PREPARING,
                    null,
                    'auto_prepare_on_paid (Wave S-1 kiosk paid TPE)',
                );
            }

            // [Wave M / Heal Z2 P1 — 2026-05-19 + advisor pivot 2026-05-19]
            // OrderCreated::dispatch moved INSIDE the closure so
            // DispatchableAfterCommit engages (transactionLevel()>0 →
            // afterCommit). On rollback the broadcast is dropped — KDS
            // never observes a ghost ACCEPT'd kiosk order.
            //
            // IMPORTANT — pass `$locked`, NOT the caller's `$frontendOrder`.
            // Pre-Wave-M, the dispatch happened outside the closure AFTER
            // `$frontendOrder->refresh()` (see line ~1275 below) so the
            // event captured the fresh ACCEPT status + cleared
            // fiscal_alloc_error_at. Inside the closure we hold the lock
            // on `$locked`; that is the instance whose in-memory state
            // mirrors the freshly-committed row. `$frontendOrder` (caller
            // parameter) is NOT mutated by this method and would broadcast
            // status=PENDING, which is exactly the symptom this heal is
            // supposed to prevent. PersistOrderCreatedToOutbox reads
            // `$order->status` into the payload (line 39 of that file) —
            // so passing the stale instance would mark the broadcast
            // ACCEPT-promotion event as still PENDING.
            //
            // The mail/SMS/push queue jobs at the bottom of this method
            // continue to fire after the closure via control flow.
            // Sentinel:
            // `tests/Feature/Sync/OrderCreatedDispatchPlacementSentinelTest`.
            OrderCreated::dispatch($locked);
        });

        if ($allocFailed) {
            // [Wave M / Heal Z5 P1-C — 2026-05-19] Persist the
            // `fiscal_alloc_error_at` marker via a raw query OUTSIDE the
            // closed-out parent transaction. Reasons:
            //   1. Raw DB::table()->update() does not engage Eloquent
            //      events, observers, or model boots — minimal failure
            //      surface vs. $locked->save().
            //   2. We are after `DB::transaction(...)` returned, so any
            //      throw here cannot roll back the previously-attempted
            //      (and rolled-back) alloc tx — they are independent.
            //   3. Wrapped in its own try/catch so a flag-write hiccup
            //      logs but does not propagate to the controller / kiosk
            //      caller (mirroring iter14's "catch and degrade" pattern).
            // Sentinel:
            // `tests/Feature/Fiscal/FiscalAllocErrorFlagOutsideTxSentinelTest`.
            try {
                DB::table('orders')
                    ->where('id', $frontendOrder->id)
                    ->update(['fiscal_alloc_error_at' => now()]);
            } catch (\Throwable $flagWriteError) {
                Log::channel('fiscal')->error('kiosk.fiscal_alloc_error_flag_write_failed', [
                    'event'    => 'kiosk.fiscal_alloc_error_flag_write_failed',
                    'order_id' => $frontendOrder->id,
                    'error'    => $flagWriteError->getMessage(),
                ]);
            }

            Log::channel('fiscal')->error('kiosk.fiscal_sequence_alloc_failed', [
                'event'    => 'kiosk.fiscal_sequence_alloc_failed',
                'order_id' => $frontendOrder->id,
                'branch_id'=> $frontendOrder->branch_id,
                'error'    => $allocFailureError !== null ? $allocFailureError->getMessage() : 'unknown',
                'flagged'  => true,
            ]);
        }

        if (!$promoted) {
            return false;
        }

        OrderStateMachine::recordTransition(
            FrontendOrder::class,
            (int) $frontendOrder->id,
            OrderStatus::PENDING,
            OrderStatus::ACCEPT,
            null,
            null
        );

        $frontendOrder->refresh();

        $this->dispatchNewOrderSignals($frontendOrder);
        SendOrderMail::dispatch(['order_id' => $frontendOrder->id, 'status' => OrderStatus::ACCEPT]);
        SendOrderSms::dispatch(['order_id' => $frontendOrder->id, 'status' => OrderStatus::ACCEPT]);
        SendOrderPush::dispatch(['order_id' => $frontendOrder->id, 'status' => OrderStatus::ACCEPT]);
        $this->dispatchOrderStatusSignals($frontendOrder, OrderStatus::PENDING, OrderStatus::ACCEPT);

        // [Wave S-1 — P-OWNER 2026-05-20] When the auto-prepare policy fires,
        // the order ended up in PREPARING (not ACCEPT) at the close of the
        // transaction above. Fire a second OrderStatusChanged ACCEPT→PREPARING
        // broadcast so PersistOrderStatusChangedToOutbox encodes both legs of
        // the transition for the realtime KDS / Suivi UIs — passing the
        // pre-`refresh()` ACCEPT alone (the legacy line) would leave KDS
        // subscribers without an explicit "now en préparation" signal and
        // they would only converge via the next poll. Dual-broadcast pattern
        // matches OrderService::changeStatus' multi-leg historical contract.
        if ((int) $frontendOrder->status === OrderStatus::PREPARING) {
            $this->dispatchOrderStatusSignals(
                $frontendOrder,
                OrderStatus::ACCEPT,
                OrderStatus::PREPARING
            );
        }

        return true;
    }

    /**
     * Helper for post-commit "new-order" queue jobs (mail / SMS / push).
     *
     * [Wave M / Heal Z2 P1 — 2026-05-19] The `OrderCreated::dispatch` call
     * that used to live here has been hoisted into the wrapping
     * `DB::transaction(...)` closure of each caller (`myOrderStore` +
     * `finalizePaidKioskOrder`) so that the DispatchableAfterCommit trait
     * engages via `transactionLevel()>0 → afterCommit()`. Queue-mode
     * notifications below remain post-commit via control flow — they have
     * their own queue-level dedupe story and do not need rollback safety.
     * Sentinel:
     * `tests/Feature/Sync/OrderCreatedDispatchPlacementSentinelTest`.
     */
    private function dispatchNewOrderSignals(FrontendOrder $frontendOrder): void
    {
        SendOrderGotMail::dispatch(['order_id' => $frontendOrder->id]);
        SendOrderGotSms::dispatch(['order_id' => $frontendOrder->id]);
        SendOrderGotPush::dispatch(['order_id' => $frontendOrder->id]);
    }

    private function dispatchOrderStatusSignals(FrontendOrder $frontendOrder, int $oldStatus, int $newStatus): void
    {
        try {
            OrderStatusChanged::dispatch($frontendOrder, $oldStatus, $newStatus);
        } catch (\Exception $e) {
            Log::warning('[Kiosk] OrderStatusChanged broadcast failed: ' . $e->getMessage());
        }
    }
}
