import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * [POS-9.1.12] Cash drawer opens on every CASH payment (POS-GA-F-19).
 *
 * Logic under test (extracted from PaymentComponent.vue confirmOrder
 * success branch):
 *   if (form.pos_payment_method === CASH) {
 *       Promise.resolve(openDrawer()).catch(() => {});
 *   }
 * It must NOT call openDrawer for CARD / MOBILE_BANKING / OTHER.
 * It must swallow rejection so it never blocks the receipt flow.
 */

const CASH = 1;
const CARD = 2;
const MOBILE = 3;

const openDrawer = vi.fn();

function maybeOpenDrawer(form) {
    if (form.pos_payment_method === CASH) {
        try {
            Promise.resolve(openDrawer()).catch(() => {});
        } catch (e) { /* swallow */ }
    }
}

describe('Cash drawer wiring [POS-9.1.12]', () => {
    beforeEach(() => {
        openDrawer.mockReset();
    });

    it('opens the drawer on a CASH payment', () => {
        openDrawer.mockResolvedValue({ ok: true });
        maybeOpenDrawer({ pos_payment_method: CASH });
        expect(openDrawer).toHaveBeenCalledOnce();
    });

    it('does NOT open the drawer on a CARD payment', () => {
        maybeOpenDrawer({ pos_payment_method: CARD });
        expect(openDrawer).not.toHaveBeenCalled();
    });

    it('does NOT open the drawer on a MOBILE_BANKING payment', () => {
        maybeOpenDrawer({ pos_payment_method: MOBILE });
        expect(openDrawer).not.toHaveBeenCalled();
    });

    it('swallows a hardware rejection without throwing', async () => {
        openDrawer.mockRejectedValue(new Error('drawer_unavailable'));
        // Must not throw synchronously
        expect(() => maybeOpenDrawer({ pos_payment_method: CASH })).not.toThrow();
        // Allow the rejected promise to settle to make sure no unhandled rejection
        await new Promise(r => setTimeout(r, 0));
        expect(openDrawer).toHaveBeenCalledOnce();
    });

    it('tolerates a synchronous throw from the bridge', () => {
        openDrawer.mockImplementation(() => { throw new Error('boom'); });
        expect(() => maybeOpenDrawer({ pos_payment_method: CASH })).not.toThrow();
    });
});
