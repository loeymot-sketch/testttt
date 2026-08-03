/**
 * Ultrareview remediation — #12 (drawer refetch on failed recall) + #14/#17 (OSS cross-order timers).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, shallowMount } from '@vue/test-utils';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(() => Promise.resolve({ data: { data: [] } })),
        post: vi.fn(),
    },
}));
import axios from 'axios';
import KdsHistoryDrawer from '../../resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue';
import PreparingAndReadyComponent from '../../resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue';

const tStub = (k) => k;

describe('#12 KdsHistoryDrawer — a failed recall refetches (purges the stale row)', () => {
    beforeEach(() => { axios.get.mockClear(); axios.post.mockClear(); });

    it('calls this.fetch() (axios.get) when recall returns 422', async () => {
        const wrapper = mount(KdsHistoryDrawer, {
            props: { open: true },
            global: { mocks: { $t: tStub }, stubs: { KdsOrderLine: true } },
        });
        wrapper.vm.now = Date.now();
        const order = { id: 77, status: 8, queue_number: 3, updated_at: new Date(Date.now() - 5_000).toISOString() };

        axios.get.mockClear(); // ignore any mount-time fetch
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { message: 'window expired' } } });

        await wrapper.vm.recall(order);
        await wrapper.vm.$nextTick();

        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.get).toHaveBeenCalled(); // the documented refetch actually happens now
    });
});

describe('#14/#17 PreparingAndReadyComponent — per-order highlight timers are independent', () => {
    let wrapper;
    beforeEach(() => {
        vi.useFakeTimers();
        wrapper = shallowMount(PreparingAndReadyComponent, {
            global: {
                mocks: {
                    $t: tStub,
                    $store: { getters: { 'auth/authBranchId': 0, authBranchId: 0 }, state: { auth: { authBranchId: 0 } }, dispatch: vi.fn(() => Promise.resolve({ data: { data: [] } })) },
                },
                stubs: { LoadingContentComponent: true, PopularItemComponent: true, transition: false },
            },
        });
        // neutralise side-effecting helpers so we exercise only the timer logic
        wrapper.vm._playReadySound = vi.fn();
    });
    afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); });

    it('a 2nd order marked <4s later does NOT cancel the 1st order clear (cross-order fix)', () => {
        wrapper.vm._markNewReady(1);
        vi.advanceTimersByTime(2_000);
        wrapper.vm._markNewReady(2);

        // both are highlighted
        expect(wrapper.vm.newReadyIds.has(1)).toBe(true);
        expect(wrapper.vm.newReadyIds.has(2)).toBe(true);

        // each clears on its own independent 10s timer (order 1 set@0s → fires@10s)
        vi.advanceTimersByTime(8_000); // t=10s: order 1 cleared, order 2 (set@2s → fires@12s) still pulsing
        expect(wrapper.vm.newReadyIds.has(1)).toBe(false);
        expect(wrapper.vm.newReadyIds.has(2)).toBe(true);

        vi.advanceTimersByTime(3_000); // t=13s: order 2's own timer fires
        expect(wrapper.vm.newReadyIds.has(2)).toBe(false);
    });
});
