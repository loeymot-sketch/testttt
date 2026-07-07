<?php

namespace App\Exports;

use App\Http\Requests\PaginateRequest;
use App\Services\WaiterService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class WaiterExport implements FromCollection, WithHeadings
{
    public WaiterService $waiterService;

    public PaginateRequest $request;

    public function __construct(WaiterService $waiterService, $request)
    {
        $this->waiterService = $waiterService;
        $this->request = $request;
    }

    public function collection(): \Illuminate\Support\Collection
    {
        // [ULTRA-LOOP R3 2026-07-07 — export tronqué à 10 lignes] Le front envoie paginate=1
        // (payload de la liste) ; l'export DOIT tout renvoyer. Miroir de CustomerExport:25.
        $this->request->merge(['paginate' => 0]);
        $waiterArray = [];
        $waiters = $this->waiterService->list($this->request);

        foreach ($waiters as $waiter) {
            $waiterArray[] = [
                $waiter->name,
                $waiter->email,
                $waiter->country_code.''.$waiter->phone,
                trans('statuse.'.$waiter->status),
            ];
        }

        return collect($waiterArray);
    }

    public function headings(): array
    {
        return [
            trans('all.label.name'),
            trans('all.label.email'),
            trans('all.label.phone'),
            trans('all.label.status'),
        ];
    }
}
