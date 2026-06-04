<?php

namespace App\Services;

use Exception;
use App\Libraries\AppLibrary;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\PermissionRequest;
use App\Libraries\QueryExceptionLibrary;
use Spatie\Permission\Models\Permission;

class PermissionService
{

    /**
     * @throws Exception
     */
    public function permission(Role $role) : object
    {
        try {
            $permissions     = Permission::get();
            $rolePermissions = Permission::join(
                "role_has_permissions",
                "role_has_permissions.permission_id",
                "=",
                "permissions.id"
            )->where("role_has_permissions.role_id", $role->id)->get()->pluck('name', 'id');
            return AppLibrary::permissionWithAccess($permissions, $rolePermissions);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(PermissionRequest $request, Role $role) : Role
    {
        try {
            // [GOAL-CMS-2026-05-18 M-R3-P0-D heal] R3 T-2.2.1 Sec S-2:
            // Self-permission-sync escalation. PermissionService::update calls
            // $role->syncPermissions(WRITE, not merge) with no guard against
            // the caller modifying their OWN role. Combined with the legacy
            // PermissionRequest::authorize() return-true (closed by parallel
            // BUILD-6 to require permission:settings), an admin with `settings`
            // could grant themselves the FULL permission set in 2 HTTP calls.
            // Now forbid syncing permissions on a role the caller holds.
            $caller = $request->user();
            if ($caller && $caller->roles->contains('id', $role->id)) {
                throw new Exception(
                    'Cannot modify permissions on your own role — privilege-escalation guard '
                    . '(R3 T-2.2.1 Sec S-2).',
                    403
                );
            }

            return $role->syncPermissions(Permission::whereIn('id', $request->get('permissions'))->get());
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
