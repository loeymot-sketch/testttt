<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\PosPaymentMethod as Mode;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Pos\CounterCollectQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M4-106 · M6-102] « Livrée » depuis la caisse (panneau « Prêt à livrer », tiroir, suivi) passe par
 * `OrderService::changeStatus`, qui ne scelle rien. Sur une LIVRAISON non encaissée (paiement à la porte), ce clic la
 * sortait définitivement du Z : DELIVERED + PENDING_COUNTER, sans numéro fiscal, hors file « À encaisser » (la livraison
 * du site n'y figure pas) et inscellable ensuite (#7888, #7911, #7931, #7961 proposées en base locale).
 *
 * Règle : une livraison non encaissée se remet par l'écran livreur (`deliveryBoyOrderChangeStatus`, qui encaisse et
 * scelle). La caisse refuse « Livrée » — sur TOUTES les routes jumelles (pos-order, online-order).
 * Une livraison déjà payée en caisse reste remettable d'un clic. Une commande téléphone À EMPORTER remise avant
 * paiement reste dans « À encaisser » et s'encaisse ensuite normalement (M6-102 : pas de vente perdue).
 */
class DeliveredRequiresSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Permission::firstOrCreate(['name' => 'online-orders', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'pos', 'guard_name' => 'sanctum']);

        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}

            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });
        $this->app->instance(FiscalSequenceService::class, new class extends FiscalSequenceService {
            private int $c = 9300;

            public function __construct() {}

            public function next(int $branchId): int
            {
                return ++$this->c;
            }
        });

        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
    }

    private function caissier(): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id]);
        $u->assignRole('POS Operator');
        $u->givePermissionTo('online-orders');

        return $u->fresh();
    }

    private function livraisonDuSiteNonEncaissee(): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::DELIVERY,
            'source' => Source::WEB,
            'source_surface' => 'delivery',
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => null,
            'status' => OrderStatus::PREPARED,
            'fiscal_sequence_no' => null,
            'total' => 4.90,
            'subtotal' => 1.00,
        ]);
    }

    public function test_caisse_refuses_delivered_on_an_unpaid_delivery_pos_route(): void
    {
        $order = $this->livraisonDuSiteNonEncaissee();

        $res = $this->actingAs($this->caissier(), 'sanctum')
            ->postJson('/api/admin/pos-order/change-status/'.$order->id, ['status' => OrderStatus::DELIVERED]);

        $res->assertStatus(422);
        $this->assertStringContainsString('livreur', (string) $res->json('message'));
        $order->refresh();
        $this->assertSame(OrderStatus::PREPARED, (int) $order->status, 'La livraison non encaissée ne doit pas être marquée livrée.');
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $order->payment_status);
        $this->assertNull($order->fiscal_sequence_no);
    }

    public function test_caisse_refuses_delivered_on_an_unpaid_delivery_online_route_twin(): void
    {
        $order = $this->livraisonDuSiteNonEncaissee();

        $this->actingAs($this->caissier(), 'sanctum')
            ->postJson('/api/admin/online-order/change-status/'.$order->id, ['status' => OrderStatus::DELIVERED])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::PREPARED, (int) $order->fresh()->status);
    }

    public function test_caisse_refuses_delivered_on_an_unpaid_phone_delivery(): void
    {
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::DELIVERY,
            'source' => Source::POS,
            'source_surface' => 'phone',
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::OUT_FOR_DELIVERY,
            'fiscal_sequence_no' => null,
            'total' => 4.90,
        ]);

        $this->actingAs($this->caissier(), 'sanctum')
            ->postJson('/api/admin/pos-order/change-status/'.$order->id, ['status' => OrderStatus::DELIVERED])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $order->fresh()->status);
    }

    public function test_a_delivery_already_paid_at_the_caisse_can_still_be_marked_delivered(): void
    {
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::DELIVERY,
            'source' => Source::POS,
            'source_surface' => 'pos',
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'status' => OrderStatus::PREPARED,
            'fiscal_sequence_no' => 4242,
            'total' => 4.90,
        ]);

        $this->actingAs($this->caissier(), 'sanctum')
            ->postJson('/api/admin/pos-order/change-status/'.$order->id, ['status' => OrderStatus::DELIVERED])
            ->assertSuccessful();

        $this->assertSame(OrderStatus::DELIVERED, (int) $order->fresh()->status);
    }

    /**
     * M6-102 : le chemin réel des 65 commandes « remises » sans encaissement (banc M6 → pos-order/change-status).
     * Une commande téléphone À EMPORTER remise avant paiement n'est pas perdue : elle reste « À encaisser » et
     * l'encaissement la scelle ensuite (numéro fiscal). Ce test caractérise ce comportement (vert avant et après).
     */
    public function test_phone_takeaway_handed_over_before_payment_stays_collectable_and_is_sealed_at_collection(): void
    {
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::POS,
            'source_surface' => 'phone',
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::PREPARED,
            'fiscal_sequence_no' => null,
            'total' => 1.90,
            'subtotal' => 1.90,
        ]);
        $caissier = $this->caissier();

        $this->actingAs($caissier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-status/'.$order->id, ['status' => OrderStatus::DELIVERED])
            ->assertSuccessful();

        $this->assertTrue(
            CounterCollectQueue::query($this->branch->id)->whereKey($order->id)->exists(),
            'Remise avant paiement : la commande doit rester « À encaisser ».'
        );

        $this->actingAs($caissier, 'sanctum')
            ->postJson('/api/admin/pos/counter-collect/'.$order->id.'/confirm', ['mode' => Mode::CASH, 'received' => 2.00])
            ->assertSuccessful();

        $order->refresh();
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertNotNull($order->fiscal_sequence_no, "L'encaissement après remise doit sceller la vente (NF525).");
    }
}
