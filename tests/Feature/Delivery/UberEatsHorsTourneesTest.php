<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Events\OrderCreated;
use App\Listeners\AssignDeliveryWaveOnOrderCreated;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [E2E 2026-10-23] Une commande Uber Eats (livrée par Uber Eats, sans adresse chez nous) n'entre jamais dans nos tournées.
 * Avant : « delivery.wave.assign_failed … Commande de livraison sans adresse » en ERROR à chaque commande, en production.
 *
 * @group delivery
 */
class UberEatsHorsTourneesTest extends TestCase
{
    use RefreshDatabase;

    private function uberEats(): Order
    {
        $branch = Branch::factory()->create();

        return Order::factory()->create([
            'branch_id' => $branch->id, 'order_type' => OrderType::DELIVERY, 'source_surface' => 'uber_eats',
            'status' => OrderStatus::ACCEPT, 'payment_status' => PaymentStatus::PAID,
        ]);
    }

    public function test_the_listener_ignores_an_uber_eats_order_silently(): void
    {
        $order = $this->uberEats();
        Log::spy();

        app(AssignDeliveryWaveOnOrderCreated::class)->handle(new OrderCreated($order));

        $this->assertNull(DeliveryStop::query()->where('order_id', $order->id)->first());
        Log::shouldNotHaveReceived('error');
    }

    public function test_the_catch_up_command_ignores_uber_eats_orders(): void
    {
        $order = $this->uberEats();
        Log::spy();

        $this->artisan('delivery:assign-pending')->assertSuccessful();

        $this->assertNull(DeliveryStop::query()->where('order_id', $order->id)->first());
        Log::shouldNotHaveReceived('error');
    }
}
