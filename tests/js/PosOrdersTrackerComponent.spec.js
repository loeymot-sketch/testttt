import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [POS-V4-CASHIER-OPS 2026-05-02] Cashier ops on tracker:
// - reprint: requestReprint dispatches posOrder/show and shows the modal
// - cancel-with-reason: enforces reason ≥ 3 chars then dispatches changeStatus
//   with `status: CANCELED` + `reason` (canonical OrderStatusRequest contract).
// - mark delivered: dispatches with `status` (not `order_status` — fixed in this cycle)

vi.mock('../../resources/js/services/eventContract', () => ({
    onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })),
}));

vi.mock('../../resources/js/services/alertService', () => ({
    default: {
        info: vi.fn(),
        success: vi.fn(),
        error: vi.fn(),
        warning: vi.fn(),
    },
}));

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        modalShow: vi.fn(),
        modalHide: vi.fn(),
    },
}));

vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));

vi.mock('../../resources/js/components/admin/pos/ReceiptComponent.vue', () => ({
    default: { name: 'ReceiptComponent', template: '<div />', props: ['order'] },
}));

import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';
import alertService from '../../resources/js/services/alertService';
import appService from '../../resources/js/services/appService';

const makeStore = () => ({
    getters: new Proxy({
        'auth/authBranchId': 1,
    }, { get(t, p) { return p in t ? t[p] : undefined; } }),
    state: { auth: { authBranchId: 1 } },
    dispatch: vi.fn(),
    commit: vi.fn(),
});

const buildHarness = () => {
    const store = makeStore();
    const Test = {
        ...PosOrdersTrackerComponent,
        mounted() {},
        beforeUnmount() {},
        methods: {
            ...PosOrdersTrackerComponent.methods,
            fetchOrders: vi.fn(() => Promise.resolve()),
        },
    };
    const wrapper = shallowMount(Test, {
        global: {
            stubs: { transition: false, 'transition-group': false, 'router-link': true },
            mocks: {
                $store: store,
                $t: (key) => key,
                $route: { query: {}, params: {} },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
    return { wrapper, store };
};

describe('PosOrdersTrackerComponent — cashier ops', () => {
    beforeEach(() => {
        alertService.info.mockClear();
        alertService.success.mockClear();
        alertService.error.mockClear();
        appService.modalShow.mockClear();
    });

    it('requestReprint dispatches posOrder/show and opens the receipt modal', async () => {
        const { wrapper, store } = buildHarness();
        const fullOrder = { id: 99, order_serial_no: 'A099', order_items: [] };
        store.dispatch.mockImplementationOnce(() => Promise.resolve({ data: { data: fullOrder } }));

        await wrapper.vm.requestReprint({ id: 99 });
        // requestReprint triggers modalShow inside $nextTick to make sure the
        // ReceiptComponent (rendered behind a v-if on reprintOrder.id) is
        // mounted before the modal is shown. The test must flush that tick.
        await wrapper.vm.$nextTick();

        expect(store.dispatch).toHaveBeenCalledWith('posOrder/show', 99);
        expect(wrapper.vm.reprintOrder).toEqual(fullOrder);
        expect(appService.modalShow).toHaveBeenCalledWith('#receiptModal');
    });

    it('requestReprint surfaces a translated error when the order cannot be loaded', async () => {
        const { wrapper, store } = buildHarness();
        store.dispatch.mockImplementationOnce(() => Promise.reject(new Error('boom')));

        await wrapper.vm.requestReprint({ id: 99 });

        expect(alertService.error).toHaveBeenCalledWith('pos.reprint_error');
        expect(appService.modalShow).not.toHaveBeenCalled();
    });

    it('confirmCancelOrder rejects reasons shorter than 3 characters', async () => {
        const { wrapper, store } = buildHarness();
        wrapper.vm.cancelDialog = { open: true, order: { id: 7 }, reason: 'ab', error: '', busy: false };

        await wrapper.vm.confirmCancelOrder();

        expect(store.dispatch).not.toHaveBeenCalledWith(
            'posOrder/changeStatus',
            expect.anything(),
        );
        expect(wrapper.vm.cancelDialog.error).toBe('pos.cancel_order_reason_required');
        expect(wrapper.vm.cancelDialog.open).toBe(true);
    });

    it('confirmCancelOrder dispatches changeStatus with status=CANCELED + reason', async () => {
        const { wrapper, store } = buildHarness();
        store.dispatch.mockImplementation(() => Promise.resolve());
        wrapper.vm.cancelDialog = { open: true, order: { id: 7 }, reason: 'erreur de saisie', error: '', busy: false };

        await wrapper.vm.confirmCancelOrder();

        expect(store.dispatch).toHaveBeenCalledWith('posOrder/changeStatus', {
            id: 7,
            status: orderStatusEnum.CANCELED,
            reason: 'erreur de saisie',
        });
        expect(alertService.success).toHaveBeenCalledWith('pos.cancel_order_done');
        expect(wrapper.vm.cancelDialog.open).toBe(false);
    });

    it('markDelivered dispatches changeStatus with status (not order_status)', async () => {
        const { wrapper, store } = buildHarness();
        store.dispatch.mockImplementation(() => Promise.resolve());
        const order = { id: 42, _delivering: false };

        await wrapper.vm.markDelivered(order);

        // Canonical contract — OrderStatusRequest expects `status`.
        expect(store.dispatch).toHaveBeenCalledWith('posOrder/changeStatus', {
            id: 42,
            status: orderStatusEnum.DELIVERED,
        });
    });

    it('openCancelDialog seeds the dialog with the target order and a blank reason', () => {
        const { wrapper } = buildHarness();
        wrapper.vm.openCancelDialog({ id: 13, order_serial_no: 'ZZ' });
        expect(wrapper.vm.cancelDialog.open).toBe(true);
        expect(wrapper.vm.cancelDialog.order.id).toBe(13);
        expect(wrapper.vm.cancelDialog.reason).toBe('');
        expect(wrapper.vm.cancelDialog.busy).toBe(false);
    });
});
