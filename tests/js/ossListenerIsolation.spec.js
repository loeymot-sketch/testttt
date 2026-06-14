import { describe, it, expect, vi } from 'vitest';
import oss from '../../resources/js/services/OssSyncService.js';

/**
 * SYNC-RESILIENCE (massive-2dot0 #8) — OssSyncService._emit must isolate each
 * subscriber: one throwing listener must NOT abort the others. Pre-heal the bare
 * `listeners.forEach((h) => h(payload))` let the first thrower break the loop, so
 * a single bad OSS subscriber froze status-screen updates for every other one.
 */
describe('OssSyncService listener isolation (#8)', () => {
    it('a throwing listener does not prevent the others from running', () => {
        const good = vi.fn();
        oss.on('orders', () => {
            throw new Error('bad listener');
        });
        oss.on('orders', good);

        expect(() => oss._emit('orders', { id: 1 })).not.toThrow();
        expect(good).toHaveBeenCalledWith({ id: 1 });
    });
});
