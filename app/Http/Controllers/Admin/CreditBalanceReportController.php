<?php

namespace App\Http\Controllers\Admin;

use App\Http\Resources\CreditBalanceUserResource;
use Exception;
use App\Enums\Role as EnumRole;
use App\Services\UserService;
use App\Http\Resources\UserResource;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\PaginateRequest;
use App\Exports\CreditBalanceReportExport;

class CreditBalanceReportController extends AdminController
{

    private UserService $userService;

    public function __construct(UserService $userService)
    {
        parent::__construct();
        $this->userService = $userService;
        $this->middleware(['permission:credit-balance-report'])->only('index', 'export');
    }

    /**
     * [CREDBAL-SEM-02 heal 2026-06-01] The store-credit liability report must list
     * CUSTOMERS only — not Admin / Branch Managers / POS Operators / Chefs / Waiters /
     * Delivery Boys (staff never hold a customer store-credit balance, and listing them
     * pads/confuses the register). Scope to the Customer role via the existing role_id
     * filter on UserService::list (which is shared, so we constrain at the controller).
     */
    private function customerScoped(PaginateRequest $request): PaginateRequest
    {
        // Resolve the Customer role id by NAME (robust across environments where the
        // role id may not equal EnumRole::CUSTOMER), falling back to the enum constant.
        $customerRoleId = \Spatie\Permission\Models\Role::query()
            ->where('name', 'Customer')->value('id') ?? EnumRole::CUSTOMER;
        $request->merge(['role_id' => $customerRoleId]);

        return $request;
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return CreditBalanceUserResource::collection($this->userService->list($this->customerScoped($request)));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function export(PaginateRequest $request): \Illuminate\Http\Response | \Symfony\Component\HttpFoundation\BinaryFileResponse | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return Excel::download(new CreditBalanceReportExport($this->userService, $this->customerScoped($request)), 'Credit-balance-Report.xlsx');
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}
