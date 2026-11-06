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
            // [fusion 2026-09-02] La garde ne mord que si le rôle DÉTIENT déjà ce droit.
            // Écrite sans cette condition, elle exigeait que le rôle le possède TOUJOURS —
            // et refusait alors toute réduction de droits sur un rôle qui ne l'avait jamais
            // eu (constaté par UnRoleNeSeVidePasSurUnClicTest, dont les droits de fixture
            // s'appellent droit_1..droit_10). Ce qu'on protège, c'est le RETRAIT du droit,
            // pas son absence.
            $detientDeja = $role->permissions->contains('name', 'pos');
            if ($detientDeja && ! $keepsPos) {
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
            // [fusion 2026-09-02] La garde ne mord que si le rôle DÉTIENT déjà ce droit.
            // Écrite sans cette condition, elle exigeait que le rôle le possède TOUJOURS —
            // et refusait alors toute réduction de droits sur un rôle qui ne l'avait jamais
            // eu (constaté par UnRoleNeSeVidePasSurUnClicTest, dont les droits de fixture
            // s'appellent droit_1..droit_10). Ce qu'on protège, c'est le RETRAIT du droit,
            // pas son absence.
            $detientDeja = $role->permissions->contains('name', 'kitchen-display-system');
            if ($detientDeja && ! $keepsKds) {
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
            // [fusion 2026-09-02] La garde ne mord que si le rôle DÉTIENT déjà ce droit.
            // Écrite sans cette condition, elle exigeait que le rôle le possède TOUJOURS —
            // et refusait alors toute réduction de droits sur un rôle qui ne l'avait jamais
            // eu (constaté par UnRoleNeSeVidePasSurUnClicTest, dont les droits de fixture
            // s'appellent droit_1..droit_10). Ce qu'on protège, c'est le RETRAIT du droit,
            // pas son absence.
            $detientDeja = $role->permissions->contains('name', 'table-orders');
            if ($detientDeja && ! $keepsTables) {
                throw new Exception(
                    'Le rôle « Waiter » doit garder le droit commandes de table (table-orders).',
                    422
                );
            }
        }
    }
}
