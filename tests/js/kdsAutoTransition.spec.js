import { describe, expect, it } from 'vitest';

import {
    shouldAutoTransition,
    pickOldestAutoPromoteCandidate,
} from '../../resources/js/helpers/kdsAutoTransition.js';
import { ORDER_STATUS } from '../../resources/js/helpers/kdsState.js';

const newOrder = (id, createdAt = '2026-05-11T10:00:00Z') => ({
    id,
    queue_number: id,
    status: ORDER_STATUS.ACCEPT,
    created_at_iso: createdAt,
});
const preparingOrder = (id, createdAt = '2026-05-11T10:00:00Z') => ({
    id,
    queue_number: id,
    status: ORDER_STATUS.PREPARING,
    created_at_iso: createdAt,
});

// [kds/sprint-2 F-4] Auto-transition NEW→PREPARING rule. Single-chef V1
// inference: only one order can be in prep at a time, so an arriving ACCEPT
// auto-promotes when the kitchen is idle.
describe('shouldAutoTransition', () => {
    it('promotes a NEW order when the queue has zero PREPARING', () => {
        const queue = [newOrder(1), newOrder(2)];
        expect(shouldAutoTransition(newOrder(1), queue, true)).toBe(true);
    });

    it('does NOT promote when another order is already PREPARING', () => {
        const queue = [preparingOrder(1), newOrder(2)];
        expect(shouldAutoTransition(newOrder(2), queue, true)).toBe(false);
    });

    it('respects the feature flag (off → never promote)', () => {
        const queue = [newOrder(1)];
        expect(shouldAutoTransition(newOrder(1), queue, false)).toBe(false);
    });

    it('rejects non-ACCEPT incoming statuses (no double-bump)', () => {
        const queue = [];
        expect(shouldAutoTransition(preparingOrder(1), queue, true)).toBe(false);
        expect(shouldAutoTransition({ id: 1, status: ORDER_STATUS.PREPARED }, queue, true)).toBe(false);
    });

    it('handles malformed inputs gracefully (defensive)', () => {
        expect(shouldAutoTransition(null, [], true)).toBe(false);
        expect(shouldAutoTransition(undefined, [], true)).toBe(false);
        expect(shouldAutoTransition({}, [], true)).toBe(false);
        expect(shouldAutoTransition(newOrder(1), null, true)).toBe(true); // empty queue treated as no PREPARING
    });
});

describe('pickOldestAutoPromoteCandidate', () => {
    it('returns null when any order is already PREPARING', () => {
        const queue = [preparingOrder(1), newOrder(2)];
        expect(pickOldestAutoPromoteCandidate(queue)).toBeNull();
    });

    it('returns null when the queue has no NEW orders', () => {
        expect(pickOldestAutoPromoteCandidate([])).toBeNull();
        expect(pickOldestAutoPromoteCandidate([{ id: 1, status: ORDER_STATUS.PREPARED }])).toBeNull();
    });

    it('picks the oldest NEW order by created_at_iso ASC', () => {
        const queue = [
            newOrder(3, '2026-05-11T10:02:00Z'),
            newOrder(1, '2026-05-11T10:00:00Z'),
            newOrder(2, '2026-05-11T10:01:00Z'),
        ];
        const picked = pickOldestAutoPromoteCandidate(queue);
        expect(picked.id).toBe(1);
    });

    it('falls back to created_at when created_at_iso is missing', () => {
        const queue = [
            { id: 3, status: ORDER_STATUS.ACCEPT, created_at: '2026-05-11T10:02:00Z' },
            { id: 1, status: ORDER_STATUS.ACCEPT, created_at: '2026-05-11T10:00:00Z' },
        ];
        expect(pickOldestAutoPromoteCandidate(queue).id).toBe(1);
    });

    it('uses id as a stable tiebreaker when timestamps match', () => {
        const same = '2026-05-11T10:00:00Z';
        const queue = [
            newOrder(99, same),
            newOrder(42, same),
            newOrder(77, same),
        ];
        expect(pickOldestAutoPromoteCandidate(queue).id).toBe(42);
    });

    it('ignores non-array input (defensive)', () => {
        expect(pickOldestAutoPromoteCandidate(null)).toBeNull();
        expect(pickOldestAutoPromoteCandidate('not-an-array')).toBeNull();
    });
});
