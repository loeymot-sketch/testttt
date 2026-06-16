<?php

namespace App\Http\Controllers\Admin;

use App\Services\UserAddressService;
use Exception;
use App\Models\User;
use App\Models\Address;
use App\Http\Requests\DeliveryBoyAddressRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\AddressResource;

class DeliveryBoyAddressController extends AdminController
{

    private UserAddressService $userAddressService;

    public function __construct(UserAddressService $userAddressService)
    {
        parent::__construct();
        $this->userAddressService = $userAddressService;
        // [GOAL-COMPLEMENT-2026-05-18 Z-4 LIVREUR-Z4-SEC-01 P1] RBAC consistency.
        // Mirror DeliveryBoyController split: read endpoints use _show, mutating
        // endpoints carry the matching _create / _edit / _delete permission.
        // Previously every method (including store/update/destroy) gated on
        // _show alone — a role with read-only delivery-boy access could mutate
        // their addresses (privilege escalation risk). Permissions are seeded
        // in database/seeders/PermissionTableSeeder.php:405-432.
        $this->middleware(['permission:delivery-boys_show'])->only('index', 'show');
        $this->middleware(['permission:delivery-boys_create'])->only('store');
        $this->middleware(['permission:delivery-boys_edit'])->only('update');
        $this->middleware(['permission:delivery-boys_delete'])->only('destroy');
    }

    public function index(PaginateRequest $request, User $deliveryBoy): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return AddressResource::collection($this->userAddressService->list($request, $deliveryBoy));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function store(DeliveryBoyAddressRequest $request, User $deliveryBoy): \Illuminate\Http\Response | AddressResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AddressResource($this->userAddressService->store($request, $deliveryBoy));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function update(DeliveryBoyAddressRequest $request, User $deliveryBoy, Address $address): \Illuminate\Http\Response | AddressResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AddressResource($this->userAddressService->update($request, $deliveryBoy, $address));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function destroy(User $deliveryBoy, Address $address): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->userAddressService->destroy($deliveryBoy, $address);
            return response('', 202);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function show(User $deliveryBoy, Address $address): \Illuminate\Http\Response | AddressResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AddressResource($this->userAddressService->show($deliveryBoy, $address));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}