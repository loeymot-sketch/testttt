/**
 * Kiosk waiting — audio failure triggers visual fallback (toast + flash) (W7.B δ).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import KioskWaitingComponent from '../../resources/js/components/frontend/kiosk/KioskWaitingComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const showToast = vi.fn();

vi.mock('../../resources/js/services/kioskHardware', () => ({
  default: {
    haptic: vi.fn(),
  },
}));

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  messages: { fr: frMessages },
});

describe('KioskWaitingComponent playReadySound visual fallback', () => {
  beforeEach(() => {
    showToast.mockClear();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('when AudioContext throws, emits toast info and applies flash class', async () => {
    class ThrowingCtx {
      constructor() {
        throw new Error('no audio');
      }
    }
    window.AudioContext = ThrowingCtx;
    window.webkitAudioContext = ThrowingCtx;

    const store = createStore({
      modules: {
        kioskCart: {
          namespaced: true,
          actions: { fetchOrderStatus: vi.fn(), reset: vi.fn() },
        },
      },
    });

    const wrapper = mount(KioskWaitingComponent, {
      global: {
        plugins: [i18n, store],
        provide: { showToast },
        mocks: {
          $route: { query: { queue: 'B7' } },
          $router: { push: vi.fn() },
        },
      },
      props: { orderId: 'offline_w7b_test' },
    });

    await wrapper.vm.playReadySound();

    expect(showToast).toHaveBeenCalledWith(frMessages.kiosk.waiting.ready_visual_fallback, 'info', 4000);
    expect(wrapper.find('[data-testid="kiosk-waiting-root"]').classes()).toContain('kiosk-ready-flash');
  });
});
