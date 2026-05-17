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
    }
}
