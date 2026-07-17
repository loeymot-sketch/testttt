<?php

namespace Tests\Feature\Frontend;

use App\Enums\Status;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [test-e2e goal4-predeploy A-002 2026-07-17] La borne (KioskAppComponent frozen)
 * prend branches[0] pour se scoper/s'abonner au temps réel. Le défaut DESC du
 * service renvoyait la branche Faker id 9 en premier sur dev → abonnement
 * private-branch.9 ≠ branche machine (1) → broadcasting/auth 403, push 86 mort.
 * Le endpoint FRONTEND doit servir un ordre STABLE id ASC (principale en tête).
 */
class FrontendBranchOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_frontend_branch_index_orders_by_id_asc(): void
    {
        $main = Branch::factory()->create(['name' => 'Le Cayenne (principal)', 'status' => Status::ACTIVE]);
        $faker = Branch::factory()->create(['name' => 'Faker Branch', 'status' => Status::ACTIVE]);
        $this->assertTrue($faker->id > $main->id);

        $ids = array_map('intval', $this->getJson('/api/frontend/branch')->assertOk()->json('data.*.id'));

        $this->assertSame(min($ids), $ids[0], 'branches[0] doit être le plus petit id (branche principale)');
        $asc = $ids;
        sort($asc);
        $this->assertSame($asc, $ids, 'ordre id ASC stable');
        $this->assertContains($faker->id, $ids);
        $this->assertGreaterThan(array_search($main->id, $ids), array_search($faker->id, $ids), 'la Faker vient APRÈS la principale');
    }
}
