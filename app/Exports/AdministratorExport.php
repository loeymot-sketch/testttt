<?php

namespace App\Exports;

use App\Http\Requests\PaginateRequest;
use App\Services\AdministratorService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class AdministratorExport implements FromCollection, WithHeadings
{
    public AdministratorService $administratorService;

    public PaginateRequest $request;

    public function __construct(AdministratorService $administratorService, $request)
    {
        $this->administratorService = $administratorService;
        $this->request = $request;
    }

    public function collection(): \Illuminate\Support\Collection
    {
        // [ULTRA-LOOP R3 2026-07-07 — export tronqué à 10 lignes] Le front envoie paginate=1
        // (payload de la liste) ; l'export DOIT tout renvoyer. Miroir de CustomerExport:25.
        $this->request->merge(['paginate' => 0]);
        $administratorArray = [];
        $administrators = $this->administratorService->list($this->request);

        foreach ($administrators as $administrator) {
            $administratorArray[] = [
                $administrator->name,
                $administrator->email,
                $administrator->country_code.$administrator->phone,
                trans('statuse.'.$administrator->status),
            ];
        }

        return collect($administratorArray);
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
