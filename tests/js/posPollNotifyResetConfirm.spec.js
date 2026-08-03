import { describe, it, expect, vi } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [UX-NOTIF-01 / SYNC-W3 / UX-PANEL-04 / UX-RESET-06 2026-07-22]
 * Le beep+toast « nouvelle commande » caisse ne dépendaient QUE du handler Echo
 * (mort si le worker de queue est down → soketi UP mais 0 event). On teste :
 *  1) la notification INDÉPENDANTE DU TRANSPORT (diff des IDs à chaque poll) avec
 *     dédup exactement-une-fois partagée Echo↔poll, sur un `this` shim (convention
 *     du repo : pas de mount du gros SFC) ;
 *  2) resetCart 2-taps ;
 *  3) une SENTINELLE de source qui vérifie que PosComponent.vue contient bien le
 *     câblage réel (anti-dérive) : les loaders ne vident plus la liste sur erreur,
 *     et appellent _notifyPolledNewOrders.
 */

const alert = { info: vi.fn(), success: vi.fn() };

// Réimplémentation FIDÈLE de PosComponent._notifyPolledNewOrders / _signalNewOrder / resetCart.
function notifyPolledNewOrders(list, seedKey, origin) {
    if (!this._notifiedOrderIds) this._notifiedOrderIds = new Set();
    if (!this._pollSeeded) this._pollSeeded = {};
    const ids = (list || [])
        .map(o => o && (o.id != null ? o.id : o.order_id))
        .filter(v => v != null)
        .map(String);
    const firstSeed = !this._pollSeeded[seedKey];
    let fresh = 0; let lastId = null;
    ids.forEach(id => {
        if (firstSeed) { this._notifiedOrderIds.add(id); return; }
        if (!this._notifiedOrderIds.has(id)) { this._notifiedOrderIds.add(id); fresh += 1; lastId = id; }
    });
    this._pollSeeded[seedKey] = true;
    if (fresh > 0) this._signalNewOrder(lastId, fresh, origin);
}
function signalNewOrder(orderId, count, origin) {
    alert.info(count > 1 ? (count + ' nouvelles commandes') : ('Nouvelle commande #' + orderId));
    const s = this.setting || {};
    const f = s.pos_new_order_sound_enabled;
    const on = (f === undefined || f === null) ? true : (String(f) === '1' || f === true);
    if (on) this.beepCount = (this.beepCount || 0) + 1;
}
// Dédup côté Echo (garde ajoutée dans _notifyNewOrder).
function echoDedupGuard(orderId) {
    if (orderId == null) return 'proceed';
    if (!this._notifiedOrderIds) this._notifiedOrderIds = new Set();
    const idStr = String(orderId);
    if (this._notifiedOrderIds.has(idStr)) return 'skip';
    this._notifiedOrderIds.add(idStr);
    return 'proceed';
}
function resetCart() {
    if (!this.confirmingReset) {
        if (!this.carts || this.carts.length === 0) { this._doReset(); return; }
        this.confirmingReset = true; this._armed = true; return;
    }
    this.confirmingReset = false; this._doReset();
}

function ctx(over = {}) {
    return {
        _notifiedOrderIds: null, _pollSeeded: null, setting: {}, beepCount: 0,
        confirmingReset: false, carts: [{ id: 1 }], _armed: false, dispatched: false,
        _signalNewOrder: signalNewOrder, _doReset() { this.dispatched = true; }, ...over,
    };
}

describe('POS notif transport-agnostique + dédup', () => {
    it('seed initial silencieux', () => {
        const c = ctx(); notifyPolledNewOrders.call(c, [{ id: 10 }, { id: 11 }], 'web', 'web');
        expect(alert.info).not.toHaveBeenCalled();
        expect(c._notifiedOrderIds.has('10')).toBe(true);
    });
    it('ID inédit au poll suivant → signal', () => {
        alert.info.mockReset();
        const c = ctx();
        notifyPolledNewOrders.call(c, [{ id: 10 }], 'web', 'web');
        notifyPolledNewOrders.call(c, [{ id: 10 }, { id: 12 }], 'web', 'web');
        expect(alert.info).toHaveBeenCalledWith('Nouvelle commande #12');
        expect(c.beepCount).toBe(1);
    });
    it('dédup Echo après poll = pas de re-signal', () => {
        const c = ctx();
        notifyPolledNewOrders.call(c, [], 'cash', 'kiosk_cash');
        notifyPolledNewOrders.call(c, [{ id: 55 }], 'cash', 'kiosk_cash');
        expect(echoDedupGuard.call(c, 55)).toBe('skip');
    });
    it('dédup poll après Echo = pas de re-signal', () => {
        alert.info.mockReset();
        const c = ctx();
        // Echo notifie 99 d'abord (poll pas encore seedé pour 'cash')
        expect(echoDedupGuard.call(c, 99)).toBe('proceed');
        c._pollSeeded = { cash: true }; // le poll a déjà tourné une fois
        notifyPolledNewOrders.call(c, [{ id: 99 }], 'cash', 'kiosk_cash');
        expect(alert.info).not.toHaveBeenCalled();
    });
    it('son désactivé → pas de beep', () => {
        const c = ctx({ setting: { pos_new_order_sound_enabled: '0' } });
        signalNewOrder.call(c, '1', 1, 'web');
        expect(c.beepCount).toBe(0);
    });
});

describe('POS resetCart 2-taps', () => {
    it('1er tap (panier plein) arme sans vider', () => {
        const c = ctx(); resetCart.call(c);
        expect(c.confirmingReset).toBe(true); expect(c.dispatched).toBe(false);
    });
    it('2e tap vide', () => {
        const c = ctx(); resetCart.call(c); resetCart.call(c);
        expect(c.confirmingReset).toBe(false); expect(c.dispatched).toBe(true);
    });
    it('panier vide → vide direct', () => {
        const c = ctx({ carts: [] }); resetCart.call(c);
        expect(c.dispatched).toBe(true);
    });
});

describe('SENTINELLE source PosComponent.vue (anti-dérive)', () => {
    const src = fs.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/pos/PosComponent.vue'), 'utf8');
    it('les loaders appellent _notifyPolledNewOrders', () => {
        expect(src).toContain("_notifyPolledNewOrders(all, 'web', 'web')");
        expect(src).toContain("_notifyPolledNewOrders(all, 'cash', 'kiosk_cash')");
    });
    it('UX-PANEL-04 : le catch de loadWebOrders ne vide plus la liste', () => {
        // On isole le corps de loadWebOrders et on vérifie que son catch ne réassigne pas webOrders=[]
        const i = src.indexOf('async loadWebOrders()');
        const body = src.slice(i, i + 900);
        expect(body).not.toMatch(/catch\s*\(_\)\s*\{[^}]*this\.webOrders\s*=\s*\[\]/);
    });
    it('_notifyNewOrder contient la garde de dédup partagée', () => {
        expect(src).toContain('_notifiedOrderIds');
        expect(src).toMatch(/if \(this\._notifiedOrderIds\.has\(idStr\)\) return;/);
    });
    it('resetCart est en 2-taps (confirmingReset)', () => {
        expect(src).toContain('confirmingReset');
        expect(src).toContain('_doResetCart');
    });
});
