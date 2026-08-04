<?php

namespace Tests\Feature\Order;

use App\Domain\Events\EventContract;
use App\Enums\EventType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * @FK-ID F-VERIFY-09-10 | @plan docs/audit/plans/PLAN_P13_PAYMENT_STATUS_STATE_MACHINE_2026-05-06.md
 *
 * Verifies the outbox row produced by {@see \App\Listeners\PersistOrderPaymentStatusChangedToOutbox}
 * is shaped per {@see EventContract::REQUIRED_PAYLOAD_KEYS} and survives the
 * `assertEnvelopeValid()` contract check.
 */
class ChangePaymentStatusOutboxTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Queue::fake();

        $this->branch  = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
    }

    public function test_unpaid_to_paid_creates_domain_event_row(): void
    {
        $order = Order::factory()->create([
            'user_id'        => $this->cashier->id,
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::UNPAID,
            'status'         => OrderStatus::PENDING,
            'total'          => 42.50,
        ]);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $response->assertStatus(200);

        $domainEvent = DomainEvent::query()
            ->where('event_type', EventType::ORDER_PAYMENT_STATUS_CHANGED)
            ->where('aggregate_id', $order->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($domainEvent, 'A domain_events row must be persisted for UNPAID→PAID.');
        $this->assertSame((int) $this->branch->id, (int) $domainEvent->branch_id);
        $this->assertSame('OrderPaymentStatusChanged', $domainEvent->broadcast_as);
        $this->assertNotNull($domainEvent->correlation_id);
        $this->assertTrue(Str::isUuid($domainEvent->correlation_id) || strlen((string) $domainEvent->correlation_id) > 0);

        $payload = $domainEvent->payload;
        $this->assertSame((int) $order->id, (int) $payload['order_id']);
        $this->assertSame((int) $this->branch->id, (int) $payload['branch_id']);
        $this->assertSame(PaymentStatus::UNPAID, (int) $payload['old_status']);
        $this->assertSame(PaymentStatus::PAID, (int) $payload['new_status']);
        $this->assertSame(42.50, (float) $payload['total']);
        $this->assertArrayHasKey('fiscal_sequence_no', $payload, 'fiscal_sequence_no must be present (may be null).');
    }

    /**
     * [SYNC cross-surface P2 2026-08-04] Une commande WEB en ACCEPT encaissée au comptoir
     * (UNPAID→PAID) auto-promeut ACCEPT→PREPARING. Avant le heal, CE chemin (OrderService,
     * 4e site d'intégration) ne diffusait QUE OrderPaymentStatusChanged (sans abonné client)
     * → KDS/OSS ne voyaient la carte cuisine devenir active qu'au prochain POLL. On exige
     * désormais la ligne outbox ORDER_STATUS_CHANGED (ACCEPT→PREPARING) = push temps-réel.
     */
    public function test_web_order_counter_paid_emits_order_status_changed_outbox_on_auto_prepare(): void
    {
        config(['pos.auto_prepare_on_paid' => true]);

        $order = Order::factory()->create([
            'user_id'            => $this->cashier->id,
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::TAKEAWAY,
            'source_surface'     => 'web',
            'payment_method'     => \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => null,
            'payment_status'     => PaymentStatus::UNPAID,
            'status'             => OrderStatus::ACCEPT,
            'total'              => 23.90,
            'fiscal_sequence_no' => null,
        ]);

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ])
            ->assertStatus(200);

        // Auto-prepare a bien flippé le statut.
        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status, 'Web order counter-paid must auto-promote to PREPARING.');

        // Le heal : la transition ACCEPT→PREPARING est diffusée (outbox → KDS/OSS/tracker).
        $statusEvent = DomainEvent::query()
            ->where('event_type', EventType::ORDER_STATUS_CHANGED)
            ->where('aggregate_id', $order->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($statusEvent, 'Auto-prepare ACCEPT→PREPARING MUST persist an ORDER_STATUS_CHANGED outbox row (SYNC-P2 heal).');
        $this->assertSame('OrderStatusChanged', $statusEvent->broadcast_as);
        $this->assertSame(OrderStatus::ACCEPT, (int) $statusEvent->payload['old_status']);
        $this->assertSame(OrderStatus::PREPARING, (int) $statusEvent->payload['new_status']);

        // Non-régression : l'event de paiement existe toujours.
        $this->assertNotNull(
            DomainEvent::query()
                ->where('event_type', EventType::ORDER_PAYMENT_STATUS_CHANGED)
                ->where('aggregate_id', $order->id)
                ->first(),
            'The payment-status event must still be emitted alongside the new status event.'
        );
    }

    public function test_event_envelope_passes_assert_envelope_valid(): void
    {
        $order = Order::factory()->create([
            'user_id'        => $this->cashier->id,
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::UNPAID,
            'status'         => OrderStatus::PENDING,
            'total'          => 18.00,
        ]);

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ])
            ->assertStatus(200);

        $domainEvent = DomainEvent::query()
            ->where('event_type', EventType::ORDER_PAYMENT_STATUS_CHANGED)
            ->where('aggregate_id', $order->id)
            ->latest('id')
            ->firstOrFail();

        $envelope = EventContract::buildEnvelope($domainEvent);

        // Must NOT throw.
        EventContract::assertEnvelopeValid($envelope, EventType::ORDER_PAYMENT_STATUS_CHANGED);

        $this->assertSame(EventContract::ENVELOPE_VERSION, $envelope['version']);
        $this->assertSame(EventType::ORDER_PAYMENT_STATUS_CHANGED, $envelope['type']);
    }
}
