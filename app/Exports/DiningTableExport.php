<?php

namespace App\Exports;

use App\Http\Requests\PaginateRequest;
use App\Services\DiningTableService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class DiningTableExport implements FromCollection, WithHeadings
{
    public DiningTableService $diningTableService;

    public PaginateRequest $request;

    public function __construct(DiningTableService $diningTableService, $request)
    {
        $this->diningTableService = $diningTableService;
        $this->request = $request;
    }

    public function collection()
    {
        // [ULTRA-LOOP R3 2026-07-07 — export tronqué à 10 lignes] Le front envoie paginate=1
        // (payload de la liste) ; l'export DOIT tout renvoyer. Miroir de CustomerExport:25.
        $this->request->merge(['paginate' => 0]);
        $diningTableArray = [];
        $diningTablesArray = $this->diningTableService->list($this->request);

        foreach ($diningTablesArray as $diningTable) {
            $diningTableArray[] = [
                $diningTable->name,
                $diningTable->size,
                trans('statuse.'.$diningTable->status),
            ];
        }

        return collect($diningTableArray);
    }

    public function headings(): array
    {
        return [
            trans('all.label.name'),
            trans('all.label.size'),
            trans('all.label.status'),
        ];
    }
}
