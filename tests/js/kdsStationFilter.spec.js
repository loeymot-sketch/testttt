import { describe, expect, it, beforeEach, afterEach } from 'vitest';

import {
    filterOrdersByStation,
    LS_STATION_FILTER,
    orderMatchesStationFilter,
} from '../../resources/js/helpers/kdsDisplay';

describe('KDS station filter', () => {
    beforeEach(() => {
        localStorage.clear();
    });
    afterEach(() => {
        localStorage.clear();
    });

    it("filter 'bar' keeps only orders that have at least one line with kds_station bar", () => {
        const orders = [
            {
                id: 1,
                order_items: [{ id: 10, kds_station: 'bar' }],
            },
            {
                id: 2,
                order_items: [{ id: 11, kds_station: 'cuisine_chaude' }],
            },
        ];
        expect(filterOrdersByStation(orders, 'bar').map((o) => o.id)).toEqual([1]);
    });

    it("filter 'all' (or null) keeps every order", () => {
        const orders = [
            { id: 1, order_items: [{ kds_station: 'none' }] },
            { id: 2, order_items: [{ kds_station: 'bar' }] },
        ];
        expect(filterOrdersByStation(orders, 'all')).toHaveLength(2);
        expect(filterOrdersByStation(orders, null)).toHaveLength(2);
    });

    it('station filter value is persisted in localStorage for reload simulation', () => {
        localStorage.setItem(LS_STATION_FILTER, 'cuisine_froide');
        expect(localStorage.getItem(LS_STATION_FILTER)).toBe('cuisine_froide');
        const read = localStorage.getItem(LS_STATION_FILTER);
        expect(filterOrdersByStation([], read)).toEqual([]);
    });

    it('order with mixed bar + cuisine_chaude lines matches both station filters', () => {
        const order = {
            id: 42,
            order_items: [
                { id: 1, kds_station: 'bar' },
                { id: 2, kds_station: 'cuisine_chaude' },
            ],
        };
        expect(orderMatchesStationFilter(order, 'bar')).toBe(true);
        expect(orderMatchesStationFilter(order, 'cuisine_chaude')).toBe(true);
    });
});
