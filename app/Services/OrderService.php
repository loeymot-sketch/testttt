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
use App\Http\Requests\TableOrderTokenRequest;

class OrderService
{
    public object $order;
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

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests = $request->all();
            $method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType = $request->get('order_by') ?? 'desc';

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
                            $query->where($key, 'like', '%' . $request . '%');
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
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType = $request->get('order_by') ?? 'desc';

            return Order::with('transaction', 'orderItems', 'branch', 'user')->where('order_type', "!=", OrderType::POS)->where(function ($query) use ($requests, $user) {
                $query->where('user_id', $user->id);
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->orderFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
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
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType = $request->get('order_by') ?? 'desc';

            return Order::with('transaction', 'orderItems', 'branch', 'user')->where('delivery_boy_id', $user->id)->where('order_type', "!=", OrderType::POS)->where(
                        function ($query) use ($requests) {
                            foreach ($requests as $key => $request) {
                                if (in_array($key, $this->orderFilter)) {
                                    $query->where($key, 'like', '%' . $request . '%');
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
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType = $request->get('order_by') ?? 'desc';

            return Order::with('transaction', 'orderItems', 'branch', 'user')->where('order_type', "!=", OrderType::POS)->where('delivery_boy_id', Auth::user()->id)->where(
                        function ($query) use ($requests) {
                            foreach ($requests as $key => $request) {
                                if (in_array($key, $this->orderFilter)) {
                                    $query->where($key, 'like', '%' . $request . '%');
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

                $i            = 0;
                $totalTax     = 0;
                $itemsArray   = [];
                $requestItems = $this->safeJsonDecode($request->items);
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

                // [PERF-02] Bulk-load variations and extras before the loop
                $variationIds = collect($requestItems)->pluck('item_variations')->flatten(1)->pluck('id')->filter()->unique()->toArray();
                $extraIds     = collect($requestItems)->pluck('item_extras')->flatten(1)->pluck('id')->filter()->unique()->toArray();
                $dbVariations = !empty($variationIds)
                    ? \App\Models\ItemVariation::whereIn('id', $variationIds)->get()->keyBy('id')
                    : collect();
                $dbExtras = !empty($extraIds)
                    ? \App\Models\ItemExtra::whereIn('id', $extraIds)->get()->keyBy('id')
                    : collect();

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

                        $verifiedTotalPrice = ($itemPrice + $variationTotal + $extraTotal) * $item->quantity;
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
                            'quantity'             => $item->quantity,
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

                // [BUG-H2 FIX] withoutGlobalScope prevents BranchScope from corrupting the query for Admin (branch_id=0)
                // [BUG-H3 FIX] Cross-table check: include FrontendOrder (table/kiosk) to prevent duplicate queue numbers
                $today = date('Y-m-d');
                $maxQueueObj = \App\Models\Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('branch_id', $this->order->branch_id)
                    ->whereDate('created_at', $today)
                    ->whereNotNull('queue_number')
                    ->lockForUpdate()
                    ->orderBy('id', 'desc')
                    ->first();
                $maxFrontendObj = \App\Models\FrontendOrder::where('branch_id', $this->order->branch_id)
                    ->whereDate('created_at', $today)
                    ->whereNotNull('queue_number')
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

                // [AUDIT-FIX P0-1] Coupon recalculation server-side — never trust $request->discount
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
                            $calculatedDiscount = (float) $coupon->discount;
                        }
                    }
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
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function posOrderStore(PosOrderRequest $request): object
    {
        try {
            $order = null;
            DB::transaction(function () use ($request, &$order) {
                $this->order = Order::create(
                    $request->validated() + [
                        'user_id' => $request->customer_id,
                        'status' => OrderStatus::ACCEPT,
                        'token' => $request->token,
                        'payment_status' => PaymentStatus::PAID,
                        'order_datetime' => date('Y-m-d H:i:s'),
                        'preparation_time' => Settings::group('order_setup')->get('order_setup_food_preparation_time')
                    ]
                );

                $i = 0;
                $totalTax = 0;
                $itemsArray = [];
                $requestItems = $this->safeJsonDecode($request->items);
                
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
                                $extraTotal += (float) $dbExt->price;
                            }
                        }
                        
                        // Prix vérifié depuis DB
                        $verifiedUnitPrice = $itemPrice + $variationTotal + $extraTotal;
                        $verifiedTotalPrice = $verifiedUnitPrice * $item->quantity;
                        
                        $taxId = isset($dbItems[$item->item_id]) ? ($dbItems[$item->item_id]->tax_id ?? 0) : 0;
                        $taxName = isset($taxes[$taxId]) ? $taxes[$taxId]->name : null;
                        $taxRate = isset($taxes[$taxId]) ? $taxes[$taxId]->tax_rate : 0;
                        $taxType = isset($taxes[$taxId]) ? $taxes[$taxId]->type : TaxType::FIXED;
                        $taxPrice = $taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100;
                        
                        $itemsArray[$i] = [
                            'order_id'             => $this->order->id,
                            'branch_id'            => $this->order->branch_id,
                            'item_id'              => $item->item_id,
                            'quantity'             => $item->quantity,
                            'discount'             => (float) ($item->discount ?? 0),
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

                if (!blank($itemsArray)) {
                    OrderItem::insert($itemsArray);
                }

                // [BUG-H2 FIX] withoutGlobalScope prevents BranchScope from corrupting query for Admin (branch_id=0)
                // [BUG-H3 FIX] Cross-table check: include FrontendOrder to prevent queue number collision with table/kiosk orders
                $today = date('Y-m-d');
                $maxQueueObj = \App\Models\Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('branch_id', $this->order->branch_id)
                    ->whereDate('created_at', $today)
                    ->whereNotNull('queue_number')
                    ->lockForUpdate()
                    ->orderBy('id', 'desc')
                    ->first();
                $maxFrontendObj = \App\Models\FrontendOrder::where('branch_id', $this->order->branch_id)
                    ->whereDate('created_at', $today)
                    ->whereNotNull('queue_number')
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

                $this->order->order_serial_no = date('dmy') . $this->order->id;
                $this->order->queue_number = $queueNumber;
                $this->order->total_tax = $totalTax;

                // [PHASE 7] SECURISATION P0 COUPON / DISCOUNT POUR TABLE ORDER
                // [BUG-3 FIX] Apply manual discount when no coupon, validate discount <= subtotal
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
                } elseif ($request->discount > 0) {
                    // [AUDIT-FIX P1-3] Manual cashier discount — validated server-side, will be logged below
                    $manualDiscount = (float) $request->discount;
                    if ($manualDiscount <= $realSubtotal) {
                        $calculatedDiscount = $manualDiscount;
                    }
                    // Si discount > subtotal, on ignore (pas de total négatif)
                }

                $this->order->subtotal = $realSubtotal;
                $this->order->discount = $calculatedDiscount;
                // [BUG-H1 FIX] null-coalescing on delivery_charge + max(0) guard prevents negative totals
                $this->order->total = max(0, $realSubtotal + $totalTax + ($this->order->delivery_charge ?? 0) - $calculatedDiscount);

                $currentTime = Carbon::now();
                $endTime = $currentTime->copy()->addMinutes(Settings::group('order_setup')->get('order_setup_schedule_order_slot_duration'));
                $start = $currentTime->format('H:i');
                $end = $endTime->format('H:i');
                $this->order->delivery_time = "$start - $end";
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
                    $address = Address::find($request->address_id);
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
        } catch (Exception $exception) {
            DB::rollBack();
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
                $this->order = FrontendOrder::create(
                    $request->validated() + [
                        'user_id' => $request->customer_id,
                        'status' => OrderStatus::PENDING,
                        'order_datetime' => date('Y-m-d H:i:s'),
                        'preparation_time' => Settings::group('order_setup')->get('order_setup_food_preparation_time')
                    ]
                );

                $i = 0;
                $totalTax = 0;
                $itemsArray = [];
                $requestItems = $this->safeJsonDecode($request->items);

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
                        $calcVariationTotal = 0;
                        if (!empty($item->item_variations)) {
                            foreach ($item->item_variations as $var) {
                                $varId = $var->id ?? 0;
                                $dbVar = $dbVariations[$varId] ?? null;
                                if ($dbVar)
                                    $calcVariationTotal += $dbVar->price;
                            }
                        }

                        // [BUG-CRIT-2 FIX] Utiliser $dbExtras bulk-loadé au lieu de find() dans la boucle
                        $calcExtraTotal = 0;
                        if (!empty($item->item_extras)) {
                            foreach ($item->item_extras as $ext) {
                                $extId = $ext->id ?? 0;
                                $dbExt = $dbExtras[$extId] ?? null;
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
                            'order_id'             => $this->order->id,
                            'branch_id'            => $item->branch_id,
                            'item_id'              => $item->item_id,
                            'quantity'             => $item->quantity,
                            'discount'             => (float) $item->discount,
                            'tax_name'             => $taxName,
                            'tax_rate'             => $taxRate,
                            'tax_type'             => $taxType,
                            'tax_amount'           => $taxPrice,
                            'price'                => $itemPrice,
                            'item_variations'      => json_encode($item->item_variations),
                            'item_extras'          => json_encode($item->item_extras),
                            'instruction'          => $item->instruction,
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

                // [BUG-H2 FIX] withoutGlobalScope prevents BranchScope corruption for Admin
                // [BUG-H3 FIX] tableOrderStore stores in FrontendOrder but must read BOTH tables for unified queue sequence
                $today = date('Y-m-d');
                $maxQueueObj = \App\Models\Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('branch_id', $this->order->branch_id)
                    ->whereDate('created_at', $today)
                    ->whereNotNull('queue_number')
                    ->lockForUpdate()
                    ->orderBy('id', 'desc')
                    ->first();
                $maxFrontendObj = \App\Models\FrontendOrder::where('branch_id', $this->order->branch_id)
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

                $this->order->order_serial_no = date('dmy') . $this->order->id;
                $this->order->queue_number = $queueNumber;
                $this->order->total_tax = $totalTax;

                // [PHASE 7] SECURISATION P0 COUPON / DISCOUNT POUR TABLE ORDER
                // [BUG-3 FIX] Apply manual discount when no coupon, validate discount <= subtotal
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
                } elseif ($request->discount > 0) {
                    // [AUDIT-FIX P1-3] Manual cashier discount — validated server-side, will be logged below
                    $manualDiscount = (float) $request->discount;
                    if ($manualDiscount <= $realSubtotal) {
                        $calculatedDiscount = $manualDiscount;
                    }
                    // Si discount > subtotal, on ignore (pas de total négatif)
                }

                $this->order->subtotal = $realSubtotal;
                $this->order->discount = $calculatedDiscount;
                // [BUG-H1 FIX] null-coalescing + max(0) guard — prevents negative total with large coupons or null delivery_charge
                $this->order->total = max(0, $realSubtotal + $totalTax + ($this->order->delivery_charge ?? 0) - $calculatedDiscount);

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
            DB::rollBack();
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
            $transaction = Transaction::where('order_id', $order->id)->first();

            if (!$transaction && $order->payment_status == PaymentStatus::UNPAID) {
                $order->payment_status = PaymentStatus::PAID;
            }
            SendOrderMail::dispatch(['order_id' => $order->id, 'status' => OrderStatus::DELIVERED]);
            SendOrderSms::dispatch(['order_id' => $order->id, 'status' => OrderStatus::DELIVERED]);
            SendOrderPush::dispatch(['order_id' => $order->id, 'status' => OrderStatus::DELIVERED]);
            $order->status = OrderStatus::DELIVERED;
            $order->save();
            return $order;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function changeStatus(Order $order, $auth = false, OrderStatusRequest $request): Order|array
    {
        try {
            if (!(new \App\Rules\ValidStatusTransition($order->status))->passes('status', $request->status)) {
                throw new Exception(trans('all.message.invalid_status_transition'), 422);
            }

            if ($auth) {
                // Customer self-cancellation path — owner check only
                if ($order->user_id == Auth::user()->id) {
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
                    }
                    SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                    SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                    SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                    $order->status = $request->status;
                    $order->save();
                }
            } else {
                // [AUDIT-FIX P0-2] Branch isolation: non-Admin staff can only modify orders of their branch
                if (Auth::check() && !Auth::user()->hasRole('Admin')) {
                    $userBranch = Auth::user()->branch_id ?? null;
                    if ($userBranch && (int) $userBranch !== (int) $order->branch_id) {
                        throw new Exception('Accès refusé : cette commande appartient à une autre succursale.', 403);
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
                }

                $oldStatus = $order->status;
                SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $request->status]);
                $order->status = $request->status;
                $order->save();

                // [PHASE-E] Broadcast status change via Soketi WebSockets
                try {
                    \App\Events\OrderStatusChanged::dispatch($order, $oldStatus, (int) $request->status);
                } catch (\Exception $e) {
                    Log::warning('OrderStatusChanged broadcast failed: ' . $e->getMessage());
                }

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
            }
            return $order;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function changePaymentStatus(Order $order, $auth = false, PaymentStatusRequest $request): Order|array
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
                // [AUDIT-FIX P0-2 / P1-5] Branch isolation: non-Admin staff can only modify their branch's orders
                if (Auth::check() && !Auth::user()->hasRole('Admin')) {
                    $userBranch = Auth::user()->branch_id ?? null;
                    if ($userBranch && (int) $userBranch !== (int) $order->branch_id) {
                        throw new Exception('Accès refusé : cette commande appartient à une autre succursale.', 403);
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

                return $order;
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }


    public function tokenCreate(Order $order, $auth = false, TableOrderTokenRequest $request): Order|array
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
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function selectDeliveryBoy(Order $order, $auth = false, Request $request): Order|array
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
        try {
            DB::transaction(function () use ($order) {
                $order->address()?->delete();
                $order->coupon()?->delete();
                $order->orderItems()?->delete();
                $order->delete();
            });
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function salesReportOverview(Request $request)
    {
        try {
            $requests = $request->all();
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType = $request->get('order_by') ?? 'desc';

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
                            $query->where($key, 'like', '%' . $request . '%');
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
