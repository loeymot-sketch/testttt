<?php

namespace Tests\Feature\Admin\POS;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptPrintControllerTest extends TestCase
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

    public function test_increment_first_call_returns_count_1_not_duplicata(): void
    {
        $order = $this->makeOrder(0);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
            ->assertOk()
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('receipt_print_count', 1)
            ->assertJsonPath('is_duplicata', false);
    }

    public function test_increment_second_call_returns_count_2_is_duplicata_true(): void
    {
        $order = $this->makeOrder(1);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
            ->assertOk()
            ->assertJsonPath('receipt_print_count', 2)
            ->assertJsonPath('is_duplicata', true);
    }

    public function test_increment_persists_in_db(): void
    {
        $order = $this->makeOrder(0);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'branch_id' => $this->branch->id,
            'receipt_print_count' => 1,
        ]);
    }

    public function test_cross_branch_order_returns_404(): void
    {
        $foreignBranch = Branch::factory()->create();
        $foreignUser = User::factory()->create(['branch_id' => $foreignBranch->id]);
        $foreignOrder = Order::factory()->create([
            'branch_id' => $foreignBranch->id,
            'user_id' => $foreignUser->id,
            'receipt_print_count' => 0,
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/orders/{$foreignOrder->id}/print-receipt")
            ->assertNotFound();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $order = $this->makeOrder(0);

        $this->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
            ->assertStatus(401);
    }

    private function makeOrder(int $printCount): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'receipt_print_count' => $printCount,
        ]);
    }
}
