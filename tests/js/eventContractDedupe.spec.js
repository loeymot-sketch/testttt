import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    isDuplicateCorrelation,
    __resetCorrelationDedupe,
    parseEvent,
    validateEnvelope,
    BROADCAST_MAP,
} from '../../resources/js/services/eventContract.js';

describe('eventContract — correlation dedupe (SYNC-002)', () => {
    beforeEach(() => {
        __resetCorrelationDedupe();
    });

    it('returns false for the first occurrence of a correlation id', () => {
        expect(isDuplicateCorrelation('corr-1')).toBe(false);
    });

    it('returns true for the second occurrence of the same correlation id', () => {
        isDuplicateCorrelation('corr-1');
        expect(isDuplicateCorrelation('corr-1')).toBe(true);
    });

    it('treats null/undefined correlation ids as never-duplicate (defensive)', () => {
        expect(isDuplicateCorrelation(null)).toBe(false);
        expect(isDuplicateCorrelation(undefined)).toBe(false);
        expect(isDuplicateCorrelation('')).toBe(false);
    });

    it('evicts the oldest entry once the LRU cap is exceeded (allows replay after rotation)', () => {
        const cap = 512;
        for (let i = 0; i < cap; i++) {
            isDuplicateCorrelation(`corr-${i}`);
        }
        expect(isDuplicateCorrelation('corr-0')).toBe(true);
        isDuplicateCorrelation('corr-new');
        expect(isDuplicateCorrelation('corr-0')).toBe(false);
    });
});

describe('eventContract — envelope validation', () => {
    it('parseEvent extracts correlationId for downstream dedupe', () => {
        const raw = {
            version: 1,
            type: 'order.created',
            aggregate_id: 42,
            branch_id: 7,
            correlation_id: 'uuid-v4-here',
            occurred_at: '2026-04-20T12:00:00Z',
            payload: { order_id: 42 },
        };
        const parsed = parseEvent(raw);
        expect(parsed.correlationId).toBe('uuid-v4-here');
        expect(parsed.aggregateId).toBe(42);
        expect(parsed.branchId).toBe(7);
    });

    it('validateEnvelope rejects v0 / missing payload / wrong type', () => {
        expect(validateEnvelope(null)).toBe(false);
        expect(validateEnvelope({ version: 0, type: 'x', payload: {} })).toBe(false);
        expect(validateEnvelope({ version: 1, type: '', payload: {} })).toBe(false);
        expect(validateEnvelope({ version: 1, type: 'x' })).toBe(false);
    });

    it('BROADCAST_MAP covers the 3 V1 events POS/Kiosk/KDS rely on', () => {
        expect(BROADCAST_MAP.OrderCreated).toBeDefined();
        expect(BROADCAST_MAP.OrderStatusChanged).toBeDefined();
        expect(BROADCAST_MAP.ItemAvailabilityChanged).toBeDefined();
    });
});
