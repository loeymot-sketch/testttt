<?php

namespace App\Services;


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
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Enums\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Http\Requests\OrderRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;
use App\Http\Requests\OrderStatusRequest;
use App\Domain\Order\OrderStateMachine;
use App\Services\CouponService;
use App\Services\Pricing\DiscountCalculator;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;

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
        // [SPLASH SECURITY] Idempotency: if the kiosk sends the same key twice (network retry,
        // double-tap), return the existing order instead of creating a duplicate.
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if ($idempotencyKey) {
            $idempotencyLock = Cache::lock('frontend_order_idempotency_' . sha1($idempotencyKey), 10);
            $idempotencyLock->block(5);
            $existing = FrontendOrder::where('idempotency_key', $idempotencyKey)->first();
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
                $isImmediatePaidKioskCash = $isKioskOrderType
                    && (int) ($validatedRequest['payment_method'] ?? 0) === PaymentGateway::CASH_ON_DELIVERY;
                $shouldAutoAcceptAfterCreate = $isImmediatePaidKioskCash;
                $shouldDispatchNewOrderSignals = !$isKioskOrderType || $isImmediatePaidKioskCash || !$isKioskPaymentMethod;

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
                        'preparation_time' => Settings::group('order_setup')->get('order_setup_food_preparation_time'),
                        'payment_status'   => $isImmediatePaidKioskCash ? PaymentStatus::PAID : PaymentStatus::UNPAID,
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
                                    $calcVariationTotal += $dbVar->price;
                                }
                            }
                            
                            // [PERF-02] Calculer prix extras depuis collection pre-chargée
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
                                    $calcExtraTotal += $dbExt->price;
                                }
                            }

                            $verifiedQuantity = max(1, (int) ($item->quantity ?? 1));
                            $verifiedTotalPrice = round(($itemPrice + $calcVariationTotal + $calcExtraTotal) * $verifiedQuantity, 2);
                            $realSubtotal += $verifiedTotalPrice;

                            $taxId = isset($items[$item->item_id]) ? $items[$item->item_id] : 0;
                            $taxName = isset($taxes[$taxId]) ? $taxes[$taxId]->name : null;
                            $taxRate = isset($taxes[$taxId]) ? $taxes[$taxId]->tax_rate : 0;
                            $taxType = isset($taxes[$taxId]) ? $taxes[$taxId]->type : TaxType::FIXED;
                            $taxPrice = round($taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100, 2);
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
                                'instruction' => $item->instruction ?? null,
                                'item_variation_total' => $calcVariationTotal,
                                'item_extra_total' => $calcExtraTotal,
                                'total_price' => $verifiedTotalPrice,
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

                // [AUDIT-P0-B] Atomic queue number allocation using Cache lock.
                // lockForUpdate() is weak when no rows exist yet (first order of the day) — two concurrent
                // transactions both read NULL and both compute A001. Cache::lock() provides a named mutex
                // that serializes the allocation even on empty result sets.
                $today = date('Y-m-d');
                $lockKey = 'queue_lock_' . $this->frontendOrder->branch_id . '_' . $today;
                $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 10); // 10s max hold

                try {
                    $lock->block(5); // wait up to 5s to acquire

                    // [AUDIT-P51-BUG3] Single atomic query to prevent race condition between Order and FrontendOrder
                    // Both models use the same 'orders' table — use direct DB query with MAX() for true atomicity
                    $maxQueueNum = (int) \Illuminate\Support\Facades\DB::table('orders')
                        ->where('branch_id', $this->frontendOrder->branch_id)
                        ->whereDate('created_at', $today)
                        ->whereNotNull('queue_number')
                        ->whereRaw("queue_number REGEXP '^A[0-9]+$'")
                        ->selectRaw("MAX(CAST(SUBSTRING(queue_number, 2) AS UNSIGNED)) as max_num")
                        ->value('max_num');

                    $nextQueueNum = $maxQueueNum + 1;
                    // [AUDIT-P2-F] 4 digits = up to A9999 (was 3 digits limited to 999)
                    $queueNumber = 'A' . str_pad($nextQueueNum, 4, '0', STR_PAD_LEFT);

                } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                    // Fallback: use timestamp-based suffix to avoid collision if lock times out
                    $queueNumber = 'A' . str_pad((int)(microtime(true) * 10) % 9999 + 1, 4, '0', STR_PAD_LEFT);
                    \Illuminate\Support\Facades\Log::warning('[Queue] Lock timeout for branch ' . $this->frontendOrder->branch_id . ' — fallback queue number used.');
                } finally {
                    $lock->release();
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

                // [AUDIT-P47-BUG5] AVERTISSEMENT ARCHITECTURE — Double déduction possible.
                // Si le kiosk appelle LoyaltyController::redeem() AVANT de soumettre la commande,
                // ET que la commande arrive ici avec discount > 0 + loyalty_code,
                // les points sont déduits DEUX FOIS (une fois dans redeem, une fois ici).
                // RÈGLE : Le kiosk NE DOIT PAS appeler redeem() si la déduction se fait ici.
                // Le flow kiosk actuel passe par ici uniquement (pas par redeem()).
                // redeem() est réservé au POS/web qui n'ont pas ce bloc.
                // [SPLASH LOYALTY] Validate and apply loyalty discount server-side.
                // The kiosk sends loyalty_code + discount amount. We verify:
                //   1. The loyalty_code belongs to a real active user
                //   2. The user has enough points to cover the requested discount
                //   3. The discount does not exceed the subtotal
                // If valid, deduct the points atomically and add the discount.
                $loyaltyUser = null;
                if ($validatedCoupon && $request->loyalty_code && $request->discount > 0) {
                    Log::info('[Loyalty] Loyalty discount skipped because coupon takes priority on frontend order.');
                } elseif ($request->loyalty_code && $request->discount > 0) {
                    try {
                        $rate = (int) Settings::group('loyalty_setup')->get('loyalty_points_for_1_euro_discount', 100);
                        if ($rate <= 0) $rate = 100;
                        $requestedDiscount = (float) $request->discount;
                        $maxDiscount = min($requestedDiscount, $realSubtotal);
                        $pointsRequired = (int) ceil($maxDiscount * $rate);
                        $minRedeemPoints = (int) Settings::group('loyalty_setup')->get('loyalty_min_redeem_points', 50);
                        if ($minRedeemPoints < 0) {
                            $minRedeemPoints = 0;
                        }

                        if ($pointsRequired > 0) {
                            if ($pointsRequired < $minRedeemPoints) {
                                Log::warning("[Loyalty] Requested redemption below minimum threshold: {$pointsRequired} < {$minRedeemPoints}");
                            } else {
                            // [BUG-11-6 FIX] Use lockForUpdate() to prevent race condition:
                            // two simultaneous kiosk orders with the same loyalty_code could
                            // both read the same point balance and both succeed, overdrawing points.
                            $loyaltyUser = \App\Models\User::where('loyalty_code', $request->loyalty_code)
                                ->where('status', 1)
                                ->lockForUpdate()
                                ->first();

                            if ($loyaltyUser && $loyaltyUser->loyalty_points >= $pointsRequired) {
                                Log::info('[Loyalty] Lock acquired, deducting points', [
                                    'user_id' => $loyaltyUser->id,
                                    'order_id' => $this->frontendOrder->id,
                                    'requested' => $pointsRequired,
                                    'available' => $loyaltyUser->loyalty_points,
                                ]);
                                $calculatedDiscount += $maxDiscount;
                                \Illuminate\Support\Facades\DB::table('users')
                                    ->where('id', $loyaltyUser->id)
                                    ->decrement('loyalty_points', $pointsRequired);
                                $this->loyaltyApplied = true;
                                // [AUDIT-P49-BUG8] Write redemption to loyalty_transactions ledger for complete audit trail.
                                // This ensures the customer's history shows the redeem event even when inline (not via LoyaltyController).
                                $balanceAfter = $loyaltyUser->loyalty_points - $pointsRequired;
                                \App\Models\LoyaltyTransaction::create([
                                    'user_id'        => $loyaltyUser->id,
                                    'loyalty_code'   => $loyaltyUser->loyalty_code,
                                    'order_id'       => $this->frontendOrder->id,
                                    'type'           => 'redeem',
                                    'points'         => -$pointsRequired,
                                    'balance_after'  => $balanceAfter,
                                    'source_surface' => 'kiosk',
                                    'description'    => 'Réduction fidélité appliquée sur commande kiosk',
                                ]);
                                Log::info("[Loyalty] {$pointsRequired} pts redeemed for user #{$loyaltyUser->id} (-{$maxDiscount}€)");
                            } elseif ($loyaltyUser) {
                                Log::warning('[Loyalty] Insufficient points after lock', [
                                    'user_id' => $loyaltyUser->id,
                                    'order_id' => $this->frontendOrder->id,
                                    'requested' => $pointsRequired,
                                    'available' => $loyaltyUser->loyalty_points,
                                ]);
                            }
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning("[Loyalty] Discount calculation failed: " . $e->getMessage());
                    }
                }

                $this->frontendOrder->order_serial_no = date('dmy') . $this->frontendOrder->id;
                $this->frontendOrder->queue_number = $queueNumber;
                $this->frontendOrder->total_tax = round($totalTax, 2);
                $this->frontendOrder->subtotal = round($realSubtotal, 2);
                $this->frontendOrder->discount = $calculatedDiscount;
                $this->frontendOrder->total = round(max(0, $realSubtotal + $totalTax + $this->frontendOrder->delivery_charge - $calculatedDiscount), 2);
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
                $this->frontendOrder->save();

                if ($request->address_id) {
                    // [SECURITY-IDOR] Ensure the address belongs to the authenticated user.
                    // Without this check, any user could reference another user's address_id
                    // and snapshot their private address data onto an order.
                    $address = Address::where('id', $request->address_id)
                        ->where('user_id', Auth::user()->id)
                        ->first();
                    if ($address) {
                        OrderAddress::create([
                            'order_id' => $this->frontendOrder->id,
                            'user_id' => Auth::user()->id,
                            'label' => $address->label,
                            'address' => $address->address,
                            'apartment' => $address->apartment,
                            'latitude' => $address->latitude,
                            'longitude' => $address->longitude
                        ]);
                    }
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
        } catch (\Illuminate\Database\QueryException $qe) {
            // [FIX-54-6] Catch MySQL duplicate key on idempotency_key UNIQUE constraint.
            // Same recovery logic as OrderService::posOrderStore() for consistency.
            if ($qe->getCode() === '23000' && $idempotencyKey) {
                $existing = FrontendOrder::where('idempotency_key', $idempotencyKey)->first();
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
                if ((int) $request->status !== (int) OrderStatus::CANCELED) {
                    throw new Exception(trans('all.message.invalid_status_transition'), 422);
                }

                if ((int) $request->status === (int) OrderStatus::CANCELED) {
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
                    $frontendOrder->status = $request->status;
                    $frontendOrder->save();
                    OrderStateMachine::recordTransition(
                        FrontendOrder::class,
                        (int) $frontendOrder->id,
                        (int) $oldStatus,
                        (int) $request->status,
                        Auth::check() ? (int) Auth::id() : null,
                        null
                    );
                    // [BUG-1 FIX] Notify KDS/OSS that order is cancelled so it disappears from screens
                    try {
                        event(new \App\Events\OrderStatusChanged(
                            $frontendOrder,
                            $oldStatus,
                            $request->status
                        ));
                    } catch (\Exception $e) {
                        Log::warning('[FrontendOrder] OrderStatusChanged on cancel failed: ' . $e->getMessage());
                    }
                    SendOrderMail::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
                    SendOrderSms::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
                    SendOrderPush::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
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
        if ($itemsArray === []) {
            return $itemsArray;
        }

        $itemsById = Item::query()
            ->select('id', 'allergen_flags')
            ->with(['allergens' => function ($query): void {
                $query->select('allergens.id', 'code')->orderBy('sort');
            }])
            ->whereIn('id', collect($itemsArray)->pluck('item_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($itemsArray as $index => $row) {
            $itemsArray[$index]['allergens_snapshot'] = json_encode(
                $this->resolveAllergenSnapshot($itemsById->get((int) ($row['item_id'] ?? 0))),
                JSON_UNESCAPED_UNICODE
            );
        }

        return $itemsArray;
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

        DB::transaction(function () use ($frontendOrder, &$promoted) {
            $locked = FrontendOrder::where('id', $frontendOrder->id)
                ->lockForUpdate()
                ->first();

            if ((int) $locked->status >= OrderStatus::ACCEPT) {
                return;
            }

            $locked->status = OrderStatus::ACCEPT;
            $locked->save();
            $promoted = true;
        });

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

        return true;
    }

    private function dispatchNewOrderSignals(FrontendOrder $frontendOrder): void
    {
        SendOrderGotMail::dispatch(['order_id' => $frontendOrder->id]);
        SendOrderGotSms::dispatch(['order_id' => $frontendOrder->id]);
        SendOrderGotPush::dispatch(['order_id' => $frontendOrder->id]);
        OrderCreated::dispatch($frontendOrder);
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
