<?php

namespace Tests\Feature\Branch;

use App\Enums\Status;
use App\Events\BranchStatusChanged;
use App\Listeners\RevokeTokensOnBranchDeactivated;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * [Wave 5G R10 heal 2026-05-17] Sentinel for RED-team R10:
 *   "Admin setting branch INACTIVE doesn't revoke tokens until 480-min TTL"
 *
 * Asserts:
 *   1. ACTIVE -> INACTIVE transition revokes all PersonalAccessTokens owned
 *      by users belonging to the deactivated branch (tokenable_type = User).
 *   2. Same status save (oldStatus === newStatus) is a no-op even if the
 *      "new" value is INACTIVE (advisor note 1 — must not re-fire on every save).
 *   3. INACTIVE -> ACTIVE transition does NOT revoke tokens.
 *   4. Tokens of users in OTHER branches are not touched (scope-strict).
 *   5. Kiosk-machine tokens (different tokenable_type) are not touched
 *      (must not modify User model significantly — advisor note 8).
 */
class BranchDeactivationTokenRevokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_to_inactive_revokes_user_tokens_in_that_branch(): void
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $user1  = User::factory()->create(['branch_id' => $branch->id]);
        $user2  = User::factory()->create(['branch_id' => $branch->id]);

        $user1->createToken('pos-login', ['*']);
        $user2->createToken('pos-login', ['*']);

        $this->assertSame(2, PersonalAccessToken::query()->count());

        $listener = app(RevokeTokensOnBranchDeactivated::class);
        $listener->handle(new BranchStatusChanged(
            (int) $branch->id,
            Status::ACTIVE,
            Status::INACTIVE
        ));

        $this->assertSame(
            0,
            PersonalAccessToken::query()->count(),
            'All Sanctum tokens of users in the deactivated branch must be revoked.'
        );
    }

    public function test_same_status_save_is_a_noop(): void
    {
        $branch = Branch::factory()->create(['status' => Status::INACTIVE]);
        $user   = User::factory()->create(['branch_id' => $branch->id]);
        $user->createToken('staff', ['*']);

        $listener = app(RevokeTokensOnBranchDeactivated::class);
        // Re-fire with oldStatus === newStatus === INACTIVE.
        $listener->handle(new BranchStatusChanged(
            (int) $branch->id,
            Status::INACTIVE,
            Status::INACTIVE
        ));

        $this->assertSame(
            1,
            PersonalAccessToken::query()->count(),
            'No-transition save must not revoke any token.'
        );
    }

    public function test_inactive_to_active_does_not_revoke_tokens(): void
    {
        $branch = Branch::factory()->create(['status' => Status::INACTIVE]);
        $user   = User::factory()->create(['branch_id' => $branch->id]);
        $user->createToken('staff', ['*']);

        $listener = app(RevokeTokensOnBranchDeactivated::class);
        $listener->handle(new BranchStatusChanged(
            (int) $branch->id,
            Status::INACTIVE,
            Status::ACTIVE
        ));

        $this->assertSame(
            1,
            PersonalAccessToken::query()->count(),
            'Reactivation must not revoke any token.'
        );
    }

    public function test_does_not_touch_users_in_other_branches(): void
    {
        $targetBranch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $otherBranch  = Branch::factory()->create(['status' => Status::ACTIVE]);

        $targetUser = User::factory()->create(['branch_id' => $targetBranch->id]);
        $otherUser  = User::factory()->create(['branch_id' => $otherBranch->id]);

        $targetUser->createToken('staff', ['*']);
        $otherUser->createToken('staff', ['*']);

        $listener = app(RevokeTokensOnBranchDeactivated::class);
        $listener->handle(new BranchStatusChanged(
            (int) $targetBranch->id,
            Status::ACTIVE,
            Status::INACTIVE
        ));

        $remaining = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $otherUser->id)
            ->count();

        $this->assertSame(
            1,
            $remaining,
            'Tokens of users in a different branch must remain untouched.'
        );
    }

    public function test_does_not_touch_non_user_tokenable_types(): void
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $user   = User::factory()->create(['branch_id' => $branch->id]);
        $user->createToken('staff', ['*']);

        // Simulate a kiosk-machine token (different tokenable_type) directly
        // via DB so we bypass PersonalAccessToken's mass-assignable filter,
        // which excludes tokenable_type / tokenable_id. This asserts the
        // listener's scope-strict filter on User::class only.
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\KioskMachine',
            'tokenable_id'   => 999,
            'name'           => 'kiosk-1',
            'token'          => hash('sha256', 'kiosk-machine-token'),
            'abilities'      => json_encode(['kiosk:order']),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $listener = app(RevokeTokensOnBranchDeactivated::class);
        $listener->handle(new BranchStatusChanged(
            (int) $branch->id,
            Status::ACTIVE,
            Status::INACTIVE
        ));

        $kioskTokens = PersonalAccessToken::query()
            ->where('tokenable_type', 'App\\Models\\KioskMachine')
            ->count();

        $this->assertSame(
            1,
            $kioskTokens,
            'Kiosk-machine tokens (different tokenable_type) must NOT be revoked when a branch deactivates.'
        );
        $this->assertSame(
            0,
            PersonalAccessToken::query()
                ->where('tokenable_type', User::class)
                ->count(),
            'User-tokenable tokens in the deactivated branch must still be revoked.'
        );
    }
}
