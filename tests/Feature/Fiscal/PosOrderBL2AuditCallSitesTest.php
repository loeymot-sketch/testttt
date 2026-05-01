<?php

namespace Tests\Feature\Fiscal;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * POS-9.4.BL.2 — Audit log call-sites on sensitive OrderService +
 * PaymentService operations.
 *
 * Each test asserts:
 *  (1) that an AuditLog row is written with the correct action name,
 *  (2) that the HMAC chain stays valid after the write
 *      (AuditLogService::verifyChain returns null for the branch).
 */
class PosOrderBL2AuditCallSitesTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $admin;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        config([
            'app.api_key' => 'test-api-key',
            'broadcasting.default' => 'log',
            'pricing.use_ssot_service' => true,
            'fiscal.audit_secret' => str_repeat('a', 48),
        ]);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'POS BL2 Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue BL2',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10',
            'code' => 'TVA10BL2',
            'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'BL2 Cat',
            'slug' => 'bl2-cat',
            'status' => Status::ACTIVE,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'BL2 Item',
            'slug' => 'bl2-item',
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $this->admin = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->admin->assignRole('Admin');
    }

    public function test_pos_order_with_manual_discount_writes_discount_applied_audit(): void
    {
        $payload = [
            'customer_id'         => $this->admin->id,
            'branch_id'           => $this->branch->id,
            'subtotal'            => 10.00,
            'discount'            => 2.50,
            'discount_reason'     => 'Geste commercial — BL2 test',
            'total'               => 8.50,
            'order_type'          => OrderType::TAKEAWAY,
            'is_advance_order'    => Ask::NO,
            'source'              => Source::POS,
            'pos_payment_method'  => PosPaymentMethod::CASH,
            'pos_received_amount' => 10.00,
            'items' => json_encode([[
                'item_id' => $this->item->id, 'quantity' => 1,
                'item_variations' => [], 'item_extras' => [],
            ]]),
        ];

        $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->admin, $payload))
            ->assertStatus(201);

        $audit = AuditLog::where('action', 'order.discount_applied')
            ->where('branch_id', $this->branch->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'Expected an order.discount_applied audit entry.');
        $this->assertSame('order', $audit->resource);
        $payload = $audit->payload;
        $this->assertSame('manual_cashier', $payload['discount_type']);
        $this->assertSame(2.5, (float) $payload['discount_amount']);

        $this->assertNull(
            app(\App\Services\Fiscal\AuditLogService::class)->verifyChain($this->branch->id),
            'Audit chain must stay intact after discount write.'
        );
    }

    public function test_change_status_to_cancel_writes_order_cancelled_audit(): void
    {
        $order = $this->createPaidOrder();

        $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::CANCELED,
                'reason' => 'Client désistement',
            ])->assertStatus(200);

        $audit = AuditLog::where('action', 'order.cancelled')
            ->where('resource_id', $order->id)
            ->first();

        $this->assertNotNull($audit, 'Expected an order.cancelled audit entry.');
        $this->assertSame((int) $this->branch->id, (int) $audit->branch_id);
        $this->assertSame((int) OrderStatus::CANCELED, (int) $audit->payload['to_status']);

        $this->assertNull(
            app(\App\Services\Fiscal\AuditLogService::class)->verifyChain($this->branch->id),
            'Audit chain must stay intact after cancel write.'
        );
    }

    public function test_change_status_to_returned_writes_order_returned_audit(): void
    {
        $order = $this->createPaidOrder();
        $order->status = OrderStatus::DELIVERED;
        $order->save();

        $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::RETURNED,
                'reason' => 'Produit retourné — non conforme',
            ])->assertStatus(200);

        $audit = AuditLog::where('action', 'order.returned')
            ->where('resource_id', $order->id)
            ->first();

        $this->assertNotNull($audit, 'Expected an order.returned audit entry.');
        $this->assertSame((int) OrderStatus::RETURNED, (int) $audit->payload['to_status']);
        $this->assertSame('Produit retourné — non conforme', $audit->payload['reason']);

        $this->assertNull(
            app(\App\Services\Fiscal\AuditLogService::class)->verifyChain($this->branch->id),
            'Audit chain must stay intact after return write.'
        );
    }

    public function test_returned_without_reason_fails_validation(): void
    {
        $order = $this->createPaidOrder();
        $order->status = OrderStatus::DELIVERED;
        $order->save();

        $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::RETURNED,
            ])->assertStatus(422);
    }

    public function test_change_payment_status_writes_payment_status_changed_audit(): void
    {
        $order = $this->createPaidOrder();

        $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos-order/change-payment-status/{$order->id}", [
                'payment_status' => PaymentStatus::UNPAID,
            ])->assertStatus(200);

        $audit = AuditLog::where('action', 'order.payment_status_changed')
            ->where('resource_id', $order->id)
            ->first();

        $this->assertNotNull($audit, 'Expected an order.payment_status_changed audit entry.');
        $this->assertSame((int) PaymentStatus::UNPAID, (int) $audit->payload['to_payment_status']);

        $this->assertNull(
            app(\App\Services\Fiscal\AuditLogService::class)->verifyChain($this->branch->id),
        );
    }

    public function test_destroy_order_writes_order_destroyed_audit(): void
    {
        $order = $this->createPaidOrder();

        $this->admin->givePermissionTo('pos-destroy-paid');

        $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->deleteJson("/api/admin/pos-order/{$order->id}", [
                'destroy_reason' => 'Double saisie',
            ])->assertStatus(202);

        $audit = AuditLog::where('action', 'order.destroyed')
            ->where('resource_id', $order->id)
            ->first();

        $this->assertNotNull($audit, 'Expected an order.destroyed audit entry.');
        $this->assertSame('Double saisie', $audit->payload['reason']);

        $this->assertNull(
            app(\App\Services\Fiscal\AuditLogService::class)->verifyChain($this->branch->id),
        );
    }

    public function test_cash_back_writes_payment_cash_back_issued_audit(): void
    {
        $order = $this->createPaidOrder();

        // Seed a Transaction so cashBack() actually writes.
        \App\Models\Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'TXN-ORIG',
            'amount'         => $order->total,
            'payment_method' => 'cash',
            'sign'           => '+',
            'type'           => 'payment',
        ]);

        $this->actingAs($this->admin);
        app(\App\Services\PaymentService::class)->cashBack($order->fresh(), 'credit', 'TXN-RBK');

        $audit = AuditLog::where('action', 'payment.cash_back_issued')
            ->where('resource_id', $order->id)
            ->first();

        $this->assertNotNull($audit, 'Expected a payment.cash_back_issued audit entry.');
        $this->assertSame('TXN-RBK', $audit->payload['transaction_no']);

        $this->assertNull(
            app(\App\Services\Fiscal\AuditLogService::class)->verifyChain($this->branch->id),
        );
    }

    private function createPaidOrder(): Order
    {
        $payload = [
            'customer_id'         => $this->admin->id,
            'branch_id'           => $this->branch->id,
            'subtotal'            => 10.00,
            'total'               => 11.00,
            'order_type'          => OrderType::TAKEAWAY,
            'is_advance_order'    => Ask::NO,
            'source'              => Source::POS,
            'pos_payment_method'  => PosPaymentMethod::CASH,
            'pos_received_amount' => 11.00,
            'items' => json_encode([[
                'item_id' => $this->item->id, 'quantity' => 1,
                'item_variations' => [], 'item_extras' => [],
            ]]),
        ];

        $resp = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->admin, $payload));
        $resp->assertStatus(201);
        return Order::findOrFail((int) $resp->json('data.id'));
    }
}
