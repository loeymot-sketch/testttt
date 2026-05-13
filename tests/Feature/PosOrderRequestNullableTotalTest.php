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
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [POS-9.1.8] PosOrderRequest — total/subtotal nullable (POS-GA-F-47).
 *
 * Backend recomputes subtotal/discount/total in OrderService::posOrderStore.
 * The request must therefore accept payloads that omit `total` (and `subtotal`)
 * and the order must still persist with the server-computed total. The
 * client-sent total can no longer be used to spoof / underbill an order.
 */
class PosOrderRequestNullableTotalTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $customer;
    protected User $operator;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        // [iter15-BUG-NF525] Legacy HT-add fixture (test expects total=11
        // for subtotal=10 + tax=1). Config default flipped to TTC 2026-05-10.
        config(['pricing.tax_inclusive_prices' => false]);

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

    private function basePayload(array $overrides = []): array
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

    public function test_payload_without_total_or_subtotal_is_accepted(): void
    {
        $this->actingAs($this->operator, 'sanctum');
        $payload = $this->basePayload();

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        // Persisted order must reflect the SERVER-computed total (10.00 + 10% TVA = 11.00),
        // not whatever the client sent (here: nothing).
        $this->assertDatabaseHas('orders', [
            'branch_id' => $this->branch->id,
            'subtotal'  => 10.00,
            'total'     => 11.00,
        ]);
    }

    public function test_spoofed_low_total_is_ignored_server_recomputes(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        // Client tries to bill 1€ instead of 11€.
        $payload = $this->basePayload([
            'subtotal' => 0.10,
            'total'    => 1.00,
        ]);
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        // Server-computed total wins.
        $this->assertDatabaseHas('orders', [
            'branch_id' => $this->branch->id,
            'total'     => 11.00,
        ]);
    }

    public function test_cash_received_below_server_total_is_rejected(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        // Client omits total, but only hands over 5€ for an 11€ order — server check must trip.
        $payload = $this->basePayload([
            'pos_received_amount' => 5.00,
        ]);
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(422);
    }

    public function test_negative_subtotal_rejected_at_validation(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->basePayload([
                'subtotal' => -5.00,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subtotal']);
    }

    public function test_negative_pos_received_amount_rejected_at_validation(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->basePayload([
                'pos_received_amount' => -1.00,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['pos_received_amount']);
    }
}
