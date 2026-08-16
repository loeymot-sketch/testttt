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

function mountDefault(route, storeCtx, routerOverrides = {}) {
  return shallowMount(DefaultComponent, {
    global: {
      plugins: [storeCtx.store],
      mocks: {
        $route: route,
        $router: {
          push: vi.fn().mockResolvedValue(),
          isReady: vi.fn().mockResolvedValue(),
          ...routerOverrides,
        },
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

  // [test-e2e fix C-007 round-2 2026-08-16] Le premier fix vérifiait
  // this.$route.meta directement dans beforeMount() — correct pour l'ordre
  // des hooks du COMPOSANT, mais faux pour la résolution ASYNCHRONE du
  // ROUTER : app.js appelle app.mount('#app') sans attendre router.isReady().
  // Sur un chargement à froid réel (QR borne / lien direct — jamais une
  // navigation interne), $route.meta est encore undefined à l'instant où
  // beforeMount() s'exécute → le gate voyait isTracking=undefined
  // (!==true) et laissait partir authcheck quand même. Prouvé par
  // test-e2e round-2 (Wave C) : authcheck observé dans network.json sur
  // CHAQUE chargement à froid malgré le "fix" round-1.
  it("attend router.isReady() avant d'évaluer le gate — authcheck ne part PAS avant la résolution même si route.meta est déjà isTracking:true au montage (simule le chargement à froid réel)", async () => {
    const ctx = makeStore({ authStatus: true });
    // Simule l'état RÉEL au premier rendu à froid : $route.meta n'est pas
    // encore résolu (objet vide), comme le router non-ready le laisserait.
    const route = { path: '/suivi/abc123', meta: {} };
    let resolveReady;
    const isReady = vi.fn(() => new Promise((r) => { resolveReady = r; }));
    const wrapper = mountDefault(route, ctx, { isReady });

    // Avant résolution du router : authcheck ne doit JAMAIS être parti,
    // même si (dans ce test) route.meta ne changera pas — le point est
    // que le gate ne s'évalue PAS avant isReady().
    await flushPromises();
    expect(isReady).toHaveBeenCalledTimes(1);
    expect(ctx.authcheck).not.toHaveBeenCalled();

    // Le router se résout maintenant — à cet instant, dans la vraie appli,
    // $route serait réactivement à jour (ici on simule directement le cas
    // où la résolution a corrigé meta.isTracking=true, le lien réel testé).
    wrapper.vm.$route.meta.isTracking = true;
    resolveReady();
    await flushPromises();

    expect(ctx.authcheck, 'authcheck ne doit jamais partir pour une route isTracking, résolue ou non').not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it("attend router.isReady() puis dispatch authcheck normalement pour une route admin résolue APRÈS le montage (cas réel non-tracking)", async () => {
    const ctx = makeStore({ authStatus: true });
    const route = { path: '/admin/dashboard', meta: {} };
    let resolveReady;
    const isReady = vi.fn(() => new Promise((r) => { resolveReady = r; }));
    const wrapper = mountDefault(route, ctx, { isReady });

    await flushPromises();
    expect(ctx.authcheck, 'ne doit pas partir avant que le router soit prêt').not.toHaveBeenCalled();

    wrapper.vm.$route.meta.isTracking = false;
    resolveReady();
    await flushPromises();

    expect(ctx.authcheck).toHaveBeenCalledTimes(1);
    wrapper.unmount();
  });

  // [test-e2e fix C-007 round-3 2026-08-16] Trouvé par l'audit adversarial
  // round-2 (Wave C) : le fix round-2 ne gardait QUE le dispatch authcheck
  // derrière isReady() — mais created() appelait TOUJOURS
  // applyThemeFromRoute() SYNCHRONEMENT, avant toute résolution. Avec
  // $route.meta non résolu, aucune branche isKiosk/isFrontend/isTable/
  // isTracking ne matchait, donc theme tombait dans le else final :
  // "backend" — montant BRIÈVEMENT BackendNavbarComponent, dont created()
  // dispatche LUI-MÊME (fichier séparé, indépendant de ce gate)
  // admin/default-access + admin/setting/branch AVANT que watch:$route ne
  // corrige theme→"tracking". Preuve terrain : ces 2 appels visibles dans
  // CHAQUE console.json admin-cookie malgré le "fix" round-2 (network.json
  // ne les capture pas — mega-audit-snap.js ne journalise que les requêtes
  // en échec/lentes/mutation).
  it("le theme reste 'frontend' (valeur sûre par défaut, JAMAIS 'backend') tant que router.isReady() n'est pas résolu — empêche le montage furtif de BackendNavbarComponent", async () => {
    const ctx = makeStore({ authStatus: true });
    const route = { path: '/suivi/abc123', meta: {} }; // état réel au chargement à froid
    let resolveReady;
    const isReady = vi.fn(() => new Promise((r) => { resolveReady = r; }));
    const wrapper = mountDefault(route, ctx, { isReady });

    // Avant résolution : theme ne doit JAMAIS être passé à "backend" — created()
    // ne détermine plus le theme du tout, il reste à data()'s "frontend" sûr.
    await flushPromises();
    expect(wrapper.vm.theme, 'theme ne doit pas devenir "backend" avant la résolution du router').not.toBe('backend');
    expect(wrapper.vm.theme).toBe('frontend');

    // Le router se résout — meta reste vide (page non-tracking résolue en /admin/x par ex.)
    resolveReady();
    await flushPromises();

    // Seulement APRÈS résolution, le theme peut légitimement devenir "backend".
    expect(wrapper.vm.theme).toBe('backend');
    wrapper.unmount();
  });
});
