<?php

namespace App\Services\Fiscal;

use App\Models\FrontendOrder;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * [P0-4 / KIO-6] Fallback fiscal receipt PDF generator.
 *
 * NF525 (CGI art. 286-I-3°bis) requires a fiscal receipt for every
 * transaction. When the kiosk's three printing methods all fail
 * (Electron printReceipt → printEscPos → window.print), the customer
 * would otherwise leave without proof of payment, exposing the
 * operator to administrative sanctions.
 *
 * This generator produces a PDF copy on demand. The endpoint is
 * additive — no existing fiscal flow (sequence allocation, audit
 * chain, Z report aggregation) is touched. The PDF is a *render of*
 * already-immutable data, never a producer of it.
 *
 * IMPORTANT: this class is greenfield code colocated under
 * Services/Fiscal/ for discoverability with the rest of the fiscal
 * surface. It does not modify any frozen file (FiscalSequenceService,
 * AuditLogService, ZReportService, XReportService).
 */
class PdfReceiptGenerator
{
    /**
     * Generate the PDF binary for a given order id.
     *
     * @param  int    $orderId    Primary key of the row in the `orders` table.
     * @param  string $orderClass Either 'Order' (POS) or 'FrontendOrder' (kiosk/web).
     *                            Both classes share the `orders` table; we pick the
     *                            class for relationship loading + branch-scope semantics.
     * @return string             Raw PDF bytes (output of dompdf).
     *
     * @throws NotFoundHttpException if the order cannot be found
     *         (Laravel's exception renderer maps this to HTTP 404 cleanly).
     */
    public function generate(int $orderId, string $orderClass = 'Order'): string
    {
        $order = $this->loadOrder($orderId, $orderClass);

        // Eager-load relations the Blade template needs.
        // OrderItem→Item is `orderItem()`, NOT `item()` (model uses an unusual
        // relation name to avoid clashing with the row-as-item terminology).
        $order->load([
            'orderItems' => function ($q) {
                $q->withTrashed();
            },
            'orderItems.orderItem',
            'branch',
            'user',
            'transaction',
        ]);

        // Variations and extras are stored as JSON STRINGS directly on the
        // OrderItem row (cast='string'); they are NOT relations. The Blade
        // template decodes them inline.

        $pdf = Pdf::loadView('pdf.fiscal-receipt', [
            'order'              => $order,
            'branch'             => $order->branch,
            'fiscal_sequence_no' => $order->fiscal_sequence_no ?? null,
            'company_name'       => config('app.name', 'FoodKing'),
        ]);

        // Thermal ticket format: 80 mm width ≈ 220 pt at 72 dpi. Height
        // grows with content; 800 pt is a comfortable upper bound that
        // dompdf will not exceed visually (tickets typically fit in ~500).
        $pdf->setPaper([0, 0, 220, 800], 'portrait');

        return $pdf->output();
    }

    /**
     * Resolve the right Eloquent class and bypass the BranchScope global
     * scope so an admin/manager can re-print a receipt even when their
     * acting branch context differs from the order's branch (legitimate
     * use case: HQ generating a fallback PDF for a customer complaint).
     *
     * Branch authorization is enforced upstream by the controller's
     * permission middleware (`pos-manage-fiscal`). This method is the
     * data layer; it does not perform authorization.
     */
    private function loadOrder(int $orderId, string $orderClass): Model
    {
        $modelClass = $orderClass === 'FrontendOrder' ? FrontendOrder::class : Order::class;

        $order = $modelClass::withoutGlobalScopes()->find($orderId);

        if (!$order) {
            // NotFoundHttpException implements HttpExceptionInterface so
            // Laravel's exception renderer maps it to a clean HTTP 404
            // (instead of the generic 500 a plain RuntimeException would
            // surface as). Frontend retry logic — and on-call paging
            // rules — depend on the 404↔500 distinction.
            throw new NotFoundHttpException("Order #{$orderId} not found.");
        }

        return $order;
    }
}
