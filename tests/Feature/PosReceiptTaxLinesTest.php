<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [POS-9.1.13] OrderDetailsResource exposes per-rate VAT breakdown.
 *
 * CGI art. 242 nonies A — every receipt must show the HT base and the VAT
 * amount for each VAT rate. Audit POS-GA-F-20.
 */
class PosReceiptTaxLinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_lines_are_grouped_by_rate(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();

        $tva10 = Tax::factory()->create([
            'name' => 'TVA 10', 'code' => 'TVA10',
            'type' => TaxType::PERCENTAGE, 'tax_rate' => 10.00, 'status' => Status::ACTIVE,
        ]);
        $tva20 = Tax::factory()->create([
            'name' => 'TVA 20', 'code' => 'TVA20',
            'type' => TaxType::PERCENTAGE, 'tax_rate' => 20.00, 'status' => Status::ACTIVE,
        ]);

        $cat = ItemCategory::factory()->create(['has_menu' => true, 'wizard_template' => 'tacos']);
        $i10 = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tva10->id, 'price' => 10.00, 'status' => Status::ACTIVE]);
        $i20 = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tva20->id, 'price' => 10.00, 'status' => Status::ACTIVE]);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 33.00,
            // 2 lines @ 10% → 1€ + 1€ = 2€ ; 1 line @ 20% → 2€ ; total 4€.
            'total_tax' => 4.00,
            'total' => 33.00,
            'pos_received_amount' => 33.00,
        ]);

        // Two lines @ 10% (each 11€ TTC, 1€ VAT) → group "10" base 20, tax 2.
        // No OrderItemFactory exists in this codebase; create Eloquent rows directly.
        $base = [
            'order_id' => $order->id, 'branch_id' => $branch->id,
            'discount' => 0, 'item_variations' => '[]', 'item_extras' => '[]',
            'item_variation_total' => 0, 'item_extra_total' => 0,
        ];
        OrderItem::create(array_merge($base, [
            'item_id' => $i10->id, 'tax_name' => 'TVA 10', 'tax_rate' => '10',
            'tax_type' => TaxType::PERCENTAGE, 'tax_amount' => 1.00,
            'price' => 10.00, 'quantity' => 1, 'total_price' => 11.00,
        ]));
        OrderItem::create(array_merge($base, [
            'item_id' => $i10->id, 'tax_name' => 'TVA 10', 'tax_rate' => '10',
            'tax_type' => TaxType::PERCENTAGE, 'tax_amount' => 1.00,
            'price' => 10.00, 'quantity' => 1, 'total_price' => 11.00,
        ]));
        // One line @ 20% → group "20" base 10, tax 2
        OrderItem::create(array_merge($base, [
            'item_id' => $i20->id, 'tax_name' => 'TVA 20', 'tax_rate' => '20',
            'tax_type' => TaxType::PERCENTAGE, 'tax_amount' => 2.00,
            'price' => 10.00, 'quantity' => 1, 'total_price' => 12.00,
        ]));

        $admin = User::factory()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        $resp = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-order/show/' . $order->id);

        $resp->assertStatus(200);
        $payload = $resp->json('data');
        $this->assertArrayHasKey('tax_lines', $payload, 'tax_lines must be exposed by OrderDetailsResource');
        $lines = collect($payload['tax_lines'])->keyBy('tax_rate');
        $this->assertCount(2, $lines, 'Two distinct VAT rates must be grouped into 2 tax_lines.');

        $line10 = $lines['10'] ?? null;
        $line20 = $lines['20'] ?? null;
        $this->assertNotNull($line10);
        $this->assertNotNull($line20);

        // Group @ 10% : two lines, each 11€ TTC and 1€ VAT
        $this->assertEqualsWithDelta(20.00, (float) $line10['base_ht'], 0.01);
        $this->assertEqualsWithDelta(2.00,  (float) $line10['tax'],     0.01);

        // Group @ 20% : one line, 12€ TTC and 2€ VAT
        $this->assertEqualsWithDelta(10.00, (float) $line20['base_ht'], 0.01);
        $this->assertEqualsWithDelta(2.00,  (float) $line20['tax'],     0.01);

        // Sum of tax_lines.tax must match total_tax (invariant)
        $sum = collect($payload['tax_lines'])->sum(fn ($l) => (float) $l['tax']);
        $this->assertEqualsWithDelta(4.00, $sum, 0.01);
    }
}
