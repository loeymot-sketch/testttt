<?php

namespace App\Http\Controllers\Admin;


use App\Http\Requests\PermissionRequest;
use App\Http\Requests\RoleRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Libraries\AppLibrary;
use App\Services\PermissionService;
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
            // [ONB-06 2026-08-28 · P0] `Permission::get()` listait TOUTES les gardes.
            // Quatre permissions existent sous `sanctum` ET sous `web`
            // (`availability_toggle`, `ingredients_manage`, `kitchen-display-system`,
            // `pos-flyer-print`) : deux semoirs les creent en bouclant
            // `foreach (['sanctum','web'] as $guard)`. L'ecran affichait donc quatre
            // paires de lignes RIGOUREUSEMENT IDENTIQUES — meme libelle, rien pour les
            // distinguer.
            //
            // Cocher la mauvaise jumelle sur un role `sanctum` faisait lever
            // `GuardDoesNotMatch` par Spatie — APRES que `syncPermissions()` ait deja
            // detache toutes les permissions. Le role finissait a ZERO : mesure sur la
            // base reelle, « POS Operator » passait de 10 permissions a 0, et tous les
            // caissiers perdaient l'acces a la caisse.
            //
            // On ne montre plus que ce qui est attribuable a CE role.
            $permissions     = Permission::query()
                ->where('guard_name', $role->guard_name)
                ->get();
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
            return new RoleResource($this->permissionService->update($request, $role));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
