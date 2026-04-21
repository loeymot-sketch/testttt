/**
 * Pure helpers for KDS station filter + wait-time escalation styling (unit-tested).
 */

export const LS_STATION_FILTER = 'kds.station_filter';
export const LS_GROUP_BY_TABLE = 'kds.group_by_table';

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
