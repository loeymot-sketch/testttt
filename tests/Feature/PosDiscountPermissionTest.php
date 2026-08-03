<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [POS-9.1.1] Discount permission gate (POS-GA-F-06).
 *
 * Any POS discount > 0 requires :
 *  - a written motif (≥ 3 chars)
 *  - permission matching the discount band :
 *      - 0%   < pct ≤ 10%   → pos-discount-up-to-10
 *      - 10%  < pct ≤ 50%   → pos-discount-over-10-requires-manager (or unlimited)
 *      - 50%  < pct          → pos-discount-unlimited
 */
class PosDiscountPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $customer;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config([
            'app.api_key' => 'test-api-key',
            'broadcasting.default' => 'log',
            'fiscal.audit_secret' => str_repeat('a', 48),
            // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] This suite tests the
            // discount permission ladder (preserved code); enable the flag so
            // the ladder runs rather than the V1 manual-discount-off gate.
            'pos.manual_discount_enabled' => true,
        ]);

        $this->branch = Branch::factory()->create();

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            // [Sprint 2B compat] users.phone NOT NULL.
            'phone' => fake()->unique()->numerify('06########'),
        ]);
        $this->customer->assignRole('Customer');

        $tax = Tax::factory()->create([
            'name' => 'TVA 10%',
            'code' => 'TVA10',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        $category = ItemCategory::factory()->create([
            'name' => 'Cat',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        $this->item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'name' => 'Item',
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeOperatorWith(array $permissions = []): User
    {
        // [Gate POS-9.1] Do NOT assign the "POS Operator" role here: per
        // seedSpatieRoles it now carries `pos-discount-up-to-10` by default,
        // which would defeat the "no permission" scenario. The test grants
        // only the explicit permissions it cares about (plus the baseline
        // `pos` / `pos-orders` needed to hit the route).
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            // [Sprint 2B compat] users.phone NOT NULL.
            'phone' => fake()->unique()->numerify('06########'),
        ]);
        $user->syncPermissions(array_merge(['pos', 'pos-orders'], $permissions));

        // [Sprint 1B 2026-05-16] POS CASH path now requires an open cash
        // drawer session — open one for the operator so the discount tests
        // can focus on discount semantics, not the cash gate.
        app(\App\Services\Cash\CashDrawerService::class)
            ->openSession($this->branch->id, $user->id, 100.00);

        return $user;
    }

    private function buildOrderPayload(float $discount, ?string $reason, float $subtotal = 10.00): array
    {
        $total = max(0, $subtotal + ($subtotal * 0.10) - $discount);
        return [
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'discount_reason' => $reason,
            'coupon_id' => 0,
            'total' => $total,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => $total,
            'items' => json_encode([
                [
                    'item_id' => $this->item->id,
                    'item_price' => $this->item->price,
                    'quantity' => 1,
                    'total_price' => $subtotal,
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ]),
        ];
    }

    private function withSealedQuote(User $operator, array $payload): array
    {
        $quote = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        return array_merge($payload, [
            'quote_token' => $quote['quote_token'],
            'quote_signature' => $quote['signature'],
            'total' => $quote['total_ttc'],
            'pos_received_amount' => $quote['total_ttc'],
        ]);
    }

    public function test_discount_without_motif_is_rejected(): void
    {
        $operator = $this->makeOperatorWith(['pos-discount-unlimited']);
        $this->actingAs($operator, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->buildOrderPayload(1.00, null));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['discount_reason']);
    }

    public function test_discount_5pct_without_permission_is_rejected(): void
    {
        $operator = $this->makeOperatorWith([]); // no discount permission
        $this->actingAs($operator, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->buildOrderPayload(0.5, 'Test motif'));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['discount']);
    }

    public function test_discount_5pct_with_cashier_permission_is_accepted(): void
    {
        $operator = $this->makeOperatorWith(['pos-discount-up-to-10']);
        $this->actingAs($operator, 'sanctum');

        $payload = $this->withSealedQuote($operator, $this->buildOrderPayload(0.5, 'Geste commercial'));

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $payload);

        $response->assertStatus(201);
    }

    public function test_discount_25pct_cashier_only_is_rejected(): void
    {
        $operator = $this->makeOperatorWith(['pos-discount-up-to-10']);
        $this->actingAs($operator, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->buildOrderPayload(2.5, 'Trop généreux'));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['discount']);
    }

    public function test_discount_25pct_with_manager_permission_is_accepted(): void
    {
        $operator = $this->makeOperatorWith([
            'pos-discount-up-to-10',
            'pos-discount-over-10-requires-manager',
        ]);
        $this->actingAs($operator, 'sanctum');

        $payload = $this->withSealedQuote($operator, $this->buildOrderPayload(2.5, 'Validé manager'));

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $payload);

        $response->assertStatus(201);
    }

    public function test_discount_75pct_manager_only_is_rejected(): void
    {
        $operator = $this->makeOperatorWith([
            'pos-discount-up-to-10',
            'pos-discount-over-10-requires-manager',
        ]);
        $this->actingAs($operator, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->buildOrderPayload(7.5, 'Trop haut'));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['discount']);
    }

    public function test_discount_75pct_with_unlimited_is_accepted(): void
    {
        $operator = $this->makeOperatorWith(['pos-discount-unlimited']);
        $this->actingAs($operator, 'sanctum');

        $payload = $this->withSealedQuote($operator, $this->buildOrderPayload(7.5, 'Validé owner'));

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $payload);

        $response->assertStatus(201);
    }

    public function test_zero_discount_does_not_require_motif_or_permission(): void
    {
        $operator = $this->makeOperatorWith([]); // no discount permission
        $this->actingAs($operator, 'sanctum');

        $payload = $this->withSealedQuote($operator, $this->buildOrderPayload(0.0, null));

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $payload);

        $response->assertStatus(201);
    }
}
