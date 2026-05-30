<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Sentinel — manual POS discounts
 * are OFF by default in V1.
 *
 * At 10% VAT the discount→HT/TVA split in the frozen ZReportService/Pricing
 * Service is wrong (TVA on pre-discount base) → a discounted order would sign a
 * fiscally-incorrect NF525 Z. The adversarial audit confirmed the
 * pos-discount-up-to-10 permission is granted to the everyday POS Operator, so
 * the path was LIVE. This sentinel locks the V1 gate: with the flag OFF, any
 * non-zero manual discount is refused (422) regardless of permission.
 */
class ManualDiscountDisabledV1SentinelTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $operator;
    protected User $customer;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('pos.simulation_hardware', true);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branch = Branch::factory()->create();
        $this->customer = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('pwd'), 'phone' => '0102030400']);
        $this->customer->assignRole('Customer');
        $this->operator = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('pwd'), 'phone' => '0102030401']);
        $this->operator->assignRole('POS Operator'); // holds pos-discount-up-to-10

        $tax = Tax::factory()->create(['name' => 'VAT', 'code' => 'VAT10', 'type' => TaxType::PERCENTAGE, 'tax_rate' => 10.00, 'status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['name' => 'Sandwich', 'wizard_template' => 'simple', 'has_menu' => false]);
        $this->item = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tax->id, 'name' => 'Sandwich Cayenne', 'price' => 10.00, 'status' => Status::ACTIVE]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 20.00,
            'items' => json_encode([[
                'item_id' => $this->item->id, 'item_price' => 10.00, 'quantity' => 1,
                'total_price' => 10.00, 'item_variations' => [], 'item_extras' => [],
            ]]),
        ], $overrides);
    }

    public function test_manual_discount_disabled_by_default_in_v1(): void
    {
        $this->assertFalse((bool) config('pos.manual_discount_enabled'), 'Manual discounts must be OFF by default in V1');
    }

    public function test_pos_operator_discount_is_refused_when_disabled(): void
    {
        // Flag OFF (V1 default). POS Operator HAS pos-discount-up-to-10, but the
        // V1 gate refuses any non-zero discount before the permission ladder.
        Config::set('pos.manual_discount_enabled', false);
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->payload(['discount' => 1.00, 'discount_reason' => 'remise client fidèle']);
        $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload))
            ->assertStatus(422);
    }

    public function test_zero_discount_order_still_succeeds_when_disabled(): void
    {
        // The gate only blocks discount > 0 — a normal full-price order is fine.
        Config::set('pos.manual_discount_enabled', false);
        $this->actingAs($this->operator, 'sanctum');

        $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $this->payload(['discount' => 0])))
            ->assertStatus(201);
    }
}
