// @vitest-environment happy-dom
/**
 * [SYNC-01 2026-06-08] onEvents().unsubscribe() must detach ONLY this subscriber's own
 * listeners on the SHARED branch.{id} channel — it must NOT call Echo.leave() (which tore the
 * whole channel down for co-subscribers: a child KioskWaitingComponent unmount killed the kiosk
 * shell's live availability stream), and it must pass the specific handler to stopListening so a
 * co-subscriber on the same event is not clobbered.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { onEvents } from '../../resources/js/services/eventContract';

describe('eventContract.onEvents.unsubscribe — SYNC-01 shared-channel safety', () => {
    let listen, stopListening, leave, channel;
    beforeEach(() => {
        listen = vi.fn();
        stopListening = vi.fn();
        leave = vi.fn();
        channel = { listen, stopListening };
        window.Echo = { private: vi.fn(() => channel), leave };
    });

    it('detaches its own handler and does NOT tear down the shared channel', () => {
        const handler = vi.fn();
        const sub = onEvents(1, [{ broadcastAs: 'OrderStatusChanged', handler }]);

        expect(listen).toHaveBeenCalledTimes(1);
        const [evt, rawHandler] = listen.mock.calls[0];
        expect(evt).toBe('.OrderStatusChanged');

        sub.unsubscribe();

        // Precise detach: stopListening called with THIS subscriber's exact handler.
        expect(stopListening).toHaveBeenCalledWith('.OrderStatusChanged', rawHandler);
        // The shared channel must NOT be left (would kill co-subscribers like the kiosk shell).
        expect(leave).not.toHaveBeenCalled();
    });

    it('detaches each bound event with its own handler', () => {
        const sub = onEvents(1, [
            { broadcastAs: 'OrderCreated', handler: vi.fn() },
            { broadcastAs: 'OrderStatusChanged', handler: vi.fn() },
        ]);
        expect(listen).toHaveBeenCalledTimes(2);
        sub.unsubscribe();
        expect(stopListening).toHaveBeenCalledTimes(2);
        expect(leave).not.toHaveBeenCalled();
    });
});
