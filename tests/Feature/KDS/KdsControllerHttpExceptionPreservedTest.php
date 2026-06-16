<?php

namespace Tests\Feature\KDS;

use App\Models\Branch;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * [KDS-02] The KDS read controllers (index/orderItems/historyToday) caught only \Exception and returned
 * 422 for everything — flattening a genuine 403/throttle to a "data error". They now catch HttpException
 * first (matching changeStatus()/recall() in the same file + the sibling OSS controller), preserving the
 * real status code. Latent today (the service re-wraps to a plain 422), so this proves the new branch.
 */
class KdsControllerHttpExceptionPreservedTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $branch = Branch::factory()->create();
        $this->chef = User::factory()->create(['branch_id' => $branch->id]);
        $this->chef->assignRole('Chef');
    }

    public function test_index_happy_path_still_returns_200(): void
    {
        $this->actingAs($this->chef, 'sanctum')
            ->getJson('/api/admin/kds-order')
            ->assertOk(); // gate passes + behavior unchanged
    }

    public function test_index_preserves_http_exception_status_instead_of_flattening_to_422(): void
    {
        $this->mock(KitchenDisplaySystemOrderService::class, function ($m) {
            $m->shouldReceive('list')->andThrow(new AccessDeniedHttpException('kds-denied'));
        });

        $this->actingAs($this->chef, 'sanctum')
            ->getJson('/api/admin/kds-order')
            ->assertStatus(403)                  // NOT 422
            ->assertJsonPath('message', 'kds-denied'); // reached our catch, not a middleware block
    }
}
