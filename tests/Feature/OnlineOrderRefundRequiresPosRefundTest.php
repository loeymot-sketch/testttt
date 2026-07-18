<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [P2-e 2026-07-18] Parité de garde refund sur le chemin ONLINE.
 *
 * OnlineOrderController::changePaymentStatus autorisait l'arête →REFUNDED SANS le
 * gate `pos-refund`, alors que la route soeur POS l'exige explicitement
 * (PosOrderController::changePaymentStatus:372-378). Un POS Operator possède
 * `online-orders` (+`pos`) mais PAS `pos-refund` → il pouvait marquer une commande
 * en ligne REMBOURSÉE (void off-book / vecteur de remboursements de masse).
 *
 * On miroir EXACTEMENT le gate de la soeur : fail-fast AVANT de déléguer au service
 * (le 403 n'est pas masqué en 422).
 */
class OnlineOrderRefundRequiresPosRefundTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Permission::firstOrCreate(['name' => 'online-orders', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'pos-refund', 'guard_name' => 'sanctum']);

        // On teste l'AUTZ, pas la chaîne HMAC → stub des services fiscaux.
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog { return new \App\Models\AuditLog(); }
        });
        $seq = 8000;
        $this->app->instance(FiscalSequenceService::class, new class($seq) extends FiscalSequenceService {
            private int $c;
            public function __construct(int $s) { $this->c = $s; }
            public function next(int $branchId): int { return ++$this->c; }
        });

        $this->branch = Branch::factory()->create();
    }

    private function operator(bool $withPosRefund): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id]);
        $u->assignRole('POS Operator');
        $u->givePermissionTo('online-orders');
        if ($withPosRefund) {
            $u->givePermissionTo('pos-refund');
        }
        return $u->fresh();
    }

    /** REFUNDED n'est légal que depuis PENDING_COUNTER (PaymentStateMachine). */
    private function pendingCounterOrder(): Order
    {
        return Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::TAKEAWAY,
            'source'             => Source::WEB,
            'source_surface'     => 'web',
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'     => PaymentStatus::PENDING_COUNTER,
            'status'             => OrderStatus::ACCEPT,
            'total'              => 20.00,
            'subtotal'           => 20.00,
        ]);
    }

    public function test_operator_without_pos_refund_cannot_mark_refunded(): void
    {
        $order = $this->pendingCounterOrder();
        $op = $this->operator(false);

        $resp = $this->actingAs($op, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'refund-deny-'.bin2hex(random_bytes(6))])
            ->postJson("/api/admin/online-order/change-payment-status/{$order->id}", [
                'payment_status' => PaymentStatus::REFUNDED,
            ]);

        $resp->assertStatus(403);
        $this->assertSame(
            PaymentStatus::PENDING_COUNTER,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status,
            'Un refund refusé ne doit PAS muter la commande.'
        );
    }

    public function test_user_with_pos_refund_can_mark_refunded(): void
    {
        $order = $this->pendingCounterOrder();
        $op = $this->operator(true);

        $resp = $this->actingAs($op, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'refund-allow-'.bin2hex(random_bytes(6))])
            ->postJson("/api/admin/online-order/change-payment-status/{$order->id}", [
                'payment_status' => PaymentStatus::REFUNDED,
            ]);

        $resp->assertStatus(200);
        $this->assertSame(
            PaymentStatus::REFUNDED,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status
        );
    }

    /** Le gate est SPÉCIFIQUE au refund : une transition non-refund n'est pas bloquée. */
    public function test_non_refund_transition_is_not_gated(): void
    {
        $order = Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::DELIVERY,
            'source'             => Source::WEB,
            'source_surface'     => 'web',
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'     => PaymentStatus::UNPAID,
            'status'             => OrderStatus::ACCEPT,
            'total'              => 20.00,
            'subtotal'           => 20.00,
        ]);
        $op = $this->operator(false); // PAS de pos-refund

        $resp = $this->actingAs($op, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'paid-'.bin2hex(random_bytes(6))])
            ->postJson("/api/admin/online-order/change-payment-status/{$order->id}", [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $this->assertNotSame(403, $resp->getStatusCode(),
            'Une transition non-refund (UNPAID→PAID) ne doit PAS être bloquée par le gate pos-refund.');
        $this->assertSame(
            PaymentStatus::PAID,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status
        );
    }
}
