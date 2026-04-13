<?php

namespace App\Models\Scopes;

use App\Models\User;
use App\Traits\DefaultAccessModelTrait;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

class BranchScope implements Scope
{
    use DefaultAccessModelTrait;

    public function apply(Builder $builder, Model $model)
    {
        // Never branch-filter the authenticatable model: Sanctum resolves the user via User queries.
        // Calling Auth::check() here would re-enter the guard → infinite recursion (see stack: BranchScope ↔ Sanctum).
        if ($model instanceof User) {
            return;
        }

        // PHPUnit runs in the console; still apply scope when APP_ENV=testing so HTTP/feature tests
        // that use actingAs() match production isolation (see BranchScopeTest).
        if ((!App::runningInConsole() || App::runningUnitTests()) && Auth::check()) {
            $field = sprintf('%s.%s', $builder->getQuery()->from, 'branch_id');
            $userBranch = $this->branch();

            // [FIX-54-8] Only admins (branch_id = 0) can see cross-branch records.
            // Regular staff should NEVER see records with branch_id = 0.
            if ($userBranch === 0) {
                // Admin: no filter applied — sees all branches including branch_id=0 rows
                return;
            }

            // Staff: only their own branch — never expose branch_id=0 rows
            $builder->where($field, '=', $userBranch);
        }
    }
}
