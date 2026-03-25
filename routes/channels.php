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

// [P4-1 FIX] Authorize branch-scoped channels for OrderStatusChanged / OrderCreated events
// Admin users (branch_id=0) can subscribe to any branch channel
Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    return (int) $user->branch_id === (int) $branchId || (int) $user->branch_id === 0;
});
