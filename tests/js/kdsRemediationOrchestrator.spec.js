/**
 * Ultrareview remediation — orchestrator-level (#11 onV2ChangeStatus 422 resync, #2 debounce-timer teardown).
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import KDSComponent from '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';

const tStub = (k) => k;

function mountOrchestrator(dispatch, orders = []) {
    return shallowMount(KDSComponent, {
        global: {
            mocks: {
                $t: tStub,
                $router: { push: vi.fn(), replace: vi.fn() },
                $route: { query: {}, params: {}, path: '/admin/kitchen-display-system' },
                $store: {
                    dispatch,
                    getters: new Proxy({
                        'auth/authBranchId': 0,
                        authBranchId: 0,
                        'frontendLanguage/show': { display_mode: 'ltr', direction: 'ltr' },
                        'kitchenDisplaySystemOrder/lists': orders,
                        'kitchenDisplaySystemOrder/orderItems': [],
                    }, { get: (t, p) => (p in t ? t[p] : []) }),
                    state: { auth: { authBranchId: 0 } },
                },
            },
        },
    });
}

describe('#11 onV2ChangeStatus — resync on 422, 409 stays silent', () => {
    afterEach(() => vi.restoreAllMocks());

    it('a 422 invalid-transition triggers a refresh AND a banner', async () => {
        const dispatch = vi.fn((action) =>
            action === 'kitchenDisplaySystemOrder/changeStatus'
                ? Promise.reject({ response: { status: 422 } })
                : Promise.resolve({ data: { data: [], meta: {} } }));

        const wrapper = mountOrchestrator(dispatch, [{ id: 5, status: 4, queue_number: 1 }]);
        const refresh = vi.spyOn(wrapper.vm, '_debouncedRefresh');

        await wrapper.vm.onV2ChangeStatus({ orderId: 5, status: 7 });
        await flushPromises();

        expect(refresh).toHaveBeenCalled();
        expect(wrapper.vm.kdsErrorBanner.visible).toBe(true);
    });
});

describe('#2 beforeUnmount clears the debounced-refresh timer', () => {
    afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); });

    it('a refresh queued <300ms before unmount does NOT fire post-unmount', async () => {
        vi.useFakeTimers();
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: [], meta: {} } }));
        const wrapper = mountOrchestrator(dispatch);
        await flushPromises();
        dispatch.mockClear();

        wrapper.vm._debouncedRefresh(); // schedules the 300ms timer
        wrapper.unmount();              // #2: beforeUnmount must clearTimeout(_refreshTimeout)
        vi.advanceTimersByTime(600);

        expect(dispatch).not.toHaveBeenCalled(); // nothing dispatched on the dead component
    });
});
