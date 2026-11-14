<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use App\Services\Delivery\DeliveryWaveService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tournées de 30 minutes : les commandes qui partent dans la même fenêtre :00/:30 sont livrées
 * en UN trajet ; deux commandes séparées de plus d'une fenêtre = deux départs.
 *
 * @group delivery
 */
class DeliveryWaveServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private DeliveryWaveService $waves;

    protected function setUp(): void
    {
        parent::setUp();
        // Service ouvert 18:00 → 00:30 (config/kds.php) ; "maintenant" = 19:02.
        Carbon::setTestNow(Carbon::parse('2026-10-20 19:02:00'));
        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        $this->waves = app(DeliveryWaveService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function deliveryOrder(float $lat = 50.43, float $lng = 2.96, array $over = []): Order
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $order = Order::factory()->create($over + [
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'order_type' => OrderType::DELIVERY,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => PaymentGateway::CARD,
            'total' => 25.00,
            'delivery_charge' => 3.90,
            'delivery_distance_km' => 1.2,
        ]);
        OrderAddress::query()->create([
            'order_id' => $order->id, 'user_id' => $user->id, 'label' => 'x', 'address' => '1 rue',
            'latitude' => (string) $lat, 'longitude' => (string) $lng,
        ]);

        return $order->fresh();
    }

    public function test_earliest_wave_respects_lead_time_and_the_half_hour_grid(): void
    {
        // 19:02 + 20 min = 19:22 → prochaine borne de la grille = 19:30.
        $this->assertSame('2026-10-20 19:30:00', $this->waves->earliestWave()->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-10-20 19:10:00')); // 19:30 pile → 19:30 accepté
        $this->assertSame('19:30', $this->waves->earliestWave()->format('H:i'));

        Carbon::setTestNow(Carbon::parse('2026-10-20 19:11:00')); // 19:31 → 20:00
        $this->assertSame('20:00', $this->waves->earliestWave()->format('H:i'));
    }

    public function test_two_asap_orders_in_the_same_window_leave_together(): void
    {
        $a = $this->waves->assign($this->deliveryOrder());
        $b = $this->waves->assign($this->deliveryOrder(50.44, 2.97));

        $this->assertTrue($a->wave_at->equalTo($b->wave_at), 'Même tournée.');
        $this->assertSame('19:30', $a->wave_at->format('H:i'));
        $this->assertSame([1, 2], DeliveryStop::query()->orderBy('sequence')->pluck('sequence')->all());
    }

    public function test_second_order_25_minutes_later_pulls_the_wave_to_leave_together(): void
    {
        // Exemple propriétaire : A maintenant, B 25 minutes plus tard → un seul départ pour les deux.
        $a = $this->waves->assign($this->deliveryOrder());
        $this->assertSame('19:30', $a->wave_at->format('H:i'));

        Carbon::setTestNow(Carbon::parse('2026-10-20 19:27:00')); // B ne peut plus partir à 19:30
        $b = $this->waves->assign($this->deliveryOrder(50.44, 2.97));

        $a->refresh();
        $this->assertTrue($a->wave_at->equalTo($b->wave_at), 'A et B partent ensemble.');
        $this->assertSame('20:00', $b->wave_at->format('H:i'), 'Le départ est décalé d\'un cran de grille.');
        $this->assertSame($a->wave_at->toDateTimeString(), Order::query()->find($a->order_id)->delivery_slot_at->toDateTimeString(), 'La commande A reflète le nouveau départ.');
    }

    public function test_orders_more_than_a_window_apart_get_separate_waves(): void
    {
        $a = $this->waves->assign($this->deliveryOrder());

        Carbon::setTestNow(Carbon::parse('2026-10-20 19:45:00')); // > 30 min après la 1re commande (19:02)
        $b = $this->waves->assign($this->deliveryOrder(50.44, 2.97));

        $this->assertFalse($a->fresh()->wave_at->equalTo($b->wave_at), 'Deux départs distincts.');
        $this->assertSame('19:30', $a->fresh()->wave_at->format('H:i'));
        $this->assertSame('20:30', $b->wave_at->format('H:i'));
    }

    /**
     * [LIVRAISON 2026-10-20 · trouvé par le test réel] Un livreur peut partir EN AVANCE (commandes prêtes avant l'heure de la
     * tournée). Une commande passée ensuite, dont le premier départ possible tombe sur CETTE même heure, rejoignait la tournée
     * déjà partie : injoignable (livreur parti, caisse sans bouton pour désigner quelqu'un).
     */
    public function test_an_order_never_joins_a_wave_whose_courier_already_left_early(): void
    {
        // 19:02 → première tournée 19:30. Le livreur part tout de suite (en avance de 28 minutes).
        $a = $this->waves->assign($this->deliveryOrder());
        $a->update(['left_at' => now()]);

        Carbon::setTestNow(Carbon::parse('2026-10-20 19:10:00')); // 19:10 + 20 min = 19:30 pile : le même créneau
        $b = $this->waves->assign($this->deliveryOrder(50.44, 2.97));

        $this->assertSame('19:30', $a->fresh()->wave_at->format('H:i'));
        $this->assertSame('20:00', $b->wave_at->format('H:i'), 'La tournée de 19:30 est partie : la commande va à la suivante.');
    }

    public function test_a_customer_cannot_pick_a_slot_whose_courier_already_left(): void
    {
        $a = $this->waves->assign($this->deliveryOrder());
        $a->update(['left_at' => now()]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 19:10:00'));

        try {
            $this->waves->assign($this->deliveryOrder(50.44, 2.97), Carbon::parse('2026-10-20 19:30:00'));
            $this->fail('Un créneau déjà parti aurait dû être refusé.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('delivery_slot_at', $e->errors());
        }
    }

    public function test_available_waves_do_not_offer_a_wave_that_has_left(): void
    {
        $a = $this->waves->assign($this->deliveryOrder());
        $a->update(['left_at' => now()]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 19:10:00'));

        $list = collect($this->waves->availableWaves($this->branch->id, null, 3))->keyBy('time');

        $this->assertTrue($list['19:30']['full'], 'Une tournée partie est présentée comme indisponible.');
        $this->assertFalse($list['20:00']['full']);
    }

    public function test_a_wave_that_has_left_is_never_modified(): void
    {
        $a = $this->waves->assign($this->deliveryOrder());
        $a->update(['left_at' => now()]);

        Carbon::setTestNow(Carbon::parse('2026-10-20 19:27:00'));
        $b = $this->waves->assign($this->deliveryOrder());

        $this->assertSame('19:30', $a->fresh()->wave_at->format('H:i'), 'Le livreur est déjà parti.');
        $this->assertSame('20:00', $b->wave_at->format('H:i'));
    }

    public function test_customer_chosen_slot_is_kept_and_never_pulled(): void
    {
        $chosen = Carbon::parse('2026-10-20 20:30:00');
        $a = $this->waves->assign($this->deliveryOrder(), $chosen);
        $this->assertTrue($a->slot_fixed);

        Carbon::setTestNow(Carbon::parse('2026-10-20 20:05:00'));
        $b = $this->waves->assign($this->deliveryOrder()); // ASAP → 20:30 (20:05+20=20:25 → 20:30) : rejoint

        $this->assertSame('20:30', $a->fresh()->wave_at->format('H:i'));
        $this->assertSame('20:30', $b->wave_at->format('H:i'));
    }

    public function test_chosen_slot_must_be_on_grid_in_the_future_and_in_service_hours(): void
    {
        foreach (['2026-10-20 19:45:00', '2026-10-20 19:00:00', '2026-10-20 14:00:00', '2026-10-24 19:30:00'] as $bad) {
            try {
                $this->waves->assign($this->deliveryOrder(), Carbon::parse($bad));
                $this->fail("Créneau {$bad} aurait dû être refusé.");
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('delivery_slot_at', $e->errors());
            }
        }
    }

    public function test_a_full_wave_overflows_to_the_next_one(): void
    {
        config(['delivery.max_stops_per_wave' => 2]);
        $this->waves->assign($this->deliveryOrder());
        $this->waves->assign($this->deliveryOrder());
        $c = $this->waves->assign($this->deliveryOrder());

        $this->assertSame('20:00', $c->wave_at->format('H:i'));
    }

    public function test_stops_are_sequenced_nearest_first_from_the_restaurant(): void
    {
        $far  = $this->waves->assign($this->deliveryOrder(50.47, 3.02));
        $near = $this->waves->assign($this->deliveryOrder(50.425, 2.956));

        $this->assertSame(1, $near->fresh()->sequence);
        $this->assertSame(2, $far->fresh()->sequence);
        $this->assertGreaterThan($near->fresh()->eta_minutes, $far->fresh()->eta_minutes);
    }

    public function test_collect_method_reflects_how_the_customer_pays(): void
    {
        $paid = $this->waves->assign($this->deliveryOrder());
        $this->assertSame(DeliveryStop::COLLECT_PAID_ONLINE, $paid->collect_method);
        $this->assertSame(0, $paid->amount_due_cents);

        $cash = $this->waves->assign($this->deliveryOrder(50.43, 2.96, [
            'payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'total' => 27.50,
        ]));
        $this->assertSame(DeliveryStop::COLLECT_CASH, $cash->collect_method);
        $this->assertSame(2750, $cash->amount_due_cents);

        $card = $this->waves->assign($this->deliveryOrder(50.43, 2.96, [
            'payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CARD, 'total' => 12.40,
        ]));
        $this->assertSame(DeliveryStop::COLLECT_CARD, $card->collect_method);
        $this->assertSame(1240, $card->amount_due_cents);
    }

    public function test_a_delivery_order_without_an_address_never_gets_a_wave(): void
    {
        $order = Order::factory()->create(['branch_id' => $this->branch->id, 'order_type' => OrderType::DELIVERY]);

        $this->expectException(\DomainException::class);
        try {
            $this->waves->assign($order);
        } finally {
            $this->assertSame(0, DeliveryStop::query()->count());
        }
    }

    public function test_a_missing_courier_role_never_blocks_the_wave(): void
    {
        // Installation neuve : le rôle « Delivery Boy » n'existe pas encore. Le rôle de test n'est pas créé ici.
        $this->assertNull($this->waves->defaultCourierId($this->branch->id));
        $this->assertNotNull($this->waves->assign($this->deliveryOrder()));
    }

    public function test_assign_is_idempotent_for_the_same_order(): void
    {
        $order = $this->deliveryOrder();
        $first = $this->waves->assign($order);
        $second = $this->waves->assign($order);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DeliveryStop::query()->count());
    }

    public function test_available_waves_lists_open_slots_with_arrival_estimate(): void
    {
        $list = $this->waves->availableWaves($this->branch->id, null, 4);

        $this->assertCount(4, $list);
        $this->assertSame('19:30', $list[0]['time']);
        $this->assertSame('20:00', $list[1]['time']);
        $this->assertFalse($list[0]['full']);
    }
}
