<?php

namespace App\Exports;

use App\Http\Requests\PaginateRequest;
use App\Services\ItemService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ItemExport implements FromCollection, WithHeadings
{
    public ItemService $itemService;

    public PaginateRequest $request;

    public function __construct(ItemService $itemService, $request)
    {
        $this->itemService = $itemService;
        $this->request = $request;
    }

    public function collection(): \Illuminate\Support\Collection
    {
        // [ULTRA-LOOP R3 2026-07-07 — export tronqué à 10 lignes] Le front envoie paginate=1
        // (payload de la liste) ; l'export DOIT tout renvoyer. Miroir de CustomerExport:25.
        $this->request->merge(['paginate' => 0]);
        $itemArray = [];
        $items = $this->itemService->list($this->request);

        foreach ($items as $item) {
            $itemArray[] = [
                $item->name,
                $item->category?->name,
                $item->price,
                trans('itemType.'.$item->item_type),
                $item->tax?->tax_rate,
                trans('statuse.'.$item->status),
                trans('ask.'.$item->is_featured),
                $item->caution,
                $item->description,
            ];
        }

        return collect($itemArray);
    }

    public function headings(): array
    {
        return [
            trans('all.label.name'),
            trans('all.label.item_category_id'),
            trans('all.label.price'),
            trans('all.label.item_type'),
            trans('all.label.tax_id'),
            trans('all.label.status'),
            trans('all.label.featured'),
            trans('all.label.caution'),
            trans('all.label.description'),
        ];
    }
}
