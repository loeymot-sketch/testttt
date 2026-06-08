<?php

namespace App\Exports;

use App\Enums\IsAdvance;
use App\Libraries\AppLibrary;
use App\Services\OrderService;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;

/**
 * [GOAL-CAISSE-UNIFIED W4.4 2026-06-08] Dedicated export for the unified
 * /admin/historique page (Borne + Caisse + En ligne + Livraison in ONE file
 * — the spreadsheet the gérant hands the accountant).
 *
 * A SEPARATE class from OrderExport on purpose: the shared OrderExport is wired
 * to the POS-only posOrders surface and must NOT gain new columns (would change
 * that export's shape). This one adds NF525 traceability — order_serial_no AND
 * fiscal_sequence_no — plus the order origin, so the accountant can reconcile
 * the fiscal sequence directly.
 *
 * Pure read layer over the EXISTING OrderService::list. It honors EVERY
 * historique filter the page passes (from_date/to_date, source_surface,
 * order_type, status, payment_status, order_serial_no) because it reuses the
 * SAME list() path the index uses. BranchScope is honored automatically
 * (Order::with(...) is scoped) — no withoutGlobalScope here. No writes, no
 * NF525 mutation: it only reads sealed fields.
 */
class OrderHistoryExport implements FromCollection, WithHeadings
{
    public OrderService $orderService;
    public $request;

    public function __construct(OrderService $orderService, $request)
    {
        $this->orderService = $orderService;
        $this->request      = $request;
    }

    public function collection(): \Illuminate\Support\Collection
    {
        $orderArray  = [];
        $ordersArray = $this->orderService->list($this->request);

        foreach ($ordersArray as $order) {
            $orderArray[] = [
                $order->order_serial_no,
                // NF525 fiscal sequence number — empty for unsealed orders (kiosk
                // pending-counter / unpaid) which carry no fiscal_sequence_no yet.
                $order->fiscal_sequence_no ?? '',
                trans('orderType.' . $order->order_type),
                optional($order->user)->name,
                AppLibrary::flatAmountFormat($order->total),
                AppLibrary::datetime($order->order_datetime),
                trans('orderStatus.' . $order->status) . ($order->is_advance_order == IsAdvance::YES ? '/' . trans('all.label.advance') : ''),
            ];
        }
        return collect($orderArray);
    }

    public function headings(): array
    {
        return [
            trans('all.label.order_serial_no'),
            // [W4.4 inline-FR stopgap] No 'all.label.fiscal_number' key exists in
            // the PHP lang files yet — hardcoded FR for the accountant export.
            'N° fiscal',
            trans('all.label.order_type'),
            trans('all.label.customer'),
            trans('all.label.amount'),
            trans('all.label.date'),
            trans('all.label.status'),
        ];
    }
}
