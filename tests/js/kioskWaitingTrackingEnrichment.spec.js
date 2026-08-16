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

// [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] `tracking` est un sibling top-level de
// `data` (OrderDetailsResource::additional(), voir OrderController::trackingPayload) —
// PAS nested dans `data.data`. Ce fixture reproduit exactement cette forme.
function makeStore(orderPayload, tracking) {
  const fetchOrderStatus = vi.fn().mockResolvedValue({ data: { data: orderPayload, tracking } });
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

function mountWaiting(orderPayload, tracking) {
  const ctx = makeStore(orderPayload, tracking);
  const wrapper = mount(KioskWaitingComponent, {
    global: {
      plugins: [i18n, ctx.store],
      provide: { showToast: vi.fn() },
      mocks: { $route: { query: { queue: orderPayload.queue_number, total: orderPayload.total } }, $router: { push: vi.fn().mockResolvedValue() } },
    },
    props: { orderId: String(orderPayload.id) },
  });
  return { wrapper, ...ctx };
}

describe('[T-C SUIVI-CLIENT 2026-08-16] KioskWaitingComponent — position/temps/QR suivi', () => {
  afterEach(() => { vi.restoreAllMocks(); vi.useRealTimers(); });

  it('affiche la position + la fourchette de temps quand almost_ready=false', async () => {
    const { wrapper } = mountWaiting(
      { id: 60, status: orderStatusEnum.PREPARING, queue_number: 'A0060', total: 12 },
      { tracking_token: 'TOKEN60', position_ahead: 4, almost_ready: false, wait_low: 20, wait_high: 25 },
    );
    await flushPromises();

    expect(wrapper.find('[data-testid="kiosk-position-ahead"]').text()).toContain('4');
    expect(wrapper.find('[data-testid="kiosk-wait-estimate"]').text()).toContain('20-25 min');
    expect(wrapper.find('[data-testid="kiosk-almost-ready"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('remplace position/temps par le bandeau "presque prête" quand almost_ready=true', async () => {
    const { wrapper } = mountWaiting(
      { id: 61, status: orderStatusEnum.PREPARING, queue_number: 'A0061', total: 12 },
      { tracking_token: 'TOKEN61', position_ahead: 2, almost_ready: true, wait_low: 15, wait_high: 20 },
    );
    await flushPromises();

    expect(wrapper.find('[data-testid="kiosk-almost-ready"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="kiosk-position-ahead"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('affiche le QR de suivi téléphone avec le bon tracking_token dans l\'URL', async () => {
    const { wrapper } = mountWaiting(
      { id: 62, status: orderStatusEnum.ACCEPT, queue_number: 'A0062', total: 12 },
      { tracking_token: 'ABCDEF48TOKEN', position_ahead: 1, almost_ready: true, wait_low: 15, wait_high: 20 },
    );
    await flushPromises();

    const qr = wrapper.find('[data-testid="kiosk-track-qr"] img');
    expect(qr.exists()).toBe(true);
    expect(qr.attributes('src')).toContain('/api/frontend/order/track-qr/ABCDEF48TOKEN');
    wrapper.unmount();
  });

  it('n\'affiche ni le QR ni le méta-bloc quand la réponse n\'a pas encore de tracking (dégradation propre)', async () => {
    const { wrapper } = mountWaiting(
      { id: 63, status: orderStatusEnum.ACCEPT, queue_number: 'A0063', total: 12 },
      undefined,
    );
    await flushPromises();

    expect(wrapper.find('[data-testid="kiosk-track-qr"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="kiosk-position-ahead"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="kiosk-almost-ready"]').exists()).toBe(false);
    wrapper.unmount();
  });
});
