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
        // [abuse-heal 2026-06-20 W5 RBAC-USERS-INDEX-01] Gate `index` too. GET /admin/users (the
        // customer-lookup directory) had NO permission gate — any authenticated staff token
        // (Chef/Waiter/POS Operator) could enumerate the whole users table incl. Admin emails
        // (?role_id=1). The legitimate consumer is the POS customer-lookup, which holds `pos`.
        // Defense-in-depth: SimpleUserService::list is ALSO forced to the CUSTOMER role so even an
        // authorized `pos` caller can never enumerate staff/Admin accounts.
        $this->middleware(['permission:pos'])->only('index', 'store', 'addresses', 'storeAddress', 'updateAddress');
    }

    public function index(PaginateRequest $request)
    {
        try {
            return SimpleUserResource::collection($this->simpleUserService->list($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function store(
        CustomerRequest $request
    ): \Illuminate\Http\Response | CustomerResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new CustomerResource($this->customerService->store($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function addresses(PaginateRequest $request, User $customer): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return AddressResource::collection($this->userAddressService->list($request, $customer));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function storeAddress(CustomerAddressRequest $request, User $customer): \Illuminate\Http\Response | AddressResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AddressResource($this->userAddressService->store($request, $customer));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function updateAddress(CustomerAddressRequest $request, User $customer, Address $address): \Illuminate\Http\Response | AddressResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new AddressResource($this->userAddressService->update($request, $customer, $address));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}