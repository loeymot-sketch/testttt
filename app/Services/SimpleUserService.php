<?php

namespace App\Services;

use Exception;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;


class SimpleUserService
{
    public $userFilter = ['name', 'email', 'balance', 'phone', 'status'];
    public $roleFilter = ['role_id'];
    protected array $exceptFilter = ['excepts'];


    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            return User::where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->roleFilter)) {
                        $query->whereHas('roles', function ($query) use ($request) {
                            $query->where('id', '=', $request);
                        });
                    }
                    if (in_array($key, $this->userFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->whereHas('roles', function ($query) use ($explode) {
                                    $query->where('id', '!=', $explode);
                                });
                            }
                        }
                    }
                }
            })
                // [abuse-heal 2026-06-20 W5 RBAC-USERS-INDEX-01] Hard-restrict this customer-lookup
                // directory to the CUSTOMER role. Without it, ?role_id=1 enumerated Admin accounts
                // (admin-email harvesting). The caller-controlled role_id filter above can only
                // NARROW within customers now — it can never surface staff/Admin rows. Staff are
                // managed via the dedicated administrator/employee/chef/waiter endpoints.
                ->whereHas('roles', function ($query) {
                    $query->where('id', \App\Enums\Role::CUSTOMER);
                })
                ->orderBy($orderColumn, $orderType)->$method(
                    $methodValue
                );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
