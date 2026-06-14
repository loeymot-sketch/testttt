import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import KioskWaitingComponent from '../../resources/js/components/frontend/kiosk/KioskWaitingComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

vi.mock('../../resources/js/services/kioskHardware', () => ({ default: { haptic: vi.fn() } }));
vi.mock('axios', () => ({ default: { post: vi.fn() } }));

const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr: frMessages } });

function makeStore(orderPayload) {
  const fetchOrderStatus = vi.fn().mockResolvedValue({ data: { data: orderPayload } });
  const reset = vi.fn();
  return {
    fetchOrderStatus, reset,
    store: createStore({ modules: { kioskCart: {
      namespaced: true,
      state: { orderRef: null, queueNumber: null, branchId: 1 },
      getters: { branchId: (s) => s.branchId },
      mutations: { SET_ORDER_REF(s, { orderId, queueNumber }) { s.orderRef = orderId; s.queueNumber = queueNumber; } },
      actions: { fetchOrderStatus: (_, id) => fetchOrderStatus(id), reset },
    } } }),
  };
}

function mountWaiting(orderPayload) {
  const routerPush = vi.fn().mockResolvedValue();
  const ctx = makeStore(orderPayload);
  const wrapper = mount(KioskWaitingComponent, {
    global: {
      plugins: [i18n, ctx.store],
      provide: { showToast: vi.fn() },
      mocks: { $route: { query: { queue: orderPayload.queue_number, total: orderPayload.total } }, $router: { push: routerPush } },
    },
    props: { orderId: String(orderPayload.id) },
  });
  return { wrapper, routerPush, ...ctx };
}

describe('F6 · markReady cancels the preparing-state 10s auto-redirect', () => {
  afterEach(() => { vi.restoreAllMocks(); vi.useRealTimers(); });

  it('a PREPARED order stays on READY past 10s (not kicked to idle before the 20s auto-reset)', async () => {
    vi.useFakeTimers();
    const { wrapper, routerPush, reset } = mountWaiting({ id: 50, status: orderStatusEnum.PREPARED, queue_number: 'A0050', total: 10 });
    await flushPromises(); // first poll → markReady()
    expect(wrapper.vm.isReady).toBe(true);

    vi.advanceTimersByTime(11000); // past the 10s preparing-redirect
    await flushPromises();

    expect(reset, 'preparing-redirect must NOT fire newOrder()/reset on a ready order').not.toHaveBeenCalled();
    expect(routerPush).not.toHaveBeenCalledWith({ name: 'kiosk.idle' });
    expect(wrapper.vm.isReady).toBe(true);
    wrapper.unmount();
  });

  it('the 20s ready auto-reset still fires (no regression)', async () => {
    vi.useFakeTimers();
    const { wrapper, routerPush } = mountWaiting({ id: 51, status: orderStatusEnum.PREPARED, queue_number: 'A0051', total: 10 });
    await flushPromises();
    vi.advanceTimersByTime(21000); // past the 20s auto-reset
    await flushPromises();
    expect(routerPush).toHaveBeenCalledWith({ name: 'kiosk.idle' });
    wrapper.unmount();
  });
});
