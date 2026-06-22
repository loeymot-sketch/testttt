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
            return $this->jsonError($exception, 422);
        }
    }

    public function update(PermissionRequest $request, Role $role)
    {
        try {
            return new RoleResource($this->permissionService->update($request, $role));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}
