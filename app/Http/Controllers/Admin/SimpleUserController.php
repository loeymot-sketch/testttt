<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\CustomerAddressRequest;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\AddressResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\SimpleUserResource;
use App\Models\Address;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\SimpleUserService;
use App\Services\UserAddressService;
use Exception;

class SimpleUserController extends AdminController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    private SimpleUserService $simpleUserService;
    private CustomerService $customerService;
    private UserAddressService $userAddressService;

    public function __construct(SimpleUserService $simpleUserService, CustomerService $customerService, UserAddressService $userAddressService)
    {
        parent::__construct();
        $this->simpleUserService  = $simpleUserService;
        $this->customerService    = $customerService;
        $this->userAddressService = $userAddressService;
        $this->middleware(['permission:pos'])->only('store', 'addresses', 'storeAddress', 'updateAddress');
        // [C09 heal 2026-07-06] GET /admin/users (index) renvoyait nom + email de
        // TOUS les utilisateurs (staff + clients ; User n'est pas branch-scopé) pour
        // n'importe quel token authentifié — aucune garde middleware ni inline. Un
        // rôle faible-privilège (ex. Chef, avec seulement kitchen-display-system)
        // pouvait ainsi énumérer les PII de tout le monde. Deny-by-default : la liste
        // sert de sélecteur client/staff dans exactement 6 surfaces SPA — POS caisse
        // (pos), pos-order (pos-orders), table-order (table-orders), online-order
        // (online-orders), push-notification (push-notifications), kiosk-machine
        // (settings). OR-gate Spatie couvrant précisément ces permissions ; le rôle
        // admin bypass via Gate::before (AuthServiceProvider). Le flux caisse (pos)
        // reste intact. Sentinelle : tests/Feature/Security/AdminRoutePermissionFloorTest.php
        $this->middleware(['permission:pos|pos-orders|table-orders|online-orders|push-notifications|settings'])->only('index');
    }

    public function index(PaginateRequest $request)
    {
        try {
            return SimpleUserResource::collection($this->simpleUserService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(
        CustomerRequest $request
    ): \Illuminate\Http\Response | CustomerResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new CustomerResource($this->customerService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function addresses(PaginateRequest $request, User $customer): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return AddressResource::collection($this->userAddressService->list($request, $customer));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function storeAddress(CustomerAddressRequest $request, User $customer): \Illuminate\Http\Response | AddressResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AddressResource($this->userAddressService->store($request, $customer));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function updateAddress(CustomerAddressRequest $request, User $customer, Address $address): \Illuminate\Http\Response | AddressResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AddressResource($this->userAddressService->update($request, $customer, $address));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}