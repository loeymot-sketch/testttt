<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
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
                // [ONB-06 2026-08-28] Le garde est JUSTE — on ne modifie pas ses propres
                // droits — mais il se presentait comme un bug interne, en anglais, avec
                // une reference de ticket. Le commercant ne savait pas qu'il devait
                // passer par un autre compte administrateur.
                throw new Exception(trans('all.message.role_propre_non_modifiable'), 403);
            }

            // [ONB-06 2026-08-28 · P0] `syncPermissions()` de Spatie fait `detach()`
            // PUIS `givePermissionTo()`, HORS TRANSACTION
            // (`HasPermissions.php:405-410`). Si la seconde etape leve — une
            // permission d'une autre garde, un identifiant disparu entre-temps — le
            // detachement, lui, a deja ete commis : le role reste VIDE.
            //
            // C'est ce qui vidait « POS Operator » de ses 10 permissions et
            // deconnectait les caissiers. Le filtrage par garde (PermissionController)
            // ferme le chemin connu ; cette transaction ferme TOUS les autres, y
            // compris ceux qu'on n'a pas encore trouves. Un echec laisse le role
            // exactement comme il etait.
            $demandees = Permission::query()
                ->whereIn('id', (array) $request->get('permissions', []))
                ->where('guard_name', $role->guard_name)
                ->get();

            return DB::transaction(fn () => $role->syncPermissions($demandees));
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
