import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    __getCorrelationDedupeSize,
    __resetCorrelationDedupe,
    isDuplicateCorrelation,
} from '../../resources/js/services/eventContract.js';

// [NEW-01 / Phase 1bis] Verifies the dedupe LRU cap was raised to 2048 and
// that capacity eviction is FIFO (oldest first) so the most recent ids
// always remain detectable.
describe('correlation dedupe capacity (NEW-01)', () => {
    beforeEach(() => {
        vi.useRealTimers();
        if (typeof window !== 'undefined' && window.sessionStorage) {
            window.sessionStorage.clear();
        }
        __resetCorrelationDedupe();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        if (typeof window !== 'undefined' && window.sessionStorage) {
            window.sessionStorage.clear();
        }
        __resetCorrelationDedupe();
    });

    it('never exceeds 2048 entries and evicts oldest first', () => {
        for (let i = 0; i < 2500; i++) {
            expect(isDuplicateCorrelation(`capacity-${i}`)).toBe(false);
        }

        expect(__getCorrelationDedupeSize()).toBeLessThanOrEqual(2048);
        // Oldest ids must have been evicted (FIFO).
        expect(isDuplicateCorrelation('capacity-0')).toBe(false);
        // Most recent id must still be in the dedupe window.
        expect(isDuplicateCorrelation('capacity-2499')).toBe(true);
    });
});
