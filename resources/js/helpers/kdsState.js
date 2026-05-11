/**
 * [kds/sprint-2 F-1] Single source of truth for state ↔ status mapping
 * between the numeric OrderStatus enum (server, app/Enums/OrderStatus.php)
 * and the canonical KdsState used by the new card grid.
 *
 * Why client-side: OrderStateMachine.php is frozen; we do not extend the
 * enum or its transitions. The UI just projects each numeric status into a
 * narrower KdsState set ('NEW' | 'PREPARING' | 'READY' | 'DONE' | 'CANCELLED')
 * with the labels the new Vue components render.
 */

// Numeric values mirror app/Enums/OrderStatus.php — DO NOT renumber.
export const ORDER_STATUS = Object.freeze({
    PENDING: 1,
    ACCEPT: 4,
    PREPARING: 7,
    PREPARED: 8,
    OUT_FOR_DELIVERY: 10,
    DELIVERED: 13,
    CANCELED: 16,
    REJECTED: 19,
    RETURNED: 22,
});

// KdsState — narrower set used by the new card visual layer.
export const KDS_STATE = Object.freeze({
    NEW: 'NEW',
    PREPARING: 'PREPARING',
    READY: 'READY',
    DONE: 'DONE',
    CANCELLED: 'CANCELLED',
});

// Map numeric status → KdsState. Anything outside the table → null
// (the card grid filters those out; they belong in history, not on the wall).
const STATUS_TO_KDS = {
    [ORDER_STATUS.PENDING]: KDS_STATE.NEW,
    [ORDER_STATUS.ACCEPT]: KDS_STATE.NEW,
    [ORDER_STATUS.PREPARING]: KDS_STATE.PREPARING,
    [ORDER_STATUS.PREPARED]: KDS_STATE.READY,
    [ORDER_STATUS.OUT_FOR_DELIVERY]: KDS_STATE.DONE,
    [ORDER_STATUS.DELIVERED]: KDS_STATE.DONE,
    [ORDER_STATUS.CANCELED]: KDS_STATE.CANCELLED,
    [ORDER_STATUS.REJECTED]: KDS_STATE.CANCELLED,
    [ORDER_STATUS.RETURNED]: KDS_STATE.CANCELLED,
};

/**
 * @param {number|string} status
 * @returns {'NEW'|'PREPARING'|'READY'|'DONE'|'CANCELLED'|null}
 */
export function kdsStateFromStatus(status) {
    const n = parseInt(status, 10);
    if (!Number.isFinite(n)) {
        return null;
    }
    return STATUS_TO_KDS[n] ?? null;
}

/**
 * Reverse mapping for KDS PATCH actions. The card grid does only two
 * transitions explicitly: NEW→PREPARING (auto-promote) and PREPARING→READY
 * (chef taps Prêt). Any other state move stays the server's responsibility.
 *
 * @param {'NEW'|'PREPARING'|'READY'|'DONE'|'CANCELLED'} kdsState
 * @returns {number|null}
 */
export function statusFromKdsState(kdsState) {
    switch (kdsState) {
        case KDS_STATE.NEW:
            return ORDER_STATUS.ACCEPT;
        case KDS_STATE.PREPARING:
            return ORDER_STATUS.PREPARING;
        case KDS_STATE.READY:
            return ORDER_STATUS.PREPARED;
        case KDS_STATE.DONE:
            return ORDER_STATUS.DELIVERED;
        case KDS_STATE.CANCELLED:
            return ORDER_STATUS.CANCELED;
        default:
            return null;
    }
}

// Display-only metadata. Component code reads these to pick the pill / dot
// colors that match the design tokens in plans/DESIGN_SPEC_KDS_V2_2026-05-11.md.
export const KDS_STATE_THEME = Object.freeze({
    NEW: { bg: '#FFFFFF', border: '#D1D5DB', dot: '#6B7280', text: '#374151' },
    PREPARING: { bg: '#DBEAFE', border: '#BFDBFE', dot: '#2563EB', text: '#1E3A8A' },
    READY: { bg: '#DCFCE7', border: '#BBF7D0', dot: '#16A34A', text: '#14532D' },
    DONE: { bg: '#F3F4F6', border: '#E5E7EB', dot: '#9CA3AF', text: '#374151' },
    CANCELLED: { bg: '#FEE2E2', border: '#FCA5A5', dot: '#DC2626', text: '#7F1D1D' },
});

// i18n key for each KdsState (extends label.kds_aria_live_*). The Vue card
// component does `$t(KDS_STATE_I18N_KEYS[state])`.
export const KDS_STATE_I18N_KEYS = Object.freeze({
    NEW: 'label.kds_state_new',
    PREPARING: 'label.kds_state_preparing',
    READY: 'label.kds_state_ready',
    DONE: 'label.kds_state_done',
    CANCELLED: 'label.kds_state_cancelled',
});
