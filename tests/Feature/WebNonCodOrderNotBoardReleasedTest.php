<?php

namespace Tests\Feature;

use App\Domain\Kds\KitchenReleaseRule;
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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [S1 2026-07-18 · jumeau non-COD de P1-3] Le flip UNPAID→PENDING_COUNTER à l'Accept
 * online (SYNC-WEB-KDS-01) board-release la commande en cuisine. Il DOIT donc être
 * gaté sur la COLLECTABILITÉ (= COD), sinon une web TAKEAWAY non-COD (carte/null) est
 * board-released MAIS jamais encaissable (pas de marqueur COUNTER_DEFERRED, et
 * PaymentService::assertCounterDeferredOrder exige CASH_ON_DELIVERY) → orpheline
 * « préparée jamais encaissable ».
 *
 *   (A) web TAKEAWAY COD  → flip + COUNTER_DEFERRED + board-released  (NON-RÉGRESSION P1-3)
 *   (B) web TAKEAWAY CARD → PAS de flip, reste UNPAID, PAS board-released (S1 corrigé)
 *
 * Le flux vivant V1 (100 % web = COD) est intégralement préservé par (A).
 */
class WebNonCodOrderNotBoardReleasedTest extends TestCase
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

        // On teste la collectabilité/board-release, pas la chaîne HMAC.
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog { return new \App\Models\AuditLog(); }
        });
        $seq = 7100;
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

    private function webOrder(int $type, int $paymentMethod): Order
    {
        return Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => $type,
            'source'             => Source::WEB,
            'source_surface'     => 'web',
            'payment_method'     => $paymentMethod,
            'payment_status'     => PaymentStatus::UNPAID,
            'pos_payment_method' => null,
            'status'             => OrderStatus::PENDING,
            'total'              => 18.50,
            'subtotal'           => 18.50,
        ]);
    }

    private function accept(User $op, Order $order)
    {
        return $this->actingAs($op, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'acc-'.bin2hex(random_bytes(6))])
            ->postJson("/api/admin/online-order/change-status/{$order->id}", [
                'status' => OrderStatus::ACCEPT,
            ]);
    }

    /** (A) NON-RÉGRESSION P1-3 : web TAKEAWAY COD reste board-released + collectable. */
    public function test_web_takeaway_cod_still_flips_marks_and_board_releases(): void
    {
        $op = $this->operator();
        $order = $this->webOrder(OrderType::TAKEAWAY, PaymentGateway::CASH_ON_DELIVERY);

        $this->accept($op, $order)->assertStatus(200);

        $order->refresh();
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $order->payment_status,
            'web takeaway COD : le flip PENDING_COUNTER doit être préservé (visibilité cuisine).');
        $this->assertSame(PosPaymentMethod::COUNTER_DEFERRED, (int) $order->pos_payment_method,
            'web takeaway COD : marqueur COUNTER_DEFERRED posé (encaissable au comptoir).');
        $this->assertTrue(KitchenReleaseRule::orderIsReleasedForBoard($order),
            'web takeaway COD : DOIT être board-released en cuisine.');
    }

    /** (B) S1 : web TAKEAWAY non-COD (carte) NE doit PAS être board-released orpheline. */
    public function test_web_takeaway_non_cod_is_not_board_released_orphan(): void
    {
        $op = $this->operator();
        $order = $this->webOrder(OrderType::TAKEAWAY, PaymentGateway::CARD);

        $this->accept($op, $order)->assertStatus(200);

        $order->refresh();
        // Accept réussit (statut change) mais SANS flip paiement.
        $this->assertSame(OrderStatus::ACCEPT, (int) $order->status,
            'l\'accept doit réussir (le filet online reste fonctionnel).');
        $this->assertSame(PaymentStatus::UNPAID, (int) $order->payment_status,
            'web takeaway non-COD : PAS de flip PENDING_COUNTER (attend un paiement carte en ligne).');
        $this->assertNull($order->pos_payment_method,
            'web takeaway non-COD : aucun marqueur COUNTER_DEFERRED.');
        $this->assertFalse(KitchenReleaseRule::orderIsReleasedForBoard($order),
            'web takeaway non-COD : NE doit PAS être board-released (sinon orpheline préparée jamais encaissable).');

        // Et absente de la file counter-collect (non encaissable au comptoir).
        $pending = $this->actingAs($op, 'sanctum')->getJson('/api/admin/pos/counter-collect/pending');
        $pending->assertStatus(200);
        $ids = collect($pending->json('data'))->pluck('id')->all();
        $this->assertNotContains($order->id, $ids,
            'web takeaway non-COD : ne doit PAS apparaître dans la file counter-collect.');
    }

    /** (C) web DELIVERY COD : flip préservé (visibilité cuisine), encaissé au doorstep — pas de marqueur comptoir. */
    public function test_web_delivery_cod_flips_for_kitchen_without_counter_marker(): void
    {
        $op = $this->operator();
        $order = $this->webOrder(OrderType::DELIVERY, PaymentGateway::CASH_ON_DELIVERY);

        $this->accept($op, $order)->assertStatus(200);

        $order->refresh();
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $order->payment_status,
            'web delivery COD : flip préservé (SYNC-WEB-KDS-01 → visibilité cuisine).');
        $this->assertNull($order->pos_payment_method,
            'web delivery COD : PAS de marqueur comptoir (encaissée au doorstep par le livreur).');
        $this->assertTrue(KitchenReleaseRule::orderIsReleasedForBoard($order),
            'web delivery COD : board-released pour la cuisine.');
    }
}
