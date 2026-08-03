<?php

namespace App\Exports;

use App\Http\Requests\PaginateRequest;
use App\Services\ItemService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ItemsReportExport implements FromCollection, WithHeadings
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
        $itemsReportArray = [];
        // [ITEMS-SEM-04 heal 2026-06-01] Use the date-aware itemReport() (list() ignored
        // from/to entirely → export diverged from screen/PDF) and force a full fetch.
        $this->request->merge(['paginate' => 0]);
        $itemsReportsArray = $this->itemService->itemReport($this->request);

        $total = 0;
        foreach ($itemsReportsArray as $item) {
            // [ITEMS-SEM-02 heal] units sold (SUM quantity, realized, date-scoped), not line count.
            $units = (int) ($item->units_sold ?? 0);
            $total += $units;
            $itemsReportArray[] = [
                $item->name,
                optional($item->category)->name,
                trans('itemType.'.$item->item_type),
                $units,
            ];
        }
        $itemsReportArray[] = [
            trans('all.label.total'),
            '',
            '',
            $total,
        ];

        return collect($itemsReportArray);
    }

    public function headings(): array
    {
        return [
            trans('all.label.name'),
            trans('all.label.item_category_id'),
            trans('all.label.item_type'),
            trans('all.label.quantity'),
        ];
    }
}
