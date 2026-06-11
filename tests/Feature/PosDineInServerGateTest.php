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
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [POS-9-H.1.5] F-A5 fix — server-side dine-in feature gate + int cast on order_type.
 *
 * Before this patch:
 *   - `request('order_type')` returns a string HTTP value, but OrderType::DINING_TABLE
 *     is an int enum → strict `===` always returned false. `dining_table_id` was
 *     therefore always `nullable`, regardless of `pos_dine_in_enabled`.
 *   - No server-side consult of the `pos_dine_in_enabled` flag — attacker could
 *     curl `order_type=15` and create a dine-in order even when UI hides it.
 */
class PosDineInServerGateTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $customer;
    protected User $operator;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
        ]);
        $this->customer->assignRole('Customer');

        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
        ]);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create([
            'name' => 'TVA 10%',
            'code' => 'TVA10',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        $cat = ItemCategory::factory()->create([
            'name' => 'Cat',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'tax_id' => $tax->id,
            'name' => 'Item',
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);
    }

    private function dineInPayload(array $overrides = []): array
    {
        return array_merge([
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => OrderType::DINING_TABLE, // 15
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 50,
            'items' => json_encode([
                [
                    'item_id' => $this->item->id,
                    'item_price' => $this->item->price,
                    'quantity' => 1,
                    'total_price' => 10.00,
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ]),
        ], $overrides);
    }

    public function test_dine_in_rejected_when_flag_off(): void
    {
        Settings::group('pos')->set('pos_dine_in_enabled', false);

        $this->actingAs($this->operator, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->dineInPayload());

        $response->assertStatus(422);
        // [UIUX-W2 G5 2026-06-11] message 422 traduit FR (était « Dine-in is disabled »).
        $this->assertStringContainsString('Le service en salle est désactivé',
            $response->json('message') . ' ' . json_encode($response->json('errors')));
    }

    public function test_dine_in_requires_table_when_flag_on(): void
    {
        Settings::group('pos')->set('pos_dine_in_enabled', true);

        $this->actingAs($this->operator, 'sanctum');

        // No dining_table_id provided — must now be a validation error.
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->dineInPayload());

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertArrayHasKey('dining_table_id', $errors ?? [],
            'dining_table_id must be required when dine-in is enabled and order_type=15.');
    }

    public function test_order_type_string_casts_to_int(): void
    {
        // Even if client sends order_type as a string, the gate must still fire.
        Settings::group('pos')->set('pos_dine_in_enabled', false);

        $this->actingAs($this->operator, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->dineInPayload([
                'order_type' => (string) OrderType::DINING_TABLE,
            ]));

        $response->assertStatus(422);
    }
}
