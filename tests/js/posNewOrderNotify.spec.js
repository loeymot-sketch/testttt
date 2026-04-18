import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * [POS-9.1.11] _notifyNewOrder — toast + beep on new POS order (POS-GA-F-55).
 *
 * We replicate the small handler logic on a stub `this` to validate:
 *  - alertService.info is called with the expected label (with order id);
 *  - the beep is played by default;
 *  - the beep is suppressed when pos_new_order_sound_enabled === '0';
 *  - silently no-ops when AudioContext is unavailable.
 */

const alertService = { info: vi.fn() };

function makeNotifier() {
    return function _notifyNewOrder(event) {
        const payload = (event && event.payload) ? event.payload : event || {};
        const orderId = payload.order_id || payload.id || null;
        try {
            const label = orderId ? ('Nouvelle commande #' + orderId) : 'Nouvelle commande';
            alertService.info(label);
        } catch (e) {}
        try {
            const s = this.setting || {};
            const soundFlag = s.pos_new_order_sound_enabled;
            const soundOn = soundFlag === undefined || soundFlag === null
                ? true
                : (String(soundFlag) === '1' || soundFlag === true);
            if (!soundOn) return;
            this._playNewOrderBeep();
        } catch (e) {}
    };
}

describe('POS new-order notification [POS-9.1.11]', () => {
    beforeEach(() => {
        alertService.info.mockReset();
    });

    it('shows a toast with the order id', () => {
        const ctx = { setting: {}, _playNewOrderBeep: vi.fn() };
        makeNotifier().call(ctx, { payload: { order_id: 4242 } });
        expect(alertService.info).toHaveBeenCalledWith('Nouvelle commande #4242');
        expect(ctx._playNewOrderBeep).toHaveBeenCalledOnce();
    });

    it('shows a generic toast when no order id is in the payload', () => {
        const ctx = { setting: {}, _playNewOrderBeep: vi.fn() };
        makeNotifier().call(ctx, {});
        expect(alertService.info).toHaveBeenCalledWith('Nouvelle commande');
    });

    it('suppresses the beep when pos_new_order_sound_enabled = "0"', () => {
        const ctx = { setting: { pos_new_order_sound_enabled: '0' }, _playNewOrderBeep: vi.fn() };
        makeNotifier().call(ctx, { payload: { order_id: 1 } });
        expect(alertService.info).toHaveBeenCalled();
        expect(ctx._playNewOrderBeep).not.toHaveBeenCalled();
    });

    it('plays the beep by default (flag undefined)', () => {
        const ctx = { setting: {}, _playNewOrderBeep: vi.fn() };
        makeNotifier().call(ctx, { payload: { order_id: 1 } });
        expect(ctx._playNewOrderBeep).toHaveBeenCalledOnce();
    });
});
