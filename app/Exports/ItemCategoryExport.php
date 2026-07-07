<?php

namespace App\Exports;

use App\Http\Requests\PaginateRequest;
use App\Services\ItemCategoryService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ItemCategoryExport implements FromCollection, WithHeadings
{
    public ItemCategoryService $itemCategoryService;

    public PaginateRequest $request;

    public function __construct(ItemCategoryService $itemCategoryService, $request)
    {
        $this->itemCategoryService = $itemCategoryService;
        $this->request = $request;
    }

    public function collection(): \Illuminate\Support\Collection
    {
        // [ULTRA-LOOP R3 2026-07-07 — export tronqué à 10 lignes] Le front envoie paginate=1
        // (payload de la liste) ; l'export DOIT tout renvoyer. Miroir de CustomerExport:25.
        $this->request->merge(['paginate' => 0]);
        $itemCategoryArray = [];
        $categories = $this->itemCategoryService->list($this->request);

        foreach ($categories as $category) {
            $itemCategoryArray[] = [
                $category->name,
                trans('statuse.'.$category->status),
                $category->description,
            ];
        }

        return collect($itemCategoryArray);
    }

    public function headings(): array
    {
        return [
            trans('all.label.name'),
            trans('all.label.status'),
            trans('all.label.description'),
        ];
    }
}
