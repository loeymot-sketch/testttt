/**
 * [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19 wave-E-1] PosComponent main-page
 * loyalty CTA helpers.
 *
 * The POS main page (PosComponent.vue) hosts a 2nd loyalty redeem CTA in
 * addition to the canonical one on PosOrderShowComponent.vue. Per owner
 * direction (2026-05-19):
 *   - V1 has `pos.dine_in_enabled=false` → the operator-bar slot that
 *     normally hosts the floorplan router-link is freed up. In that V1
 *     slot we surface "Add Loyalty" so the cashier can redeem WITHOUT
 *     navigating to PosOrderShow.
 *   - When dine-in is re-enabled (V2 / B2B branches), the floorplan link
 *     wins — the loyalty CTA hides. The two never coexist (mutually
 *     exclusive renders).
 *   - Loyalty applies during this order; on order completion (terminal
 *     status or PAID) → CTA naturally hides via the gating computed.
 *
 * Defense-in-depth:
 *   - Backend `pos.redeem-loyalty` permission + PosRedemptionService
 *     terminal/PAID guards remain authoritative.
 *   - This UI helper only filters the visual entry point so the cashier
 *     doesn't see an option that would always 422/409 server-side.
 *
 * Extracted as a pure helper module (mirrors tests/js/posDineInFlag.spec.js
 * pattern) so the gating logic is independently testable without mounting
 * the 4 000-line PosComponent.vue. Backwards compatible — the host
 * computed delegates here and passes the enum values it already imports.
 */

/**
 * Normalise an order:confirmed payload (or any object carrying an order
 * shape) into the minimum tuple required to drive the CTA visibility.
 *
 * @param {object|null|undefined} order — raw payload from PaymentComponent
 *   `$emit('order:confirmed', this.order)`. May also be a posOrder/save
 *   response.data.data envelope.
 * @returns {{ id: number, paymentStatus: number, status: number } | null}
 *   null when the payload is missing or has no usable numeric id.
 */
export function extractLoyaltyOrderInfo(order) {
    if (!order || typeof order !== 'object') {
        return null;
    }
    const rawId = order.id;
    if (rawId === null || rawId === undefined) {
        return null;
    }
    const id = Number(rawId);
    if (!Number.isFinite(id) || id <= 0) {
        return null;
    }
    return {
        id,
        paymentStatus: order.payment_status,
        status: order.status,
    };
}

/**
 * Decide whether to render the POS main-page loyalty CTA.
 *
 * @param {object} opts
 * @param {boolean} opts.dineInEnabled — host computed `dineInEnabled`.
 * @param {object|null} opts.currentOrder — output of extractLoyaltyOrderInfo.
 * @param {number|string} opts.paidStatus — host enum paymentStatusEnum.PAID.
 * @param {Array<number>} [opts.terminalStatuses] — host array (mirror of
 *   PosOrderShowComponent.canShowLoyaltyRedeem terminal set).
 * @returns {boolean}
 */
export function canShowLoyaltyMainCta(opts) {
    if (!opts || typeof opts !== 'object') {
        return false;
    }
    if (opts.dineInEnabled === true) {
        return false;
    }
    const order = opts.currentOrder;
    if (!order || order.id == null) {
        return false;
    }
    // PAID check — host always passes the enum value; use == for compat
    // across number/string Eloquent payload variants.
    // eslint-disable-next-line eqeqeq
    if (order.paymentStatus != null && order.paymentStatus == opts.paidStatus) {
        return false;
    }
    const terminals = Array.isArray(opts.terminalStatuses) ? opts.terminalStatuses : [];
    if (terminals.length > 0 && terminals.includes(order.status)) {
        return false;
    }
    return true;
}
