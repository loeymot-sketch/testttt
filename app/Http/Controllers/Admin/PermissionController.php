<?php

namespace App\Http\Controllers\Admin;


use App\Http\Requests\PermissionRequest;
use App\Http\Requests\RoleRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Libraries\AppLibrary;
use App\Services\PermissionService;
use App\Services\RoleService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;


class PermissionController extends AdminController
{
    private PermissionService $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        parent::__construct();
        $this->permissionService = $permissionService;
        // [GOAL-CMS-2026-05-18 M-R3-P0-A heal] R3 T-2.2.1 Arch F1: Wave 5H hardening
        // gated Role/Branch/Administrator FormRequests but FORGOT Permission.
        // `index` was unprotected — any auth:sanctum holder (POS Operator, Chef,
        // stale Customer token) could enumerate full permission matrix of any
        // role via GET /admin/permission/{role}. Now all 3 mutating + reading
        // methods require permission:settings.
        $this->middleware(['permission:settings']);
    }

    public function index(Role $role)
    {
        try {
            $permissions     = Permission::get();
            $rolePermissions = Permission::join(
                "role_has_permissions",
                "role_has_permissions.permission_id",
                "=",
                "permissions.id"
            )->where("role_has_permissions.role_id", $role->id)->get()->pluck('name', 'id');
            $permissions     = AppLibrary::permissionWithAccess($permissions, $rolePermissions);
            $permissions     = AppLibrary::numericToAssociativeArrayBuilder($permissions->toArray());
            return new JsonResponse(['data' => $permissions], 201);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(PermissionRequest $request, Role $role)
    {
        try {
            $this->assertProtectedRoleKeepsAccess($role, $request);

            return new RoleResource($this->permissionService->update($request, $role));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Avant : Enregistrer les droits d'un caissier avec la liste vide
     * répondait OK. Le rôle existait encore, la caisse ouvrait en 403.
     */
    private function assertProtectedRoleKeepsAccess(Role $role, PermissionRequest $request): void
    {
        if (! in_array($role->name, RoleService::protectedRoleNames(), true)) {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', (array) $request->input('permissions', []))));
        if ($ids === []) {
            throw new Exception(
                "Impossible de vider les droits du rôle système « {$role->name} ». Le caissier / la cuisine ne pourraient plus ouvrir leur écran.",
                422
            );
        }

        if ($role->name === 'POS Operator') {
            $keepsPos = Permission::query()->whereIn('id', $ids)->where('name', 'pos')->exists();
            if (! $keepsPos) {
                throw new Exception(
                    'Le rôle « POS Operator » doit garder le droit caisse (pos).',
                    422
                );
            }
        }

        if ($role->name === 'Chef' || $role->name === 'Stuff') {
            $keepsKds = Permission::query()
                ->whereIn('id', $ids)
                ->where('name', 'kitchen-display-system')
                ->exists();
            if (! $keepsKds) {
                throw new Exception(
                    "Le rôle « {$role->name} » doit garder le droit écran cuisine (kitchen-display-system).",
                    422
                );
            }
        }

        if ($role->name === 'Waiter') {
            $keepsTables = Permission::query()
                ->whereIn('id', $ids)
                ->where('name', 'table-orders')
                ->exists();
            if (! $keepsTables) {
                throw new Exception(
                    'Le rôle « Waiter » doit garder le droit commandes de table (table-orders).',
                    422
                );
            }
        }
    }
}
