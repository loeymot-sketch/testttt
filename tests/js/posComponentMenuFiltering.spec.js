import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createStore } from 'vuex';
/**
 * item.js pulls `appService` → circular full `store` bootstrap on import.
 * Stub `appService` so the module definition loads under Vitest without
 * instantiating the production root store.
 */
vi.mock('../../resources/js/services/appService', () => ({
    default: {
        requestHandler: vi.fn(() => ''),
    },
}));

import { item } from '../../resources/js/store/modules/item.js';
import { kioskMenu } from '../../resources/js/store/modules/kioskMenu.js';
import menuParityFixture from './__fixtures__/menu-parity.json';

vi.mock('../../resources/js/helpers/kioskMenuCache', () => ({
    saveSnapshot: vi.fn(() => Promise.resolve()),
    loadSnapshot: vi.fn(() => null),
    isSnapshotFresh: vi.fn(() => false),
}));

/**
 * Reality probe — channel visibility contract (dual-surface MENU SSOT):
 * Mirrors `App\Models\Item::isVisibleOn()` (channels === null ⇒ all surfaces;
 * non-null array ⇒ must include surface; empty [] ⇒ nowhere).
 *
 * POS: `resources/js/components/admin/pos/PosComponent.vue` reads catalog via
 * `this.$store.getters['item/lists']` — no Vuex getter applies channel slicing;
 * `ItemService` / controllers apply `surface=pos` on the wire.
 *
 * Kiosk: `resources/js/store/modules/kioskMenu.js` hydrates state from API payloads
 * pre-filtered with `surface=kiosk`; getters (`allItems`, `itemsByCategory`) only
 * slice by category / sandwich UI — no channel predicate client-side.
 *
 * Sentinel: hydrate both modules with the SAME full fixture blob, derive visible IDs
 * with this predicate for `pos` vs `kiosk` so POS↔Kiosk intersection semantics stay
 * locked to backend Item rules without touching product code.
 */
function catalogItemVisibleOnSurface(payload, surface) {
    const ch = payload.channels;
    if (ch === null || typeof ch === 'undefined') {
        return true;
    }
    const arr = Array.isArray(ch) ? ch : [];
    return arr.length > 0 && arr.includes(surface);
}

function cloneItemModule() {
    return {
        ...item,
        state: JSON.parse(JSON.stringify(item.state)),
    };
}

function cloneKioskMenuModule() {
    return {
        ...kioskMenu,
        state: JSON.parse(JSON.stringify(kioskMenu.state)),
    };
}

function setMinus(a, b) {
    return new Set([...a].filter((x) => !b.has(x)));
}

/** IDs visible on BOTH surfaces — proves shared pool = legacy-null ∪ explicit dual-channel. */
const IDS_UNIVERSAL = new Set([101, 102, 103, 201, 202]);
/** POS-only row — proves `channels=['pos']` never appears on kiosk projection set. */
const IDS_POS_ONLY = new Set([301, 302]);
/** Kiosk-only row — proves `channels=['kiosk']` never appears on POS projection set. */
const IDS_KIOSK_ONLY = new Set([401, 402]);
/** Explicit empty-array — proves defensive “visible nowhere”. */
const ID_EMPTY_CHANNELS = 500;

describe('CV1 sentinel — POS↔Kiosk catalog channel parity (fixture-driven)', () => {
    let store;

    beforeEach(() => {
        store = createStore({
            modules: {
                item: cloneItemModule(),
                kioskMenu: cloneKioskMenuModule(),
            },
        });
        const blob = [...menuParityFixture.items];
        store.commit('item/lists', blob);
        store.commit('kioskMenu/SET_ITEMS', blob);
    });

    it('intersection(allItems, lists projections) equals universal-channel IDs after surface filter', () => {
        const rawPos = store.getters['item/lists'];
        const rawKiosk = store.getters['kioskMenu/allItems'];

        expect(rawPos.map((x) => x.id).sort()).toEqual(rawKiosk.map((x) => x.id).sort());

        const posIds = rawPos.filter((i) => catalogItemVisibleOnSurface(i, 'pos')).map((i) => i.id);
        const kioskIds = rawKiosk.filter((i) => catalogItemVisibleOnSurface(i, 'kiosk')).map((i) => i.id);

        const posVisibleSet = new Set(posIds);
        const kioskVisibleSet = new Set(kioskIds);

        const intersect = new Set([...posVisibleSet].filter((id) => kioskVisibleSet.has(id)));
        expect(intersect.size).toBe(IDS_UNIVERSAL.size);
        expect(intersect).toEqual(IDS_UNIVERSAL);
    });

    it('POS \\ Kiosk = pos-only exclusives — proves cashier-only skew', () => {
        const rawPos = store.getters['item/lists'];
        const rawKiosk = store.getters['kioskMenu/allItems'];
        const posVisibleSet = new Set(rawPos.filter((i) => catalogItemVisibleOnSurface(i, 'pos')).map((i) => i.id));
        const kioskVisibleSet = new Set(rawKiosk.filter((i) => catalogItemVisibleOnSurface(i, 'kiosk')).map((i) => i.id));

        expect(setMinus(posVisibleSet, kioskVisibleSet)).toEqual(IDS_POS_ONLY);
    });

    it('Kiosk \\ POS = kiosk-only exclusives — proves borne-only skew', () => {
        const rawPos = store.getters['item/lists'];
        const rawKiosk = store.getters['kioskMenu/allItems'];
        const posVisibleSet = new Set(rawPos.filter((i) => catalogItemVisibleOnSurface(i, 'pos')).map((i) => i.id));
        const kioskVisibleSet = new Set(rawKiosk.filter((i) => catalogItemVisibleOnSurface(i, 'kiosk')).map((i) => i.id));

        expect(setMinus(kioskVisibleSet, posVisibleSet)).toEqual(IDS_KIOSK_ONLY);
    });

    it('empty channels array ⇒ excluded from both surfaces — proves strict channel gate', () => {
        const rawPos = store.getters['item/lists'];
        const rawKiosk = store.getters['kioskMenu/allItems'];
        const posVisibleSet = new Set(rawPos.filter((i) => catalogItemVisibleOnSurface(i, 'pos')).map((i) => i.id));
        const kioskVisibleSet = new Set(rawKiosk.filter((i) => catalogItemVisibleOnSurface(i, 'kiosk')).map((i) => i.id));

        expect(posVisibleSet.has(ID_EMPTY_CHANNELS)).toBe(false);
        expect(kioskVisibleSet.has(ID_EMPTY_CHANNELS)).toBe(false);
    });
});
