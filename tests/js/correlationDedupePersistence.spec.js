import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    __flushCorrelationDedupePersistence,
    __forceLoadCorrelationDedupe,
    __resetCorrelationDedupe,
    isDuplicateCorrelation,
} from '../../resources/js/services/eventContract.js';

// [NEW-01 / Phase 1bis] Persistence behaviour for the correlation-id dedupe
// window: survives a simulated tab reload, gracefully degrades when
// sessionStorage throws, and lazily evicts entries past the 10-min TTL.
describe('correlation dedupe persistence (NEW-01)', () => {
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

    it('persists across simulated reload', () => {
        expect(isDuplicateCorrelation('persist-a')).toBe(false);
        expect(isDuplicateCorrelation('persist-b')).toBe(false);
        expect(isDuplicateCorrelation('persist-c')).toBe(false);

        // Simulate a tab reload: in-memory state is wiped, but sessionStorage
        // survives. After re-load, the same ids must still be detected as
        // duplicates.
        __forceLoadCorrelationDedupe();

        expect(isDuplicateCorrelation('persist-a')).toBe(true);
        expect(isDuplicateCorrelation('persist-b')).toBe(true);
        expect(isDuplicateCorrelation('persist-c')).toBe(true);
    });

    it('gracefully degrades when sessionStorage is unavailable', () => {
        const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
        // happy-dom exposes sessionStorage as a host object whose own methods
        // cannot be reassigned. Simulate the "quota denied / privacy mode"
        // scenario by hiding sessionStorage entirely on window (the dedupe
        // module checks getCorrelationSessionStorage() before each access and
        // gracefully reports the unavailable storage exactly once).
        const descriptor = Object.getOwnPropertyDescriptor(window, 'sessionStorage');
        Object.defineProperty(window, 'sessionStorage', {
            configurable: true,
            get() {
                throw new Error('storage unavailable');
            },
        });

        try {
            // First call: getCorrelationSessionStorage throws → outer try/catch
            // in persistCorrelationDedupe is bypassed because the throw happens
            // inside getter; ensure isDuplicateCorrelation itself does NOT
            // surface the error to callers.
            expect(() => isDuplicateCorrelation('memory-only-1')).not.toThrow();
            expect(() => isDuplicateCorrelation('memory-only-2')).not.toThrow();
            // Force the debounced storage flush so the warn fires synchronously.
            __flushCorrelationDedupePersistence();
            // In-memory dedupe still functions.
            expect(isDuplicateCorrelation('memory-only-1')).toBe(true);
            expect(isDuplicateCorrelation('memory-only-2')).toBe(true);
            // Exactly one console.warn for the entire session (not one per call).
            expect(warnSpy).toHaveBeenCalledTimes(1);
        } finally {
            if (descriptor) {
                Object.defineProperty(window, 'sessionStorage', descriptor);
            }
        }
    });

    it('expires entries older than the 10-min TTL', () => {
        const start = 1_700_000_000_000;
        const dateNowSpy = vi.spyOn(Date, 'now').mockReturnValue(start);

        expect(isDuplicateCorrelation('ttl-entry')).toBe(false);
        expect(isDuplicateCorrelation('ttl-entry')).toBe(true);

        // Advance past TTL: lazy eviction should kick in on next lookup and
        // the same id is no longer considered a duplicate.
        dateNowSpy.mockReturnValue(start + (10 * 60 * 1000) + 1);

        expect(isDuplicateCorrelation('ttl-entry')).toBe(false);
    });

    // [Audit T-MISS-04] Invalid inputs return false without mutating state
    // and never throw — defensive guard for upstream bugs (missing
    // correlation_id, server-side null, etc.).
    it('handles null/undefined/empty correlation IDs without crashing', () => {
        expect(isDuplicateCorrelation(null)).toBe(false);
        expect(isDuplicateCorrelation(undefined)).toBe(false);
        expect(isDuplicateCorrelation('')).toBe(false);
        expect(isDuplicateCorrelation(0)).toBe(false);
        expect(isDuplicateCorrelation({ id: 'not-a-string' })).toBe(false);

        // None of the above should have polluted the dedupe window.
        expect(isDuplicateCorrelation('real-id')).toBe(false);
        expect(isDuplicateCorrelation('real-id')).toBe(true);
    });

    // [Audit T-MISS-05] When sessionStorage contains a mix of TTL-valid and
    // TTL-expired entries at module load time, only the valid entries are
    // restored — expired entries are silently dropped.
    it('drops TTL-expired entries on reload but keeps fresh ones', () => {
        const now = 1_700_000_000_000;
        const dateNowSpy = vi.spyOn(Date, 'now').mockReturnValue(now);

        // Manually plant a mix of fresh + expired entries in sessionStorage,
        // bypassing the public API (simulates a tab that was paused and
        // resumed past the TTL window).
        window.sessionStorage.setItem(
            'foodking_correlation_dedupe_v1',
            JSON.stringify([
                { id: 'fresh-1', ts: now - 60_000 },                        // 1 min old → keep
                { id: 'expired-1', ts: now - (10 * 60 * 1000) - 1_000 },    // > 10 min → drop
                { id: 'fresh-2', ts: now - 5 * 60_000 },                    // 5 min → keep
                { id: 'expired-2', ts: now - (15 * 60 * 1000) },            // > 10 min → drop
            ])
        );

        __forceLoadCorrelationDedupe();

        expect(isDuplicateCorrelation('fresh-1')).toBe(true);
        expect(isDuplicateCorrelation('fresh-2')).toBe(true);
        expect(isDuplicateCorrelation('expired-1')).toBe(false);
        expect(isDuplicateCorrelation('expired-2')).toBe(false);

        dateNowSpy.mockRestore();
    });
});
