/**
 * Kiosk confirmation — print failed fallback shows full on-screen receipt (W7.B α).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import KioskConfirmationComponent from '../../resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const escPosPrint = vi.fn();

vi.mock('../../resources/js/helpers/kioskPrinter', () => ({
  printReceipt: (...args) => escPosPrint(...args),
  buildReceiptData: vi.fn(() => ({})),
  reportPrinterFailure: vi.fn(),
}));

vi.mock('../../resources/js/composables/useKioskSpeech', () => ({
  useKioskSpeech: () => ({ speak: vi.fn(), stop: vi.fn() }),
}));

vi.mock('../../resources/js/helpers/kioskReceiptPersistence', () => ({
  saveKioskReceiptSnapshot: vi.fn(),
  readKioskReceiptSnapshot: vi.fn(() => null),
  clearKioskReceiptSnapshot: vi.fn(),
}));

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  messages: { fr: frMessages },
});

describe('KioskConfirmationComponent print-failed fallback', () => {
  beforeEach(() => {
    escPosPrint.mockResolvedValue({ method: 'none', error: 'test' });
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('shows the full receipt block when printFailed is true (data-print-failed + heading)', async () => {
    const store = createStore({
      modules: {
        kioskCart: {
          namespaced: true,
          state: { items: [], queueNumber: 'A12', loyaltyDiscount: 0 },
          getters: { total: () => 9.9 },
          actions: { reset: vi.fn() },
        },
        globalState: {
          namespaced: true,
          state: { lists: { company_name: 'Test', loyalty_points_per_euro: 0 } },
        },
      },
    });

    const wrapper = mount(KioskConfirmationComponent, {
      global: {
        plugins: [i18n, store],
        mocks: {
          $router: { push: vi.fn().mockResolvedValue() },
        },
      },
      props: { orderNumber: 'A12', orderTotal: 9.9 },
      attachTo: document.body,
    });

    await wrapper.vm.$nextTick();
    await new Promise((r) => setTimeout(r, 0));

    wrapper.setData({ printFailed: true });
    await wrapper.vm.$nextTick();

    const zone = wrapper.find('[data-testid="kiosk-print-receipt"]');
    expect(zone.exists()).toBe(true);
    expect(zone.attributes('data-print-failed')).toBe('true');
    expect(wrapper.find('.kiosk-fallback-receipt-title').text()).toContain('Votre ticket');

    wrapper.unmount();
  });
});
