<?php

namespace App\Services;

use Exception;
use App\Enums\Role as EnumsRole;
use App\Http\Requests\RoleRequest;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;

class RoleService
{
    protected array $roleFilter = [
        'name'
    ];
    protected array $exceptFilter = [
        'excepts'
    ];
    protected array $roleArray = [
        EnumsRole::ADMIN, EnumsRole::CUSTOMER, EnumsRole::DELIVERY_BOY, EnumsRole::WAITER, EnumsRole::CHEF
    ];

    /** Noms système : l'id auto-incrémenté n'est PAS une garantie (un rôle métier peut recevoir l'id 7). */
    private const PROTECTED_ROLE_NAMES = [
        'Admin',
        'Customer',
        'Delivery Boy',
        'Waiter',
        'Chef',
        'Branch Manager',
        'POS Operator',
        'Stuff',
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'asc';

            return Role::withCount('users')->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->roleFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->where('id', '!=', $explode);
                            }
                        }
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function store(RoleRequest $request)
    {
        try {
            return Role::create($request->validated() + ['guard_name' => 'sanctum']);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(RoleRequest $request, Role $role)
    {
        try {
            $newName = (string) ($request->validated()['name'] ?? $role->name);
            // Avant : on ne protégeait que la corbeille. Renommer « POS Operator »
            // puis supprimer passait : le caissier n'avait plus de rôle.
            if ($this->isProtectedRole($role) && $newName !== $role->name) {
                throw new Exception(
                    "Impossible de renommer le rôle système « {$role->name} ».",
                    422
                );
            }
            return tap($role)->update($request->validated());
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public static function protectedRoleNames(): array
    {
        return self::PROTECTED_ROLE_NAMES;
    }

    /**
     * @throws Exception
     */
    public function destroy(Role $role): void
    {
        try {
            // Avant : seuls les ids 1–5 étaient bloqués. « POS Operator » (souvent
            // id 7) partait à la corbeille : le caissier n'ouvrait plus la caisse.
            if ($this->isProtectedRole($role)) {
                throw new Exception(
                    "Impossible de supprimer le rôle « {$role->name} » : c'est un rôle système (caisse, cuisine, admin…). Créez un rôle métier à la place.",
                    422
                );
            }
            $role->delete();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    private function isProtectedRole(Role $role): bool
    {
        return in_array($role->name, self::PROTECTED_ROLE_NAMES, true);
    }

    /**
     * @throws Exception
     */
    public function show(Role $role): Role
    {
        try {
            return $role;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
