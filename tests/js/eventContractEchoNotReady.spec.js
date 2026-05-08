import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { onEvents, onEvent } from '../../resources/js/services/eventContract.js';

// [V1 SYNC_ROBUSTNESS 3.2] When window.Echo is undefined at the moment a Vue
// component calls onEvents(), eventContract returns a no-op silently.
// The audit caught this as a memory-leak risk (component thinks it subscribed,
// holds the unsubscribe handle, real channel never bound). We add a
// console.warn so ops/dev can detect mis-mounts in console.

describe('eventContract — Echo-not-ready warn', () => {
    let warnSpy;
    let originalEcho;

    beforeEach(() => {
        warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
        originalEcho = window.Echo;
        delete window.Echo;
    });

    afterEach(() => {
        warnSpy.mockRestore();
        if (originalEcho !== undefined) {
            window.Echo = originalEcho;
        } else {
            delete window.Echo;
        }
    });

    it('warns to console when window.Echo is undefined', () => {
        const handler = vi.fn();
        const sub = onEvents(42, [{ broadcastAs: 'OrderCreated', handler }]);

        expect(warnSpy).toHaveBeenCalledWith(
            expect.stringContaining('window.Echo not ready'),
        );
        // Returned handle must still be a no-op-shaped object so callers
        // (which call .unsubscribe() on unmount) don't crash.
        expect(typeof sub.unsubscribe).toBe('function');
        expect(() => sub.unsubscribe()).not.toThrow();
    });

    it('warns when called via onEvent helper without Echo', () => {
        const handler = vi.fn();
        onEvent(42, 'OrderStatusChanged', handler);
        expect(warnSpy).toHaveBeenCalledWith(
            expect.stringContaining('window.Echo not ready'),
        );
    });

    it('does NOT warn when Echo is ready and bindings are valid', () => {
        const stopListening = vi.fn();
        const listen = vi.fn();
        const leave = vi.fn();
        window.Echo = {
            private: () => ({ listen, stopListening }),
            leave,
        };

        onEvents(7, [{ broadcastAs: 'OrderCreated', handler: vi.fn() }]);

        const allCalls = warnSpy.mock.calls.map(args => String(args[0] || ''));
        expect(allCalls.some(s => s.includes('window.Echo not ready'))).toBe(false);
        expect(listen).toHaveBeenCalledTimes(1);
    });
});
