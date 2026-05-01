<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * @FK-ID FK-008 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M09-BRANCH-ISOLATION | @reason transaction filters must stay exact on order.branch_id and never regress to LIKE.
 */
class TransactionBranchExactnessSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_branch_staff_cannot_query_transactions_for_another_branch(): void
    {
        foreach ([1, 10] as $id) {
            Branch::factory()->create(['id' => $id]);
        }

        $staff = User::factory()->create(['branch_id' => 10]);
        $staff->assignRole('POS Operator');
        Permission::firstOrCreate(['name' => 'transactions', 'guard_name' => 'sanctum']);
        $staff->givePermissionTo('transactions');

        $orderOne = Order::factory()->create(['branch_id' => 1, 'order_type' => OrderType::POS]);
        $orderTen = Order::factory()->create(['branch_id' => 10, 'order_type' => OrderType::POS]);

        Transaction::create([
            'order_id' => $orderOne->id,
            'transaction_no' => 'FK-TX-1',
            'amount' => 10,
            'payment_method' => 'cash',
            'type' => 'payment',
            'sign' => '+',
        ]);
        Transaction::create([
            'order_id' => $orderTen->id,
            'transaction_no' => 'FK-TX-10',
            'amount' => 10,
            'payment_method' => 'cash',
            'type' => 'payment',
            'sign' => '+',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/admin/transaction?branch_id=1')
            ->assertStatus(403);
    }
}
