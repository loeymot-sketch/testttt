<?php

namespace App\Services\Concerns;

use App\Models\User;

/**
 * [USR-RBAC-02 heal 2026-06-01] Shared own-branch scoping for staff-creation services.
 *
 * A non-`settings` caller (e.g. Branch Manager) may only create/assign staff in their OWN
 * branch — a request-supplied branch_id is ignored to prevent cross-branch assignment. A
 * `settings` holder (admin, typically branch_id=0) keeps full cross-branch discretion.
 *
 * Used by EmployeeService, ChefService, WaiterService, DeliveryBoyService (CustomerService
 * hardcodes branch_id=0 and does not need it).
 */
trait EnforcesOwnBranchScope
{
    public function effectiveBranchId(?User $caller, $requestedBranchId): int
    {
        if ($caller && !$caller->can('settings')) {
            return (int) $caller->branch_id;
        }

        return (int) $requestedBranchId;
    }
}
