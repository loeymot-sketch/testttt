// [test-e2e fix C-007 round-1 2026-08-16 · audit_integrity/privacy]
// DefaultComponent.vue's beforeMount() used to unconditionally fire the
// admin-authenticated bootstrap (`authcheck`, which server-side returns
// user/menu/permission) whenever `$store.getters.authStatus` was true —
// REGARDLESS of which theme/route was about to render. So a staff member
// with an active admin session cookie opening the PUBLIC tracking page
// (/suivi/:token) in the same browser still had privileged admin data land
// in that page's Vuex store / JS memory, even though the "tracking" theme
// branch renders zero admin chrome (no visible leak, but an unnecessary
// privilege-adjacent side effect on a page meant to be fully
// public/anonymous-safe).
//
// Fix: gate the beforeMount() admin-bootstrap block on the SAME meta flag
// applyThemeFromRoute() already uses to pick the "tracking" theme branch —
// `route.meta.isTracking === true` — not a parallel condition.
//
// This spec mocks out `../router` and `../services/appService` (both
// transitively drag in the real store singleton + the entire admin route
// tree) so the test stays a fast, minimal unit test of the beforeMount()
// gate — not an app-boot test. See kioskWaitingTrackingEnrichment.spec.js
// for the store/router mocking convention this follows.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';

vi.mock('../../resources/js/router', () => ({ routes: [] }));
vi.mock('../../resources/js/services/appService', () => ({
  default: { recursiveRouter: vi.fn() },
}));

import DefaultComponent from '../../resources/js/components/DefaultComponent.vue';

function makeStore({ authStatus }) {
  const authcheck = vi.fn().mockResolvedValue({ data: { status: true, permission: null } });
  const frontendSettingLists = vi.fn().mockResolvedValue({
    data: { data: { site_default_branch: 1, site_default_language: 1 } },
  });
  const globalStateInit = vi.fn();
  const store = createStore({
    state: { authStatus },
    getters: {
      authStatus: (s) => s.authStatus,
    },
    actions: {
      authcheck,
    },
    modules: {
      frontendLanguage: {
        namespaced: true,
        getters: { show: () => ({ display_mode: 'ltr' }) },
      },
      frontendSetting: {
        namespaced: true,
        actions: { lists: frontendSettingLists },
      },
      globalState: {
        namespaced: true,
        actions: { init: globalStateInit },
      },
    },
  });
  return { store, authcheck, frontendSettingLists, globalStateInit };
}

function mountDefault(route, storeCtx) {
  return shallowMount(DefaultComponent, {
    global: {
      plugins: [storeCtx.store],
      mocks: {
        $route: route,
        $router: { push: vi.fn().mockResolvedValue() },
      },
      stubs: { 'router-view': true },
    },
  });
}

describe('[test-e2e fix C-007 round-1 2026-08-16] DefaultComponent — gate admin bootstrap sur la page tracking publique', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('ne dispatch PAS authcheck quand route.meta.isTracking === true, même avec authStatus === true (staff avec cookie session admin ouvrant /suivi/:token)', async () => {
    const ctx = makeStore({ authStatus: true });
    const route = { path: '/suivi/abc123', meta: { isTracking: true } };
    const wrapper = mountDefault(route, ctx);
    await flushPromises();

    expect(ctx.authcheck).not.toHaveBeenCalled();
    expect(wrapper.vm.theme).toBe('tracking');
    wrapper.unmount();
  });

  it("dispatch authcheck normalement quand authStatus === true et la route N'EST PAS tracking (non-régression du bootstrap admin)", async () => {
    const ctx = makeStore({ authStatus: true });
    const route = { path: '/admin/dashboard', meta: {} };
    const wrapper = mountDefault(route, ctx);
    await flushPromises();

    expect(ctx.authcheck).toHaveBeenCalledTimes(1);
    expect(wrapper.vm.theme).toBe('backend');
    wrapper.unmount();
  });

  it("n'appelle pas authcheck quand authStatus === false, indépendamment du thème (comportement existant inchangé)", async () => {
    const ctx = makeStore({ authStatus: false });
    const route = { path: '/suivi/xyz', meta: { isTracking: true } };
    const wrapper = mountDefault(route, ctx);
    await flushPromises();

    expect(ctx.authcheck).not.toHaveBeenCalled();
    wrapper.unmount();
  });
});
