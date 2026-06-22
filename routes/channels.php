<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// [P4-1 FIX] Authorize branch-scoped channels for OrderStatusChanged / OrderCreated events.
// [GAP-21-5] Kiosk machine users have branch_id=0 (they use the admin user as owner).
// Without the token-name check, a kiosk token could subscribe to ALL branch channels
// (same as an admin) — a privilege escalation. Kiosk tokens are minted with name
// 'kiosk-token' (KioskMachineLoginController:99) and must be restricted to the branch
// of their KioskMachine record.
//
// [GOAL-CMS-2026-05-18 S-R3-P0-G heal] R3 T-3.2.2 Sec F-SEC-W6-01:
// Previously used $user->tokenCan('kiosk:order') as the kiosk discriminator. But
// LoginController:113 mints admin/staff tokens with abilities=['*'] (wildcard) and
// Sanctum's PersonalAccessToken::can() returns TRUE for any ability when '*' is
// present — so every admin/staff token ALSO satisfied tokenCan('kiosk:order'),
// routing them through the kiosk branch where they were denied (no KioskMachine
// row). Real KDS/POS staff silently lost real-time push (masked by polling
// fallback). A one-line refactor flipped this to over-grant; now we use the
// TOKEN NAME directly (un-spoofable, never wildcard-matched).
//
// Also (R3 T-3.2.2 Sec F-SEC-W6-02 latent): admin-zero clause now requires an
// explicit role check (Admin or Tenant Admin) instead of a bare branch_id===0
// sentinel. Closes the Guest-Echo-Bypass latent path (customers and guests
// signed up with branch_id=0 by default).
Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    // Kiosk machine token: restrict to the machine's own branch.
    // Token-name check (NOT tokenCan) — immune to Sanctum '*' wildcard.
    $tokenName = $user->currentAccessToken()?->name;
    if ($tokenName === 'kiosk-token') {
        $machine = \App\Models\KioskMachine::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('user_id', $user->id)
            ->first();
        return $machine && (int) $machine->branch_id === (int) $branchId;
    }

    // Admin / Tenant Admin: cross-branch subscription allowed.
    // ROLE check (not bare branch_id===0) closes the Guest-Echo-Bypass —
    // customers + guests are seeded with branch_id=0 and must NOT subscribe
    // to arbitrary branch channels.
    if ($user->hasRole('Admin') || $user->hasRole('Tenant Admin')) {
        return true;
    }

    // Regular staff: own branch only
    return (int) $user->branch_id === (int) $branchId;
});
