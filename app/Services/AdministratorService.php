<?php

namespace App\Services;

use Exception;
use App\Enums\Ask;
use App\Models\User;
use App\Libraries\AppLibrary;
use App\Enums\Role as EnumRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\ChangeImageRequest;
use App\Http\Requests\AdministratorRequest;
use App\Http\Requests\UserChangePasswordRequest;

class AdministratorService
{
    public $user;
    public $userFilter = ['name', 'email', 'username', 'phone', 'branch_id', 'status'];

    /**a
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

            return User::with('media')->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->userFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }
                }
            // [GOAL-pageby-V1.0.2 class-of-bug] Spatie's ->role($int) calls findById($int) (HasRoles L84).
            // Passing EnumRole::ADMIN int breaks whenever roles.id AUTO_INCREMENT skipped past it
            // (fresh seed lands at 73-80). Stable identity = role NAME. Pattern from DeliveryBoyService heal (0332e5b7e).
            })->role('Admin', 'sanctum')->orderBy($orderColumn, $orderType)->$method(
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
    public function store(AdministratorRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $this->user = User::create([
                    'name'              => $request->name,
                    'email'             => $request->email,
                    'phone'             => $request->phone,
                    'username'          => AppLibrary::username($request->name),
                    'password'          => Hash::make($request->password),
                    'status'            => $request->status,
                    'email_verified_at' => now(),
                    'branch_id'         => $request->branch_id,
                    'country_code'      => $request->country_code,
                    'is_guest'          => Ask::NO,
                ]);
                $this->user->assignRole(EnumRole::ADMIN);
            });
            return $this->user;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [CENTRAL-01 / WAVE5-SEC-001 parity] Defense-in-depth: ensure the
     * route-bound User is actually an Admin before any mutation. update()
     * was the lone mutating method here WITHOUT a target-role guard — its
     * siblings (changePassword/changeImage/show/destroy) all gate on
     * hasRole(ADMIN). Without this, a Branch Manager with
     * `administrators_edit` could PUT /api/admin/administrator/{non_admin_id}
     * and mutate a Customer/Chef/etc. through the admin path (IDOR /
     * cross-role type-confusion). Mirrors CustomerService::assertTargetRole.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 403
     */
    private function assertTargetRole(User $administrator): void
    {
        if (! $administrator->hasRole(EnumRole::ADMIN)) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(
                403,
                'Cannot mutate user outside expected role.'
            );
        }
    }

    /**
     * @throws Exception
     */
    public function update(AdministratorRequest $request, User $administrator)
    {
        // [CENTRAL-01] See update() comment — same role-target guard the
        // sibling services enforce. Placed BEFORE the try/catch so the 403
        // HttpException is not swallowed/rethrown as 422 by the catch block.
        $this->assertTargetRole($administrator);

        try {
            DB::transaction(function () use ($administrator, $request) {
                $this->user               = $administrator;
                $this->user->name         = $request->name;
                $this->user->email        = $request->email;
                $this->user->phone        = $request->phone;
                $this->user->status       = $request->status;
                $this->user->branch_id    = $request->branch_id;
                $this->user->country_code = $request->country_code;

                if ($request->password) {
                    $this->user->password = Hash::make($request->password);
                }
                $this->user->save();
            });
            return $this->user;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(User $administrator)
    {
        try {
            if (Auth::user()->id != $administrator->id && $administrator->id != 1) {
                if ($administrator->hasRole(EnumRole::ADMIN)) {
                    DB::transaction(function () use ($administrator) {
                        $administrator->removeRole($administrator->roles[0]->id);
                        $administrator->addresses()->delete();
                        $administrator->delete();
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

    /**
     * @throws Exception
     */
    public function show(User $administrator): User
    {
        try {
            $administrator = $administrator->load('roles', 'media');
            if ($administrator->hasRole(EnumRole::ADMIN)) {
                return $administrator;
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
    public function changePassword(UserChangePasswordRequest $request, User $administrator): User
    {
        try {
            if ($administrator->hasRole(EnumRole::ADMIN)) {
                $administrator->password = Hash::make($request->password);
                $administrator->save();
                return $administrator;
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
    public function changeImage(ChangeImageRequest $request, User $administrator): User
    {
        try {
            if ($administrator->hasRole(EnumRole::ADMIN)) {
                $administrator->clearMediaCollection('profile');
                $administrator->addMediaFromRequest('image')->toMediaCollection('profile');
                return $administrator;
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}