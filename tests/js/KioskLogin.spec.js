import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import KioskLoginComponent from '../../resources/js/components/frontend/kiosk/KioskLoginComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

describe('KioskLoginComponent', () => {
  beforeEach(() => {
    window.foodkingConfig = {
      kioskAutoLogin: {
        username: 'kiosk-state',
        password: 'kiosk123',
      },
    };
    sessionStorage.clear();
  });

  afterEach(() => {
    sessionStorage.clear();
  });

  it('does not auto-login while kiosk maintenance mode is enabled', async () => {
    sessionStorage.setItem('kiosk_maintenance_mode', '1');
    const kioskLogin = vi.fn().mockResolvedValue({});
    const replace = vi.fn();
    const store = createStore({
      modules: {
        kioskCart: {
          namespaced: true,
          actions: { kioskLogin },
        },
      },
    });

    const wrapper = mount(KioskLoginComponent, {
      global: {
        plugins: [store, i18n],
        mocks: {
          $router: { replace },
        },
      },
    });

    await Promise.resolve();
    expect(kioskLogin).not.toHaveBeenCalled();
    expect(replace).not.toHaveBeenCalled();
    expect(wrapper.vm.error).toBe(frMessages.kiosk.login_screen.err_maintenance);
  });
});
