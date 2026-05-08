<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * [P0-4 / KIO-6] Fallback fiscal receipt email.
 *
 * Carries the rendered PDF binary as an attachment. The body itself is
 * intentionally minimal — the legal artefact is the PDF.
 */
class FiscalReceiptMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected string $pdf,
        protected int $orderId,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject(trans('emails.fiscal_receipt_subject', ['id' => $this->orderId], null, app()->getLocale())
                ?: "Votre ticket fiscal n°{$this->orderId}")
            ->view('emails.fiscal-receipt-text', [
                'orderId' => $this->orderId,
            ])
            ->attachData($this->pdf, "receipt-{$this->orderId}.pdf", [
                'mime' => 'application/pdf',
            ]);
    }

    /**
     * Test-only accessor for the order id (used by Mail::assertSent closures).
     */
    public function getOrderId(): int
    {
        return $this->orderId;
    }
}
