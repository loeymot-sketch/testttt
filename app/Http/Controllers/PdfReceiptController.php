<?php

namespace App\Http\Controllers;

use App\Mail\FiscalReceiptMail;
use App\Services\Fiscal\PdfReceiptGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * [P0-4 / KIO-6] Fallback fiscal receipt endpoints.
 *
 *  - GET  /api/admin/order/{id}/receipt-pdf?type=Order|FrontendOrder
 *         Streams the PDF as an attachment for staff/manager download.
 *
 *  - POST /api/admin/order/{id}/receipt-pdf/email
 *         Body: { email: 'customer@example.com', type?: 'Order'|'FrontendOrder' }
 *         Sends the PDF to the customer's email.
 *
 * Authorization: gated by the existing `pos-manage-fiscal` permission
 * (Branch Manager / Admin). This permission is the closest semantic
 * match for a manual NF525-related action and avoids inventing a new
 * role/permission.
 *
 * NOTE: this controller does NOT modify, regenerate, or invalidate any
 * fiscal sequence, audit chain, or Z report row. It only renders an
 * already-immutable record. This is by design — the spec freezes
 * Fiscal/* services, and an out-of-band receipt copy is a legitimate
 * downstream consumer.
 */
class PdfReceiptController extends Controller
{
    public function __construct()
    {
        $this->middleware(['permission:pos-manage-fiscal'])->only([
            'download',
            'emailToCustomer',
        ]);
    }

    /**
     * Stream the receipt PDF as an attachment.
     */
    public function download(Request $request, int $orderId): Response
    {
        $type = $this->resolveType($request->input('type'));
        $pdf  = app(PdfReceiptGenerator::class)->generate($orderId, $type);

        Log::info('[FiscalPdf] Receipt downloaded', [
            'order_id' => $orderId,
            'type'     => $type,
            'user_id'  => optional($request->user())->id,
            'ip'       => $request->ip(),
        ]);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"receipt-{$orderId}.pdf\"",
            // Cache-Control hardening — fiscal data must never be cached by
            // intermediate proxies; the receipt is generated on-demand.
            'Cache-Control'       => 'private, no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);
    }

    /**
     * Send the receipt PDF to a customer-supplied email address.
     */
    public function emailToCustomer(Request $request, int $orderId): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'type'  => 'sometimes|string|in:Order,FrontendOrder',
        ]);

        $type = $this->resolveType($request->input('type'));
        $pdf  = app(PdfReceiptGenerator::class)->generate($orderId, $type);

        Mail::to($request->input('email'))->send(new FiscalReceiptMail($pdf, $orderId));

        // Manual log line (the central AuditLogService is in the frozen
        // fiscal surface — we deliberately use the framework log here to
        // avoid coupling and to leave a trace for ops).
        Log::info('[FiscalPdf] Receipt sent by email', [
            'order_id' => $orderId,
            'type'     => $type,
            'email'    => $request->input('email'),
            'user_id'  => optional($request->user())->id,
            'ip'       => $request->ip(),
        ]);

        return response()->json([
            'status'   => 'sent',
            'order_id' => $orderId,
        ]);
    }

    /**
     * Whitelist the type parameter so a malicious caller cannot inject
     * arbitrary class names through the controller.
     */
    private function resolveType(?string $raw): string
    {
        return $raw === 'FrontendOrder' ? 'FrontendOrder' : 'Order';
    }
}
