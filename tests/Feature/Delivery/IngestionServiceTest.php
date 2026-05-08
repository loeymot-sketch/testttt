<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DeliveryPlatform;
use App\Models\DeliveryPlatformExternalOrder;
use App\Models\DeliveryWebhookEvent;
use App\Models\FrontendOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Delivery\DeliveryOrderIngestionService;
use App\Services\Fiscal\AuditLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.2 / Delivery Platform Integration — Phase 2]
 *
 * The hub of the inbound flow — verifies the full transactional
 * contract: fiscal sequence allocation, item insertion, address
 * persistence, audit chain integrity, and replay idempotency.
 *
 * Tests use Event::fake() rather than Bus::fake() so we can verify
 * the OrderCreated dispatch firing AFTER commit (not during).
 */
class IngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private DeliveryPlatform $config;
    private DeliveryOrderIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-08T10:15:30Z');

        $this->branch = Branch::factory()->create();
        $this->config = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'store_uuid_42',
            'credentials'       => ['webhook_secret' => 'wh', 'api_key' => 'k'],
        ]);

        $this->service = app(DeliveryOrderIngestionService::class);
    }

    public function test_happy_path_creates_order_with_fiscal_sequence_and_audit_log(): void
    {
        Event::fake([\App\Events\OrderCreated::class]);

        $event = $this->buildEvent($this->orderCreatedFixture());
        $result = $this->service->ingest($event);

        // (a) FrontendOrder created with all the delivery-specific markers.
        $order = FrontendOrder::find($result['frontend_order_id']);
        $this->assertNotNull($order);
        $this->assertSame($this->branch->id,  $order->branch_id);
        $this->assertSame(OrderType::DELIVERY, $order->order_type);
        $this->assertSame(Source::DELIVERY_PLATFORM, $order->source);
        $this->assertSame('uber_eats',         $order->source_surface);
        $this->assertSame(PaymentStatus::PAID, $order->payment_status);
        $this->assertSame(PaymentGateway::DELIVERY_PLATFORM, $order->payment_method);
        $this->assertSame(OrderStatus::ACCEPT, $order->status);
        $this->assertSame(
            'dp:uber_eats:87a2b9b8-1111-2222-3333-444455556666',
            $order->idempotency_key
        );

        // (b) Money correctly carried from cents → decimal:6.
        $this->assertEquals('21.500000', (string) $order->subtotal);
        $this->assertEquals('2.500000',  (string) $order->delivery_charge);
        $this->assertEquals('26.150000', (string) $order->total);

        // (c) fiscal_sequence_no allocated (assign-then-save pattern).
        // Re-read raw column because the field is NOT in $fillable.
        $rawRow = DB::table('orders')->where('id', $order->id)->first();
        $this->assertNotNull($rawRow);
        $this->assertSame(1, (int) $rawRow->fiscal_sequence_no);

        // (d) order_items rows.
        $items = OrderItem::where('order_id', $order->id)->get();
        $this->assertCount(2, $items);
        $this->assertEquals('8.500000', (string) $items[0]->price);
        $this->assertSame(2, (int) $items[0]->quantity);

        // (e) AuditLog row appended to the chain.
        $audit = AuditLog::where('action', 'delivery.order.ingested')
            ->where('branch_id', $this->branch->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($order->id, (int) $audit->resource_id);
        $this->assertSame('order',    $audit->resource);

        // Verify chain integrity.
        $tampered = app(AuditLogService::class)->verifyChain($this->branch->id);
        $this->assertNull($tampered, 'audit chain must be intact after ingest');

        // (f) external_orders row closed (frontend_order_id + ingested_at).
        $extOrder = DeliveryPlatformExternalOrder::withoutGlobalScopes()
            ->where('id', $result['external_order_id'])
            ->first();
        $this->assertNotNull($extOrder);
        $this->assertSame($order->id, $extOrder->frontend_order_id);
        $this->assertSame(OrderStatus::ACCEPT, $extOrder->internal_status);
        $this->assertNotNull($extOrder->ingested_at);
        $this->assertFalse($result['replayed']);

        // (g) OrderCreated event dispatched after commit.
        Event::assertDispatched(\App\Events\OrderCreated::class);
    }

    public function test_replay_same_external_id_returns_existing_order(): void
    {
        Event::fake([\App\Events\OrderCreated::class]);

        $first = $this->service->ingest($this->buildEvent($this->orderCreatedFixture()));
        $countBefore = Order::withoutGlobalScopes()->count();

        $second = $this->service->ingest($this->buildEvent($this->orderCreatedFixture()));

        $countAfter = Order::withoutGlobalScopes()->count();
        $this->assertSame($countBefore, $countAfter, 'replay must NOT create a duplicate order');
        $this->assertSame($first['external_order_id'], $second['external_order_id']);
        $this->assertTrue($second['replayed']);
        $this->assertNull($second['frontend_order_id'], 'replayed result is short-circuited');

        // OrderCreated only dispatched once across both calls.
        Event::assertDispatchedTimes(\App\Events\OrderCreated::class, 1);
    }

    public function test_transaction_rollback_keeps_fiscal_sequence_max_unchanged(): void
    {
        // The orders table has user_id NOT NULL FK on users — pre-seed
        // a synthetic user the same way the IngestionService does, so
        // the surrogate fiscal_sequence_no=1 row inserts cleanly.
        $seedUser = \App\Models\User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);

        // Pre-seed an order with fiscal_sequence_no=1 so MAX is observable.
        $order1 = FrontendOrder::create([
            'user_id'        => $seedUser->id,
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::POS,
            'source'         => Source::POS,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => 1,
            'subtotal' => 10, 'discount' => 0, 'delivery_charge' => 0, 'total_tax' => 0, 'total' => 10,
        ]);
        $order1->fiscal_sequence_no = 1;
        $order1->save();

        $maxBefore = (int) DB::table('orders')
            ->where('branch_id', $this->branch->id)
            ->max('fiscal_sequence_no');
        $this->assertSame(1, $maxBefore);

        // Force the IngestionService transaction to fail by deleting the
        // platform config row right before ingest (the lookup returns null
        // and the service throws).
        $this->config->delete();

        try {
            $this->service->ingest($this->buildEvent($this->orderCreatedFixture()));
            $this->fail('Ingestion should have thrown when platform config is missing.');
        } catch (\Throwable $e) {
            // expected
        }

        $maxAfter = (int) DB::table('orders')
            ->where('branch_id', $this->branch->id)
            ->max('fiscal_sequence_no');
        $this->assertSame(
            $maxBefore,
            $maxAfter,
            'fiscal_sequence_no MAX must be unchanged after a rolled-back ingest'
        );
    }

    public function test_transaction_rollback_on_audit_failure_leaves_no_partial_order(): void
    {
        // Simulate a cache-outage scenario where the AuditLogService
        // throws AFTER FiscalSequenceService has already reserved a
        // sequence number. We use a binding monkey-patch (mirrors what
        // happens in production when the audit-chain Cache::lock fails).
        $this->app->bind(AuditLogService::class, function () {
            return new class extends AuditLogService {
                public function write(array $data): \App\Models\AuditLog
                {
                    throw new \RuntimeException('simulated audit chain failure');
                }
            };
        });

        $service = $this->app->make(DeliveryOrderIngestionService::class);

        $orderCountBefore = Order::withoutGlobalScopes()->count();
        $extCountBefore   = DeliveryPlatformExternalOrder::withoutGlobalScopes()->count();

        // [NF525 invariant] MAX(fiscal_sequence_no) before the failed
        // ingest must equal MAX after — proving the sequence reserved
        // inside FiscalSequenceService's nested transaction was rolled
        // back along with the surrounding outer transaction. A reserved
        // number that "sticks" after rollback would create a gap, which
        // breaks the gap-free monotonic contract of NF525.
        $maxFiscalBefore = (int) DB::table('orders')
            ->where('branch_id', $this->branch->id)
            ->max('fiscal_sequence_no');

        try {
            $service->ingest($this->buildEvent($this->orderCreatedFixture()));
            $this->fail('Expected failure was swallowed.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('simulated audit chain failure', $e->getMessage());
        }

        // No partial state — the surrounding DB::transaction rolled back.
        $this->assertSame($orderCountBefore, Order::withoutGlobalScopes()->count());
        $this->assertSame($extCountBefore,   DeliveryPlatformExternalOrder::withoutGlobalScopes()->count());

        $maxFiscalAfter = (int) DB::table('orders')
            ->where('branch_id', $this->branch->id)
            ->max('fiscal_sequence_no');
        $this->assertSame(
            $maxFiscalBefore,
            $maxFiscalAfter,
            'fiscal_sequence_no MAX must not advance after a rolled-back ingest '
            . '(NF525 gap-free monotonic invariant)'
        );
    }

    public function test_audit_chain_remains_verifiable_after_two_ingests(): void
    {
        Event::fake();

        $payload1 = json_decode($this->orderCreatedFixture(), true);
        $payload1['data']['id'] = 'order-A-' . uniqid();
        $this->service->ingest($this->buildEvent(json_encode($payload1)));

        $payload2 = json_decode($this->orderCreatedFixture(), true);
        $payload2['data']['id'] = 'order-B-' . uniqid();
        $this->service->ingest($this->buildEvent(json_encode($payload2)));

        $tampered = app(AuditLogService::class)->verifyChain($this->branch->id);
        $this->assertNull($tampered, 'audit chain must verify after two ingests');

        // Two delivery audit rows, sequentially.
        $rows = AuditLog::where('action', 'delivery.order.ingested')
            ->where('branch_id', $this->branch->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $rows);
        // The chain links: row 2 prev_hash == row 1 current_hash.
        $this->assertSame($rows[0]->current_hash, $rows[1]->prev_hash);
    }

    private function orderCreatedFixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/uber_eats/order_created.json'));
    }

    /**
     * Build a minimum DeliveryWebhookEvent row with the parsed payload
     * and the right branch/platform context — mirrors the row the
     * controller would have persisted in production.
     */
    private function buildEvent(string $rawBody): DeliveryWebhookEvent
    {
        $payload = json_decode($rawBody, true);

        return DeliveryWebhookEvent::create([
            'platform'         => 'uber_eats',
            'event_type'       => 'order.created',
            'signature_header' => 'v=1,t=1,k=key_1,h=' . str_repeat('0', 64),
            'signature_valid'  => true,
            'nonce'            => 'nonce-' . uniqid(),
            'headers'          => ['Content-Type' => 'application/json'],
            'body'             => $payload,
            'branch_id'        => $this->branch->id,
            'received_at'      => now(),
        ]);
    }
}
