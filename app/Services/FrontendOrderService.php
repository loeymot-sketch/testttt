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
use App\Libraries\AppLibrary;
use App\Models\FrontendOrder;
use App\Events\SendOrderGotSms;
use App\Events\SendOrderGotMail;
use App\Events\SendOrderGotPush;
use App\Events\OrderCreated;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\OrderRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;
use App\Http\Requests\OrderStatusRequest;

class FrontendOrderService
{

    public object $frontendOrder;
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
            $frontendOrderColumn = $request->get('order_column') ?? 'id';
            $frontendOrderType = $request->get('order_by') ?? 'desc';

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
        // [SPLASH SECURITY] Idempotency: if the kiosk sends the same key twice (network retry,
        // double-tap), return the existing order instead of creating a duplicate.
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if ($idempotencyKey) {
            $existing = FrontendOrder::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $this->frontendOrder = $existing;
                return $this->frontendOrder;
            }
        }

        try {
            DB::transaction(function () use ($request, $idempotencyKey) {
                $validatedRequest = $request->validated();
                $kiosk = \App\Models\KioskMachine::where('user_id', Auth::user()->id)->first();
                if ($kiosk) {
                    $validatedRequest['branch_id'] = $kiosk->branch_id;
                    $validatedRequest['order_type'] = OrderType::KIOSK;  // [SPRINT 9] Forcer le type borne
                }

                // Attach idempotency key if provided by client
                if ($idempotencyKey) {
                    $validatedRequest['idempotency_key'] = substr($idempotencyKey, 0, 64);
                }

                $this->frontendOrder = FrontendOrder::create(
                    $validatedRequest + [
                        'user_id' => Auth::user()->id,
                        'status' => OrderStatus::PENDING,
                        'order_datetime' => date('Y-m-d H:i:s'),
                        'preparation_time' => Settings::group('order_setup')->get('order_setup_food_preparation_time')
                    ]
                );

                $i = 0;
                $totalTax = 0;
                $itemsArray = [];
                $requestItems = $this->safeJsonDecode($request->items);
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
                                $dbVar = $dbVariations[$var->id ?? 0] ?? null;
                                if ($dbVar)
                                    $calcVariationTotal += $dbVar->price;
                            }
                        }
                        
                        // [PERF-02] Calculer prix extras depuis collection pre-chargée
                        $calcExtraTotal = 0;
                        if (!empty($item->item_extras)) {
                            foreach ($item->item_extras as $ext) {
                                $dbExt = $dbExtras[$ext->id ?? 0] ?? null;
                                if ($dbExt)
                                    $calcExtraTotal += $dbExt->price;
                            }
                        }

                        $verifiedTotalPrice = ($itemPrice + $calcVariationTotal + $calcExtraTotal) * $item->quantity;
                        $realSubtotal += $verifiedTotalPrice;

                        $taxId = isset($items[$item->item_id]) ? $items[$item->item_id] : 0;
                        $taxName = isset($taxes[$taxId]) ? $taxes[$taxId]->name : null;
                        $taxRate = isset($taxes[$taxId]) ? $taxes[$taxId]->tax_rate : 0;
                        $taxType = isset($taxes[$taxId]) ? $taxes[$taxId]->type : TaxType::FIXED;
                        $taxPrice = $taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100;
                        $itemsArray[$i] = [
                            'order_id' => $this->frontendOrder->id,
                            'branch_id' => $this->frontendOrder->branch_id,
                            'item_id' => $item->item_id,
                            'quantity' => $item->quantity,
                            'discount' => (float) ($item->discount ?? 0),
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

                if (!blank($itemsArray)) {
                    OrderItem::insert($itemsArray);
                }

                // [BUG-C2 FIX] Cross-table queue number: read BOTH Order (POS) and FrontendOrder (web/kiosk)
                // to prevent duplicate queue numbers when POS and kiosk orders coexist in the same branch.
                // [BUG-H2 FIX] withoutGlobalScope prevents BranchScope from corrupting query for Admin (branch_id=0)
                $today = date('Y-m-d');
                $maxQueueObj = \App\Models\Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('branch_id', $this->frontendOrder->branch_id)
                    ->whereDate('created_at', $today)
                    ->whereNotNull('queue_number')
                    ->lockForUpdate()
                    ->orderBy('id', 'desc')
                    ->first();
                $maxFrontendObj = \App\Models\FrontendOrder::where('branch_id', $this->frontendOrder->branch_id)
                    ->whereDate('created_at', $today)
                    ->whereNotNull('queue_number')
                    ->lockForUpdate()
                    ->orderBy('id', 'desc')
                    ->first();
                $maxOrderNum = 0;
                if ($maxQueueObj && preg_match('/^A(\d+)$/', $maxQueueObj->queue_number, $m)) {
                    $maxOrderNum = (int) $m[1];
                }
                $maxFrontendNum = 0;
                if ($maxFrontendObj && preg_match('/^A(\d+)$/', $maxFrontendObj->queue_number, $m)) {
                    $maxFrontendNum = (int) $m[1];
                }
                $nextQueueNum = max($maxOrderNum, $maxFrontendNum) + 1;
                $queueNumber = 'A' . str_pad($nextQueueNum, 3, '0', STR_PAD_LEFT);

                // [PHASE 7] SECURISATION P0 COUPON / DISCOUNT
                $calculatedDiscount = 0;
                if ($request->coupon_id > 0) {
                    $coupon = \App\Models\Coupon::find($request->coupon_id);
                    if ($coupon) {
                        if ($coupon->discount_type == \App\Enums\DiscountType::PERCENTAGE) {
                            $calculatedDiscount = ($realSubtotal * $coupon->discount) / 100;
                            if ($coupon->maximum_discount > 0 && $calculatedDiscount > $coupon->maximum_discount) {
                                $calculatedDiscount = $coupon->maximum_discount;
                            }
                        } else {
                            $calculatedDiscount = $coupon->discount;
                        }
                    }
                }

                $this->frontendOrder->order_serial_no = date('dmy') . $this->frontendOrder->id;
                $this->frontendOrder->queue_number = $queueNumber;
                $this->frontendOrder->total_tax = $totalTax;
                $this->frontendOrder->subtotal = $realSubtotal;
                $this->frontendOrder->discount = $calculatedDiscount;
                $this->frontendOrder->total = $realSubtotal + $totalTax + $this->frontendOrder->delivery_charge - $calculatedDiscount;
                $this->frontendOrder->save();

                if ($request->address_id) {
                    $address = Address::find($request->address_id);
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

                if ($request->coupon_id > 0) {
                    OrderCoupon::create([
                        'order_id' => $this->frontendOrder->id,
                        'coupon_id' => $request->coupon_id,
                        'user_id' => Auth::user()->id,
                        'discount' => $calculatedDiscount
                    ]);
                }
            });

            // [KIOSK] Auto-accept kiosk orders so they immediately appear in KDS.
            // KDS filters on status >= ACCEPT (4). Without this, kiosk orders stay
            // PENDING and are invisible to kitchen staff until manually accepted.
            if ($this->frontendOrder->order_type === OrderType::KIOSK) {
                $this->frontendOrder->status = OrderStatus::ACCEPT;
                $this->frontendOrder->save();
                // Fire OrderStatusChanged so KDS/OSS are notified in real-time
                try {
                    event(new \App\Events\OrderStatusChanged(
                    $this->frontendOrder,
                    OrderStatus::PENDING,    // oldStatus (before auto-accept)
                    OrderStatus::ACCEPT      // newStatus
                ));
                } catch (\Exception $e) {
                    Log::warning('[Kiosk] OrderStatusChanged broadcast failed: ' . $e->getMessage());
                }
            }

            // [BUG-C1 FIX] Dispatch notifications AFTER transaction commit
            // Prevents ghost KDS orders if the transaction rolls back after these dispatches
            // [FEAT] OrderCreated broadcast enables real-time KDS/OSS updates via Soketi
            try {
                $notifStatus = $this->frontendOrder->status; // ACCEPT for kiosk, PENDING for others
                SendOrderMail::dispatch(['order_id' => $this->frontendOrder->id, 'status' => $notifStatus]);
                SendOrderSms::dispatch(['order_id' => $this->frontendOrder->id, 'status' => $notifStatus]);
                SendOrderPush::dispatch(['order_id' => $this->frontendOrder->id, 'status' => $notifStatus]);
                SendOrderGotMail::dispatch(['order_id' => $this->frontendOrder->id]);
                SendOrderGotSms::dispatch(['order_id' => $this->frontendOrder->id]);
                SendOrderGotPush::dispatch(['order_id' => $this->frontendOrder->id]);
                // [PHASE-E] Broadcast via Soketi WebSockets (no-op if BROADCAST_DRIVER=null)
                OrderCreated::dispatch($this->frontendOrder);
            } catch (\Exception $e) {
                Log::warning('[FrontendOrder] Post-commit notifications failed for order #' . $this->frontendOrder->id . ': ' . $e->getMessage());
            }

            return $this->frontendOrder;
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(FrontendOrder $frontendOrder): FrontendOrder|array
    {
        try {
            if ($frontendOrder->user_id == Auth::user()->id) {
                return $frontendOrder;
            }
            return [];
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
            if ($frontendOrder->user_id == Auth::user()->id) {
                if ($request->status != OrderStatus::CANCELED) {
                    throw new Exception(trans('all.message.invalid_status_transition'), 422);
                }

                if ($request->status == OrderStatus::CANCELED) {
                    // Kiosk orders are auto-accepted (ACCEPT=4) for KDS visibility.
                    // Allow customer cancel as long as kitchen has not started PREPARING (7).
                    $isKioskOrder = $frontendOrder->order_type === OrderType::KIOSK;
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
                    SendOrderMail::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
                    SendOrderSms::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
                    SendOrderPush::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
                    $frontendOrder->status = $request->status;
                    $frontendOrder->save();
                }
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
}
