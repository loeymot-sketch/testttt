/**
 * Ultrareview remediation — component-level regression suite (@vue/test-utils).
 * #10 KdsStatusBanner reactive clock, #5 KdsOrderCard CTA single-emit, #15 KdsV2Grid FIFO.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import KdsStatusBanner from '../../resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue';
import KdsOrderCard from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue';
import KdsV2Grid from '../../resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue';

const tStub = (k, p) => (p && p.m !== undefined ? `${p.m}m${p.s}s` : k);
const globalMounts = { mocks: { $t: tStub }, stubs: { KdsOrderLine: true, KdsOrderCard: true, KdsStatusBanner: true } };

describe('#10 KdsStatusBanner — offline counter ticks with the reactive `now` prop', () => {
    it('recomputes elapsed m/s when `now` advances (was non-reactive Date.now())', async () => {
        const offlineSince = 1_000_000;
        const wrapper = mount(KdsStatusBanner, {
            props: { offlineSince, now: offlineSince + 65_000 },
            global: { mocks: { $t: tStub } },
        });
        expect(wrapper.text()).toContain('1m5s');

        await wrapper.setProps({ now: offlineSince + 125_000 });
        expect(wrapper.text()).toContain('2m5s');
    });

    it('shows nothing before 60s offline', () => {
        const offlineSince = 1_000_000;
        const wrapper = mount(KdsStatusBanner, {
            props: { offlineSince, now: offlineSince + 30_000 },
            global: { mocks: { $t: tStub } },
        });
        expect(wrapper.text()).not.toContain('m');
    });
});

describe('#5 KdsOrderCard — CTA emits "ready" exactly once', () => {
    const order = {
        id: 42, queue_number: 7, status: 7, order_type: 1, source_surface: 'pos',
        created_at_iso: new Date(Date.now() - 60_000).toISOString(),
        order_items: [], payment_pending_counter: false,
    };

    it('coalesces a rapid double-click into a single emit (in-flight guard)', async () => {
        const wrapper = mount(KdsOrderCard, { props: { order }, global: globalMounts });
        const cta = wrapper.find('[data-testid="kds-card-cta-ready"]');
        await cta.trigger('click');
        await cta.trigger('click');
        expect(wrapper.emitted('ready')).toHaveLength(1);
        expect(wrapper.emitted('ready')[0]).toEqual([42]);
    });

    it('Enter on the focused card region (target=root) still emits once', async () => {
        const wrapper = mount(KdsOrderCard, { props: { order }, global: globalMounts });
        await wrapper.trigger('keydown.enter'); // target = root div → .self handler fires
        expect(wrapper.emitted('ready')).toHaveLength(1);
    });
});

describe('#15 KdsV2Grid — FIFO is stable when created_at is unparseable (NaN-safe)', () => {
    const mk = (id) => ({ id, status: 4, created_at_iso: null, order_items: [], queue_number: id });

    it('two null-created orders keep a deterministic id-tiebreak order', () => {
        const wrapper = mount(KdsV2Grid, { props: { orders: [mk(9), mk(5)] }, global: globalMounts });
        expect(wrapper.vm.visibleOrders.map((o) => o.id)).toEqual([5, 9]);
    });

    it('a finite created_at sorts before an unparseable one', () => {
        const finite = { id: 2, status: 4, created_at_iso: new Date(Date.now() - 10_000).toISOString(), order_items: [], queue_number: 2 };
        const wrapper = mount(KdsV2Grid, { props: { orders: [mk(1), finite] }, global: globalMounts });
        expect(wrapper.vm.visibleOrders.map((o) => o.id)).toEqual([2, 1]);
    });
});
