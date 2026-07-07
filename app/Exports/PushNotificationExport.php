<?php

namespace App\Exports;

use App\Http\Requests\PaginateRequest;
use App\Services\PushNotificationService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PushNotificationExport implements FromCollection, WithHeadings
{
    public PushNotificationService $pushNotificationService;

    public PaginateRequest $request;

    public function __construct(PushNotificationService $pushNotificationService, $request)
    {
        $this->pushNotificationService = $pushNotificationService;
        $this->request = $request;
    }

    public function collection(
    ): \Vanilla\Support\Collection|\IlluminateAgnostic\Str\Support\Collection|\IlluminateAgnostic\StrAgnostic\Str\Support\Collection|\IlluminateAgnostic\Collection\Support\Collection|\IlluminateAgnostic\ArrAgnostic\Arr\Support\Collection|\Illuminate\Support\Collection|\IlluminateAgnostic\Arr\Support\Collection {
        // [ULTRA-LOOP R3 2026-07-07 — export tronqué à 10 lignes] Le front envoie paginate=1
        // (payload de la liste) ; l'export DOIT tout renvoyer. Miroir de CustomerExport:25.
        $this->request->merge(['paginate' => 0]);
        $pushNotificationArray = [];
        $pushNotifications = $this->pushNotificationService->list($this->request);

        foreach ($pushNotifications as $pushNotification) {
            $pushNotificationArray[] = [
                $pushNotification->title,
                $pushNotification->role_id == 0 ? trans('all.label.all_roles') : $pushNotification?->role?->name,
                $pushNotification->user_id == 0 ? trans('all.label.all_users') : $pushNotification?->customer?->name,
            ];
        }

        return collect($pushNotificationArray);
    }

    public function headings(): array
    {
        return [
            trans('all.label.title'),
            trans('all.label.role'),
            trans('all.label.user'),
        ];
    }
}
