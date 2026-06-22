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

    // [VGAP-01 close] orderItems() got the same [KDS-02] catch (line 62-66) but was never runtime-exercised
    // — only the source text was verified. Prove the HttpException branch actually fires at runtime, not
    // just that the bytes exist. (CAISSE-S1 lesson: source-grep is not proof a consumer behaves.)
    public function test_order_items_preserves_http_exception_status_instead_of_flattening_to_422(): void
    {
        $this->mock(KitchenDisplaySystemOrderService::class, function ($m) {
            $m->shouldReceive('orderItems')->andThrow(new AccessDeniedHttpException('kds-items-denied'));
        });

        $this->actingAs($this->chef, 'sanctum')
            ->getJson('/api/admin/kds-order/items')
            ->assertStatus(403)                       // NOT 422
            ->assertJsonPath('message', 'kds-items-denied');
    }

    // [VGAP-01 close] historyToday() got the same [KDS-02] catch (line 82-86), also never runtime-exercised.
    public function test_history_today_preserves_http_exception_status_instead_of_flattening_to_422(): void
    {
        $this->mock(KitchenDisplaySystemOrderService::class, function ($m) {
            $m->shouldReceive('historyToday')->andThrow(new AccessDeniedHttpException('kds-history-denied'));
        });

        $this->actingAs($this->chef, 'sanctum')
            ->getJson('/api/admin/kds-order/history-today')
            ->assertStatus(403)                       // NOT 422
            ->assertJsonPath('message', 'kds-history-denied');
    }

    // [VGAP-01 close] Guard the OTHER half: a genuine data error (plain \Exception) must STILL flatten to 422
    // on these two methods. Proves the new HttpException catch didn't accidentally widen and swallow the
    // generic branch. Without this, the heal could regress to "everything is 403" undetected.
    public function test_order_items_still_flattens_plain_exception_to_422(): void
    {
        $this->mock(KitchenDisplaySystemOrderService::class, function ($m) {
            $m->shouldReceive('orderItems')->andThrow(new \RuntimeException('boom'));
        });

        $this->actingAs($this->chef, 'sanctum')
            ->getJson('/api/admin/kds-order/items')
            ->assertStatus(422)
            // [GENIE Wave2 A1] the controller now routes the unexpected exception through
            // Controller::jsonError → status still 422, but the raw 'boom' is genericized (no leak).
            ->assertJsonPath('message', __('all.message.something_wrong'))
            ->assertJsonMissing(['message' => 'boom']);
    }

    public function test_history_today_still_flattens_plain_exception_to_422(): void
    {
        $this->mock(KitchenDisplaySystemOrderService::class, function ($m) {
            $m->shouldReceive('historyToday')->andThrow(new \RuntimeException('boom'));
        });

        $this->actingAs($this->chef, 'sanctum')
            ->getJson('/api/admin/kds-order/history-today')
            ->assertStatus(422)
            // [GENIE Wave2 A1] the controller now routes the unexpected exception through
            // Controller::jsonError → status still 422, but the raw 'boom' is genericized (no leak).
            ->assertJsonPath('message', __('all.message.something_wrong'))
            ->assertJsonMissing(['message' => 'boom']);
    }
}
