<?php

namespace Tests\Feature\Receipt;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\Receipt\ReceiptDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M11-01 / S11-02 / S16-01 — NF525 receipt OPERATOR identity.
 *
 * The printed-ticket "Opérateur" must be the CASHIER, never the customer.
 * `orders.user_id` is the customer; the operator is the POS creator
 * (creator_id) or, for a kiosk order collected at the counter, the collecting
 * cashier (editor_id, set by PaymentService::confirmCounterPayment).
 * ReceiptDataService previously printed `optional($order->user)->name` = the
 * customer ("Client passage").
 */
class OperatorIdentityNf525Test extends TestCase
{
    use RefreshDatabase;

    private function branch(): Branch
    {
        return Branch::factory()->create([
            'siret' => '73282932000074',
            'vat_intra' => 'FR12345678901',
            'register_id' => 'POS-001',
            'legal_footer' => 'Merci.',
        ]);
    }

    public function test_pos_direct_receipt_operator_is_creator_cashier_not_customer(): void
    {
        $b = $this->branch();
        $cashier = User::factory()->create(['branch_id' => $b->id, 'name' => 'Cashier Alice']);
        $customer = User::factory()->create(['branch_id' => $b->id, 'name' => 'Client Bob']);
        $order = Order::factory()->create([
            'branch_id' => $b->id, 'user_id' => $customer->id,
            'creator_id' => $cashier->id, 'editor_id' => null,
            'order_type' => OrderType::POS, 'total' => 10,
        ]);

        $data = (new ReceiptDataService())->buildForOrderModel($order->fresh());

        $this->assertSame('Cashier Alice', $data['operator_name']);
        $this->assertNotSame('Client Bob', $data['operator_name']);
    }

    public function test_kiosk_counter_collected_receipt_operator_is_collecting_cashier(): void
    {
        $b = $this->branch();
        $collector = User::factory()->create(['branch_id' => $b->id, 'name' => 'Cashier Carol']);
        $customer = User::factory()->create(['branch_id' => $b->id, 'name' => 'Kiosk Client']);
        // Self-service kiosk order: creator_id NULL; collector recorded on editor_id.
        $order = Order::factory()->create([
            'branch_id' => $b->id, 'user_id' => $customer->id,
            'creator_id' => null, 'editor_id' => $collector->id,
            'order_type' => OrderType::POS, 'total' => 10,
        ]);

        $data = (new ReceiptDataService())->buildForOrderModel($order->fresh());

        $this->assertSame('Cashier Carol', $data['operator_name']);
        $this->assertNotSame('Kiosk Client', $data['operator_name']);
    }

    public function test_editor_collector_takes_precedence_over_creator(): void
    {
        $b = $this->branch();
        $creator = User::factory()->create(['branch_id' => $b->id, 'name' => 'Original Cashier']);
        $collector = User::factory()->create(['branch_id' => $b->id, 'name' => 'Collecting Cashier']);
        $customer = User::factory()->create(['branch_id' => $b->id, 'name' => 'Cust']);
        $order = Order::factory()->create([
            'branch_id' => $b->id, 'user_id' => $customer->id,
            'creator_id' => $creator->id, 'editor_id' => $collector->id,
            'order_type' => OrderType::POS, 'total' => 10,
        ]);

        $data = (new ReceiptDataService())->buildForOrderModel($order->fresh());

        $this->assertSame('Collecting Cashier', $data['operator_name']);
    }

    public function test_no_operator_recorded_yields_null_never_the_customer(): void
    {
        $b = $this->branch();
        $customer = User::factory()->create(['branch_id' => $b->id, 'name' => 'Lonely Customer']);
        $order = Order::factory()->create([
            'branch_id' => $b->id, 'user_id' => $customer->id,
            'creator_id' => null, 'editor_id' => null,
            'order_type' => OrderType::POS, 'total' => 10,
        ]);

        $data = (new ReceiptDataService())->buildForOrderModel($order->fresh());

        $this->assertNull($data['operator_name']);
        $this->assertNotSame('Lonely Customer', $data['operator_name']);
    }

    /**
     * S16-01 end-to-end: collecting a self-service kiosk order at the counter
     * must record the COLLECTING cashier on the order (editor_id) so the NF525
     * receipt prints them — not the kiosk customer, not blank.
     */
    public function test_confirm_counter_payment_records_collecting_cashier_as_operator(): void
    {
        $branch = $this->branch();
        $cashier = User::factory()->create(['branch_id' => $branch->id, 'name' => 'Collecting Dave']);
        $customer = User::factory()->create(['branch_id' => $branch->id, 'name' => 'Kiosk Eve']);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $customer->id,
            'creator_id' => null,          // self-service kiosk order
            'editor_id' => null,
            'order_type' => OrderType::POS,
            'status' => OrderStatus::ACCEPT,
            // Deferred counter-collect marker TRIPLE (per PaymentService seal guard).
            'source_surface' => 'kiosk',
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'total' => 12.00,
        ]);

        $this->actingAs($cashier, 'sanctum');
        app(PaymentService::class)->confirmCounterPayment($order, PosPaymentMethod::CASH, 12.00, 'e2e collect');

        $fresh = $order->fresh();
        $this->assertSame((int) $cashier->id, (int) $fresh->editor_id, 'collecting cashier must be recorded on editor_id (S16-01)');

        $data = (new ReceiptDataService())->buildForOrderModel($fresh);
        $this->assertSame('Collecting Dave', $data['operator_name']);
        $this->assertNotSame('Kiosk Eve', $data['operator_name']);
    }
}
