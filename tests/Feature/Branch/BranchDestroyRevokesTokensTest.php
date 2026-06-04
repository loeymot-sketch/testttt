<?php

namespace Tests\Feature\Branch;

use App\Enums\Status;
use App\Events\BranchStatusChanged;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [P1 V1 Cloud-Prep insights 2026-05-18] BranchController::destroy must
 * fire BranchStatusChanged so the RevokeTokensOnBranchDeactivated listener
 * revokes user tokens scoped to that branch — same as status flip to
 * INACTIVE via update() (Wave 5G R10). Closes orphan-token gap:
 * previously, soft-deleting a branch left auth_tokens valid up to the
 * 480-min Sanctum TTL.
 *
 * Mirror test_active_to_inactive_revokes_user_tokens_in_that_branch from
 * BranchDeactivationTokenRevokeTest.php but driven through the HTTP DELETE
 * endpoint instead of the listener directly, so we lock the dispatch site
 * in the controller as well as the listener behavior.
 */
class BranchDestroyRevokesTokensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    /** @test */
    public function test_branch_destroy_fires_branch_status_changed_event(): void
    {
        Event::fake([BranchStatusChanged::class]);

        $defaultBranch = Branch::factory()->create(['status' => Status::ACTIVE]);
        Settings::group('site')->set('site_default_branch', $defaultBranch->id);

        // Target branch must NOT be the default branch — BranchService::destroy
        // refuses to delete the default branch (throws "Default branch not deletable").
        $target = Branch::factory()->create(['status' => Status::ACTIVE]);

        $admin = User::factory()->create(['branch_id' => $defaultBranch->id, 'status' => Status::ACTIVE]);
        $admin->assignRole('Admin');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/setting/branch/{$target->id}")
            ->assertStatus(202);

        Event::assertDispatched(BranchStatusChanged::class, function ($event) use ($target) {
            return $event->branchId === (int) $target->id
                && $event->newStatus === Status::INACTIVE;
        });
    }

    /** @test */
    public function test_branch_destroy_revokes_tokens_of_users_in_that_branch(): void
    {
        $defaultBranch = Branch::factory()->create(['status' => Status::ACTIVE]);
        Settings::group('site')->set('site_default_branch', $defaultBranch->id);

        $target = Branch::factory()->create(['status' => Status::ACTIVE]);

        $admin = User::factory()->create(['branch_id' => $defaultBranch->id, 'status' => Status::ACTIVE]);
        $admin->assignRole('Admin');

        $staffInTarget = User::factory()->create(['branch_id' => $target->id, 'status' => Status::ACTIVE]);
        $staffInTarget->createToken('pos-login', ['*']);
        $staffInTarget->createToken('staff', ['*']);

        $this->assertSame(2, PersonalAccessToken::query()->where('tokenable_id', $staffInTarget->id)->count());

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/setting/branch/{$target->id}")
            ->assertStatus(202);

        $this->assertSame(
            0,
            PersonalAccessToken::query()->where('tokenable_id', $staffInTarget->id)->count(),
            'All Sanctum tokens of users in the soft-deleted branch must be revoked by the listener.'
        );
    }
}
