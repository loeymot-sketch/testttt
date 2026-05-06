<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [P11-FZH / F-VERIFY-08-02]
 *
 * Thrown by SealedOrderGuard::assertMutable() when a caller tries to mutate
 * (RETURNED / REFUNDED / destroy) an order whose created_at is contained
 * in a closed Z report window (opened_at, closed_at].
 *
 * Extends HttpException(409 Conflict) so the controller layer surfaces a
 * clean 409 to the cashier UI without the generic 422 catch-all.
 *
 * Resolution: cashier MUST use
 *   POST /api/admin/pos-order/{order}/refund-with-counter-entry
 * which creates a mirror order traceable in the current Z window.
 */
class OrderSealedException extends HttpException
{
    public int $orderId;
    public int $sealedByZReportId;
    public int $branchId;
    public string $attemptedTransition;

    public function __construct(
        int $orderId,
        int $branchId,
        int $sealedByZReportId,
        string $attemptedTransition
    ) {
        parent::__construct(409, sprintf(
            'Order #%d is sealed by closed Z report #%d (NF525 immutability). '
            . 'Use refund-with-counter-entry instead of "%s".',
            $orderId,
            $sealedByZReportId,
            $attemptedTransition
        ));
        $this->orderId             = $orderId;
        $this->branchId            = $branchId;
        $this->sealedByZReportId   = $sealedByZReportId;
        $this->attemptedTransition = $attemptedTransition;
    }
}
