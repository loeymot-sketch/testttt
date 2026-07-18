<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [S5 2026-07-18 · accept web atomique] Le flip UNPAID→PENDING_COUNTER (+COUNTER_DEFERRED)
 * et l'appel OrderService::changeStatus doivent être ATOMIQUES (miroir de l'atomicité borne
 * FrontendOrderService). Si changeStatus jette APRÈS le flip, l'état ne doit PAS rester
 * incohérent (PENDING_COUNTER + statut toujours PENDING). La DB::transaction englobante doit
 * tout rollback : la commande reste UNPAID / pos_payment_method NULL / PENDING.
 */
class WebAcceptIsAtomicTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Permission::firstOrCreate(['name' => 'online-orders', 'guard_name' => 'sanctum']);

        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog { return new \App\Models\AuditLog(); }
        });
        $seq = 7200;
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

    public function test_flip_is_rolled_back_when_change_status_throws(): void
    {
        // changeStatus jette systématiquement → prouve que le flip ne persiste pas seul.
        $this->app->bind(OrderService::class, function () {
            return new class extends OrderService {
                public function __construct() {}
                public function changeStatus(Order $order, OrderStatusRequest $request, bool $auth = false): Order|array
                {
                    throw new \RuntimeException('boom-after-flip');
                }
            };
        });

        $order = Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::TAKEAWAY,
            'source'             => Source::WEB,
            'source_surface'     => 'web',
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY, // COD → le flip S'ACTIVE
            'payment_status'     => PaymentStatus::UNPAID,
            'pos_payment_method' => null,
            'status'             => OrderStatus::PENDING,
            'total'              => 22.00,
            'subtotal'           => 22.00,
        ]);

        $op = $this->operator();
        $resp = $this->actingAs($op, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'atm-'.bin2hex(random_bytes(6))])
            ->postJson("/api/admin/online-order/change-status/{$order->id}", [
                'status' => OrderStatus::ACCEPT,
            ]);

        // L'échec de changeStatus est remonté proprement (422 via catch Exception).
        $resp->assertStatus(422);

        // ÉTAT DB : le flip doit être ROLLBACK (pas de moitié-appliqué).
        $fresh = Order::find($order->id);
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status,
            'S5 : le flip PENDING_COUNTER doit être rollback si changeStatus jette.');
        $this->assertNull($fresh->pos_payment_method,
            'S5 : le marqueur COUNTER_DEFERRED doit être rollback si changeStatus jette.');
        $this->assertSame(OrderStatus::PENDING, (int) $fresh->status,
            'S5 : le statut ne doit pas avoir changé (changeStatus a échoué).');
    }
}
