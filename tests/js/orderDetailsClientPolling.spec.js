import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import frMessages from '../../resources/js/languages/fr.json';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

// Composants enfants stubés (maps / impression) — hors périmètre du polling.
vi.mock('../../resources/js/components/frontend/account/myOrder/OrderDetailsMapComponent', () => ({
  default: { name: 'OrderDetailsMapComponent', render: () => null },
}));
vi.mock('../../resources/js/components/frontend/account/myOrder/FrontendOrderReceiptComponent', () => ({
  default: { name: 'FrontendOrderReceiptComponent', render: () => null },
}));
vi.mock('../../resources/js/components/frontend/components/OrderStatusComponent', () => ({
  default: { name: 'OrderStatusComponent', render: () => null },
}));
vi.mock('../../resources/js/components/admin/components/LoadingComponent', () => ({
  default: { name: 'LoadingComponent', render: () => null },
}));

import OrderDetailsComponent from '../../resources/js/components/frontend/account/myOrder/OrderDetailsComponent.vue';

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  messages: { fr: frMessages },
});

const POLL_MS = 15000;

/**
 * Store de test : le getter `frontendOrder/show` reflète l'état courant,
 * l'action `show` peut faire évoluer le statut pour simuler une transition
 * (ex. passage à DELIVERED lors d'un tick de polling).
 */
function makeStore(showAction, initialStatus = orderStatusEnum.PENDING) {
  return createStore({
    modules: {
      frontendOrder: {
        namespaced: true,
        state: {
          show: { status: initialStatus, order_serial_no: 'A1', order_type: 2 },
          orderItems: [],
          orderUser: {},
          orderBranch: {},
          orderAddress: null,
        },
        getters: {
          show: (s) => s.show,
          orderItems: (s) => s.orderItems,
          orderUser: (s) => s.orderUser,
          orderBranch: (s) => s.orderBranch,
          orderAddress: (s) => s.orderAddress,
        },
        actions: { show: showAction },
      },
      frontendSetting: {
        namespaced: true,
        getters: { lists: () => ({}) },
      },
    },
  });
}

function mountComponent(store, routeId = 42) {
  return shallowMount(OrderDetailsComponent, {
    global: {
      plugins: [i18n, store],
      mocks: { $route: { params: { id: routeId } } },
      stubs: { 'router-link': true, RouterLink: true },
    },
  });
}

function countShowDispatches(spy) {
  return spy.mock.calls.filter((c) => c[0] === 'frontendOrder/show').length;
}

describe('OrderDetailsComponent — polling de fraîcheur du statut client', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it('re-dispatch frontendOrder/show toutes les 15s après le premier fetch', async () => {
    const showAction = vi.fn(() => Promise.resolve());
    const store = makeStore(showAction);
    const dispatchSpy = vi.spyOn(store, 'dispatch');
    const wrapper = mountComponent(store);

    await flushPromises();
    // 1 seul fetch initial, pas encore de tick.
    expect(countShowDispatches(dispatchSpy)).toBe(1);
    expect(wrapper.vm.pollIntervalId).not.toBeNull();

    await vi.advanceTimersByTimeAsync(POLL_MS);
    expect(countShowDispatches(dispatchSpy)).toBe(2);

    await vi.advanceTimersByTimeAsync(POLL_MS);
    expect(countShowDispatches(dispatchSpy)).toBe(3);

    wrapper.unmount();
  });

  it('clearInterval au beforeUnmount — aucune fuite ni fetch après démontage', async () => {
    const showAction = vi.fn(() => Promise.resolve());
    const store = makeStore(showAction);
    const dispatchSpy = vi.spyOn(store, 'dispatch');
    const wrapper = mountComponent(store);

    await flushPromises();
    expect(countShowDispatches(dispatchSpy)).toBe(1);

    wrapper.unmount();
    expect(wrapper.vm.pollIntervalId).toBeNull();

    // Après démontage, le temps qui passe ne déclenche plus aucun fetch.
    await vi.advanceTimersByTimeAsync(POLL_MS * 3);
    expect(countShowDispatches(dispatchSpy)).toBe(1);
  });

  it('arrête le polling dès que le statut devient terminal (DELIVERED)', async () => {
    // Le 2e appel (premier tick) fait passer la commande à DELIVERED.
    let calls = 0;
    const showAction = vi.fn((ctx) => {
      calls += 1;
      if (calls >= 2) {
        ctx.state.show = { ...ctx.state.show, status: orderStatusEnum.DELIVERED };
      }
      return Promise.resolve();
    });
    const store = makeStore(showAction);
    const dispatchSpy = vi.spyOn(store, 'dispatch');
    const wrapper = mountComponent(store);

    await flushPromises();
    expect(countShowDispatches(dispatchSpy)).toBe(1); // fetch initial (PENDING)

    await vi.advanceTimersByTimeAsync(POLL_MS); // tick 1 → DELIVERED → stop
    expect(countShowDispatches(dispatchSpy)).toBe(2);
    expect(wrapper.vm.pollIntervalId).toBeNull();

    // Plus aucun fetch après état terminal.
    await vi.advanceTimersByTimeAsync(POLL_MS * 3);
    expect(countShowDispatches(dispatchSpy)).toBe(2);

    wrapper.unmount();
  });

  it('ne démarre pas de polling si la commande est déjà terminale au montage', async () => {
    const showAction = vi.fn(() => Promise.resolve());
    const store = makeStore(showAction, orderStatusEnum.CANCELED);
    const dispatchSpy = vi.spyOn(store, 'dispatch');
    const wrapper = mountComponent(store);

    await flushPromises();
    expect(countShowDispatches(dispatchSpy)).toBe(1); // fetch initial seulement
    expect(wrapper.vm.pollIntervalId).toBeNull();

    await vi.advanceTimersByTimeAsync(POLL_MS * 3);
    expect(countShowDispatches(dispatchSpy)).toBe(1);

    wrapper.unmount();
  });

  it('ne démarre pas de polling sans ID de commande (défensif)', async () => {
    const showAction = vi.fn(() => Promise.resolve());
    const store = makeStore(showAction);
    const dispatchSpy = vi.spyOn(store, 'dispatch');
    // Route sans params.id : mounted() ne fetch pas et ne démarre pas de polling.
    const wrapper = shallowMount(OrderDetailsComponent, {
      global: {
        plugins: [i18n, store],
        mocks: { $route: { params: {} } },
        stubs: { 'router-link': true, RouterLink: true },
      },
    });

    await flushPromises();
    expect(countShowDispatches(dispatchSpy)).toBe(0);
    expect(wrapper.vm.pollIntervalId).toBeNull();

    await vi.advanceTimersByTimeAsync(POLL_MS * 2);
    expect(countShowDispatches(dispatchSpy)).toBe(0);

    wrapper.unmount();
  });
});
