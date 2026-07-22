import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import fs from 'fs';
import path from 'path';

/**
 * [pos-86-propagation-dead-no-poll 2026-07-22 / Vague 2 STOCK-HARDENING]
 * Live 86 propagation to the caisse tiles/cart rode ONLY on the Echo
 * ItemAvailabilityChanged broadcast — dead when the queue worker is down. The poll
 * fallback re-fetches the branch 86 snapshot every ~30s and REPLAYS the event through
 * _onItemAvailabilityChanged. We test the real method (PosComponent imports axios, so it
 * is mocked at the module level; the replay sink is mocked at the options level so
 * internal `this._onItemAvailabilityChanged` resolves to it) and pin the wiring with a
 * source sentinel.
 */

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({
    default: { name: 'ItemComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({
    default: { name: 'PaymentComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({
    default: { name: 'CreateCustomerAddressComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({
    default: { name: 'CustomerAddressCreateComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));

import axios from 'axios';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

const getterValues = {
    'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: 'EUR', site_currency_position: 'left', pos_dine_in_enabled: 0 },
    'frontendLanguage/show': { display_mode: 0 },
    'posCategory/lists': [], 'item/lists': [], 'user/lists': [], 'posCart/lists': [],
    'posCart/subtotal': 0, 'posCart/discount': 0, 'diningTable/lists': [], 'user/addressLists': [],
    'auth/authBranchId': 1, 'auth/authInfo': { branch_id: 1 },
};
const storeMock = {
    getters: new Proxy(getterValues, { get: (t, p) => (p in t ? t[p] : []) }),
    dispatch: vi.fn(() => Promise.resolve({})),
    commit: vi.fn(),
};

// Replay sink mocked at the OPTIONS level so the real method's internal
// `this._onItemAvailabilityChanged(...)` resolves to this mock (a vi.spyOn on the
// instance proxy would NOT intercept internal calls in Vue 3).
const onAvail = vi.fn();

const TestPosComponent = {
    ...PosComponent,
    mounted() {},
    beforeUnmount() {},
    methods: {
        ...PosComponent.methods,
        closeSidebar: vi.fn(), itemCategories: vi.fn(), itemList: vi.fn(),
        loadKioskCashOrders: vi.fn(), _subscribeEcho: vi.fn(), _startKioskPolling: vi.fn(),
        _bindWsService: vi.fn(), _unsubscribeEcho: vi.fn(), _unbindWsService: vi.fn(),
        _onItemAvailabilityChanged: onAvail,
    },
};

function mountPos() {
    return shallowMount(TestPosComponent, {
        global: {
            stubs: { transition: false, 'router-link': true, 'vue-select': true },
            mocks: {
                $store: storeMock,
                $t: (key) => key,
                $route: { query: {}, params: {} },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
}

describe('POS 86 poll fallback (transport-agnostic)', () => {
    beforeEach(() => {
        onAvail.mockClear();
        axios.get.mockReset();
        // Mount side-effects (e.g. _ensureWalkInCustomerInner) call axios.post().then() —
        // give it a resolvable default so the component mounts without an unhandled rejection.
        axios.post.mockResolvedValue({ data: { data: null } });
    });

    it('seeds silently on the first poll (no replay burst on boot)', async () => {
        axios.get.mockResolvedValue({ data: { data: { items: [{ item_id: 5, reason: 'stock_rupture' }] } } });
        const wrapper = mountPos();

        await wrapper.vm.loadAvailabilitySnapshotFallback();

        expect(onAvail).not.toHaveBeenCalled();
        expect(wrapper.vm._availabilitySnapshotSeeded).toBe(true);
    });

    it('replays newly-86 and came-back items as ItemAvailabilityChanged on the next poll', async () => {
        // URL-keyed so unrelated mount-time GETs never consume the availability payload.
        let availItems = [{ item_id: 5, reason: 'stock_rupture' }];
        axios.get.mockImplementation((url) => String(url).includes('menu/availability/branch')
            ? Promise.resolve({ data: { data: { items: availItems } } })
            : Promise.resolve({ data: { data: [] } }));
        const wrapper = mountPos();

        await wrapper.vm.loadAvailabilitySnapshotFallback();   // seed {5}
        availItems = [{ item_id: 6, reason: 'out_of_stock' }]; // 5 came back, 6 newly 86'd
        wrapper.vm._lastAvailabilityPoll = 0;                  // bypass 30s throttle
        await wrapper.vm.loadAvailabilitySnapshotFallback();

        expect(onAvail).toHaveBeenCalledWith({ payload: { item_id: 6, is_available: false, reason: 'out_of_stock' } });
        expect(onAvail).toHaveBeenCalledWith({ payload: { item_id: 5, is_available: true } });
    });

    // Count only the availability-fallback fetch, isolated from unrelated mount-time GETs.
    const availFetches = () => axios.get.mock.calls.filter((c) => String(c[0]).includes('menu/availability/branch')).length;

    it('throttles to ~30s (a poll within the window is a no-op)', async () => {
        axios.get.mockResolvedValue({ data: { data: { items: [] } } });
        const wrapper = mountPos();

        await wrapper.vm.loadAvailabilitySnapshotFallback(); // seeds + stamps _lastAvailabilityPoll
        const afterSeed = availFetches();
        await wrapper.vm.loadAvailabilitySnapshotFallback(); // within 30s → skipped, no new fetch

        expect(afterSeed).toBe(1);
        expect(availFetches()).toBe(1);
    });

    it('no-ops without a branch scope (never fetches the branch snapshot on branch 0)', async () => {
        axios.get.mockResolvedValue({ data: { data: { items: [] } } });
        const wrapper = mountPos();
        wrapper.vm.authBranchId = () => 0;

        await wrapper.vm.loadAvailabilitySnapshotFallback();

        expect(availFetches()).toBe(0);
    });
});

describe('SENTINELLE source PosComponent.vue — filet 86 câblé (anti-dérive)', () => {
    const src = fs.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/pos/PosComponent.vue'), 'utf8');

    it('la méthode fallback existe et lit le snapshot 86 de la branche', () => {
        expect(src).toContain('async loadAvailabilitySnapshotFallback()');
        expect(src).toContain('admin/menu/availability/branch/${branchId}');
    });
    it('elle rejoue via _onItemAvailabilityChanged (bascule tuile + purge panier + annonce)', () => {
        expect(src).toMatch(/loadAvailabilitySnapshotFallback[\s\S]*?_onItemAvailabilityChanged\(\{ payload: \{ item_id: id, is_available: false/);
        expect(src).toMatch(/loadAvailabilitySnapshotFallback[\s\S]*?_onItemAvailabilityChanged\(\{ payload: \{ item_id: id, is_available: true/);
    });
    it('le tick de polling caisse appelle le filet 86', () => {
        expect(src).toMatch(/const tick = \(\) => \{[\s\S]*?this\.loadAvailabilitySnapshotFallback\(\);[\s\S]*?_kioskPollTimer = setTimeout\(tick/);
    });
});
