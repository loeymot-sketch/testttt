<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PosParkedOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosParkedOrderTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->operator->assignRole('POS Operator');
    }

    public function test_park_creates_a_database_entry(): void
    {
        $response = $this->actingAs($this->operator, 'sanctum')->postJson(
            '/api/admin/pos/parked-orders',
            $this->parkPayload('Counter #1', 'park-001')
        );

        $response->assertCreated()
            ->assertJsonPath('data.label', 'Counter #1')
            ->assertJsonPath('data.items_count', 2);

        $this->assertDatabaseHas('pos_parked_orders', [
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'label' => 'Counter #1',
            'idempotency_token' => 'park-001',
        ]);
    }

    public function test_list_returns_only_orders_for_the_same_operator_and_branch(): void
    {
        $otherBranch = Branch::factory()->create();
        $otherOperator = User::factory()->create(['branch_id' => $this->branch->id]);
        $otherOperator->assignRole('POS Operator');

        PosParkedOrder::query()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'label' => 'Visible order',
            'payload_json' => $this->snapshotPayload(),
            'preview_total' => 28.50,
            'items_count' => 2,
            'idempotency_token' => 'visible-1',
        ]);

        PosParkedOrder::query()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $otherOperator->id,
            'label' => 'Other operator',
            'payload_json' => $this->snapshotPayload(),
            'preview_total' => 28.50,
            'items_count' => 2,
            'idempotency_token' => 'hidden-1',
        ]);

        PosParkedOrder::query()->create([
            'branch_id' => $otherBranch->id,
            'user_id' => $this->operator->id,
            'label' => 'Other branch',
            'payload_json' => $this->snapshotPayload(),
            'preview_total' => 28.50,
            'items_count' => 2,
            'idempotency_token' => 'hidden-2',
        ]);

        $response = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/admin/pos/parked-orders');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'Visible order');
    }

    public function test_recall_deletes_the_entry_and_returns_the_payload(): void
    {
        $parkedOrder = PosParkedOrder::query()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'label' => 'Recall me',
            'payload_json' => $this->snapshotPayload(),
            'preview_total' => 28.50,
            'items_count' => 2,
            'idempotency_token' => 'recall-1',
        ]);

        $response = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/admin/pos/parked-orders/{$parkedOrder->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $parkedOrder->id)
            ->assertJsonPath('data.payload_json.lists.0.item_id', 101)
            ->assertJsonPath('data.warnings.unavailable_variations', []);

        $this->assertDatabaseMissing('pos_parked_orders', ['id' => $parkedOrder->id]);
    }

    public function test_recall_on_unknown_id_returns_404(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/admin/pos/parked-orders/999999')
            ->assertNotFound()
            ->assertJson(['error' => 'not_found']);
    }

    public function test_discard_deletes_the_entry(): void
    {
        $parkedOrder = PosParkedOrder::query()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'label' => 'Discard me',
            'payload_json' => $this->snapshotPayload(),
            'preview_total' => 28.50,
            'items_count' => 2,
            'idempotency_token' => 'discard-1',
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->deleteJson("/api/admin/pos/parked-orders/{$parkedOrder->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('pos_parked_orders', ['id' => $parkedOrder->id]);
    }

    /**
     * [V14 C-α / FINDING C-9 P1 sentinel]
     * Recall MUST NOT cross-tenant: an operator from another branch trying to
     * recall a parked order from THIS branch must get a 404 (not a leak).
     */
    public function test_recall_cross_branch_returns_404(): void
    {
        $parkedOrder = PosParkedOrder::query()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'label' => 'Branch-A only',
            'payload_json' => $this->snapshotPayload(),
            'preview_total' => 28.50,
            'items_count' => 2,
            'idempotency_token' => 'cross-branch-1',
        ]);

        $otherBranch = Branch::factory()->create();
        $otherOperator = User::factory()->create(['branch_id' => $otherBranch->id]);
        $otherOperator->assignRole('POS Operator');

        $this->actingAs($otherOperator, 'sanctum')
            ->getJson("/api/admin/pos/parked-orders/{$parkedOrder->id}")
            ->assertNotFound()
            ->assertJson(['error' => 'not_found']);

        // Sentinel: the original parked order must STILL be present (not deleted by the failed cross-tenant attempt)
        $this->assertDatabaseHas('pos_parked_orders', [
            'id' => $parkedOrder->id,
            'user_id' => $this->operator->id,
        ]);
    }

    /**
     * [V14 C-α / FINDING C-9 P1 sentinel]
     * Discard cross-tenant must also fail with 404.
     */
    public function test_discard_cross_branch_returns_404(): void
    {
        $parkedOrder = PosParkedOrder::query()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'label' => 'Branch-A only',
            'payload_json' => $this->snapshotPayload(),
            'preview_total' => 28.50,
            'items_count' => 2,
            'idempotency_token' => 'cross-discard-1',
        ]);

        $otherBranch = Branch::factory()->create();
        $otherOperator = User::factory()->create(['branch_id' => $otherBranch->id]);
        $otherOperator->assignRole('POS Operator');

        $this->actingAs($otherOperator, 'sanctum')
            ->deleteJson("/api/admin/pos/parked-orders/{$parkedOrder->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('pos_parked_orders', ['id' => $parkedOrder->id]);
    }

    public function test_park_with_the_same_idempotency_token_creates_only_one_row(): void
    {
        $firstResponse = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/admin/pos/parked-orders', $this->parkPayload('Double click', 'same-token'));

        $secondResponse = $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/admin/pos/parked-orders', $this->parkPayload('Double click', 'same-token'));

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();

        $this->assertSame(
            $firstResponse->json('data.id'),
            $secondResponse->json('data.id')
        );

        $this->assertDatabaseCount('pos_parked_orders', 1);
    }

    private function parkPayload(?string $label, string $token): array
    {
        return [
            'label' => $label,
            'idempotency_token' => $token,
            'payload' => $this->snapshotPayload(),
        ];
    }

    private function snapshotPayload(): array
    {
        return [
            'lists' => [
                [
                    'item_id' => 101,
                    'name' => 'Tacos 4 viandes',
                    'quantity' => 1,
                    'instruction' => 'Bien cuit',
                    'item_variations' => [
                        ['id' => 7001, 'item_attribute_id' => 90, 'quantity' => 3, 'name' => 'Steak'],
                        ['id' => 7002, 'item_attribute_id' => 90, 'quantity' => 1, 'name' => 'Poulet'],
                    ],
                    'item_extras' => [
                        ['id' => 8001, 'quantity' => 2, 'name' => 'Cheddar'],
                    ],
                ],
                [
                    'item_id' => 102,
                    'name' => 'Frites',
                    'quantity' => 1,
                    'instruction' => '',
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ],
            'subtotal' => 28.50,
            'discount' => 0,
            'total' => 28.50,
            'checkout_form' => [
                'customer_id' => 22,
                'order_type' => 10,
                'dining_table_id' => null,
                'address_id' => null,
                'delivery_charge' => 0,
            ],
        ];
    }
}
