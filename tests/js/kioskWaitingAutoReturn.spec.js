import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import KioskWaitingComponent from '../../resources/js/components/frontend/kiosk/KioskWaitingComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';
import paymentStatusEnum from '../../resources/js/enums/modules/paymentStatusEnum';

vi.mock('../../resources/js/services/kioskHardware', () => ({
  default: { haptic: vi.fn() },
}));

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  messages: { fr: frMessages },
});

function makeStore(orderPayload) {
  const fetchOrderStatus = vi.fn().mockResolvedValue({ data: { data: orderPayload } });
  const reset = vi.fn();

  return {
    fetchOrderStatus,
    reset,
    store: createStore({
      modules: {
        kioskCart: {
          namespaced: true,
          state: { orderRef: null, queueNumber: null, branchId: 1 },
          getters: { branchId: (state) => state.branchId },
          mutations: {
            SET_ORDER_REF(state, { orderId, queueNumber }) {
              state.orderRef = orderId;
              state.queueNumber = queueNumber;
            },
          },
          actions: {
            fetchOrderStatus: (_, orderId) => fetchOrderStatus(orderId),
            reset,
          },
        },
      },
    }),
  };
}

function mountWaiting(orderPayload) {
  const routerPush = vi.fn().mockResolvedValue();
  const ctx = makeStore(orderPayload);
  const wrapper = mount(KioskWaitingComponent, {
    global: {
      plugins: [i18n, ctx.store],
      provide: { showToast: vi.fn() },
      mocks: {
        $route: { query: { queue: orderPayload.queue_number, total: orderPayload.total } },
        $router: { push: routerPush },
      },
    },
    props: { orderId: String(orderPayload.id) },
  });

  return { wrapper, routerPush, ...ctx };
}

describe('KioskWaitingComponent auto-return after simulated payment', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('routes a paid ACCEPT order to confirmation instead of waiting for KDS prepared status', async () => {
    const { wrapper, routerPush, store } = mountWaiting({
      id: 42,
      status: orderStatusEnum.ACCEPT,
      payment_status: paymentStatusEnum.PAID,
      payment_pending_counter: false,
      queue_number: 'A0042',
      total: 12.5,
    });

    await flushPromises();

    expect(routerPush).toHaveBeenCalledWith({
      name: 'kiosk.confirmation',
      query: { number: 'A0042', total: 12.5 },
    });
    expect(store.state.kioskCart.orderRef).toBe(42);
    expect(store.state.kioskCart.queueNumber).toBe('A0042');

    wrapper.unmount();
  });

  it('routes a pending-counter order to confirmation without waiting for POS collection', async () => {
    const { wrapper, routerPush } = mountWaiting({
      id: 43,
      status: orderStatusEnum.ACCEPT,
      payment_status: paymentStatusEnum.PENDING_COUNTER,
      payment_pending_counter: true,
      queue_number: 'C0043',
      total: 18,
    });

    await flushPromises();

    expect(routerPush).toHaveBeenCalledWith({
      name: 'kiosk.confirmation',
      query: { number: 'C0043', total: 18 },
    });

    wrapper.unmount();
  });

  it('keeps PREPARING orders on the waiting screen so the ready flow remains intact', async () => {
    const { wrapper, routerPush } = mountWaiting({
      id: 44,
      status: orderStatusEnum.PREPARING,
      payment_status: paymentStatusEnum.PAID,
      payment_pending_counter: false,
      queue_number: 'A0044',
      total: 9,
    });

    await flushPromises();

    expect(routerPush).not.toHaveBeenCalled();
    expect(wrapper.vm.showCancelButton).toBe(false);

    wrapper.unmount();
  });
});
