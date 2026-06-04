<?php

namespace App\Models\Scopes;

use App\Models\User;
use App\Traits\DefaultAccessModelTrait;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

/**
 * [2026-05-18 PR-D blocker heal] BranchScope variant for tables whose
 * branch-isolation column is **nullable** and acts as "global OR my branch"
 * rather than the strict equality `BranchScope` enforces.
 *
 * Concretely: `item_wizard_profiles.branch_id_scope` is nullable —
 * NULL = profile published globally for all branches; INT = profile
 * scoped to that branch only. The default `BranchScope` would 500 on
 * SQL `unknown column 'branch_id'` AND would also silently hide every
 * global profile from staff queries. This scope:
 *
 *   - Admin (branch_id=0)              → no filter (sees everything)
 *   - Staff with branch_id > 0         → `WHERE branch_id_scope IS NULL
 *                                       OR branch_id_scope = <user_branch>`
 *   - Authenticatable models (User)    → never apply (Sanctum recursion guard,
 *                                       mirrors BranchScope.php:21-23)
 *   - Console without authenticated unit-test context → no filter
 *
 * Sister test: tests/Feature/Multitenant/WizardProfileBranchScopeTest.php
 */
class WizardProfileBranchScope implements Scope
{
    use DefaultAccessModelTrait;

    public function apply(Builder $builder, Model $model)
    {
        if ($model instanceof User) {
            return;
        }

        if ((! App::runningInConsole() || App::runningUnitTests()) && Auth::check()) {
            $userBranch = $this->branch();

            // Admin: sees every profile across branches.
            if ($userBranch === 0) {
                return;
            }

            $field = sprintf('%s.%s', $builder->getQuery()->from, 'branch_id_scope');
            $builder->where(function ($q) use ($field, $userBranch): void {
                $q->whereNull($field)
                  ->orWhere($field, '=', $userBranch);
            });
        }
    }
}
