<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerNfcLookupTest extends TestCase
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

    public function test_lookup_returns_customer_when_uid_matches_in_branch(): void
    {
        $customer = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $customer->assignRole('Customer');
        $customer->forceFill(['nfc_uid' => 'ABCD1234', 'loyalty_points' => 42])->save();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/admin/pos/customers/lookup-by-nfc', ['nfc_uid' => 'ABCD1234'])
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('customer.name', $customer->name)
            ->assertJsonPath('customer.loyalty_points', 42);
    }

    public function test_lookup_returns_404_when_uid_unknown(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/admin/pos/customers/lookup-by-nfc', ['nfc_uid' => 'UNKNOWN'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'customer_nfc_not_found');
    }

    public function test_lookup_returns_404_for_cross_branch_uid(): void
    {
        $otherBranch = Branch::factory()->create();

        $customer = User::factory()->create([
            'branch_id' => $otherBranch->id,
        ]);
        $customer->assignRole('Customer');
        $customer->forceFill(['nfc_uid' => 'CROSSBR01'])->save();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/admin/pos/customers/lookup-by-nfc', ['nfc_uid' => 'CROSSBR01'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'customer_nfc_not_found');
    }
}
