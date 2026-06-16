<?php

/**
 * [GENIE Wave 1 — CC-4 2026-06-16] Listener isolation on the BranchStatusChanged cascade.
 *
 * The cascade is [RevokeTokensOnBranchDeactivated (security: revoke first), PersistBranchStatusChangedToOutbox
 * (broadcast)]. If the token revocation throws (DB hiccup) it previously HALTED the sync dispatcher → the
 * "branch INACTIVE" broadcast never fired → POS/Kiosk keep operating on a disabled branch. Heal: RevokeTokens
 * isolates its own failure (log, no rethrow) so the outbox/broadcast still persists. Latent in single-branch
 * V1, real at multi-branch — "structuré pour le futur".
 */

namespace Tests\Feature\Branch;

use App\Enums\Status;
use App\Events\BranchStatusChanged;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchStatusCascadeIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_token_revocation_failure_does_not_halt_the_outbox_broadcast(): void
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        User::factory()->create(['branch_id' => $branch->id]); // non-empty → reaches the token delete

        // Force the token-revocation query to throw mid-cascade.
        Schema::drop('personal_access_tokens');

        $before = DomainEvent::query()->where('branch_id', $branch->id)->count();

        // Fire the FULL cascade (both listeners, in order). RevokeTokens will throw internally.
        event(new BranchStatusChanged(
            branchId: $branch->id,
            oldStatus: (int) Status::ACTIVE,
            newStatus: (int) Status::INACTIVE,
        ));

        // The broadcast listener MUST still have run despite the revocation failure.
        $this->assertSame(
            $before + 1,
            DomainEvent::query()->where('branch_id', $branch->id)->count(),
            'Outbox broadcast must persist even when token revocation fails (listener isolation).'
        );
    }
}
