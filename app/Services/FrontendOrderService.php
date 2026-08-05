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
            // [WEB-WIREUP 2026-06-26] Web/app guest tokens carry the kiosk:order ability but
            // have no KioskMachine and a guest branch_id of 0. Fall back to the validated
            // request branch_id (OrderRequest requires + validates it for non-kiosk orders).
            // Must reference a real branch so the idempotency namespace stays branch-scoped
            // and we never create against a bogus/cross-branch context.
            $requestBranchId = (int) ($request->input('branch_id') ?? 0);
            // Raw existence check — bypass Eloquent global scopes (Branch carries active/
            // soft-delete scopes whose columns may not exist in every deployment schema).
            if ($requestBranchId > 0 && DB::table('branches')->where('id', $requestBranchId)->exists()) {
                $lockBranchId = $requestBranchId;
            }
        }
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
            $existing = $this->findExistingFrontendOrderForIdempotencyRecovery($idempotencyKey, $lockBranchId, auth()->id());
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
                // [A1 cycle 4 · GOAL_WEB_ADVERSARIAL 2026-08-05] LA PLAINTE OWNER : « j'annule le
                // paiement et la commande passe quand même ». Reproduite avec les octets ESC/POS :
                // une commande CARTE WEB était diffusée à la CRÉATION — donc AVANT que le client
                // voie seulement l'écran 3-D Secure — et les listeners d'impression
                // (PrintKioskKitchenTicketOnOrderCreated, PrintKioskOrderToCounter) ne testent que
                // `source_surface`, JAMAIS `payment_status` : le ticket cuisine sortait pour une
                // commande jamais payée, et aucun listener n'imprime d'avis d'annulation.
                // La garde anti-« ghost order » décrite juste au-dessus ne couvrait que la BORNE.
                //
                // Pourquoi c'est sûr de l'étendre au web MAINTENANT : `finalizePaidKioskOrder`
                // traite les commandes carte web depuis LOCK_WEB_CARD_FISCAL_SEAL (2026-08-04,
                // `$isWebCardOrder` ligne ~1381) — le chemin « payé » est UNIFIÉ. Retenir la
                // diffusion à la création ne coupe donc plus la cuisine : `OrderCreated` part au
                // webhook PAID, avec le scellement fiscal, exactement comme pour la borne.
                // (C'est ce qui invalidait l'escalade G-W5 : elle reposait sur une lecture périmée
                // où ce chemin no-opait pour le web.)
                //
                // Ne concerne QUE l'intention carte en ligne : une commande web réglée AU COMPTOIR
                // (cash) doit continuer de partir immédiatement en cuisine — c'est le mode normal.
                $isWebCardIntentAtCreate = strtolower((string) ($validatedRequest['source_surface'] ?? ($isKioskMachineOrder ? 'kiosk' : 'web'))) === 'web'
                    && (int) ($validatedRequest['payment_method'] ?? 0) === (int) PaymentGateway::CARD;

                $shouldDispatchNewOrderSignals = (!$isKioskOrderType || $isCounterDeferredKioskCash || !$isKioskPaymentMethod)
                    && !$isWebCardIntentAtCreate;

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

                // [DELIVERY hardening 2026-06-27] Non-DELIVERY orders MUST carry no delivery
                // charge. delivery_charge is `nullable` for non-delivery in OrderRequest, so a
                // crafted payload (e.g. order_type=TAKEAWAY + delivery_charge=99) would otherwise
                // mass-assign a phantom charge into the total (over-billing / report pollution).
                // For DELIVERY the value is the server-recomputed SSOT (OrderRequest merges the
                // signed quote) and is preserved. This also makes the free-above invariant below
                // ("delivery_charge=0 sinon") actually hold.
                if ((int) ($validatedRequest['order_type'] ?? 0) !== OrderType::DELIVERY) {
                    $validatedRequest['delivery_charge'] = 0;
                }

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

                    // [CAISSE-LOGIC-HEAL SYNC-P1 2026-07-11] Inclure les composants de menu
                    // (addon_item_id) dans la garde : un composant en rupture (boisson d'un
                    // menu) restait commandable car seuls les item_id de 1er niveau étaient testés.
                    $availabilityService = app(AvailabilityService::class);
                    $availabilityService->assertItemsOrderableForBranch(
                        (int) $this->frontendOrder->branch_id,
                        array_merge($requestedItemIds, $availabilityService->componentItemIdsFor($requestItems)),
                        true
                    );

                    // [SYNC cross-surface 2026-08-04 · LEGACY-only, defense-en-profondeur] Symétrie
                    // borne/caisse pour les EXTRAS/VARIATIONS 86. NB (audit RED L3 2026-08-05) : on est
                    // ICI dans la branche `else` = `use_ssot_service=false` (verrouillé OFF en prod). Le
                    // chemin SSOT/prod (bloc `if` ci-dessus) rejette DÉJÀ les extras/variations 86 via
                    // PricingService::calculateOrder → ChoiceAvailabilityResolver::assertSelectionsOrderable.
                    // Cette garde n'est donc un filet que si le flag est basculé OFF au runtime.
                    $availabilityService->assertExtrasAndVariationsOrderableForBranch(
                        (int) $this->frontendOrder->branch_id,
                        $extraIds,
                        $variationIds
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
                            (int) Auth::id(),
                            // [S3 coupon surface/branch enforced-at-commit 2026-07-18]
                            // Pass the order's REAL branch + surface so an admin-set
                            // surface/branch restriction is enforced at COMMIT (accept on
                            // match, reject on mismatch), not only at the pre-check. Null
                            // defaults leave isUsableNow()'s surface/branch filters wrong.
                            (int) $this->frontendOrder->branch_id,
                            $isKioskMachineOrder ? 'kiosk' : 'web'
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
                        (int) Auth::id(),
                        // [S3 coupon surface/branch enforced-at-commit 2026-07-18]
                        // Real branch + surface (kiosk vs web) so surface/branch-
                        // restricted coupons are enforced at COMMIT, not only pre-check.
                        (int) $this->frontendOrder->branch_id,
                        $isKioskMachineOrder ? 'kiosk' : 'web'
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
                    // [DELIVERY 2026-06-27] Livraison OFFERTE au-dessus du seuil (owner : ≥30€).
                    // Appliqué ICI — hors PricingService (frozen) — où le sous-total SSOT
                    // ($realSubtotal, recalculé serveur) est connu, donc non-falsifiable client.
                    // N'affecte que les commandes DELIVERY (delivery_charge=0 sinon). Seuil
                    // configurable via Settings delivery.free_delivery_above (défaut 30€).
                    $freeAbove = (float) (Settings::group('delivery')->get('free_delivery_above', 30) ?? 30);
                    if ($freeAbove > 0
                        && (float) $realSubtotal >= $freeAbove
                        && (float) $this->frontendOrder->delivery_charge > 0) {
                        $this->frontendOrder->delivery_charge = 0;
                    }
                    // [TTC-MODE] In TTC mode, $realSubtotal already contains tax.
                    if ((bool) config('pricing.tax_inclusive_prices', false)) {
                        $this->frontendOrder->total = round(max(0, $realSubtotal + $this->frontendOrder->delivery_charge - $calculatedDiscount), 2);
                    } else {
                        $this->frontendOrder->total = round(max(0, $realSubtotal + $totalTax + $this->frontendOrder->delivery_charge - $calculatedDiscount), 2);
                    }

                    // [WEB-TOTAL-GUARD 2026-07-19] Défense-en-profondeur non-frozen contre le
                    // « drop de prix » web (racine : reports/goal-drop-prix-2026-07-19/DIAG_WEB_BORNE.md).
                    // Le front web standalone chiffre des options côté client puis en OMET
                    // silencieusement au submit (api.js resolveLine skip) → le payload arrive
                    // incomplet → PricingService (SSOT) scelle un total INFÉRIEUR à ce que le
                    // client croyait payer, sans aucune erreur (vu 12€, facturé 10€). Contrairement
                    // à la borne, le web n'a PAS de seal (OrderQuoteService::sealForCommit, kiosk-only).
                    // Garde : si le client déclare un total attendu (expected_total, OPTIONNEL), on
                    // REFUSE de sceller quand le total serveur diverge de plus d'un centime. Le total
                    // ci-dessus reste 100% SSOT (PricingService) — expected_total ne SERT JAMAIS à
                    // facturer, uniquement de témoin. Rétro-compat : champ absent → aucun rejet.
                    // N'affecte pas le sealing borne ci-dessous (inchangé) ; sur borne le seal 409
                    // demeure la défense de référence.
                    if ($request->filled('expected_total')) {
                        $expectedTotal = round((float) $request->input('expected_total'), 2);
                        $serverTotal = (float) $this->frontendOrder->total;
                        if (abs($serverTotal - $expectedTotal) > 0.01) {
                            throw new HttpException(
                                422,
                                'Le total ne correspond pas au montant attendu — certaines options sont peut-être indisponibles. Merci de recommencer votre commande.'
                            );
                        }
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
                    // Track which surface generated this order for loyalty analytics.
                    // [SOURCE-SURFACE-FIX 2026-06-27] Distinguer borne vs web par la
                    // présence d'une KioskMachine liée au token ($isKioskMachineOrder),
                    // PAS par order_type : le site web envoie order_type=TAKEAWAY(10),
                    // donc l'ancien dérivé classait TOUTE commande web-à-emporter en
                    // 'kiosk' → analytics fidélité + Dashboard + CashOverview faussés
                    // (comptées borne) depuis le wireup web. La machine = vraie borne.
                    if (!$this->frontendOrder->source_surface) {
                        $this->frontendOrder->source_surface = $isKioskMachineOrder ? 'kiosk' : 'web';
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
                $existing = $this->findExistingFrontendOrderForIdempotencyRecovery($idempotencyKey, $lockBranchId, auth()->id());
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
        // [TERRAIN-HEAL 2026-07-16 · FRONT-SHOW-403-422] L'abort(403) était DANS un try/catch qui
        // convertissait toute Exception en 422 → un refus d'accès (IDOR) renvoyait 422 au lieu de 403
        // (le refus fonctionnait, mais le code HTTP était trompeur). Aucune requête DB ici → try/catch
        // inutile. On garde la garde de propriété avec le bon code 403.
        if ((int) $frontendOrder->user_id !== (int) Auth::id()) {
            abort(403, 'Access denied: you do not own this order.');
        }
        return $frontendOrder;
    }

    protected function findExistingFrontendOrderForIdempotencyRecovery(?string $idempotencyKey, int $branchId, ?int $userId = null): ?FrontendOrder
    {
        if (blank($idempotencyKey) || $branchId <= 0) {
            return null;
        }

        return FrontendOrder::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('branch_id', $branchId)
            // [IDEMPOTENCY-USER-SCOPE 2026-06-27] Scoper par user_id (miroir du
            // jumeau POS OrderService:3066, déjà durci). Aligne la couche service
            // sur le scope du middleware idempotency (branch_id, user_id, hash(key))
            // — depuis le wireup WEB l'endpoint est multi-utilisateur (tokens guest
            // web, même branch_id), donc sans ce scope un client B réutilisant la
            // clé d'un client A récupérait SA commande (fuite PII/total/items).
            // Miroir EXACT du jumeau POS : défaut null (tests branch-only) saute le
            // scope ; les vrais appels myOrderStore passent auth()->id().
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
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
                    // [TERRAIN-HEAL 2026-07-16 · FRONT-CANCEL-RACE] L'auto-annulation client (web/borne)
                    // faisait cashBack + refundPoints + release stock SANS transaction ni verrou, en lisant
                    // le status du modèle route-bound (STALE) → deux annulations concurrentes (double-clic /
                    // retry réseau) passaient toutes deux le seuil et RE-remboursaient (double avoir + double
                    // clawback points + double libération stock ; le middleware idempotency ne dédup que les
                    // clés IDENTIQUES). On sérialise via DB::transaction + re-fetch lockForUpdate + early-return
                    // idempotent sur le status FRAIS verrouillé (miroir du durcissement OrderService::changeStatus).
                    return DB::transaction(function () use ($frontendOrder, $request) {
                        $locked = FrontendOrder::query()->whereKey($frontendOrder->id)->lockForUpdate()->firstOrFail();

                        // Idempotent : déjà annulée par une requête concurrente → aucun re-remboursement.
                        if ((int) $locked->status === (int) OrderStatus::CANCELED) {
                            return $locked;
                        }
                        // Re-valide transition + seuil sur le status FRAIS (pas le stale route-bound).
                        if (!(new \App\Rules\ValidStatusTransition($locked->status))->passes('status', $request->status)) {
                            throw new Exception(trans('all.message.invalid_status_transition'), 422);
                        }
                        // [FIX] KIOSK (25) et TAKEAWAY (10) borne : même seuil (annulable jusqu'à PREPARING).
                        $isKioskOrder = in_array(
                            (int) $locked->order_type,
                            [OrderType::KIOSK, OrderType::TAKEAWAY],
                            true
                        );
                        $cancelableThreshold = $isKioskOrder ? OrderStatus::PREPARING : OrderStatus::ACCEPT;
                        if ($locked->status >= $cancelableThreshold) {
                            throw new Exception(trans('all.message.order_accept'), 422);
                        }

                        // [P1-6 SÉCU 2026-08-04] Un client ne peut PAS auto-annuler une commande
                        // DÉJÀ PAYÉE : le seuil ne testait que `status`. Une commande carte web PAYÉE
                        // restée PENDING (avant auto-cuisine, ou seal en échec) était annulable → le
                        // remboursement `cashBack` est conditionné à `$locked->transaction` (relation
                        // hasOne toujours VIDE pour Mollie, qui n'écrit que la colonne transaction_id)
                        // → annulation SANS remboursement = argent perdu. Le remboursement d'un
                        // paiement en ligne = geste comptoir/ops (dashboard Mollie), jamais un
                        // self-cancel silencieux. Refus 422.
                        if ((int) $locked->payment_status === PaymentStatus::PAID) {
                            throw new Exception(trans('all.message.order_accept'), 422);
                        }

                        if ($locked->transaction) {
                            // [F-CASH-REFUND-DRAWER 2026-07-15 / P1] slug = origine du paiement.
                            $refundGateway = ((int) $locked->pos_payment_method === \App\Enums\PosPaymentMethod::CASH) ? 'cash' : 'credit';
                            app(PaymentService::class)->cashBack(
                                $locked,
                                $refundGateway,
                                'TXN-' . \Illuminate\Support\Str::random(12)
                            );
                        }
                        app(LoyaltyService::class)->refundPoints($locked, 'kiosk');
                        $oldStatus = $locked->status;
                        // [AUDIT-F-004] raison → transition row (invariant ORDER_FLOW §49).
                        $cancelReason = $request->input('reason');
                        if (is_string($cancelReason)) {
                            $cancelReason = trim($cancelReason);
                            if ($cancelReason === '') {
                                $cancelReason = null;
                            }
                        }
                        if ($cancelReason !== null && $locked->isFillable('reason')) {
                            $locked->reason = $cancelReason;
                        }
                        $locked->status = $request->status;
                        $locked->save();
                        OrderStateMachine::recordTransition(
                            FrontendOrder::class,
                            (int) $locked->id,
                            (int) $oldStatus,
                            (int) $request->status,
                            Auth::check() ? (int) Auth::id() : null,
                            $cancelReason
                        );
                        // Events DispatchableAfterCommit → déférés au commit de la tx (KDS/OSS retirent la tuile).
                        try {
                            OrderStatusChanged::dispatch($locked, $oldStatus, (int) $request->status);
                        } catch (\Exception $e) {
                            Log::warning('[FrontendOrder] OrderStatusChanged on cancel failed: ' . $e->getMessage());
                        }
                        SendOrderMail::dispatch(['order_id' => $locked->id, 'status' => $request->status]);
                        SendOrderSms::dispatch(['order_id' => $locked->id, 'status' => $request->status]);
                        SendOrderPush::dispatch(['order_id' => $locked->id, 'status' => $request->status]);
                        // [F-01] Libération stock compensatoire (idempotent via released_qty).
                        try {
                            OrderCanceled::dispatch($locked); // allow: stock-release dispatch; recordTransition wrote the canonical audit row.
                        } catch (\Exception $e) {
                            Log::warning('[FrontendOrder] OrderCanceled on cancel failed: ' . $e->getMessage()); // allow: warning only
                        }
                        return $locked;
                    });
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
     * [OWNER 2026-08-03 SÉCU · MOLLIE-CANCEL] Annulation SYSTÈME d'une commande web carte
     * dont le paiement en ligne est TERMINAL non abouti (failed/canceled/expired chez
     * Mollie, webhook = source de vérité). « J'annule le paiement à la banque et la
     * commande était quand même validée » : avant, la commande restait PENDING en caisse
     * (cuisine lançable) avec un écran de confiance côté client.
     *
     * Garde-fous (verrou + idempotent, miroir du cancel client :801) :
     *  - UNIQUEMENT source WEB + intent carte (payment_method=CARD) ;
     *  - UNIQUEMENT encore PENDING + UNPAID — une commande ACCEPTÉE (cuisine lancée) ou
     *    PAYÉE n'est JAMAIS annulée par un webhook retardataire (décision humaine) ;
     *  - side-effects du chemin canonique : refund points fidélité, transition auditée
     *    (acteur système), events board (KDS/caisse retirent la tuile), release stock.
     *    Pas de cashBack : aucun argent encaissé (transaction_id null par définition).
     *
     * @return bool true si la commande a été annulée par CET appel.
     */
    public function cancelForFailedOnlinePayment(FrontendOrder $order, string $mollieStatus): bool
    {
        return DB::transaction(function () use ($order, $mollieStatus) {
            $locked = FrontendOrder::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->status === (int) OrderStatus::CANCELED) {
                return false; // rejeu webhook → idempotent
            }
            // [A1 cycle 5 — 2026-08-05] Même angle mort que le gate de finalisation : une
            // commande LIVRAISON porte `source_surface = 'delivery'` (forcé par
            // FrontendOrder::creating), donc un paiement carte échoué n'était PAS annulé et la
            // commande restait PENDING indéfiniment. Les deux surfaces désignent la même chose :
            // une intention de paiement carte passée depuis le site.
            $isWebCardIntent = in_array(strtolower((string) $locked->source_surface), ['web', 'delivery'], true)
                && (int) $locked->payment_method === (int) PaymentGateway::CARD;
            if (! $isWebCardIntent
                || (int) $locked->status !== (int) OrderStatus::PENDING
                || (int) $locked->payment_status !== (int) PaymentStatus::UNPAID) {
                return false;
            }

            app(\App\Services\LoyaltyService::class)->refundPoints($locked, 'kiosk');
            $oldStatus = (int) $locked->status;
            $reason = 'Paiement en ligne non abouti (mollie:' . $mollieStatus . ') — annulation automatique';
            if ($locked->isFillable('reason')) {
                $locked->reason = $reason;
            }
            $locked->status = OrderStatus::CANCELED;
            $locked->save();
            \App\Domain\Order\OrderStateMachine::recordTransition(
                FrontendOrder::class,
                (int) $locked->id,
                $oldStatus,
                (int) OrderStatus::CANCELED,
                null,
                $reason
            );
            try {
                OrderStatusChanged::dispatch($locked, $oldStatus, (int) OrderStatus::CANCELED);
            } catch (\Exception $e) {
                Log::warning('[MollieCancel] OrderStatusChanged failed: ' . $e->getMessage());
            }
            SendOrderMail::dispatch(['order_id' => $locked->id, 'status' => (int) OrderStatus::CANCELED]);
            SendOrderPush::dispatch(['order_id' => $locked->id, 'status' => (int) OrderStatus::CANCELED]);
            try {
                OrderCanceled::dispatch($locked); // release stock (idempotent via released_qty)
            } catch (\Exception $e) {
                Log::warning('[MollieCancel] OrderCanceled failed: ' . $e->getMessage()); // allow: warning only
            }

            return true;
        });
    }

    /**
     * [DÉCOUPLAGE FIDÉLITÉ 2026-07-18] Customer-facing (kiosk/web) discount gate.
     *
     * La fidélité est désormais découplée des remises discrétionnaires. Sur ce
     * chemin, coupon et redeem fidélité sont MUTUELLEMENT EXCLUSIFs
     * (applyKioskLoyaltyDiscount early-return si un coupon est présent, sans
     * poser $this->loyaltyApplied) → $discount provient soit du coupon SOIT de la
     * fidélité, jamais des deux. On route donc :
     *
     *   - remise fidélité ($this->loyaltyApplied) → flag DÉDIÉ `pos.loyalty_enabled`
     *     (défaut true). F1 (netting TVA du Z sur base remisée) est FIXÉ + prouvé
     *     (ZReportDiscountNettingTest, incl. close()+sign()+verifyChain() sur un Z
     *     remisé) → un ordre remisé fidélité signe un Z fiscalement CORRECT.
     *   - remise coupon (discrétionnaire) → kill-switch `pos.manual_discount_enabled`
     *     (défaut false) — INCHANGÉ : les remises restent coupées.
     *
     * Mirrors OrderService::assertDiscretionaryDiscountAllowed pour le coupon.
     */
    private function assertDiscretionaryDiscountAllowed(float $discount): void
    {
        if ($discount <= 0.0) {
            return;
        }

        if ($this->loyaltyApplied) {
            if (config('pos.loyalty_enabled') !== true) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'discount' => "La fidélité est temporairement désactivée.",
                ]);
            }
            return;
        }

        if (config('pos.manual_discount_enabled') !== true) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => "Les remises (coupon) sont désactivées en V1.",
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
        // [AUDIT FIDÉLITÉ 2026-08-01] `status=1` est la convention LEGACY de LoyaltyController ;
        // l'écrasante majorité des clients sont ACTIVE(5). Filtrer sur 1 seul rendait ces
        // clients INTROUVABLES → remise silencieusement ramenée à 0 (le client croit payer
        // avec ses points et paie plein tarif). On accepte les deux, comme
        // PosRedemptionService et LoyaltyController::isCustomerActive().
        $loyaltyUser = \App\Models\User::where('loyalty_code', $loyaltyCode)
            ->whereIn('status', [1, \App\Enums\Status::ACTIVE])
            ->lockForUpdate()
            ->first();

        if (!$loyaltyUser) {
            return;
        }

        // [LOCK_FRONTENDORDER_REDEEM_REORDER 2026-08-04] Ordre CORRECT : (1) valeur de remise SANS
        // barrière de solde (le rattachement d'un pré-rachat déjà débité ne doit pas re-tester le
        // solde — RED-1) ; (2) garde IDOR EN PREMIER (couvre rattachement ET débit — RED-3) ;
        // (3) rattachement TOUTE surface (RED-2) sans re-check ; (4) débit FRAIS avec check solde.
        $redemption = $this->discountCalculator->kioskLoyaltyRedemption(
            $validatedCoupon,
            $loyaltyCode,
            $requestedDiscount,
            $realSubtotal,
            $loyaltyUser,
            skipBalanceGate: true
        );
        $maxDiscount = (float) $redemption['discount'];
        $pointsRequired = (int) $redemption['points'];

        if ($pointsRequired <= 0 || $maxDiscount <= 0.0) {
            // Montant nul / sous le plancher min_redeem → aucune remise, aucun débit.
            Log::warning('[Loyalty] Redemption skipped (zero/below-min points)', [
                'user_id' => $loyaltyUser->id,
                'order_id' => $this->frontendOrder->id,
                'requested_discount' => $requestedDiscount,
            ]);
            return;
        }

        // [SEC MISSION-28/30 · RED-3 2026-08-04] Anti-vol de points (IDOR) — DÉPLACÉ EN PREMIER pour
        // couvrir AUSSI le rattachement d'un pré-rachat (avant, la branche rattachement `return`ait
        // AVANT cette garde → un invité consommait le pré-rachat d'autrui). Borne OU propriétaire OU
        // staff, sinon 422 (aucun point brûlé, aucun rattachement).
        $callerId = (int) (Auth::id() ?? 0);
        $isKioskCaller = $callerId > 0 && \App\Models\KioskMachine::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('user_id', $callerId)->exists();
        $isOwnerCaller = $callerId > 0 && (int) $loyaltyUser->id === $callerId;
        $isStaffCaller = Auth::user()?->hasAnyRole(['Admin', 'Branch Manager', 'POS Operator', 'Stuff']) ?? false;
        if (! $isKioskCaller && ! $isOwnerCaller && ! $isStaffCaller) {
            Log::warning('[Loyalty] Débit/rattachement code étranger REFUSÉ (création commande) — IDOR bloqué (Mission-28/RED-3)', [
                'caller_id' => $callerId,
                'loyalty_user_id' => $loyaltyUser->id,
            ]);
            throw new \InvalidArgumentException(
                'Ce code fidélité ne peut pas être utilisé sur cette commande.',
                422
            );
        }

        // [RED-2 2026-08-04] Rattachement d'un pré-rachat DÉJÀ débité — TOUTE surface (avant, le
        // filtre `source_surface='kiosk'` ignorait un client web/mobile écrivant 'pos' → double débit).
        $pendingRedeem = \App\Models\LoyaltyTransaction::query()
            ->where('user_id', $loyaltyUser->id)
            ->where('loyalty_code', $loyaltyUser->loyalty_code)
            ->where('type', 'redeem')
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

            // Rattacher SANS re-débiter ni re-vérifier le solde (les points sont déjà partis).
            $pendingRedeem->order_id = $this->frontendOrder->id;
            $pendingRedeem->description = 'Reduction fidelite rattachee a la commande';
            $pendingRedeem->save();

            $calculatedDiscount += $maxDiscount;
            $this->loyaltyApplied = true;

            Log::info('[Loyalty] Pending redeem attached without second deduction', [
                'user_id' => $loyaltyUser->id,
                'order_id' => $this->frontendOrder->id,
                'transaction_id' => $pendingRedeem->id,
            ]);
            return;
        }

        // Débit FRAIS : ICI SEULEMENT on vérifie le solde (le pré-rachat, lui, a déjà payé).
        if ((int) $loyaltyUser->loyalty_points < $pointsRequired) {
            Log::warning('[Loyalty] Solde insuffisant pour un débit frais — aucune remise', [
                'user_id' => $loyaltyUser->id,
                'order_id' => $this->frontendOrder->id,
                'available' => $loyaltyUser->loyalty_points,
                'required' => $pointsRequired,
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

            // [owner 2026-07-07] Le compteur quotidien démarre à kiosk.queue_start_number
            // (32 par défaut) : 1er ordre du jour = A0032, puis suit le max existant.
            $startNumber = max(1, (int) config('kiosk.queue_start_number', 1));

            return 'A' . str_pad(max($maxQueueNum + 1, $startNumber), 4, '0', STR_PAD_LEFT);
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
        // [OWNER 2026-08-04 · LOCK_WEB_CARD_FISCAL_SEAL] Une vente CARTE WEB payée en ligne
        // (Mollie) doit être scellée EXACTEMENT comme une borne-payée : sans ça, le gate
        // KioskMachine ci-dessus no-ope (user_id = client, pas une borne) → PAID sans
        // fiscal_sequence_no → hors du Z signé NF525. On unifie le chemin.
        // [A1 cycle 5 — 2026-08-05 · P1 LATENT] Ce gate n'acceptait QUE la surface 'web', alors
        // que `FrontendOrder::creating` force `source_surface = 'delivery'` dès que
        // `order_type === DELIVERY` (le web n'envoie pas la surface, il envoie `source: 5`).
        // Une commande LIVRAISON payée par carte tombait donc dans un trou noir : ma garde de
        // création la retenait (intention carte), et ce gate-ci refusait ensuite de la libérer
        // au paiement — `promoted=false`, jamais dispatchée, restée PENDING, et même pas
        // rattrapée par `cancelForFailedOnlinePayment` ni par aucune lane du janitor.
        // PAYÉE, JAMAIS EN CUISINE, JAMAIS ANNULÉE. Latent aujourd'hui (le drapeau
        // `feature-delivery` est absent, la livraison est renvoyée vers Uber Eats), mais P0 le
        // jour de l'activation — et c'est une régression de mon propre correctif `87bbaf6ab`.
        $isWebCardOrder = in_array(strtolower((string) $frontendOrder->source_surface), ['web', 'delivery'], true)
            && (int) $frontendOrder->payment_method === (int) PaymentGateway::CARD
            && in_array((int) $frontendOrder->order_type, [OrderType::TAKEAWAY, OrderType::DELIVERY], true);
        $isKioskOrderType = $isKioskOrderType || $isWebCardOrder;
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

                    // [P1 fiscal_dated_at — LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT 2026-07-07 · NF-01]
                    // Chemin kiosk-payé (y compris ALLOCATION DIFFÉRÉE via RetryFiscalAllocCommand
                    // / PaymentReconcile) : si l'alloc a échoué à T0 puis est rattrapée dans un Z
                    // ULTÉRIEUR, sans ce stamp aggregate() fenêtre par created_at (=T0, Z déjà clos)
                    // → reçu numéroté hors de TOUT Z signé (le P1 exact, oublié au 1er fix). Stamper
                    // l'instant d'allocation réel → la commande appartient au Z d'allocation.
                    if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'fiscal_dated_at')) {
                        $locked->fiscal_dated_at = now();
                    }

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
