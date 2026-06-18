<?php

namespace Tests\Feature\Fiscal;

use App\Enums\EventType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FiscalCashAtCounterLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        config([
            'fiscal.audit_secret' => 'unit-test-audit-padding-48-chars-required-by-prod-guard',
            'fiscal.z_report_secret' => 'unit-test-z-padding-48-chars-required-by-prod-guard',
        ]);

        $this->branch = Branch::factory()->create();
        $this->operator = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->operator->assignRole('POS Operator');
    }

    public function test_cash_at_counter_sequence_is_allocated_only_on_confirm_and_not_on_reprint(): void
    {
        Queue::fake();

        $first = $this->pendingCounterOrder(['queue_number' => 'A9101']);
        $second = $this->pendingCounterOrder(['queue_number' => 'A9102']);

        $this->assertNull($first->fiscal_sequence_no);
        $this->assertNull($second->fiscal_sequence_no);

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/counter-collect/{$first->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 20,
                'note' => 'NF525 cash-at-counter confirm',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID)
            ->assertJsonPath('data.fiscal_sequence_no', 1);

        $first->refresh();
        $this->assertSame(1, (int) $first->fiscal_sequence_no);

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/orders/{$first->id}/print-receipt")
            ->assertOk()
            ->assertJsonPath('receipt_print_count', 1)
            ->assertJsonPath('is_duplicata', false);

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            // Distinct key: the 2nd reprint must REACH the controller for the duplicata outcome (not an HTTP replay).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/orders/{$first->id}/print-receipt")
            ->assertOk()
            ->assertJsonPath('receipt_print_count', 2)
            ->assertJsonPath('is_duplicata', true);

        $this->assertSame(
            1,
            (int) $first->fresh()->fiscal_sequence_no,
            'Receipt reprint must not allocate or mutate the fiscal order sequence.'
        );

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/counter-collect/{$second->id}/confirm", [
                'mode' => PosPaymentMethod::CARD,
            ])
            ->assertOk()
            ->assertJsonPath('data.fiscal_sequence_no', 2);

        $this->assertNull(app(AuditLogService::class)->verifyChain($this->branch->id));
    }

    public function test_cash_at_counter_confirm_is_idempotent_without_second_sequence_or_duplicate_payment(): void
    {
        Queue::fake();

        $order = $this->pendingCounterOrder(['queue_number' => 'A9103']);

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('data.fiscal_sequence_no', 1);

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            // Distinct key: this asserts CONTROLLER-level idempotency (no 2nd sequence / no duplicate payment),
            // so the 2nd confirm must REACH the controller — not be short-circuited by an HTTP replay of a cached 2xx.
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('data.fiscal_sequence_no', 1);

        $order->refresh();

        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertSame(1, (int) $order->fiscal_sequence_no);
        $this->assertSame(1, Transaction::query()->where('order_id', $order->id)->where('type', 'payment')->count());
        $this->assertSame(
            1,
            DomainEvent::query()
                ->where('event_type', EventType::ORDER_PAYMENT_CONFIRMED)
                ->where('aggregate_id', $order->id)
                ->count()
        );
    }

    public function test_cash_at_counter_cancel_before_payment_never_allocates_sequence_or_payment_transaction(): void
    {
        Queue::fake();

        $order = $this->pendingCounterOrder(['queue_number' => 'A9104']);

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/cancel", [
                'reason' => 'Client absent before counter payment',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::REFUNDED)
            ->assertJsonPath('data.status', OrderStatus::CANCELED)
            ->assertJsonPath('data.fiscal_sequence_no', null);

        $order->refresh();

        $this->assertSame(PaymentStatus::REFUNDED, (int) $order->payment_status);
        $this->assertSame(OrderStatus::CANCELED, (int) $order->status);
        $this->assertNull($order->fiscal_sequence_no);
        $this->assertSame(0, Transaction::query()->where('order_id', $order->id)->where('type', 'payment')->count());

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 15,
            ])
            ->assertStatus(422);

        $this->assertNull($order->fresh()->fiscal_sequence_no);
    }

    private function pendingCounterOrder(array $overrides = []): Order
    {
        return Order::factory()->create($overrides + [
            'branch_id' => $this->branch->id,
            'user_id' => User::factory()->create(['branch_id' => $this->branch->id])->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::ACCEPT,
            'source_surface' => 'kiosk',
            'subtotal' => 12.00,
            'total' => 12.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'fiscal_sequence_no' => null,
        ]);
    }
}
