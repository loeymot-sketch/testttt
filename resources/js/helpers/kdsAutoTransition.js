/**
 * [kds/sprint-2 F-4] Conditional auto-transition rule NEW → PREPARING.
 *
 * Justification (RESEARCH_KDS_MODERN_2024_2026 §4.3): no documented modern
 * KDS auto-promotes — every system (Toast, Lightspeed 2.0, Otter, Wingstop,
 * Fresh) requires an explicit chef action. This is a deliberate departure
 * for FoodKing V1 single-chef takeaway-only:
 *   - only ONE order can physically be in prep at once
 *   - the system can infer "started prep" with near-zero false-positive risk
 *   - removes one tap per order (saves wet-glove touches)
 *   - degrades cleanly to manual the moment a second chef / second station
 *     arrives (the "zero PREPARING" condition becomes rarely satisfied)
 *
 * Feature flag: `window.kdsAutoTransitionEnabled` (default ON). Owner can
 * disable from settings menu when multi-station ships.
 *
 * Server safety: the existing OrderStateMachine::apply lockForUpdate +
 * idempotent early-return makes this safe under any race between client
 * watchers and concurrent operator action.
 */

import { ORDER_STATUS } from './kdsState.js';

/**
 * Decide whether `incoming` should be auto-promoted to PREPARING right now.
 *
 * @param {object} incoming         the order that just entered/refreshed
 * @param {Array}  currentQueue     all KDS orders currently visible (incl. incoming)
 * @param {boolean} [featureFlag]   default true
 * @returns {boolean}
 */
export function shouldAutoTransition(incoming, currentQueue, featureFlag = true) {
    if (!featureFlag) {
        return false;
    }
    if (incoming == null || typeof incoming !== 'object') {
        return false;
    }
    const rawStatus = parseInt(incoming.status ?? incoming.rawStatus, 10);
    if (rawStatus !== ORDER_STATUS.ACCEPT) {
        return false;
    }
    const queue = Array.isArray(currentQueue) ? currentQueue : [];
    const anyPreparing = queue.some((o) => {
        const s = parseInt(o?.status ?? o?.rawStatus, 10);
        return s === ORDER_STATUS.PREPARING;
    });
    return !anyPreparing;
}

/**
 * Pick the single oldest NEW order to auto-promote when the queue refreshes.
 * Used by the store watcher after a `lists` action returns. Stable tie-breaker:
 * created_at then id.
 *
 * @param {Array} queue
 * @returns {object|null}
 */
export function pickOldestAutoPromoteCandidate(queue) {
    if (!Array.isArray(queue) || queue.length === 0) {
        return null;
    }
    const anyPreparing = queue.some((o) => parseInt(o?.status, 10) === ORDER_STATUS.PREPARING);
    if (anyPreparing) {
        return null;
    }
    const news = queue.filter((o) => parseInt(o?.status, 10) === ORDER_STATUS.ACCEPT);
    if (news.length === 0) {
        return null;
    }
    news.sort((a, b) => {
        const ta = a?.created_at_iso || a?.created_at || a?.order_datetime || '';
        const tb = b?.created_at_iso || b?.created_at || b?.order_datetime || '';
        if (ta !== tb) {
            return ta < tb ? -1 : 1;
        }
        const ia = parseInt(a?.id, 10) || 0;
        const ib = parseInt(b?.id, 10) || 0;
        return ia - ib;
    });
    return news[0];
}
