import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import ParkedOrdersComponent from '../../resources/js/components/admin/pos/ParkedOrdersComponent.vue';

// [POS-V4-CASHIER-OPS 2026-05-02] Parked-orders inline search.
// Filter is purely client-side over the already-fetched `posParked/list`,
// matches by id prefix / label substring / customer name (case-insensitive),
// and stays defensive against missing fields in the parked payload.

const makeStore = (parkedList) => ({
    getters: new Proxy({
        'posParked/list': parkedList,
        'frontendSetting/lists': { site_default_currency_code: 'EUR', site_default_language: 'fr' },
    }, { get(t, p) { return p in t ? t[p] : []; } }),
    dispatch: vi.fn(() => Promise.resolve()),
    commit: vi.fn(),
});

const mountWithStore = (parkedList) => shallowMount({
    ...ParkedOrdersComponent,
    watch: {}, // suppress the open watcher (which auto-fetches) — we feed the list directly via store
}, {
    props: { open: true },
    global: {
        stubs: { transition: false },
        mocks: {
            $store: makeStore(parkedList),
            $t: (key) => key,
        },
    },
});

describe('ParkedOrdersComponent search', () => {
    let parked;

    beforeEach(() => {
        parked = [
            { id: 12, label: 'Famille Dupont', items_count: 3, preview_total: 24.5, created_at: new Date().toISOString() },
            { id: 34, label: 'Comptoir A', customer_name: 'Marie Curie', items_count: 1, preview_total: 9.9, created_at: new Date().toISOString() },
            { id: 125, label: 'Livraison', items_count: 5, preview_total: 42, created_at: new Date().toISOString() },
        ];
    });

    it('returns the full list when the search query is empty', () => {
        const wrapper = mountWithStore(parked);
        expect(wrapper.vm.filteredParkedOrders.length).toBe(3);
    });

    it('filters by id prefix (digits only)', async () => {
        const wrapper = mountWithStore(parked);
        await wrapper.setData({ searchQuery: '12' });
        // matches 12 and 125 (both start with '12'), but not 34
        const ids = wrapper.vm.filteredParkedOrders.map((o) => o.id).sort();
        expect(ids).toEqual([12, 125]);
    });

    it('filters by label substring (case insensitive)', async () => {
        const wrapper = mountWithStore(parked);
        await wrapper.setData({ searchQuery: 'comptoir' });
        const labels = wrapper.vm.filteredParkedOrders.map((o) => o.label);
        expect(labels).toEqual(['Comptoir A']);
    });

    it('filters by customer name when present', async () => {
        const wrapper = mountWithStore(parked);
        await wrapper.setData({ searchQuery: 'curie' });
        const ids = wrapper.vm.filteredParkedOrders.map((o) => o.id);
        expect(ids).toEqual([34]);
    });

    it('returns empty list when nothing matches', async () => {
        const wrapper = mountWithStore(parked);
        await wrapper.setData({ searchQuery: 'zzzzz' });
        expect(wrapper.vm.filteredParkedOrders.length).toBe(0);
    });

    it('does not throw on null / missing fields', async () => {
        const wrapper = mountWithStore([
            null,
            { id: null, label: null },
            { id: 5, label: undefined, customer_name: null },
        ]);
        await wrapper.setData({ searchQuery: 'foo' });
        expect(wrapper.vm.filteredParkedOrders.length).toBe(0);
    });
});
