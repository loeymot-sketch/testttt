<?php

namespace App\Exports;

use App\Http\Requests\PaginateRequest;
use App\Services\ChefService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ChefExport implements FromCollection, WithHeadings
{
    public ChefService $chefService;

    public PaginateRequest $request;

    public function __construct(ChefService $chefService, $request)
    {
        $this->chefService = $chefService;
        $this->request = $request;
    }

    public function collection(): \Illuminate\Support\Collection
    {
        // [ULTRA-LOOP R3 2026-07-07 — export tronqué à 10 lignes] Le front envoie paginate=1
        // (payload de la liste) ; l'export DOIT tout renvoyer. Miroir de CustomerExport:25.
        $this->request->merge(['paginate' => 0]);
        $waiterArray = [];
        $chefs = $this->chefService->list($this->request);

        foreach ($chefs as $chef) {
            $waiterArray[] = [
                $chef->name,
                $chef->email,
                $chef->country_code.''.$chef->phone,
                trans('statuse.'.$chef->status),
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
