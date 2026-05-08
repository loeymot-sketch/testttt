<?php

namespace App\Services\Delivery;

use App\Domain\Delivery\NormalizedDeliveryOrder;
use App\Domain\Delivery\PlatformAdapterRegistry;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Events\OrderCreated;
use App\Models\DeliveryPlatform;
use App\Models\DeliveryPlatformExternalOrder;
use App\Models\DeliveryWebhookEvent;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [PARALLEL-TRACK-1.2 / Delivery Platform Integration — Phase 2]
 *
 * The hub of the inbound delivery pipeline.
 *
 * Lifecycle (every step inside a single DB transaction so that any
 * failure rolls back atomically — including the fiscal sequence
 * reservation, which is "consumed" only on commit):
 *
 *   a. Adapter.parseOrder(payload, branchId) → NormalizedDeliveryOrder DTO
 *   b. UPSERT delivery_platform_external_orders by (platform, external_id):
 *      - first webhook of an order: INSERT a fresh mapping row;
 *      - replay (same external_id): catch QueryException 1062 and
 *        return the existing row, no-op.
 *   c. FiscalSequenceService::next($branchId) — strictly monotonic
 *      gap-free sequence number for NF525 compliance.
 *   d. FrontendOrder::create() with the remaining fields, then
 *      assign-then-save fiscal_sequence_no (matches OrderService
 *      pattern at line 862, since the field is NOT in $fillable).
 *   e. OrderItem::insert() in bulk — synthetic items (item_id=null)
 *      when the platform-side SKU is not known to FoodKing.
 *   f. Optional OrderAddress::create() for delivery (skipped on
 *      customer_pickup).
 *   g. Update DeliveryPlatformExternalOrder.frontend_order_id +
 *      internal_status + ingested_at (closes the mapping loop).
 *   h. AuditLogService.write('delivery.order.ingested') — appends
 *      to the per-branch fiscal hash chain.
 *
 * After commit (DB::afterCommit closure):
 *   - OrderCreated::dispatch($order) → fans out to:
 *       - SendFcmOnOrderCreated      (mobile push)
 *       - PersistOrderCreatedToOutbox (websocket broadcast via
 *                                      DispatchDomainEventsJob)
 *
 * Frozen-zone respect: this service consumes FiscalSequenceService
 * and AuditLogService verbatim (`app(...)`); it never touches their
 * source files.
 */
class DeliveryOrderIngestionService
{
    public function __construct(
        private PlatformAdapterRegistry $registry,
        private FiscalSequenceService $fiscalSequence,
        private AuditLogService $auditLog,
    ) {
    }

    /**
     * Ingest a webhook event and return a small summary used by the
     * caller (the queue worker) to update the webhook event row.
     *
     * @return array{
     *   external_order_id: int,
     *   frontend_order_id: int|null,
     *   replayed: bool
     * }
     */
    public function ingest(DeliveryWebhookEvent $event): array
    {
        $platform = (string) $event->platform;
        $payload  = (array) ($event->body ?? []);
        $branchId = (int) $event->branch_id;

        if ($branchId <= 0) {
            throw new \InvalidArgumentException(
                "DeliveryOrderIngestionService::ingest() requires a positive branch_id "
                . "on the webhook event row (got {$branchId})."
            );
        }

        $adapter = $this->registry->for($platform);
        $eventType  = $adapter->eventTypeFrom($payload);
        $externalId = $adapter->externalIdFrom($payload);

        // Only process create events — cancel / status flow lands in Phase 3.
        if ($eventType !== 'order.created') {
            Log::info('[DeliveryOrderIngestionService] skipping non-create event', [
                'platform'    => $platform,
                'external_id' => $externalId,
                'event_type'  => $eventType,
            ]);
            return [
                'external_order_id' => 0,
                'frontend_order_id' => null,
                'replayed'          => false,
            ];
        }

        // Resolve the platform config once, outside the transaction —
        // it's a read-only lookup that doesn't need to be rolled back.
        /** @var DeliveryPlatform|null $cfg */
        $cfg = DeliveryPlatform::withoutGlobalScopes()
            ->where('platform', $platform)
            ->where('branch_id', $branchId)
            ->first();

        if ($cfg === null) {
            throw new \RuntimeException(
                "DeliveryOrderIngestionService: no DeliveryPlatform row for "
                . "platform={$platform} branch_id={$branchId}."
            );
        }

        $dto = $adapter->parseOrder($payload, $branchId);

        $orderRef = null;
        $externalOrderRow = null;
        $replayed = false;

        DB::transaction(function () use (
            $platform,
            $externalId,
            $branchId,
            $cfg,
            $payload,
            $dto,
            &$orderRef,
            &$externalOrderRow,
            &$replayed
        ): void {
            // ---- (b) Dedupe key on (platform, external_id) -------------
            try {
                $externalOrderRow = DeliveryPlatformExternalOrder::create([
                    'platform'             => $platform,
                    'external_id'          => $externalId,
                    'branch_id'            => $branchId,
                    'delivery_platform_id' => $cfg->id,
                    'raw_payload'          => $payload,
                    'received_at'          => now(),
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    // Replay — return the existing row untouched.
                    $externalOrderRow = DeliveryPlatformExternalOrder::withoutGlobalScopes()
                        ->where('platform', $platform)
                        ->where('external_id', $externalId)
                        ->firstOrFail();
                    $replayed = true;
                    return;
                }
                throw $e;
            }

            // ---- (c) Reserve a fiscal sequence number ------------------
            $seq = $this->fiscalSequence->next($branchId);

            // ---- (d) Hydrate FrontendOrder ----------------------------
            // The orders table has user_id NOT NULL FK on users — for
            // platform-originated orders we attribute them to a per-branch
            // synthetic "bot" user so reports and filters keep working
            // without modifying the schema. Mirrors the kiosk machine
            // user pattern (KioskMachine.user_id).
            $botUser = $this->resolveDeliveryBotUser($branchId, $platform);

            $totalDecimal = $this->centsToDecimal($dto->totals['total'] ?? 0);
            $subtotalDecimal = $this->centsToDecimal($dto->totals['subtotal'] ?? 0);
            $deliveryDecimal = $this->centsToDecimal($dto->totals['delivery_charge'] ?? 0);

            /** @var FrontendOrder $order */
            $order = FrontendOrder::create([
                'user_id'          => $botUser->id,
                'branch_id'        => $branchId,
                'order_type'       => OrderType::DELIVERY,
                'source'           => Source::DELIVERY_PLATFORM,
                'source_surface'   => $platform,
                'status'           => OrderStatus::ACCEPT,
                'payment_status'   => PaymentStatus::PAID,
                'payment_method'   => PaymentGateway::DELIVERY_PLATFORM,
                'subtotal'         => $subtotalDecimal,
                'discount'         => 0,
                'delivery_charge'  => $deliveryDecimal,
                'total_tax'        => 0,
                'total'            => $totalDecimal,
                'order_datetime'   => $dto->placed_at,
                'preparation_time' => $this->computePreparationMinutes($dto),
                'idempotency_key'  => 'dp:' . $platform . ':' . substr($externalId, 0, 60),
            ]);

            // [PARALLEL-TRACK-1.2] fiscal_sequence_no is NOT in $fillable
            // (matches OrderService.php:862 pattern). Assign-then-save so
            // the column is persisted inside the same transaction.
            $order->fiscal_sequence_no = $seq;
            $order->order_serial_no    = date('dmy') . $order->id;
            $order->save();

            $orderRef = $order;

            // ---- (e) OrderItem rows (bulk insert) ----------------------
            // SKU resolution lands in Phase 3 — until then we attach every
            // imported item to a per-platform synthetic placeholder Item
            // so the FK on order_items.item_id is satisfied. The
            // `instruction` column carries the platform SKU + title so the
            // KDS still has a human-readable line.
            $placeholderItem = $this->resolveDeliveryPlaceholderItem($platform);

            $rows = [];
            $now  = now();
            foreach ($dto->items as $item) {
                $rows[] = [
                    'order_id'             => $order->id,
                    'branch_id'            => $branchId,
                    'item_id'              => $placeholderItem->id,
                    'quantity'             => $item->qty,
                    'discount'             => 0,
                    'tax_name'             => null,
                    'tax_rate'             => 0,
                    'tax_type'             => 0,
                    'tax_amount'           => 0,
                    'price'                => $this->centsToDecimal($item->unit_price_cents),
                    'item_variations'      => json_encode([]),
                    'item_extras'          => json_encode($item->modifiers),
                    'item_variation_total' => 0,
                    'item_extra_total'     => $this->centsToDecimal($this->modifiersTotalCents($item->modifiers)),
                    'instruction'          => sprintf(
                        '[%s/%s] %s',
                        $platform,
                        $item->external_sku ?: 'no-sku',
                        $item->name
                    ),
                    'total_price'          => $this->centsToDecimal($item->unit_price_cents * $item->qty),
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ];
            }
            if (!empty($rows)) {
                OrderItem::insert($rows);
            }

            // ---- (f) Optional OrderAddress -----------------------------
            // The orders schema requires user_id NOT NULL on order_addresses too,
            // so we attribute the row to the same delivery bot identity.
            if ($dto->pickup_type === 'delivery' && !empty($dto->address)) {
                OrderAddress::create([
                    'order_id'  => $order->id,
                    'user_id'   => $botUser->id,
                    'label'     => 'delivery',
                    'address'   => trim((string) ($dto->address['address'] ?? '')),
                    'apartment' => $dto->address['apartment'] ?? null,
                    'latitude'  => $dto->address['latitude'] ?? null,
                    'longitude' => $dto->address['longitude'] ?? null,
                ]);
            }

            // ---- (g) Close the mapping loop ----------------------------
            $externalOrderRow->forceFill([
                'frontend_order_id' => $order->id,
                'internal_status'   => OrderStatus::ACCEPT,
                'external_status'   => 'created',
                'ingested_at'       => now(),
            ])->save();

            // ---- (h) Audit (NF525 hash chain) --------------------------
            $this->auditLog->write([
                'branch_id'  => $branchId,
                'action'     => 'delivery.order.ingested',
                'resource'   => 'order',
                'resource_id'=> $order->id,
                'payload'    => [
                    'platform'             => $platform,
                    'external_id'          => $externalId,
                    'order_id'             => $order->id,
                    'fiscal_sequence_no'   => $seq,
                    'total_cents'          => (int) ($dto->totals['total'] ?? 0),
                    'item_count'           => count($dto->items),
                    'pickup_type'          => $dto->pickup_type,
                    'customer_name'        => $dto->customer['name'] ?? null,
                ],
            ]);
        });

        // ---- After-commit: broadcast OrderCreated ----------------------
        if ($orderRef !== null && !$replayed) {
            $orderForBroadcast = $orderRef;
            DB::afterCommit(function () use ($orderForBroadcast): void {
                OrderCreated::dispatch($orderForBroadcast);
            });
        }

        return [
            'external_order_id' => (int) ($externalOrderRow->id ?? 0),
            'frontend_order_id' => $orderRef?->id,
            'replayed'          => $replayed,
        ];
    }

    /**
     * Resolve (or create) a per-platform synthetic placeholder Item.
     *
     * `order_items.item_id` is a NOT NULL FK on `items`. Until SKU
     * mapping is wired in Phase 3, every inbound platform line item is
     * pinned to a single placeholder row so the FK is honoured. The
     * `instruction` column on each OrderItem carries the platform-side
     * SKU + title so the KDS still has a printable line.
     *
     * One placeholder per platform (not per branch) — categories and
     * items are global in V1 (no branch_id column on items). Items
     * inserted here are status=INACTIVE so they never appear in the
     * customer-facing menu.
     */
    private function resolveDeliveryPlaceholderItem(string $platform): Item
    {
        $slug = "__delivery_placeholder_{$platform}__";

        $existing = Item::withoutGlobalScopes()->where('slug', $slug)->first();
        if ($existing !== null) {
            return $existing;
        }

        $categorySlug = '__delivery_placeholder_category__';
        $category = ItemCategory::query()->where('slug', $categorySlug)->first();
        if ($category === null) {
            $category = ItemCategory::create([
                'name'        => 'Delivery Platform Placeholder',
                'slug'        => $categorySlug,
                'description' => 'Synthetic category — never shown to customers. '
                    . 'Holds placeholder items used by inbound delivery platform '
                    . 'orders (Phase 2).',
                'status'      => 0, // INACTIVE
            ]);
        }

        return Item::create([
            'name'             => "Delivery Platform Placeholder ({$platform})",
            'slug'             => $slug,
            'item_category_id' => $category->id,
            'tax_id'           => null,
            'price'            => 0,
            'status'           => 0,            // INACTIVE — never visible
            'item_type'        => 1,            // Default — purely structural
            'is_featured'      => 0,
            'description'      => "Synthetic placeholder for inbound {$platform} orders. "
                . 'Real item titles live in OrderItem.instruction. '
                . 'Phase 3 maps platform SKU → real item.',
        ]);
    }

    /**
     * Resolve (or create) the per-branch synthetic delivery bot User.
     *
     * Why a bot user instead of NULL: the `orders.user_id` column is
     * NOT NULL with a FK on `users` (migration 2022_11_17_110810). We
     * keep the schema untouched and instead route every delivery
     * platform order to a stable, branch-scoped bot account. Reports,
     * branch filters and audit trails remain coherent — the bot is just
     * an unauthenticated "system" identity, marked is_guest=1.
     */
    private function resolveDeliveryBotUser(int $branchId, string $platform): User
    {
        $email = sprintf('delivery-bot+%d@foodking.local', $branchId);

        // Bypass BranchScope so the bot is reusable across branch contexts
        // when the worker runs without an auth session (queue handler).
        $existing = User::withoutGlobalScopes()->where('email', $email)->first();
        if ($existing !== null) {
            return $existing;
        }

        return User::withoutGlobalScopes()->create([
            'name'              => "Delivery Bot (branch {$branchId})",
            'email'             => $email,
            'username'          => 'delivery-bot-b' . $branchId,
            'phone'             => null,
            'password'          => bcrypt(\Illuminate\Support\Str::random(40)),
            'branch_id'         => $branchId,
            'country_code'      => '+33',
            'status'            => 1, // ACTIVE
            'is_guest'          => 1,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Convert integer cents to FoodKing's `decimal:6` string. We work
     * in cents from the platform side and only round once, here, at
     * the persistence boundary.
     */
    private function centsToDecimal(int $cents): string
    {
        return number_format($cents / 100, 6, '.', '');
    }

    /**
     * Sum the modifier prices into a single cents amount that maps
     * onto OrderItem.item_extra_total.
     *
     * @param  array<int, array<string,mixed>>  $modifiers
     */
    private function modifiersTotalCents(array $modifiers): int
    {
        $sum = 0;
        foreach ($modifiers as $mod) {
            $unit = (int) ($mod['unit_price_cents'] ?? 0);
            $qty  = max(1, (int) ($mod['qty'] ?? 1));
            $sum += $unit * $qty;
        }
        return $sum;
    }

    /**
     * Best-effort estimate of the prep window in minutes — used for
     * KDS countdown timers. Falls back to the order_setup default
     * when the platform did not send an estimate.
     */
    private function computePreparationMinutes(NormalizedDeliveryOrder $dto): int
    {
        $default = (int) (config('order_setup.food_preparation_time')
            ?? config('settings.food_preparation_time')
            ?? 20);

        if ($dto->expected_ready_at === null) {
            return $default;
        }

        $minutes = (int) max(1, $dto->placed_at->diffInMinutes($dto->expected_ready_at));
        return min(180, $minutes); // hard cap at 3h
    }

    /**
     * SQLState 23000 (MySQL) / 23505 (Postgres) / 'UNIQUE constraint
     * failed' (SQLite). Centralised so the test suite hits the same
     * detection path as production.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        if (in_array($code, ['23000', '23505'], true)) {
            return true;
        }
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'unique')
            || str_contains($msg, 'duplicate');
    }
}
