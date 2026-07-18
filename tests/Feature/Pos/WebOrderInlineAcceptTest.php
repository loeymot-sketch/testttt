<?php

namespace Tests\Feature\Pos;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [C1 2026-07-18 · accept web INLINE en caisse] Preuve du chemin que le bouton
 * « Accepter » du panneau « Commandes web » de la caisse (PosComponent.vue,
 * acceptWebOrder) appelle SANS quitter le POS : POST /api/admin/online-order/change-status
 * avec status=ACCEPT. Une commande WEB à emporter COD PENDING doit alors devenir :
 *   status = ACCEPT ; payment_status = PENDING_COUNTER ; pos_payment_method = COUNTER_DEFERRED
 * → elle rejoint la file d'encaissement comptoir (counter-collect) et devient encaissable
 * INLINE via confirmCounterPayment, réalisant le cycle web unifié en caisse (C1 + C2).
 *
 * Complète WebAcceptIsAtomicTest (qui ne prouve QUE le rollback sur échec) par la preuve
 * du chemin heureux. Aucune logique fiscale nouvelle : réutilise OnlineOrderController::changeStatus.
 */
class WebOrderInlineAcceptTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Permission::firstOrCreate(['name' => 'online-orders', 'guard_name' => 'sanctum']);

        // NF525 : neutralise l'écriture de chaîne + l'allocation fiscale (non déclenchées
        // par un simple ACCEPT, mais isolées par prudence — miroir WebAcceptIsAtomicTest).
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog { return new \App\Models\AuditLog(); }
        });
        $seq = 8100;
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
        $u->assignRole('POS Operator');
        $u->givePermissionTo('online-orders');
        return $u->fresh();
    }

    private function webOrder(): Order
    {
        return Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::TAKEAWAY,
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

    public function test_cashier_accepts_web_pending_order_inline_and_it_becomes_counter_collectable(): void
    {
        Queue::fake();

        $order = $this->webOrder();
        $op = $this->operator();

        $resp = $this->actingAs($op, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'web-accept-'.bin2hex(random_bytes(6))])
            ->postJson("/api/admin/online-order/change-status/{$order->id}", [
                'status' => OrderStatus::ACCEPT,
            ]);

        $resp->assertOk();

        $fresh = Order::find($order->id);
        $this->assertSame(OrderStatus::ACCEPT, (int) $fresh->status,
            'C1 : la commande web PENDING doit passer ACCEPT après accept inline.');
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $fresh->payment_status,
            'C1 : COD accepté → PENDING_COUNTER (visibilité cuisine + file encaissement).');
        $this->assertSame(PosPaymentMethod::COUNTER_DEFERRED, (int) $fresh->pos_payment_method,
            'C1 : marqueur COUNTER_DEFERRED → encaissable inline via confirmCounterPayment.');
    }

    public function test_accepted_web_order_surfaces_in_the_counter_collect_pending_queue(): void
    {
        Queue::fake();

        $order = $this->webOrder();
        $op = $this->operator();

        $this->actingAs($op, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'web-accept-'.bin2hex(random_bytes(6))])
            ->postJson("/api/admin/online-order/change-status/{$order->id}", [
                'status' => OrderStatus::ACCEPT,
            ])->assertOk();

        // C2 : le cycle web est suivi SANS quitter la caisse — la commande acceptée
        // remonte dans la file d'encaissement comptoir unifiée.
        $ids = array_map(
            'intval',
            $this->actingAs($op, 'sanctum')
                ->getJson('/api/admin/pos/counter-collect/pending')
                ->assertOk()
                ->json('data.*.id')
        );

        $this->assertContains($order->id, $ids,
            'C2 : la commande web acceptée doit apparaître dans /counter-collect/pending (encaissable inline).');
    }
}
