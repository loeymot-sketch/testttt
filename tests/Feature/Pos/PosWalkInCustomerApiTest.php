<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\User;
use App\Services\Pos\WalkInCustomerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosWalkInCustomerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_operator_can_resolve_walk_in_customer_via_dedicated_endpoint(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');

        $response = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/pos/walk-in-customer');

        $response->assertOk()
            ->assertJsonPath('status', true);

        $expected = (new WalkInCustomerResolver)->resolve();
        $response->assertJsonPath('data.id', $expected->id)
            ->assertJsonPath('data.email', WalkInCustomerResolver::EMAIL);
    }
}
