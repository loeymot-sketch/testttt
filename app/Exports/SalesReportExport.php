<?php

namespace App\Exports;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Libraries\AppLibrary;
use App\Services\OrderService;
use App\Http\Requests\PaginateRequest;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;

class SalesReportExport implements FromCollection, WithHeadings
{

    public OrderService $orderService;
    public PaginateRequest $request;

    public function __construct(OrderService $orderService, $request)
    {
        $this->orderService = $orderService;
        $this->request      = $request;
    }

    public function collection() : \Illuminate\Support\Collection
    {
        $salesReportArray  = [];
        // [REP-EXP-01 FIX 2026-06-06] Force a FULL fetch. The screen forwards its
        // paginated request (paginate:1, per_page:10, page:N), which made the export
        // contain only the current page (10 rows). Strip pagination so OrderService
        // takes the get() branch over the full filtered dataset. Mirrors the
        // ItemsReportExport ITEMS-SEM-04 heal.
        $this->request->merge(['paginate' => 0]);
        $salesReportsArray = $this->orderService->list($this->request);

        foreach ($salesReportsArray as $order) {
            $salesReportArray[] = [
                $order->order_serial_no,
                AppLibrary::datetime($order->order_datetime),
                AppLibrary::flatAmountFormat($order->total),
                AppLibrary::flatAmountFormat($order->discount),
                AppLibrary::flatAmountFormat($order->delivery_charge),
                $order->transaction ? $this->transactionLabel($order->transaction)
                : $this->getPaymentMethod($order),
                trans('payment_status.' . $order->payment_status)
            ];
        }
        return collect($salesReportArray);
    }

    public function headings() : array
    {
        return [
            trans('all.label.order_serial_no'),
            trans('all.label.date'),
            trans('all.label.total'),
            trans('all.label.discount'),
            trans('all.label.delivery_charge'),
            trans('all.label.payment_type'),
            trans('all.label.payment_status')
        ];
    }

    public function transactionLabel($transaction)
    {
        // [FP-ARMY-P2A] Map the unified counter-encaissement codes (counter_cash/counter_card/
        // counter_ticket_restaurant/counter_mobile_banking/counter_other) to FR via the existing
        // pos_payment_method lang so the export matches the on-screen report (was strtoupper()
        // leaking COUNTER_CARD verbatim). Real gateway provider names still pass through uppercased.
        // [CDASH-SALESREP-PAY-01 FIX 2026-06-13] Some encaissement rows store the BARE code
        // (cash/card/split/other/ticket_restaurant/mobile_banking) WITHOUT the counter_ prefix,
        // so CASH/CARD/SPLIT/OTHER/TICKET_RESTAURANT leaked verbatim into the Excel export (matches
        // the on-screen SalesReportListComponent txMap heal). Map both prefixed and bare codes.
        $method = strtolower((string) ($transaction->payment_method ?? ''));
        $counterMap = [
            'counter_cash'              => PosPaymentMethod::CASH,
            'counter_card'             => PosPaymentMethod::CARD,
            'counter_ticket_restaurant' => PosPaymentMethod::TICKET_RESTAURANT,
            'counter_mobile_banking'   => PosPaymentMethod::MOBILE_BANKING,
            'counter_other'            => PosPaymentMethod::OTHER,
            'cash'                     => PosPaymentMethod::CASH,
            'card'                     => PosPaymentMethod::CARD,
            'ticket_restaurant'        => PosPaymentMethod::TICKET_RESTAURANT,
            'mobile_banking'           => PosPaymentMethod::MOBILE_BANKING,
            'other'                    => PosPaymentMethod::OTHER,
            'counter_deferred'         => PosPaymentMethod::COUNTER_DEFERRED,
        ];
        if (isset($counterMap[$method])) {
            return trans('pos_payment_method.' . $counterMap[$method]);
        }
        // `split` has no PosPaymentMethod enum value (multi-tranche encaissement) — render the FR
        // mixed-payment label directly rather than leaking SPLIT.
        if ($method === 'split') {
            return 'Paiement mixte';
        }
        return strtoupper((string) ($transaction->payment_method ?? ''));
    }

    public function getPaymentMethod($order){
        if($order->order_type === OrderType::POS){
            return trans('pos_payment_method.' . $order->pos_payment_method) != "pos_payment_method." ? trans('pos_payment_method.' . $order->pos_payment_method) : "";
        }

        return trans(
            'payment_gateway.' . $order->payment_method
        );
    }
}
