<?php

namespace Tests\Feature\Fiscal;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use App\Models\ZReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * POS-9.4.BL.3 — destroy() must return 409 when the order is sealed by a
 * closed Z report (NF525 immutability).
 */
class PosOrderBL3DestroyAfterZTest extends TestCase
{
    use RefreshDatabase;

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
            'fiscal.z_report_secret' => str_repeat('b', 48),
        ]);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'POS BL3 Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue BL3',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10',
            'code' => 'TVA10BL3',
            'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'BL3 Cat',
            'slug' => 'bl3-cat',
            'status' => Status::ACTIVE,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'BL3 Item',
            'slug' => 'bl3-item',
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $this->admin = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo('pos-destroy-paid');
    }

    public function test_destroy_rejected_409_when_sealed_by_closed_z(): void
    {
        $order = $this->placePosOrder();

        // Force created_at inside a "historic" window we will seal.
        $order->forceFill(['created_at' => now()->subDay()->setTime(12, 30)])->saveQuietly();

        // Seal by a closed Z report whose window covers 12:30 yesterday.
        ZReport::forceCreate([
            'branch_id'   => $this->branch->id,
            'sequence_no' => 1,
            'opened_at'   => now()->subDay()->setTime(8, 0),
            'closed_at'   => now()->subDay()->setTime(23, 59),
            'opened_by'   => $this->admin->id,
            'closed_by'   => $this->admin->id,
            'total_ht'    => 10.0,
            'total_ttc'   => 11.0,
            'total_tva'   => 1.0,
            'total_by_method'   => ['cash' => 11.0],
            'total_by_tax_rate' => ['10' => 1.0],
            'order_count' => 1,
            'cancel_count'=> 0,
            'refund_count'=> 0,
            'prev_hash'   => null,
            'signature'   => str_repeat('c', 64),
            'status'      => ZReport::STATUS_CLOSED,
        ]);

        $resp = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->deleteJson("/api/admin/pos-order/{$order->id}", ['destroy_reason' => 'attempt']);

        $resp->assertStatus(409);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_allowed_when_z_report_still_open(): void
    {
        $order = $this->placePosOrder();

        ZReport::forceCreate([
            'branch_id'   => $this->branch->id,
            'sequence_no' => 1,
            'opened_at'   => now()->subHour(),
            'closed_at'   => null,
            'opened_by'   => $this->admin->id,
            'closed_by'   => null,
            'total_ht'    => 0,
            'total_ttc'   => 0,
            'total_tva'   => 0,
            'total_by_method'   => [],
            'total_by_tax_rate' => [],
            'order_count' => 0,
            'cancel_count'=> 0,
            'refund_count'=> 0,
            'prev_hash'   => null,
            'signature'   => str_repeat('c', 64),
            'status'      => ZReport::STATUS_OPEN,
        ]);

        $resp = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->deleteJson("/api/admin/pos-order/{$order->id}", ['destroy_reason' => 'still-open-ok']);

        $resp->assertStatus(202);
    }

    public function test_destroy_allowed_for_order_without_fiscal_sequence(): void
    {
        // Older order flow may have created rows without fiscal_sequence_no;
        // those must still be destroyable (otherwise we'd brick legacy data).
        $order = Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'user_id'        => $this->admin->id,
            'payment_status' => \App\Enums\PaymentStatus::UNPAID,
            'fiscal_sequence_no' => null,
        ]);

        ZReport::forceCreate([
            'branch_id'   => $this->branch->id,
            'sequence_no' => 1,
            'opened_at'   => now()->subDay()->setTime(8, 0),
            'closed_at'   => now()->subDay()->setTime(23, 59),
            'opened_by'   => $this->admin->id,
            'closed_by'   => $this->admin->id,
            'total_ht'    => 0,
            'total_ttc'   => 0,
            'total_tva'   => 0,
            'total_by_method'   => [],
            'total_by_tax_rate' => [],
            'order_count' => 0,
            'cancel_count'=> 0,
            'refund_count'=> 0,
            'prev_hash'   => null,
            'signature'   => str_repeat('d', 64),
            'status'      => ZReport::STATUS_CLOSED,
        ]);

        // Legacy order must still be destroyable — guard only trips when
        // fiscal_sequence_no is non-null (i.e. was part of the fiscal flow).
        $order->forceFill(['created_at' => now()->subDay()->setTime(12, 30)])->saveQuietly();

        $resp = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->deleteJson("/api/admin/pos-order/{$order->id}", ['destroy_reason' => 'legacy-order']);

        $resp->assertStatus(202);
    }

    private function placePosOrder(): Order
    {
        $resp = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', [
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
            ]);
        $resp->assertStatus(201);
        return Order::findOrFail((int) $resp->json('data.id'));
    }
}
