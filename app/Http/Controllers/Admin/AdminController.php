<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AdminController extends Controller
{
    public function __construct()
    {

    }

    protected function authorizeBranchScope(Request $request, ?int $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        $user = $request->user();
        if ($user && ($user->hasRole('Admin') || $user->hasRole('Tenant Admin'))) {
            return;
        }

        abort_if(! $user || (int) $user->branch_id !== (int) $branchId, 403);
    }

    protected function authorizeWritableBranchScope(Request $request, ?int $branchId): void
    {
        $user = $request->user();
        abort_if(! $user, 403);

        if ($user->hasRole('Admin') || $user->hasRole('Tenant Admin')) {
            return;
        }

        abort_if($branchId === null, 403);
        abort_if((int) $user->branch_id !== (int) $branchId, 403);
    }
}
