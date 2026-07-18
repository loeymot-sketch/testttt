<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [P1-3 2026-07-18] Une commande WEB acceptée (SYNC-WEB-KDS-01 la bascule UNPAID→
 * PENDING_COUNTER pour la visibilité cuisine) doit rester ENCAISSABLE :
 *   (1) TAKEAWAY : visible dans la file /pos/counter-collect + encaissable au
 *       comptoir (le filtre ne listait que kiosk/pos/phone → 'web' invisible ;
 *       et assertCounterDeferredOrder rejetait 'web' → 422 à l'encaissement).
 *   (2) DELIVERY : l'encaissement doorstep COD (DELIVERED) doit sceller la vente
 *       (PAID + fiscal seq) malgré le flip PENDING_COUNTER (le sceau était gardé
 *       $wasUnpaidCash===UNPAID → cassé par le flip → vente livraison off-book).
 *
 * On PRÉSERVE la visibilité cuisine du heal 15/07 ; on AJOUTE la couverture 'web'
 * sans modifier la logique fiscale (allocation seq/cash-trail inchangées).
 */
class WebOrderCounterCollectableTest extends TestCase
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

        // On teste la COLLECTABILITÉ (visibilité + acceptation), pas la chaîne HMAC.
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog { return new \App\Models\AuditLog(); }
        });
        $seq = 7000;
        $this->app->instance(FiscalSequenceService::class, new class($seq) extends FiscalSequenceService {
            private int $c;
            public function __construct(int $s) { $this->c = $s; }
            public function next(int $branchId): int { return ++$this->c; }
        });

        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
    }

    private function operator(): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id]);
        $u->assignRole('POS Operator'); // possède déjà 'pos'
        $u->givePermissionTo('online-orders');
        return $u->fresh();
    }

    private function webOrder(int $type): Order
    {
        return Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => $type,
            'source'             => Source::WEB,
            'source_surface'     => 'web',
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'     => PaymentStatus::UNPAID,
            'pos_payment_method' => null,
            'status'             => OrderStatus::PENDING,
            'total'              => 18.50,
            'subtotal'           => 18.50,
        ]);
    }

    /** TAKEAWAY web : Accept → PENDING_COUNTER → visible + encaissable au comptoir. */
    public function test_web_takeaway_accepted_is_visible_and_collectable_at_counter(): void
    {
        $order = $this->webOrder(OrderType::TAKEAWAY);
        $op = $this->operator();

        // 1) Accept via le contrôleur online (SYNC-WEB-KDS-01 + marqueur counter-deferred web).
        $accept = $this->actingAs($op, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'acc-'.bin2hex(random_bytes(6))])
            ->postJson("/api/admin/online-order/change-status/{$order->id}", [
                'status' => OrderStatus::ACCEPT,
            ]);
        $accept->assertStatus(200);

        $order->refresh();
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $order->payment_status,
            'Le heal SYNC-WEB-KDS-01 doit basculer la commande web acceptée en PENDING_COUNTER (visibilité cuisine).');

        // 2) Visible dans la file counter-collect.
        $pending = $this->actingAs($op, 'sanctum')
            ->getJson('/api/admin/pos/counter-collect/pending');
        $pending->assertStatus(200);
        $ids = collect($pending->json('data'))->pluck('id')->all();
        $this->assertContains($order->id, $ids,
            'La commande web PENDING_COUNTER (takeaway) doit apparaître dans la file counter-collect.');

        // 3) Encaissable : confirmCounterPayment CASH → PAID + fiscal seq alloué.
        $confirm = $this->actingAs($op, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'col-'.bin2hex(random_bytes(6))])
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode'     => PosPaymentMethod::CASH,
                'received' => 20.00,
            ]);
        $confirm->assertStatus(200);

        $order->refresh();
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertNotNull($order->fiscal_sequence_no,
            "L'encaissement comptoir doit allouer un fiscal_sequence_no (NF525).");
    }

    /** DELIVERY web : le doorstep COD scelle malgré le flip PENDING_COUNTER. */
    public function test_web_delivery_doorstep_cod_seals_despite_pending_counter(): void
    {
        // Commande livraison web déjà acceptée (PENDING_COUNTER) + en cours de livraison.
        $order = Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::DELIVERY,
            'source'             => Source::WEB,
            'source_surface'     => 'web',
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'     => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => null,
            'status'             => OrderStatus::OUT_FOR_DELIVERY,
            'total'              => 30.00,
            'subtotal'           => 30.00,
        ]);

        $boy = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $order->delivery_boy_id = $boy->id;
        $order->save();

        $this->actingAs($boy, 'sanctum');

        app(OrderService::class)->deliveryBoyOrderChangeStatus(
            $order->fresh(),
            new Request(['status' => OrderStatus::DELIVERED])
        );

        $order->refresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $order->status);
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status,
            'Le doorstep COD doit flipper PENDING_COUNTER→PAID (sinon vente livraison off-book).');
        $this->assertNotNull($order->fiscal_sequence_no,
            'Le doorstep COD doit allouer un fiscal_sequence_no (NF525).');
    }
}
