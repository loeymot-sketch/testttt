import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import KioskConfirmationComponent from '../../resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const escPosPrint = vi.fn();
const clearSnapshot = vi.fn();

vi.mock('../../resources/js/helpers/kioskPrinter', () => ({
  printReceipt: (...args) => escPosPrint(...args),
  buildReceiptData: vi.fn(() => ({})),
  reportPrinterFailure: vi.fn(),
}));

vi.mock('../../resources/js/composables/useKioskSpeech', () => ({
  useKioskSpeech: () => ({ speak: vi.fn().mockResolvedValue(), stop: vi.fn() }),
}));

vi.mock('../../resources/js/helpers/kioskReceiptPersistence', () => ({
  saveKioskReceiptSnapshot: vi.fn(),
  readKioskReceiptSnapshot: vi.fn(() => null),
  clearKioskReceiptSnapshot: () => clearSnapshot(),
}));

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  messages: { fr: frMessages },
});

function makeStore(reset) {
  return createStore({
    modules: {
      kioskCart: {
        namespaced: true,
        state: {
          items: [{ item_id: 1, name: 'Tacos', quantity: 1, total: 8 }],
          queueNumber: 'A0005',
          loyaltyDiscount: 0,
          paymentMethod: 'card',
        },
        getters: { total: () => 8 },
        actions: { reset },
      },
      globalState: {
        namespaced: true,
        state: { lists: { company_name: 'Le Cayenne', loyalty_points_per_euro: 0 } },
      },
    },
  });
}

describe('KioskConfirmationComponent countdown auto-return', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    window.foodkingConfig = { kioskConfirmationAutoReturnSeconds: 2 };
    escPosPrint.mockResolvedValue({ method: 'none', error: 'printer disabled in test' });
    clearSnapshot.mockClear();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete window.foodkingConfig;
  });

  it('returns to kiosk idle and resets the cart when the countdown reaches zero', async () => {
    const reset = vi.fn();
    const routerPush = vi.fn().mockResolvedValue();
    const wrapper = mount(KioskConfirmationComponent, {
      global: {
        plugins: [i18n, makeStore(reset)],
        mocks: { $router: { push: routerPush } },
      },
      props: { orderNumber: 'A0005', orderTotal: 8 },
    });

    await flushPromises();
    await vi.advanceTimersByTimeAsync(2000);

    expect(routerPush).toHaveBeenCalledWith({ name: 'kiosk.idle' });
    expect(reset).toHaveBeenCalled();
    expect(clearSnapshot).toHaveBeenCalled();

    wrapper.unmount();
  });
});
