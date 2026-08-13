<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\User;
use App\Models\Address;
use App\Services\UserAddressService;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\AddressResource;
use App\Http\Requests\AdministratorAddressRequest;

class AdministratorAddressController extends AdminController
{

    private UserAddressService $userAddressService;

    public function __construct(UserAddressService $userAddressService)
    {
        parent::__construct();
        $this->userAddressService = $userAddressService;
        // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] RBAC consistency,
        // mirrors DeliveryBoyAddressController's [GOAL-COMPLEMENT-2026-05-18
        // Z-4 LIVREUR-Z4-SEC-01 P1] heal: read endpoints use _show, mutating
        // endpoints carry the matching _create / _edit / _delete permission.
        // Previously every method (including store/update/destroy) gated on
        // _show alone — a role with read-only administrator access could
        // mutate addresses (privilege escalation).
        $this->middleware(['permission:administrators_show'])->only('index', 'show');
        $this->middleware(['permission:administrators_create'])->only('store');
        $this->middleware(['permission:administrators_edit'])->only('update');
        $this->middleware(['permission:administrators_delete'])->only('destroy');
    }

    public function index(PaginateRequest $request, User $administrator): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return AddressResource::collection($this->userAddressService->list($request, $administrator));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(AdministratorAddressRequest $request, User $administrator): \Illuminate\Http\Response | AddressResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AddressResource($this->userAddressService->store($request, $administrator));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(AdministratorAddressRequest $request, User $administrator, Address $address): \Illuminate\Http\Response | AddressResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AddressResource($this->userAddressService->update($request, $administrator, $address));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(User $administrator, Address $address): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->userAddressService->destroy($administrator, $address);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(User $administrator, Address $address): \Illuminate\Http\Response | AddressResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AddressResource($this->userAddressService->show($administrator, $address));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
