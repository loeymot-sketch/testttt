<?php

namespace App\Exports;

use App\Libraries\AppLibrary;
use App\Services\UserService;
use App\Http\Requests\PaginateRequest;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;

class CreditBalanceReportExport implements FromCollection, WithHeadings
{

    public UserService $userService;
    public PaginateRequest $request;

    public function __construct(UserService $userService, $request)
    {
        $this->userService = $userService;
        $this->request      = $request;
    }

    public function collection(): \Illuminate\Support\Collection
    {
        $creditBalanceReportArray  = [];
        // [CREDBAL-NET-01 heal 2026-06-01] Force a non-paginated fetch. The UI
        // sends paginate=1 / per_page=10; UserService::list would otherwise
        // return only the first page, silently truncating the store-credit
        // liability register. An export must always be the FULL register.
        $this->request->merge(['paginate' => 0]);
        $usersArray = $this->userService->list($this->request);

        foreach ($usersArray as $user) {
            $creditBalanceReportArray[] = [
                $user->name,
                $user->email,
                $user->country_code . '' . $user->phone,
                AppLibrary::flatAmountFormat($user->balance),
            ];
        }
        return collect($creditBalanceReportArray);
    }

    public function headings(): array
    {
        return [
            trans('all.label.name'),
            trans('all.label.email'),
            trans('all.label.phone'),
            trans('all.label.balance')
        ];
    }
}
