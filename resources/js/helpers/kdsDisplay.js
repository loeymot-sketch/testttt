/**
 * Pure helpers for KDS station filter + wait-time escalation styling (unit-tested).
 */

/** [Lot 2.C / F-07] Minimum gap between "new order" chimes when WS floods. */
export const KDS_NEW_ORDER_SOUND_MIN_INTERVAL_MS = 2500;

/**
 * @param {number} lastPlayedAt
 * @param {number} [nowMs]
 * @returns {boolean}
 */
export function shouldPlayKdsNewOrderSound(lastPlayedAt, nowMs = Date.now()) {
    if (lastPlayedAt == null || !Number.isFinite(lastPlayedAt)) {
        return true;
    }
    return nowMs - lastPlayedAt >= KDS_NEW_ORDER_SOUND_MIN_INTERVAL_MS;
}

export const LS_STATION_FILTER = 'kds.station_filter';
export const LS_GROUP_BY_TABLE = 'kds.group_by_table';

/**
 * [Lot 2.F / F-10] Station filter is persisted per logged-in user so two staff
 * on the same browser profile do not overwrite each other’s KDS view.
 * Falls back to the legacy unscoped key when `userId` is missing/invalid.
 */
export function kdsStationFilterStorageKey(userId) {
    const n = userId == null || userId === '' ? 0 : parseInt(userId, 10);
    if (!Number.isFinite(n) || n <= 0) {
        return LS_STATION_FILTER;
    }
    return `${LS_STATION_FILTER}.u${n}`;
}

const STATIONS = ['bar', 'cuisine_chaude', 'cuisine_froide', 'none'];

export function normalizeKdsStation(raw) {
    const v = raw == null || raw === '' ? 'none' : String(raw);
    return STATIONS.includes(v) ? v : 'none';
}

export function orderMatchesStationFilter(order, filter) {
    if (!filter || filter === 'all') {
        return true;
    }
    const items = order.order_items || [];
    return items.some((line) => normalizeKdsStation(line.kds_station) === filter);
}

export function filterOrdersByStation(orders, filter) {
    if (!filter || filter === 'all') {
        return orders || [];
    }
    return (orders || []).filter((o) => orderMatchesStationFilter(o, filter));
}

export function getKdsEscalationClass(createdMs, nowMs = Date.now()) {
    const age = nowMs - createdMs;
    const fiveMin = 5 * 60 * 1000;
    const tenMin = 10 * 60 * 1000;
    if (age < fiveMin) {
        return 'kds-wait-green';
    }
    if (age < tenMin) {
        return 'kds-wait-orange';
    }
    return 'kds-wait-red animate-pulse';
}

export function parseOrderCreatedMs(order) {
    if (order.created_at) {
        const t = Date.parse(order.created_at);
        if (!Number.isNaN(t)) {
            return t;
        }
    }
    if (order.order_datetime) {
        const t2 = Date.parse(order.order_datetime);
        if (!Number.isNaN(t2)) {
            return t2;
        }
    }
    return Date.now();
}
