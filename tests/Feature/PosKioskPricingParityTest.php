<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [V14 T03] POS vs Kiosk order totals must match for identical compositions (SSOT pricing).
 */
class PosKioskPricingParityTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;
    use SeedsOpenCashDrawerSession;

    private Branch $branch;
    private User $posOperator;
    private User $customer;
    private Tax $tax;
    private ItemCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $this->posOperator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'pos-parity@test.com',
            'password' => Hash::make('password123'),
        ]);
        $this->posOperator->assignRole('POS Operator');
        $this->posOperator->givePermissionTo('pos');
        // [Sprint H6 TEST-DEBT-001 2026-05-17] Sprint 1B requires an OPEN cash session for CASH.
        $this->seedOpenSessionFor($this->posOperator, $this->branch);

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'cust-parity@test.com',
            'password' => Hash::make('password123'),
        ]);
        $this->customer->assignRole('Customer');
        KioskMachine::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->customer->id,
            'status' => Status::ACTIVE,
            'is_login' => Ask::NO,
        ]);

        $this->tax = Tax::factory()->create([
            'name' => 'TVA 10%',
            'code' => 'TVA10',
            'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        $this->category = ItemCategory::factory()->create([
            'name' => 'Tacos Parity',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);
    }

    public function test_case_a_two_meat_mix_1_plus_1_totals_match(): void
    {
        $ctx = $this->makeTacosItemWithMeats();
        $vars = [
            ['id' => $ctx['kebab']->id, 'quantity' => 1],
            ['id' => $ctx['merguez']->id, 'quantity' => 1],
        ];

        $posTotal = $this->postPosOrder($ctx['item'], $vars, []);
        $kioskTotal = $this->postKioskOrder($ctx['item'], $vars, []);

        $this->assertTotalsNearEqual($posTotal, $kioskTotal);
    }

    public function test_case_b_three_same_meat_totals_match(): void
    {
        $ctx = $this->makeTacosItemWithMeats();
        $vars = [
            ['id' => $ctx['kebab']->id, 'quantity' => 3],
        ];

        $posTotal = $this->postPosOrder($ctx['item'], $vars, []);
        $kioskTotal = $this->postKioskOrder($ctx['item'], $vars, []);

        $this->assertTotalsNearEqual($posTotal, $kioskTotal);
    }

    public function test_case_c_four_meat_2_plus_1_plus_1_totals_match(): void
    {
        $ctx = $this->makeTacosItemWithMeats();
        $vars = [
            ['id' => $ctx['kebab']->id, 'quantity' => 2],
            ['id' => $ctx['merguez']->id, 'quantity' => 1],
            ['id' => $ctx['lardon']->id, 'quantity' => 1],
        ];

        $posTotal = $this->postPosOrder($ctx['item'], $vars, []);
        $kioskTotal = $this->postKioskOrder($ctx['item'], $vars, []);

        $this->assertTotalsNearEqual($posTotal, $kioskTotal);
    }

    public function test_case_d_with_paid_extra_totals_match(): void
    {
        $ctx = $this->makeTacosItemWithMeats();
        $extra = ItemExtra::query()->create([
            'item_id' => $ctx['item']->id,
            'name' => 'Sauce payante',
            'price' => 1.50,
            'new_price' => 1.50,
            'status' => Status::ACTIVE,
        ]);

        $vars = [
            ['id' => $ctx['kebab']->id, 'quantity' => 2],
            ['id' => $ctx['merguez']->id, 'quantity' => 2],
        ];
        $extras = [
            ['id' => $extra->id, 'quantity' => 1, 'name' => $extra->name],
        ];

        $posTotal = $this->postPosOrder($ctx['item'], $vars, $extras);
        $kioskTotal = $this->postKioskOrder($ctx['item'], $vars, $extras);

        $this->assertTotalsNearEqual($posTotal, $kioskTotal);
    }

    private function assertTotalsNearEqual(float $a, float $b): void
    {
        $this->assertLessThanOrEqual(0.001, abs($a - $b), "Totals differ: POS/Kiosk {$a} vs {$b}");
    }

    /**
     * @param  array<int, array{id:int, quantity:int}>  $variations
     * @param  array<int, array{id:int, quantity:int, name?:string}>  $extras
     */
    private function postPosOrder(Item $item, array $variations, array $extras): float
    {
        $this->actingAs($this->posOperator, 'sanctum');

        $line = [
            'item_id' => $item->id,
            'item_price' => (float) $item->price,
            'quantity' => 1,
            'total_price' => (float) $item->price,
            'item_variations' => $variations,
            'item_extras' => $extras,
        ];

        $payload = [
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => 0,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 9999.00,
            'items' => json_encode([$line]),
        ];

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->posOperator, $payload));

        $response->assertCreated();

        return (float) $response->json('data.total');
    }

    /**
     * @param  array<int, array{id:int, quantity:int}>  $variations
     * @param  array<int, array{id:int, quantity:int, name?:string}>  $extras
     */
    private function postKioskOrder(Item $item, array $variations, array $extras): float
    {
        $line = [
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'item_price' => (float) $item->price,
            'price' => (float) $item->price,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => (float) $item->price,
            'instruction' => '',
            'item_variations' => $variations,
            'item_extras' => $extras,
        ];

        $payload = [
            'branch_id' => $this->branch->id,
            'subtotal' => 0,
            'discount' => 0,
            'delivery_charge' => 0,
            'total' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'status' => PaymentStatus::UNPAID,
            'items' => json_encode([$line]),
        ];

        $response = $this->actingAs($this->customer, 'sanctum')
            ->withHeader('x-api-key', (string) config('app.api_key'))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $this->payloadWithKioskQuote($this->customer, $payload));

        $this->assertContains($response->status(), [200, 201], $response->getContent());

        return (float) $response->json('data.total');
    }

    /**
     * @return array{item: Item, attr: ItemAttribute, kebab: ItemVariation, merguez: ItemVariation, lardon: ItemVariation}
     */
    private function makeTacosItemWithMeats(): array
    {
        $item = Item::factory()->create([
            'item_category_id' => $this->category->id,
            'tax_id' => $this->tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);

        $attr = ItemAttribute::query()->create([
            'name' => 'Viande ' . uniqid(),
            'status' => Status::ACTIVE,
            'min_select' => 1,
            'max_select' => 4,
            'allow_repeat' => true,
        ]);

        $kebab = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attr->id,
            'name' => 'Kebab',
            'price' => 2.00,
            'new_price' => 2.00,
            'status' => Status::ACTIVE,
        ]);

        $merguez = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attr->id,
            'name' => 'Merguez',
            'price' => 2.50,
            'new_price' => 2.50,
            'status' => Status::ACTIVE,
        ]);

        $lardon = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attr->id,
            'name' => 'Lardon',
            'price' => 1.00,
            'new_price' => 1.00,
            'status' => Status::ACTIVE,
        ]);

        return compact('item', 'attr', 'kebab', 'merguez', 'lardon');
    }
}
