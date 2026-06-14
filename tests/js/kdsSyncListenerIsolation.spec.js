import { describe, it, expect, vi } from 'vitest';
import { KdsSyncService } from '../../resources/js/services/KdsSyncService';

/**
 * SYNC-RESILIENCE (massive-2dot0 supervisor-audit R1) — KdsSyncService._emit is the
 * byte-identical unguarded `listeners.forEach(cb => cb(payload))` that heal #8 fixed
 * in OssSyncService, but on the MORE critical KDS board (kitchen production display).
 * One throwing 'sync'/'state_change' listener froze board updates for every other
 * subscriber. Each listener must be isolated.
 */
describe('KdsSyncService listener isolation (#8 sibling — KDS board)', () => {
    it('a throwing listener does not prevent the others from running', () => {
        const svc = new KdsSyncService({ wsService: { on: () => () => {} }, fetchFn: vi.fn() });
        const good = vi.fn();
        svc.on('sync', () => {
            throw new Error('bad listener');
        });
        svc.on('sync', good);

        expect(() => svc._emit('sync', { orders: [] })).not.toThrow();
        expect(good).toHaveBeenCalledWith({ orders: [] });
    });
});
