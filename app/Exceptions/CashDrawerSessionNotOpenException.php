<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [Sprint 1B — Cash trail backend NF525] Thrown when a POS CASH sale is
 * attempted without an open cash drawer session for the acting cashier
 * (single-tender CASH path or any CASH tranche in a split payment).
 *
 * NF525 requires every espèces movement to be linked to a session so the
 * Z-report variance is computable. A silent log (legacy F-003 best-effort
 * path) leaves the receipt PAID but breaks fiscal traceability — Sprint 1B
 * promotes this to a hard 422 stop at sale time.
 *
 * Note: kiosk counter-collect (`PaymentService::confirmCounterPayment`)
 * still uses the legacy non-strict path (`recordCashOrderMovement` with
 * $strict=false) to preserve backward-compatibility for kiosk takeaway.
 */
class CashDrawerSessionNotOpenException extends HttpException
{
    public function __construct(
        string $message = 'Aucune caisse ouverte — ouvrir une session avant de prendre un paiement espèces.',
        int $statusCode = 422,
    ) {
        parent::__construct($statusCode, $message);
    }
}
