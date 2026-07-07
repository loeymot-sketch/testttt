<?php

namespace App\Exports;

use App\Http\Requests\PaginateRequest;
use App\Services\DeliveryBoyService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class DeliveryBoyExport implements FromCollection, WithHeadings
{
    public DeliveryBoyService $deliveryBoyService;

    public PaginateRequest $request;

    public function __construct(DeliveryBoyService $deliveryBoyService, $request)
    {
        $this->deliveryBoyService = $deliveryBoyService;
        $this->request = $request;
    }

    public function collection(): \Illuminate\Support\Collection
    {
        // [ULTRA-LOOP R3 2026-07-07 — export tronqué à 10 lignes] Le front envoie paginate=1
        // (payload de la liste) ; l'export DOIT tout renvoyer. Miroir de CustomerExport:25.
        $this->request->merge(['paginate' => 0]);
        $deliveryBoyArray = [];
        $deliveryBoys = $this->deliveryBoyService->list($this->request);

        foreach ($deliveryBoys as $deliveryBoy) {
            $deliveryBoyArray[] = [
                $deliveryBoy->name,
                $deliveryBoy->email,
                $deliveryBoy->country_code.''.$deliveryBoy->phone,
                trans('statuse.'.$deliveryBoy->status),
            ];
        }

        return collect($deliveryBoyArray);
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
