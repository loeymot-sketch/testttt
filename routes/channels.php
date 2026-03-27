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
// Without the token ability check, a kiosk token could subscribe to ALL branch channels
// (same as an admin) — a privilege escalation. Kiosk tokens carry 'kiosk:order' ability
// and must be restricted to the branch of their KioskMachine record.
Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    // Kiosk machine token: restrict to the machine's own branch
    if ($user->currentAccessToken() && $user->tokenCan('kiosk:order')) {
        $machine = \App\Models\KioskMachine::where('user_id', $user->id)->first();
        return $machine && (int) $machine->branch_id === (int) $branchId;
    }

    // Admin users (branch_id=0) can subscribe to any branch channel
    if ((int) $user->branch_id === 0) {
        return true;
    }

    // Regular staff: own branch only
    return (int) $user->branch_id === (int) $branchId;
});
