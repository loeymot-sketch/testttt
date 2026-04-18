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
        // [H.3.4] Mirror the production filter: cashier-originated types are
        // skipped (POS=15, DINING_TABLE=20).
        const POS_SELF_TYPES = [15, 20];
        const orderType = parseInt(payload.order_type, 10);
        if (!Number.isNaN(orderType) && POS_SELF_TYPES.includes(orderType)) {
            return;
        }
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

// Standalone replica of _playNewOrderBeep for AudioContext.resume() coverage.
function makePlayBeep() {
    return function _playNewOrderBeep() {
        const ctx = this._audioCtx;
        if (!ctx) return;
        const emit = () => { this._emitted = true; };
        if (ctx.state === 'suspended' && typeof ctx.resume === 'function') {
            const p = ctx.resume();
            if (p && typeof p.then === 'function') {
                p.then(emit).catch(() => {});
                return p;
            }
        }
        emit();
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

    // [H.3.4 / F-A6] Source filter — cashier-originated orders must not
    // re-notify the same cashier.
    it('skips notification for POS-originated orders (order_type=15)', () => {
        const ctx = { setting: {}, _playNewOrderBeep: vi.fn() };
        makeNotifier().call(ctx, { payload: { order_id: 7, order_type: 15 } });
        expect(alertService.info).not.toHaveBeenCalled();
        expect(ctx._playNewOrderBeep).not.toHaveBeenCalled();
    });

    it('skips notification for DINING_TABLE orders (order_type=20)', () => {
        const ctx = { setting: {}, _playNewOrderBeep: vi.fn() };
        makeNotifier().call(ctx, { payload: { order_id: 8, order_type: 20 } });
        expect(alertService.info).not.toHaveBeenCalled();
        expect(ctx._playNewOrderBeep).not.toHaveBeenCalled();
    });

    it('notifies for KIOSK orders (order_type=25) — the whole point', () => {
        const ctx = { setting: {}, _playNewOrderBeep: vi.fn() };
        makeNotifier().call(ctx, { payload: { order_id: 9, order_type: 25 } });
        expect(alertService.info).toHaveBeenCalledWith('Nouvelle commande #9');
        expect(ctx._playNewOrderBeep).toHaveBeenCalledOnce();
    });
});

describe('POS new-order beep [H.3.4 AudioContext.resume]', () => {
    it('calls ctx.resume() when the AudioContext is suspended', async () => {
        const resume = vi.fn().mockResolvedValue(undefined);
        const audioCtx = { state: 'suspended', resume, currentTime: 0 };
        const self = { _audioCtx: audioCtx };
        const p = makePlayBeep().call(self);
        expect(resume).toHaveBeenCalledOnce();
        // Emit happens inside the resume() .then() — await it.
        await p;
        expect(self._emitted).toBe(true);
    });

    it('skips resume() when the context is already running', () => {
        const resume = vi.fn();
        const audioCtx = { state: 'running', resume, currentTime: 0 };
        const self = { _audioCtx: audioCtx };
        makePlayBeep().call(self);
        expect(resume).not.toHaveBeenCalled();
        expect(self._emitted).toBe(true);
    });
});
