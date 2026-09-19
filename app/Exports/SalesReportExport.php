<?php

namespace App\Exports;

use App\Enums\OrderType;
use App\Http\Requests\PaginateRequest;
use App\Libraries\AppLibrary;
use App\Services\OrderService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SalesReportExport implements FromCollection, WithHeadings
{
    public OrderService $orderService;

    public PaginateRequest $request;

    public function __construct(OrderService $orderService, $request)
    {
        $this->orderService = $orderService;
        $this->request = $request;
    }

    public function collection(): \Illuminate\Support\Collection
    {
        // [SELF-AUDIT R4 P1 2026-07-05 — export tronqué à 10 lignes] L'UI envoie paginate=1&per_page=10 ;
        // OrderService::list voit paginate==1 → ->paginate(10) → l'export ne contenait que la 1re page
        // (sous-comptage silencieux ~97% vs l'écran non-paginé). On force un fetch complet (miroir
        // ItemsReportExport:28 / CreditBalanceReportExport).
        $this->request->merge(['paginate' => 0]);
        $salesReportArray = [];
        // [GOAL-OPS-SWAP W2 2026-08-12] `true` écarte les contre-écritures de
        // remboursement, comme `salesReportOverview()` le fait déjà.
        //
        // [ONB-07 2026-08-28] Ce commentaire disait « écran, PDF et tableur doivent
        // compter à l'identique ». Ce n'est PLUS vrai : le chemin PDF est repassé à
        // `false` pour que son Total imprimé défalque les remboursements, comme le
        // gabarit l'annonce depuis juin. L'écran et le tableur gardent l'exclusion —
        // ils LISTENT, ils ne totalisent pas. Laisser une affirmation de parité que le
        // lot venait de supprimer, c'est ce que je reproche au reste du dépôt.
        $salesReportsArray = $this->orderService->list($this->request, true);

        foreach ($salesReportsArray as $order) {
            $salesReportArray[] = [
                $order->order_serial_no,
                AppLibrary::datetime($order->order_datetime),
                AppLibrary::flatAmountFormat($order->total),
                AppLibrary::flatAmountFormat($order->discount),
                AppLibrary::flatAmountFormat($order->delivery_charge),
                // [ONB-07 2026-08-28] QUATRIEME site de fuite d'enum brut, oublie par
                // mon propre commit qui n'en enumerait que trois. `strtoupper` rendait
                // « COUNTER_CASH » EN MAJUSCULES dans le tableur du rapport de ventes —
                // le document meme que ma prose designait comme « le seul que le
                // commercant transmet a son comptable ». Trouve par un agent adverse.
                $order->transaction
                    ? \App\Support\LibellePaiement::pour($order->transaction->payment_method)
                    : $this->getPaymentMethod($order),
                trans('payment_status.'.$order->payment_status),
            ];
        }

        return collect($salesReportArray);
    }

    public function headings(): array
    {
        return [
            trans('all.label.order_serial_no'),
            trans('all.label.date'),
            trans('all.label.total'),
            trans('all.label.discount'),
            trans('all.label.delivery_charge'),
            trans('all.label.payment_type'),
            trans('all.label.payment_status'),
        ];
    }

    public function getPaymentMethod($order)
    {
        if ($order->order_type === OrderType::POS) {
            return trans('pos_payment_method.'.$order->pos_payment_method) != 'pos_payment_method.' ? trans('pos_payment_method.'.$order->pos_payment_method) : '';
        }

        return trans(
            'payment_gateway.'.$order->payment_method
        );
    }
}
