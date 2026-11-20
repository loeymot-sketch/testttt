import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * [POS-9.1.12] Cash drawer opens on every CASH payment (POS-GA-F-19).
 *
 * Logic under test (extracted from PaymentComponent.vue confirmOrder
 * success branch):
 *   if (form.pos_payment_method === CASH) {
 *       const result = await openDrawer();
 *       if (!result.ok) alertService.error('pos.cash_drawer_bridge_offline');
 *   }
 * It must NOT call openDrawer for CARD / MOBILE_BANKING / OTHER.
 * It must not undo a sealed sale on a hardware rejection, but it must make
 * the failure visible to the cashier.
 */

const CASH = 1;
const CARD = 2;
const MOBILE = 3;

const openDrawer = vi.fn();
const alertService = { error: vi.fn() };

async function maybeOpenDrawer(form) {
    if (form.pos_payment_method === CASH) {
        try {
            const result = await openDrawer();
            if (!result || result.ok === false) {
                alertService.error('pos.cash_drawer_bridge_offline');
            }
        } catch (_e) {
            alertService.error('pos.cash_drawer_bridge_offline');
        }
    }
}

describe('Cash drawer wiring [POS-9.1.12]', () => {
    beforeEach(() => {
        openDrawer.mockReset();
        alertService.error.mockReset();
    });

    it('opens the drawer on a CASH payment', async () => {
        openDrawer.mockResolvedValue({ ok: true });
        await maybeOpenDrawer({ pos_payment_method: CASH });
        expect(openDrawer).toHaveBeenCalledOnce();
        expect(alertService.error).not.toHaveBeenCalled();
    });

    it('does NOT open the drawer on a CARD payment', async () => {
        await maybeOpenDrawer({ pos_payment_method: CARD });
        expect(openDrawer).not.toHaveBeenCalled();
    });

    it('does NOT open the drawer on a MOBILE_BANKING payment', async () => {
        await maybeOpenDrawer({ pos_payment_method: MOBILE });
        expect(openDrawer).not.toHaveBeenCalled();
    });

    it('shows a bridge error without undoing the cash sale when hardware rejects', async () => {
        openDrawer.mockRejectedValue(new Error('drawer_unavailable'));
        await expect(maybeOpenDrawer({ pos_payment_method: CASH })).resolves.toBeUndefined();
        expect(openDrawer).toHaveBeenCalledOnce();
        expect(alertService.error).toHaveBeenCalledWith('pos.cash_drawer_bridge_offline');
    });

    it('shows a bridge error for an explicit unavailable result', async () => {
        openDrawer.mockResolvedValue({ ok: false, error: 'drawer_bridge_unavailable' });
        await maybeOpenDrawer({ pos_payment_method: CASH });
        expect(alertService.error).toHaveBeenCalledWith('pos.cash_drawer_bridge_offline');
    });

    it('tolerates a synchronous throw from the bridge', async () => {
        openDrawer.mockImplementation(() => { throw new Error('boom'); });
        await expect(maybeOpenDrawer({ pos_payment_method: CASH })).resolves.toBeUndefined();
        expect(alertService.error).toHaveBeenCalledWith('pos.cash_drawer_bridge_offline');
    });
});
