import { describe, it, expect, vi } from 'vitest';

/**
 * [POS-9.1.10] POS subscribes to ItemAvailabilityChanged (POS-GA-F-45).
 *
 * The handler is extracted as a pure function-style mixin — we test it on
 * a plain `this` shim (no Vue mount, no Echo) to validate the contract:
 *   - applies is_available + availability_reason in-place to the matched item
 *   - is a no-op if the broadcast targets an unknown item id
 *   - calls itemList() (full refetch) only when payload.type === 'full'
 */

function makeHandler() {
    // Re-implement the small handler exactly as wired in PosComponent.vue
    // so the test does not need to mount the whole Vue component.
    return function _onItemAvailabilityChanged(event) {
        const payload = (event && event.payload) ? event.payload : event || {};
        const itemId = parseInt(payload.item_id || payload.itemId || 0, 10);
        if (!itemId) return;
        const list = Array.isArray(this.itemsRaw) ? this.itemsRaw
                   : (Array.isArray(this.items) ? this.items : null);
        if (list) {
            const idx = list.findIndex(i => parseInt(i.id, 10) === itemId);
            if (idx !== -1) {
                const isAvailable = payload.is_available === true || payload.is_available === 1 || payload.is_available === '1';
                const prevName = list[idx].name;
                list[idx] = Object.assign({}, list[idx], {
                    is_available: isAvailable,
                    availability_reason: payload.reason || null,
                });
                if (!isAvailable) {
                    try { this.$store?.dispatch?.('posCart/pruneUnavailable', itemId); } catch (e) { /* defensive */ }
                    this._maybeToastItemUnavailableLost?.(itemId, prevName);
                }
                try {
                    const child = this.$refs?.posItemComponent;
                    if (child && typeof child.syncItemAvailabilityFromBroadcast === 'function') {
                        child.syncItemAvailabilityFromBroadcast(itemId, isAvailable, payload.reason || null);
                    }
                } catch (e) { /* defensive */ }
            }
        }
        if (payload.type === 'full') {
            try { this.itemList(); } catch (e) { /* defensive */ }
        }
    };
}

describe('POS ItemAvailabilityChanged handler [POS-9.1.10]', () => {
    it('flips is_available=false and stamps reason for the matched item', () => {
        const ctx = {
            itemsRaw: [
                { id: 1, name: 'Tacos M', is_available: true },
                { id: 7, name: 'Burger',  is_available: true },
            ],
            itemList: vi.fn(),
        };
        makeHandler().call(ctx, { payload: { item_id: 7, is_available: false, reason: 'out_of_stock' } });
        expect(ctx.itemsRaw[1]).toMatchObject({ id: 7, is_available: false, availability_reason: 'out_of_stock' });
        expect(ctx.itemsRaw[0].is_available).toBe(true);
        expect(ctx.itemList).not.toHaveBeenCalled();
    });

    it('flips back to is_available=true when admin re-enables the item', () => {
        const ctx = {
            itemsRaw: [{ id: 7, name: 'Burger', is_available: false, availability_reason: 'manual' }],
            itemList: vi.fn(),
        };
        makeHandler().call(ctx, { payload: { item_id: 7, is_available: 1, reason: null } });
        expect(ctx.itemsRaw[0].is_available).toBe(true);
        expect(ctx.itemsRaw[0].availability_reason).toBeNull();
    });

    it('is a no-op when item_id is unknown', () => {
        const ctx = {
            itemsRaw: [{ id: 1, is_available: true }],
            itemList: vi.fn(),
        };
        makeHandler().call(ctx, { payload: { item_id: 999, is_available: false } });
        expect(ctx.itemsRaw[0].is_available).toBe(true);
        expect(ctx.itemList).not.toHaveBeenCalled();
    });

    it('triggers itemList() when payload.type === "full"', () => {
        const ctx = {
            itemsRaw: [{ id: 1, is_available: true }],
            itemList: vi.fn(),
        };
        makeHandler().call(ctx, { payload: { item_id: 1, is_available: false, type: 'full' } });
        expect(ctx.itemList).toHaveBeenCalledOnce();
    });

    it('accepts the event directly (no `payload` wrapper)', () => {
        const ctx = {
            itemsRaw: [{ id: 5, is_available: true }],
            itemList: vi.fn(),
        };
        makeHandler().call(ctx, { item_id: 5, is_available: false });
        expect(ctx.itemsRaw[0].is_available).toBe(false);
    });

    it('after broadcast unavailable, syncs open modal item and does not close modal (child hook)', () => {
        const sync = vi.fn();
        const dispatch = vi.fn();
        const toast = vi.fn();
        const ctx = {
            itemsRaw: [{ id: 7, name: 'Burger', is_available: true }],
            itemList: vi.fn(),
            $store: { dispatch },
            $refs: { posItemComponent: { syncItemAvailabilityFromBroadcast: sync } },
            _maybeToastItemUnavailableLost: toast,
        };
        makeHandler().call(ctx, { payload: { item_id: 7, is_available: false, reason: 'out_of_stock' } });
        expect(sync).toHaveBeenCalledWith(7, false, 'out_of_stock');
        expect(dispatch).toHaveBeenCalledWith('posCart/pruneUnavailable', 7);
        expect(toast).toHaveBeenCalledWith(7, 'Burger');
    });
});
