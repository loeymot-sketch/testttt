<?php

namespace Database\Seeders;

use App\Enums\Role as EnumRole;
use Spatie\Permission\Models\Role;

/**
 * Resolves Spatie roles by stable name + guard — not by primary key.
 *
 * Legacy code used {@see EnumRole} integer constants as if they were `roles.id`
 * values. That breaks whenever MySQL rolls back transactions (AUTO_INCREMENT is
 * not reset), so fresh RoleTableSeeder inserts no longer land on ids 1..8.
 */
final class SpatieRoleLookup
{
    public static function byLegacyId(int $legacyId): ?Role
    {
        $name = match ($legacyId) {
            EnumRole::ADMIN => 'Admin',
            EnumRole::CUSTOMER => 'Customer',
            EnumRole::DELIVERY_BOY => 'Delivery Boy',
            EnumRole::WAITER => 'Waiter',
            EnumRole::CHEF => 'Chef',
            EnumRole::BRANCH_MANAGER => 'Branch Manager',
            EnumRole::POS_OPERATOR => 'POS Operator',
            EnumRole::STUFF => 'Stuff',
            default => null,
        };

        if ($name === null) {
            return null;
        }

        return Role::query()
            ->where('name', $name)
            ->where('guard_name', 'sanctum')
            ->first();
    }
}
