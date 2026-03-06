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
        try {
            DB::transaction(function () use ($request) {
                $validatedRequest = $request->validated();
                $kiosk = \App\Models\KioskMachine::where('user_id', Auth::user()->id)->first();
                if ($kiosk) {
                    $validatedRequest['branch_id'] = $kiosk->branch_id;
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
                $requestItems = json_decode($request->items);
                $items = Item::get()->pluck('tax_id', 'id');
                $taxes = AppLibrary::pluck(Tax::get(), 'obj', 'id');

                $realSubtotal = 0;
                if (!blank($requestItems)) {
                    foreach ($requestItems as $item) {
                        // SECURE PRICING HACK FIX
                        $dbItem = Item::find($item->item_id);
                        $itemPrice = $dbItem ? $dbItem->price : $item->item_price;

                        $calcVariationTotal = 0;
                        if (!empty($item->item_variations)) {
                            foreach ($item->item_variations as $var) {
                                $dbVar = \App\Models\ItemVariation::find($var->id ?? 0);
                                if ($dbVar)
                                    $calcVariationTotal += $dbVar->price;
                            }
                        }
                        $calcExtraTotal = 0;
                        if (!empty($item->item_extras)) {
                            foreach ($item->item_extras as $ext) {
                                $dbExt = \App\Models\ItemExtra::find($ext->id ?? 0);
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
                            'branch_id' => $item->branch_id,
                            'item_id' => $item->item_id,
                            'quantity' => $item->quantity,
                            'discount' => (float) $item->discount,
                            'tax_name' => $taxName,
                            'tax_rate' => $taxRate,
                            'tax_type' => $taxType,
                            'tax_amount' => $taxPrice,
                            'price' => $itemPrice,
                            'item_variations' => json_encode($item->item_variations),
                            'item_extras' => json_encode($item->item_extras),
                            'instruction' => $item->instruction,
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

                $today = date('Y-m-d');
                $maxQueueObj = \App\Models\Order::where('branch_id', $this->frontendOrder->branch_id)
                    ->whereDate('created_at', $today)
                    ->whereNotNull('queue_number')
                    ->lockForUpdate() // Lock pour éviter la race condition dans la transaction
                    ->orderBy('id', 'desc')
                    ->first();

                $nextQueueNum = 1;
                if ($maxQueueObj && preg_match('/^A(\d+)$/', $maxQueueObj->queue_number, $matches)) {
                    $nextQueueNum = intval($matches[1]) + 1;
                }
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
                SendOrderMail::dispatch(['order_id' => $this->frontendOrder->id, 'status' => OrderStatus::PENDING]);
                SendOrderSms::dispatch(['order_id' => $this->frontendOrder->id, 'status' => OrderStatus::PENDING]);
                SendOrderPush::dispatch(['order_id' => $this->frontendOrder->id, 'status' => OrderStatus::PENDING]);

                SendOrderGotMail::dispatch(['order_id' => $this->frontendOrder->id]);
                SendOrderGotSms::dispatch(['order_id' => $this->frontendOrder->id]);
                SendOrderGotPush::dispatch(['order_id' => $this->frontendOrder->id]);
            });
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
                    if ($frontendOrder->status >= OrderStatus::ACCEPT) {
                        throw new Exception(trans('all.message.order_accept'), 422);
                    } else {
                        if ($frontendOrder->transaction) {
                            app(PaymentService::class)->cashBack(
                                $frontendOrder,
                                'credit',
                                rand(111111111111111, 99999999999999)
                            );
                        }
                        SendOrderMail::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
                        SendOrderSms::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
                        SendOrderPush::dispatch(['order_id' => $frontendOrder->id, 'status' => $request->status]);
                        $frontendOrder->status = $request->status;
                        $frontendOrder->save();
                    }
                }
            }
            return $frontendOrder;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
