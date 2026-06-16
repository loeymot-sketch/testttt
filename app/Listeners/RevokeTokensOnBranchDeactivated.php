<?php

namespace App\Listeners;

use App\Enums\Status;
use App\Events\BranchStatusChanged;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * [Wave 5G R10 heal 2026-05-17] Révoque tous les Sanctum tokens des
 * utilisateurs scopés à une branche lorsque celle-ci passe en INACTIVE.
 *
 * Garde-fou critique :
 *  - No-op si pas de transition réelle ($oldStatus === $newStatus).
 *  - No-op si la nouvelle valeur n'est pas INACTIVE (cf. advisor note 1 —
 *    "newStatus === INACTIVE" seul re-firerait sur chaque save).
 *  - Scope strict `tokenable_type = User::class` pour ne PAS toucher
 *    aux tokens kiosk-machine (autre tokenable_type) — cf. task constraint
 *    "DO NOT modify User model significantly" et advisor note 8.
 */
class RevokeTokensOnBranchDeactivated
{
    public function handle(BranchStatusChanged $event): void
    {
        if ($event->oldStatus === $event->newStatus) {
            return;
        }

        if ((int) $event->newStatus !== (int) Status::INACTIVE) {
            return;
        }

        // [GENIE Wave1 CC-4 2026-06-16] Listener isolation. This runs FIRST in the
        // BranchStatusChanged cascade (security: revoke before broadcast), so an unhandled
        // throw here previously HALTED the sync dispatcher and the next listener
        // (PersistBranchStatusChangedToOutbox) never ran → the "branch INACTIVE" broadcast
        // never fired and POS/Kiosk kept operating on a disabled branch. Isolate the failure:
        // log it (so the residual security gap — tokens not revoked — is visible to ops) but
        // do NOT rethrow, so the more-critical broadcast still persists.
        try {
            $userIds = User::query()
                ->where('branch_id', $event->branchId)
                ->pluck('id')
                ->all();

            if ($userIds === []) {
                Log::channel(config('logging.channels.security') ? 'security' : 'stack')->info(
                    '[R10] Branch deactivated, 0 tokens to revoke (no users in branch)',
                    [
                        'event'     => 'branch.status_changed',
                        'branch_id' => $event->branchId,
                        'gate'      => 'wave-5g-r10-heal',
                    ]
                );
                return;
            }

            $revokedCount = PersonalAccessToken::query()
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $userIds)
                ->delete();

            Log::channel(config('logging.channels.security') ? 'security' : 'stack')->warning(
                '[R10] Branch deactivated, Sanctum tokens revoked',
                [
                    'event'         => 'branch.status_changed.tokens_revoked',
                    'branch_id'     => $event->branchId,
                    'user_count'    => count($userIds),
                    'revoked_count' => (int) $revokedCount,
                    'gate'          => 'wave-5g-r10-heal',
                ]
            );
        } catch (\Throwable $e) {
            // [CC-4] Do NOT let a token-revocation failure halt the cascade — the INACTIVE
            // broadcast (next listener) MUST still fire. Surface the security gap to ops.
            Log::channel(config('logging.channels.security') ? 'security' : 'stack')->error(
                '[CC-4] Branch token revocation FAILED — broadcast preserved, tokens may still be live',
                [
                    'event'     => 'branch.status_changed.tokens_revoke_failed',
                    'branch_id' => $event->branchId,
                    'exception' => $e->getMessage(),
                    'gate'      => 'genie-wave1-cc4',
                ]
            );
        }
    }
}
