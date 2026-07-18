<?php

namespace Tests\Feature\Sync;

use App\Enums\EventType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [C3 2026-07-18 · notif client « prête »] Deux garanties :
 *  (1) BROADCAST — un changement de statut d'une commande WEB fan-out AUSSI sur le canal
 *      privé du client `private-customer.{user_id}` (en plus du canal staff
 *      `private-branch.{branch_id}`), via l'outbox EXISTANT (retry/dedup/contrat réutilisés).
 *      Une commande BORNE (kiosk : user_id = compte machine, pas un client) NE fan-out PAS
 *      vers un canal client.
 *  (2) POLLING (fallback robuste) — /api/frontend/order/show expose clairement le statut
 *      PREPARED (« Prête ») pour que le compte client bascule « en cours » → « prête »
 *      MÊME sans WebSocket.
 */
class WebOrderStatusBroadcastsToCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
    }

    private function customer(): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id]);
        $u->assignRole('Customer');
        return $u->fresh();
    }

    public function test_web_order_status_change_fans_out_to_customer_channel(): void
    {
        Queue::fake();
        $customer = $this->customer();

        $order = Order::factory()->create([
            'user_id'        => $customer->id,
            'branch_id'      => $this->branch->id,
            'source'         => Source::WEB,
            'source_surface' => 'web',
            'order_type'     => OrderType::TAKEAWAY,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'queue_number'   => 'A0100',
            'status'         => OrderStatus::PREPARED,
            'total'          => 15.00,
            'subtotal'       => 15.00,
        ]);

        OrderStatusChanged::dispatch($order, OrderStatus::PREPARING, OrderStatus::PREPARED);

        $event = DomainEvent::query()
            ->where('event_type', EventType::ORDER_STATUS_CHANGED)
            ->latest('id')
            ->firstOrFail();

        $channels = json_decode($event->channel, true);
        $this->assertIsArray($channels);
        $this->assertContains('private-branch.'.$this->branch->id, $channels,
            'C3 : le canal staff private-branch.{id} DOIT rester présent (sync caisse/KDS/OSS inchangée).');
        $this->assertContains('private-customer.'.$customer->id, $channels,
            'C3 : une commande WEB DOIT AUSSI diffuser sur private-customer.{user_id} (notif client).');
    }

    public function test_kiosk_order_status_change_does_not_fan_out_to_customer_channel(): void
    {
        Queue::fake();
        // Borne : user_id = compte machine/propriétaire, PAS un client → aucun canal client.
        $machineOwner = User::factory()->create(['branch_id' => $this->branch->id]);

        $order = Order::factory()->create([
            'user_id'        => $machineOwner->id,
            'branch_id'      => $this->branch->id,
            'source'         => Source::POS,
            'source_surface' => 'kiosk',
            'order_type'     => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'queue_number'   => 'A0101',
            'status'         => OrderStatus::PREPARED,
            'total'          => 15.00,
            'subtotal'       => 15.00,
        ]);

        OrderStatusChanged::dispatch($order, OrderStatus::PREPARING, OrderStatus::PREPARED);

        $event = DomainEvent::query()
            ->where('event_type', EventType::ORDER_STATUS_CHANGED)
            ->latest('id')
            ->firstOrFail();

        $channels = json_decode($event->channel, true);
        $this->assertContains('private-branch.'.$this->branch->id, $channels);
        $this->assertNotContains('private-customer.'.$machineOwner->id, $channels,
            'C3 : une commande BORNE ne doit PAS diffuser sur un canal client (user = machine, pas client).');
    }

    public function test_order_show_exposes_prepared_status_to_customer_polling(): void
    {
        $customer = $this->customer();

        $order = Order::factory()->create([
            'user_id'        => $customer->id,
            'branch_id'      => $this->branch->id,
            'source'         => Source::WEB,
            'source_surface' => 'web',
            'order_type'     => OrderType::TAKEAWAY,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'queue_number'   => 'A0102',
            'status'         => OrderStatus::PREPARED,
            'total'          => 15.00,
            'subtotal'       => 15.00,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/frontend/order/show/{$order->id}")
            ->assertOk()
            // Le statut « prête » (PREPARED=8) est exposé de façon machine-lisible …
            ->assertJsonPath('data.status', OrderStatus::PREPARED)
            // … et humainement (« Prête ») → le compte bascule « en cours » → « prête ».
            ->assertJsonPath('data.status_name', trans('orderStatus.'.OrderStatus::PREPARED));
    }
}
