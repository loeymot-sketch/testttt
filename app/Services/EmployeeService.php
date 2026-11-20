<?php

namespace App\Services;

use Exception;
use App\Enums\Ask;
use App\Models\User;
use App\Enums\Role as EnumRole;
use Spatie\Permission\Models\Role;
use App\Services\Concerns\EnforcesOwnBranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\EmployeeRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\ChangeImageRequest;
use App\Http\Requests\UserChangePasswordRequest;


class EmployeeService
{
    use EnforcesOwnBranchScope;

    public $user;
    public $phoneFilter = ['phone'];
    public $roleFilter = ['role_id'];
    public $userFilter = ['name', 'email', 'username', 'branch_id', 'status', 'phone'];
    public $blockRoles = [EnumRole::ADMIN, EnumRole::CUSTOMER, EnumRole::DELIVERY_BOY, EnumRole::WAITER, EnumRole::CHEF];


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
            $orderType   = $request->get('order_type') ?? 'desc';

            return User::with('media', 'addresses', 'roles')->where(
                function ($query) use ($requests) {
                    $query->whereHas('roles', function ($query) {
                        $query->where('id', '!=', EnumRole::ADMIN);
                        $query->where('id', '!=', EnumRole::CUSTOMER);
                        $query->where('id', '!=', EnumRole::DELIVERY_BOY);
                        $query->where('id', '!=', EnumRole::WAITER);
                        $query->where('id', '!=', EnumRole::CHEF);
                    });
                    foreach ($requests as $key => $request) {
                        if (in_array($key, $this->roleFilter)) {
                            $query->whereHas('roles', function ($query) use ($request, $key) {
                                $query->where('id', '=', $request);
                            });
                        }
                        if (in_array($key, $this->userFilter)) {
                            $query->where($key, 'like', '%' . $request . '%');
                        }
                    }
                }
            )->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [USR-RBAC-01 heal 2026-06-01] Caller-entitlement gate for role grants.
     *
     * Prevents privilege escalation + peer-cloning via the employee endpoint. A `settings`
     * holder (admin) may grant any non-core role; a non-settings staff (e.g. Branch Manager)
     * may grant ONLY a role whose permission set is a STRICT SUBSET of the caller's own —
     * i.e. the caller holds every permission of the target role AND the target has strictly
     * fewer (blocks granting a permission the caller lacks, and blocks cloning a peer).
     */
    public function callerMayGrantRole(?User $caller, $roleId): bool
    {
        if (!$caller) {
            return false;
        }
        if ($caller->can('settings')) {
            return true;
        }
        $role = Role::find($roleId);
        if (!$role) {
            return false;
        }
        $callerPerms = $caller->getAllPermissions()->pluck('name');
        $targetPerms = $role->permissions->pluck('name');

        return $targetPerms->diff($callerPerms)->isEmpty()
            && $targetPerms->count() < $callerPerms->count();
    }

    public function store(EmployeeRequest $request)
    {
        try {
            if (!in_array($request->role_id, $this->blockRoles)) {
                if (!$this->callerMayGrantRole(auth()->user(), $request->role_id)) {
                    throw new Exception(trans('all.message.permission_denied'), 422);
                }
                DB::transaction(function () use ($request) {
                    $this->user = User::create([
                        'name'              => $request->name,
                        'email'             => $request->email,
                        'phone'             => $request->phone,
                        'username'          => $this->username($request->email),
                        'password'          => bcrypt($request->password),
                        'branch_id'         => $this->effectiveBranchId(auth()->user(), $request->branch_id),
                        'status'            => $request->status,
                        'email_verified_at' => now(),
                        'country_code'      => $request->country_code,
                        'is_guest'          => Ask::NO,
                    ]);

                    $this->user->assignRole($request->role_id);
                });
                return $this->user;
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(EmployeeRequest $request, User $employee)
    {
        try {
            if (!in_array($request->role_id, $this->blockRoles) && !in_array(
                optional($employee->roles[0])->id,
                $this->blockRoles
            )) {
                if (!$this->callerMayGrantRole(auth()->user(), $request->role_id)) {
                    throw new Exception(trans('all.message.permission_denied'), 422);
                }
                // [AUDIT ABUSIF 2026-11-06 · W4-P1-2] Le rôle ACTUEL de la cible compte aussi (symétrie destroy) : un Branch
                // Manager rétrogradait un PAIR en « Stuff » en changeant son e-mail et son mot de passe, puis se connectait
                // à son compte. On ne modifie que quelqu'un dont on pourrait attribuer le rôle actuel.
                if ((int) $employee->id !== (int) optional(auth()->user())->id
                    && !$this->callerMayGrantRole(auth()->user(), optional($employee->roles[0])->id)) {
                    throw new Exception(trans('all.message.permission_denied'), 422);
                }
                $identifiantsChanges = $request->password || mb_strtolower((string) $request->email) !== mb_strtolower((string) $employee->email);
                DB::transaction(function () use ($employee, $request) {
                    $this->user               = $employee;
                    $this->user->name         = $request->name;
                    $this->user->email        = $request->email;
                    $this->user->phone        = $request->phone;
                    $this->user->branch_id    = $this->effectiveBranchId(auth()->user(), $request->branch_id);
                    $this->user->status       = $request->status;
                    $this->user->country_code = $request->country_code;
                    if ($request->password) {
                        $this->user->password = Hash::make($request->password);
                    }
                    $this->user->save();
                    // [USR-RBAC-03 heal 2026-06-01] syncRoles inside the transaction — atomic with
                    // the user update (was outside: a failed sync left the user mutated but role stale).
                    $this->user->syncRoles($request->role_id);
                });
                // [AUDIT ABUSIF 2026-11-06 · W4-P2] Mot de passe ou e-mail changé par un tiers : les sessions ouvertes du compte
                // sont fermées (sinon l'ancien détenteur — ou celui qui a pris le compte — reste connecté jusqu'à 8 h).
                if ($identifiantsChanges && (int) $employee->id !== (int) optional(auth()->user())->id) {
                    $employee->tokens()->delete();
                }
                return $this->user;
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(User $employee): User
    {
        try {
            if (!in_array(optional($employee->roles[0])->id, $this->blockRoles)) {
                return $employee;
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */

    public function destroy(User $employee)
    {
        try {
            if (!in_array(optional($employee->roles[0])->id, $this->blockRoles)) {
                // [F-EMPLOYEE-PEER-GUARD 2026-07-15 / P2] Refuser la suppression d'un PAIR de
                // privilège égal/supérieur (destruction horizontale) — symétrie store/update.
                if (!$this->callerMayGrantRole(auth()->user(), optional($employee->roles[0])->id)) {
                    throw new Exception(trans('all.message.permission_denied'), 422);
                }
                if ($employee->hasRole(optional($employee->roles[0])->id)) {
                    DB::transaction(function () use ($employee) {
                        $employee->addresses()->delete();
                        // [AUDIT ABUSIF 2026-11-06 · W4-P1-1] Un employé supprimé gardait ses jetons (accès admin 200 jusqu'à 8 h).
                        $employee->tokens()->delete();
                        $employee->delete();
                    });
                } else {
                    throw new Exception(trans('all.message.permission_denied'), 422);
                }
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    private function username($email): string
    {
        $emails = explode('@', $email);
        return $emails[0] . mt_rand();
    }

    /**
     * @throws Exception
     */
    public function changePassword(UserChangePasswordRequest $request, User $employee): User
    {
        try {
            if (!in_array(optional($employee->roles[0])->id, $this->blockRoles)) {
                // [F-EMPLOYEE-PEER-GUARD 2026-07-15 / P2] Symétrie avec store/update :
                // refuser d'opérer sur un PAIR de privilège égal/supérieur (callerMayGrantRole
                // = sous-ensemble strict). Sans ça, un Branch Manager pouvait réinitialiser le
                // mot de passe d'un PAIR Branch Manager = account takeover horizontal, alors que
                // le clonage d'un pair est justement refusé par store().
                if (!$this->callerMayGrantRole(auth()->user(), optional($employee->roles[0])->id)) {
                    throw new Exception(trans('all.message.permission_denied'), 422);
                }
                $employee->password = Hash::make($request->password);
                $employee->save();

                // [MULTI-DEVICE 2026-08-07 — régression fermée]
                // Un responsable qui réinitialise le mot de passe d'un employé
                // le fait le plus souvent pour lui COUPER l'accès (départ,
                // soupçon de partage de compte). Les sessions ouvertes de cet
                // employé doivent donc tomber.
                //
                // Ce chemin s'appuyait implicitement sur la révocation totale
                // que `LoginController` faisait à la reconnexion suivante. En
                // la scopant à l'appareil pour permettre le multi-terminaux,
                // ce garde-fou a disparu : l'employé restait connecté sur son
                // téléphone malgré le changement.
                $employee->tokens()->delete();

                return $employee;
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function changeImage(ChangeImageRequest $request, User $employee): User
    {
        try {
            if (!in_array(optional($employee->roles[0])->id, $this->blockRoles)) {
                // [F-EMPLOYEE-PEER-GUARD 2026-07-15 / P2] Refuser la modification d'image d'un
                // PAIR de privilège égal/supérieur (défacement) — symétrie store/update.
                if (!$this->callerMayGrantRole(auth()->user(), optional($employee->roles[0])->id)) {
                    throw new Exception(trans('all.message.permission_denied'), 422);
                }
                if ($request->image) {
                    $employee->clearMediaCollection('profile');
                    $employee->addMediaFromRequest('image')->toMediaCollection('profile');
                }
                return $employee;
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}