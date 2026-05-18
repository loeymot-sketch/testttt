<?php

namespace App\Services;

use Exception;
use App\Enums\Ask;
use Carbon\Carbon;
use App\Models\Order;
use App\Enums\OrderType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Domain\Kds\KitchenReleaseRule;
use App\Events\SendOrderSms;
use Illuminate\Http\Request;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Domain\Order\OrderStateMachine;
use App\Listeners\DispatchKdsTicket;
use Illuminate\Support\Facades\Log;
use App\Libraries\QueryExceptionLibrary;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KitchenDisplaySystemOrderService
{
    public object $order;
    private bool $lastListOverflow = false;
    private DispatchKdsTicket $kdsTicketDispatcher;

    public function __construct(?DispatchKdsTicket $kdsTicketDispatcher = null)
    {
        $this->kdsTicketDispatcher = $kdsTicketDispatcher ?? app(DispatchKdsTicket::class);
    }

    protected array $orderFilter = [
        'order_serial_no',
        'branch_id',
        'order_type',
        'status',
        'source',
        'payment_method', // [GAP-29-3] Allow filtering by payment method (e.g. cash=1 for kiosk cash panel)
    ];

    protected array $exceptFilter = [
        'excepts'
    ];

    /**
     * @throws Exception
     */
    public function list(Request $request)
    {
        try {
            $requests = $request->all();
            $this->lastListOverflow = false;
            $allowedColumns = ['id', 'order_datetime', 'queue_number', 'order_serial_no', 'status', 'created_at'];
            $requestedColumn = (string) ($request->get('order_column') ?? 'id');
            $orderColumn = in_array($requestedColumn, $allowedColumns, true) ? $requestedColumn : 'id';
            $requestedType = strtolower((string) ($request->get('order_by') ?? 'asc'));
            $orderType = in_array($requestedType, ['asc', 'desc'], true) ? $requestedType : 'desc';

            $userBranchId = auth()->user()->branch_id ?? 0;

            // [Sprint 2A DEL-3 2026-05-16] Eager-load `address` + `user` so
            // KDSOrderDetailsResource can expose order_address + customer for
            // DELIVERY orders (chef + livreur). Order::user() is BranchScope-
            // exempt + withTrashed; Order::address() is hasOne with no scope.
            // No isolation risk — relations join via order_id only.
            $query = Order::with(['orderItems', 'address', 'user'])
                ->whereIn('status', KitchenReleaseRule::visibleStatuses())
                ->where(function ($query) {
                    $query->where('payment_status', PaymentStatus::PAID)
                        ->orWhere('payment_status', PaymentStatus::PENDING_COUNTER)
                        ->orWhere(function ($cashQuery) {
                            $cashQuery->where('order_type', OrderType::POS)
                                ->where('pos_payment_method', PosPaymentMethod::CASH);
                        });
                });

            // [FIX BUG-KDS-SYNC] Admin users have branch_id=0 → show all branches.
            // Branch-specific staff see only their own branch.
            if ($userBranchId > 0) {
                $query->where('branch_id', $userBranchId);
            }

            // [FIX-FRONT-05] Pagination KDS: limiter à 50 commandes actives maximum
            // [AUDIT-P51-BUG1] Fix: include advance orders scheduled for today OR overdue from yesterday+
            // Previously only showed yesterday's advance orders, causing "zombie" orders to persist unseen.
            // [RED-team P1 perf 2026-05-17] whereDate non-sargable → range query (uses idx_orders_datetime)
            //
            // [Wave 3b KDS Adversarial P0 2026-05-18 KDS-ADV3B-01] Mirror of
            // Wave 2b heal `148dbebce` (KdsSyncService). Carbon::today() /
            // Carbon::tomorrow() resolve in app.timezone='Europe/Paris' but
            // config/database.php mysql connection has NO `timezone` key →
            // MySQL session TZ defaults to UTC. orders.order_datetime is a
            // TIMESTAMP column (UTC-stored). Binding Paris-local midnight
            // as-is shifted the active window by 1-2h, silently dropping
            // nightly orders [00:00-02:00 Paris] from the KDS UI hydration.
            // Heal: convert Paris-local day bounds → UTC before binding.
            // Surgical at the query level (does NOT mutate
            // config/database.php mysql.timezone). Sentinel:
            // tests/Feature/Services/SisterServicesTzAwareTest.php.
            $appTz = config('app.timezone');
            $parisTodayStartUtc = Carbon::today($appTz)->setTimezone('UTC');
            $parisTodayEndUtc = Carbon::today($appTz)->endOfDay()->setTimezone('UTC');
            $parisTomorrowStartUtc = Carbon::tomorrow($appTz)->setTimezone('UTC');

            $orders = $query->where(function ($query) use ($parisTodayStartUtc, $parisTodayEndUtc, $parisTomorrowStartUtc) {
                // Standard orders: placed today (non-advance)
                $query->where(function ($subQuery) use ($parisTodayStartUtc, $parisTodayEndUtc) {
                    $subQuery->whereBetween('order_datetime', [$parisTodayStartUtc, $parisTodayEndUtc])
                             ->where('is_advance_order', Ask::NO);
                })
                // Advance orders: scheduled for today OR overdue from yesterday/past
                ->orWhere(function ($subQuery) use ($parisTomorrowStartUtc) {
                    $subQuery->where('is_advance_order', Ask::YES)
                             ->where('order_datetime', '<', $parisTomorrowStartUtc) // Today or overdue past dates
                             ->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED]); // Not already completed
                });
            })->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->orderFilter)) {
                        if ($key === "status" && $request) {
                            $query->where($key, (int) $request);
                        } else if ($key === "payment_method" && $request !== null && $request !== '') {
                            $query->where($key, (int) $request);
                        } else if (in_array($key, ['branch_id', 'order_type', 'source'], true)) {
                            // [POS-9.1.5] LIKE → = on integer-ID columns to prevent
                            // cross-branch substring leakage. Using LIKE '%1%' on branch_id
                            // matched rows 1, 10, 11, 12, 21, 100… a real data leak.
                            if ($request !== null && $request !== '') {
                                $query->where($key, (int) $request);
                            }
                        } else {
                            $query->where($key, 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $request) . '%');
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
            })->orderBy($orderColumn, $orderType)
            ->limit(51)
            ->get();

            $this->lastListOverflow = $orders->count() > 50;

            return $orders->take(50)->values();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function lastListOverflow(): bool
    {
        return $this->lastListOverflow;
    }

    /**
     * @throws Exception
     */
    public function changeStatus(Order $order, Request $request)
    {
        try {
            $newStatus = (int) $request->input('status');
            $expectedFrom = (int) $request->input('expected_status');

            $result = DB::transaction(function () use ($order, $newStatus, $expectedFrom) {
                $locked = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $userBranchId = (int) (auth()->user()->branch_id ?? 0);
                if ($userBranchId > 0 && (int) $locked->branch_id !== $userBranchId) {
                    abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
                }

                $fromLocked = (int) $locked->status;

                if ($fromLocked !== $expectedFrom) {
                    try {
                        Log::channel('stack')->warning('[KDS_409]', [
                            'op'                => 'kds.change_status',
                            'order_id'          => $locked->id ?? null,
                            'branch_id'         => $locked->branch_id ?? null,
                            'current_status'    => $locked->status ?? null,
                            'attempted_status'  => $newStatus,
                            'user_id'           => auth()->id(),
                            'reason'            => 'optimistic_lock_conflict',
                        ]);
                    } catch (\Throwable $logEx) { /* never break the abort flow */ }
                    abort(409, 'Order status was updated elsewhere — please refresh the KDS.');
                }

                if ($fromLocked === $newStatus) {
                    return ['model' => $locked->fresh(), 'from' => $fromLocked, 'changed' => false];
                }

                if (
                    ! KitchenReleaseRule::canTransition($fromLocked, $newStatus)
                    || ! OrderStateMachine::allows($fromLocked, $newStatus, auth()->user())
                ) {
                    throw new Exception(trans('all.message.invalid_status_transition'), 422);
                }

                $locked->status = $newStatus;
                $locked->save();

                OrderStateMachine::recordTransition(
                    Order::class,
                    (int) $locked->id,
                    $fromLocked,
                    $newStatus,
                    auth()->check() ? (int) auth()->id() : null,
                    null
                );

                return ['model' => $locked->fresh(), 'from' => $fromLocked, 'changed' => true];
            });

            $snapshot = $result['model'];
            $oldStatus = $result['from'];

            if (! ($result['changed'] ?? false)) {
                return;
            }

            SendOrderMail::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);
            SendOrderSms::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);
            SendOrderPush::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);

            try {
                $this->kdsTicketDispatcher->dispatch($snapshot, $oldStatus, $newStatus);
            } catch (\Exception $e) {
                Log::warning('[KDS] OrderStatusChanged broadcast failed: ' . $e->getMessage());
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function orderItems()
    {
        try {
            $userBranchId = auth()->user()->branch_id ?? 0;

            // [P3-2 FIX] Include ACCEPT orders so new POS orders appear on items board immediately
            // without waiting for chef to click "Start Preparing"
            $query = Order::with('orderItems')
                ->whereIn('status', KitchenReleaseRule::itemBoardStatuses());

            // Admin bypass: branch_id=0 sees all branches
            if ($userBranchId > 0) {
                $query->where('branch_id', $userBranchId);
            }

            // [FIX-53-2] Mirror the same fix applied to list() in Phase 51:
            // orderItems() was still using Carbon::yesterday() for advance orders,
            // causing overdue orders to vanish from the items board after 24h.
            // [RED-team P1 perf 2026-05-17] whereDate non-sargable → range query (uses idx_orders_datetime)
            //
            // [Wave 3b KDS Adversarial P0 2026-05-18 KDS-ADV3B-01] Mirror of
            // Wave 2b heal `148dbebce`. See list() above for full context —
            // same Paris-vs-UTC bug affected the items-board query, dropping
            // nightly orders [00:00-02:00 Paris] from the chef's items view.
            // Sentinel: tests/Feature/Services/SisterServicesTzAwareTest.php.
            $appTz = config('app.timezone');
            $parisTodayStartUtc = Carbon::today($appTz)->setTimezone('UTC');
            $parisTodayEndUtc = Carbon::today($appTz)->endOfDay()->setTimezone('UTC');
            $parisTomorrowStartUtc = Carbon::tomorrow($appTz)->setTimezone('UTC');

            $orders = $query->where(function ($query) use ($parisTodayStartUtc, $parisTodayEndUtc, $parisTomorrowStartUtc) {
                $query->where(function ($subQuery) use ($parisTodayStartUtc, $parisTodayEndUtc) {
                    $subQuery->whereBetween('order_datetime', [$parisTodayStartUtc, $parisTodayEndUtc])->where('is_advance_order', Ask::NO);
                })->orWhere(function ($subQuery) use ($parisTomorrowStartUtc) {
                    $subQuery->where('is_advance_order', Ask::YES)
                             ->where('order_datetime', '<', $parisTomorrowStartUtc)
                             ->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED]);
                });
            })->get();

            $allItems = $orders->pluck('orderItems')->flatten();
            $mergedItems = $allItems->groupBy(function ($item) {
                $variations = empty($item['item_variations']) ? '[]' : collect($item['item_variations'])->sortKeys()->toJson();
                $extras = empty($item['item_extras']) ? '[]' : collect($item['item_extras'])->sortKeys()->toJson();
                $addons = json_encode($this->normalizeAddonsForHash(data_get($item, 'composition_snapshot.addons', [])));
                // [L2 FIX] Normalize instruction: trim whitespace and lowercase to avoid
                // spurious KDS splits caused by minor formatting differences
                $instruction = mb_strtolower(trim($item['instruction'] ?? ''));
                // [Lot 2.I / G-5] split lines whose allergens snapshots differ — food safety.
                // Two order_items sharing item_id+variations+extras+instruction MUST appear
                // as 2 distinct KDS lines if their allergens_snapshot differ. Otherwise the
                // chef sees "Burger x2" with allergens of the FIRST item only — masking the
                // second customer's allergy declaration.
                $allergensHash = sha1(json_encode($this->normalizeAllergensForHash($item['allergens_snapshot'] ?? [])));

                return json_encode([
                    'item_id' => $item['item_id'],
                    'item_variations' => $variations,
                    'item_extras' => $extras,
                    'item_addons' => $addons,
                    'instruction' => $instruction,
                    'allergens_hash' => $allergensHash,
                ]);
            })->map(function ($groupedItems) {
                $firstItem = $groupedItems->first();
                // [B-2 FIX] Always sum quantities — items with same instruction are already grouped separately
                $firstItem['quantity'] = $groupedItems->sum('quantity');
                return $firstItem;
            })->values();
            return $mergedItems;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [Lot 2.I / G-5] Deterministic allergen hash input.
     *
     * Defensive against legacy data shapes (null, JSON object, scalar string)
     * that may exist on rows pre-dating the 2026_04_18_140004 backfill. Empty
     * snapshot, null, and non-array values all collapse to the same hash so
     * items WITHOUT declared allergens still merge together (regression safe).
     *
     * @param  mixed  $snapshot
     * @return array<int, string>
     */
    private function normalizeAllergensForHash($snapshot): array
    {
        if (! is_array($snapshot)) {
            return [];
        }

        $normalized = array_values(array_unique(array_map(
            'strval',
            array_filter($snapshot, static fn ($value) => $value !== null && $value !== '')
        )));

        sort($normalized);

        return $normalized;
    }

    /**
     * Keep KDS merged item rows split when composer addons differ.
     *
     * @param  mixed  $addons
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAddonsForHash($addons): array
    {
        if (! is_array($addons)) {
            return [];
        }

        return collect($addons)
            ->filter(fn ($addon): bool => is_array($addon))
            ->map(fn (array $addon): array => [
                'addon_id' => (int) ($addon['addon_id'] ?? 0),
                'addon_item_id' => (int) ($addon['addon_item_id'] ?? 0),
                'role' => (string) ($addon['role'] ?? ''),
                'quantity' => (int) ($addon['quantity'] ?? 1),
            ])
            ->sortBy([
                ['role', 'asc'],
                ['addon_id', 'asc'],
                ['addon_item_id', 'asc'],
                ['quantity', 'asc'],
            ])
            ->values()
            ->all();
    }
}
