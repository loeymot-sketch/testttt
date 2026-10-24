import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
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
    vi.restoreAllMocks();
  });

  it('ignores stale kiosk maintenance mode and still auto-logins', async () => {
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

    await flushPromises();

    expect(kioskLogin.mock.calls[0][1]).toEqual({
      username: 'kiosk-state',
      password: 'kiosk123',
    });
    expect(replace).toHaveBeenCalledWith({ name: 'kiosk.idle' });
    expect(wrapper.vm.error).not.toBe(frMessages.kiosk.login_screen.err_maintenance);
  });

  it('reloads the server bootstrap when the public retry has no credentials', async () => {
    window.foodkingConfig = { kioskAutoLogin: null };
    const reload = vi.spyOn(window.location, 'reload').mockImplementation(() => {});
    const kioskLogin = vi.fn();
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
        mocks: { $router: { replace: vi.fn() } },
      },
    });

    await flushPromises();
    expect(wrapper.vm.setupRequired).toBe(true);

    await wrapper.get('button.kiosk-login-btn').trigger('click');

    expect(reload).toHaveBeenCalledOnce();
    expect(kioskLogin).not.toHaveBeenCalled();
  });
});
