<?php

namespace App\Exceptions\Payment;

/**
 * [GOAL-K2-HEAL-01 2026-05-24] Phase K.4 H9 P1 + J-CASCADE H9 UNHEALED:
 *
 * Thrown when a cashier attempts to confirm payment on an order that was
 * already collected by another cashier inside the lockForUpdate race
 * window of `PaymentService::confirmCounterPayment`.
 *
 * Pre-heal, `PaymentService.php:227-230` short-circuited the second call
 * silently — returned 200 + OrderDetailsResource of the now-PAID order,
 * which the `PosCounterCollectModal.vue` `onConfirm` success branch then
 * toasted as `cash_drawer_opened_simulation`. Cashier B believed they had
 * collected the money → drawer opened + till-count drift risk. Data
 * integrity was intact (no second cash_movement, no second audit row,
 * no second fiscal_sequence_no allocation), but the UX/operational
 * defect was real (and reported by Phase J adversarial round + Phase K.4
 * deep audit).
 *
 * Render contract — route closure / Handler converts to:
 *   HTTP 409 Conflict
 *   { message, error_code: 'payment_already_collected', order_id,
 *     collected_by_user_id, collected_at }
 *
 * Frontend `PosCounterCollectModal.vue` `onConfirm` catch branch matches
 * on `error_code === 'payment_already_collected'` and displays a clear
 * FR error toast + emits `cancel` so the Q10 "à encaisser" panel refreshes
 * and the cashier moves on without a phantom drawer-open simulation.
 *
 * Implementation notes:
 *
 *   - Extends `\RuntimeException` (NOT `HttpException`) on purpose: the
 *     project's `Handler::render` (app/Exceptions/Handler.php:105-113)
 *     hardcodes any HttpException to 422 regardless of carrier
 *     statusCode, which would silently downgrade our 409 to 422 and
 *     defeat the whole point of typed-exception → status-code mapping.
 *     A plain `\RuntimeException` lets the route closure / Handler do
 *     the explicit 409 conversion before any generic 422 fallback fires.
 *
 *   - `collectedByUserId`/`collectedAt` are sourced from the
 *     `order.counter_payment_confirmed` `audit_logs` row written by the
 *     first transaction (PaymentService.php:309-321). `user_id` of that
 *     row is the cashier who actually won the lockForUpdate, and its
 *     `created_at` is the timestamp at which the collection committed.
 *     Both are nullable: if the audit row is missing (legacy data
 *     written before this code path existed, or an audit-write failure)
 *     the exception is still thrown — treating unknown collector as
 *     foreign is the safe default. The frontend modal uses these for
 *     an optional informational sub-line; the 409 + FR error message
 *     stands on its own without them.
 *
 *   - Discriminator: PaymentService preserves the V5.5 sister-guard
 *     same-cashier idempotent replay no-op (200) documented in
 *     `C5_EncaisserKdsPreserveTest:302-355`. The exception only fires
 *     when the calling cashier differs from the audit-recorded
 *     collector (or the collector is unknown). Same-cashier double-tap
 *     with a fresh idempotency key still returns 200 — that codepath
 *     never throws this exception.
 */
class PaymentAlreadyCollectedException extends \RuntimeException
{
    public function __construct(
        public readonly int $orderId,
        public readonly ?int $collectedByUserId = null,
        public readonly ?string $collectedAt = null,
    ) {
        parent::__construct(
            'Commande déjà encaissée par un autre caissier.'
        );
    }
}
