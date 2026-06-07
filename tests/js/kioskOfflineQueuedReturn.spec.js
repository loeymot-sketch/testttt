/**
 * [P3 breadth-audit heal 2026-06-07] KIOSK-OFFLINE-PLANB-01 recovery path.
 *
 * The offline-cash branch of KioskPaymentComponent.processCashPayment renders a
 * dedicated "commande enregistrée hors-ligne" screen instead of the cash-collect
 * screen (which would show a blank "#—"). BUT that screen lives on the
 * `kiosk.payment` route, where the global kiosk idle timer is DISABLED
 * (KioskAppComponent.startIdleTimer noTimerRoutes), and it has no CTA. Without an
 * auto-return it would strand the next self-service customer.
 *
 * This spec proves the recovery: `_scheduleOfflineQueuedReturn` schedules a push
 * to `kiosk.idle` after OFFLINE_QUEUED_RETURN_MS, mirroring the 45s auto-redirect
 * of the cash-instruction screen it bypasses. Uses fake timers + a stub `this`
 * context (no full mount needed — the timer/navigation contract is the unit).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import KioskPaymentComponent from '../../resources/js/components/frontend/kiosk/KioskPaymentComponent.vue';

describe('KIOSK-OFFLINE-PLANB-01 — offline-queued screen auto-returns to idle', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    const RETURN_MS = KioskPaymentComponent.OFFLINE_QUEUED_RETURN_MS;

    it('exposes a positive auto-return delay (mirrors the 45s cash-instruction redirect)', () => {
        expect(typeof RETURN_MS).toBe('number');
        expect(RETURN_MS).toBeGreaterThan(0);
        expect(RETURN_MS).toBe(45000);
    });

    it('schedules a push to kiosk.idle after the delay (not immediately, not never)', () => {
        const push = vi.fn(() => Promise.resolve());
        const ctx = {
            $router: { push },
            $options: { OFFLINE_QUEUED_RETURN_MS: RETURN_MS },
            _offlineQueuedTimer: null,
        };

        KioskPaymentComponent.methods._scheduleOfflineQueuedReturn.call(ctx);

        // Must NOT fire synchronously (a 0/undefined delay bug would strand or flash).
        expect(push).not.toHaveBeenCalled();

        // Just before the deadline: still nothing.
        vi.advanceTimersByTime(RETURN_MS - 1);
        expect(push).not.toHaveBeenCalled();

        // At the deadline: returns to idle exactly once.
        vi.advanceTimersByTime(1);
        expect(push).toHaveBeenCalledTimes(1);
        expect(push).toHaveBeenCalledWith({ name: 'kiosk.idle' });
    });

    it('re-arming clears the previous timer (no double navigation)', () => {
        const push = vi.fn(() => Promise.resolve());
        const ctx = {
            $router: { push },
            $options: { OFFLINE_QUEUED_RETURN_MS: RETURN_MS },
            _offlineQueuedTimer: null,
        };
        const fn = KioskPaymentComponent.methods._scheduleOfflineQueuedReturn;

        fn.call(ctx);
        fn.call(ctx); // re-arm — must clear the first
        vi.advanceTimersByTime(RETURN_MS);

        expect(push).toHaveBeenCalledTimes(1);
    });
});
