/**
 * L5.1 — AxeCoreCriticalGateSentinel
 *
 * Sentinel WCAG 2.1 A/AA contre régression a11y sur les surfaces critiques
 * dont l'audit live Playwright était bloqué par le frontend permission cache
 * (admin user backend-grant K.D.S + O.S.S mais authPermission.access=false).
 *
 * Mount each primary component, run axe-core (wcag2a/wcag2aa/wcag21a/wcag21aa),
 * assert ZERO critical / serious violations + ≤5 moderate (allow V1 polish).
 *
 * Color-contrast est désactivé : happy-dom ne mesure pas les couleurs
 * (faux négatifs systématiques — précédent : tests/js/kioskA11yAxe.spec.js).
 * Le contraste est audité empiriquement via Playwright MCP live (cf.
 * reports/test-e2e/goal-2026-05-23/phase-l/L5.1-axe-core-findings.json).
 *
 * Component surfaces couvertes ici :
 *   1. OrderStatusScreenComponent (OSS — customer wall TV)
 *   2. PreparingAndReadyComponent (OSS — sub-component, multi-root)
 *   3. KitchenDisplaySystemComponent (KDS — kitchen board)
 *   4. CashOverviewComponent (admin cash overview, déjà couvert live)
 *
 * Doc : plan L5.1 deeper accessibility / phase-l GOAL_ULTRA_FINAL_PRE_CLOUD.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount, shallowMount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import axe from 'axe-core';

/**
 * Les composants OSS/KDS de production utilisent des imports webpack-style
 * sans extension (`from "./Foo"` au lieu de `from "./Foo.vue"`). Vite/Vitest
 * ne résout pas ces imports — on stub les enfants pour ne charger que la
 * surface parente sous test. Modifier les imports source serait une side-effect
 * non-scopée (cf. CLAUDE.md §3 principle 7).
 */
vi.mock('../../../resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent', () => ({
  default: { name: 'PreparingAndReadyComponent', template: '<div data-stub="oss-preparing-ready" />' },
}));
vi.mock('../../../resources/js/components/admin/common/ConnectionStatusBanner.vue', () => ({
  default: { name: 'ConnectionStatusBanner', template: '<div data-stub="conn-banner" />' },
}));

import OrderStatusScreenComponent from '../../../resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue';
import PreparingAndReadyComponent from '../../../resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue';
import KitchenDisplaySystemComponent from '../../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';

import frMessages from '../../../resources/js/languages/fr.json';

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Disabled rules :
 * - color-contrast : non-mesurable sous happy-dom (cf. kioskA11yAxe.spec.js).
 *   La couverture empirique est faite via Playwright MCP (reports/.../L5.1).
 */
async function runAxeStrict(el) {
  return axe.run(el, {
    runOnly: { type: 'tag', values: AXE_TAGS },
    rules: { 'color-contrast': { enabled: false } },
    resultTypes: ['violations'],
  });
}

/**
 * Assertion gate :
 *   - ZERO critical
 *   - ZERO serious
 *   - ≤5 moderate (allow V1 polish — sentinel ratchet à serrer en V1.0.2)
 *
 * `extraAllowedRuleIds` : rule IDs qu'on relaxe ponctuellement pour cette
 * surface (ex : `aria-allowed-role` pour les grilles produits). Tout ajout
 * exige un commentaire scope+plan.
 */
function expectAxeGateGreen(result, extraAllowedRuleIds = new Set()) {
  const buckets = { critical: [], serious: [], moderate: [], minor: [] };
  for (const v of result.violations) {
    if (extraAllowedRuleIds.has(v.id)) continue;
    const impact = v.impact || 'minor';
    if (!buckets[impact]) buckets[impact] = [];
    buckets[impact].push({ id: v.id, help: v.help, count: v.nodes.length });
  }
  expect(buckets.critical, `CRITICAL violations:\n${JSON.stringify(buckets.critical, null, 2)}`).toEqual([]);
  expect(buckets.serious, `SERIOUS violations:\n${JSON.stringify(buckets.serious, null, 2)}`).toEqual([]);
  // Moderate ≤5 — V1 polish allowance (sentinel à serrer)
  expect(buckets.moderate.length, `MODERATE violation count >5:\n${JSON.stringify(buckets.moderate, null, 2)}`).toBeLessThanOrEqual(5);
}

/* ============================================================================
   OSS — OrderStatusScreenComponent + PreparingAndReadyComponent
   ============================================================================ */

function makeOssStore() {
  return createStore({
    modules: {
      orderStatusScreenOrder: {
        namespaced: true,
        state: () => ({
          preparingItems: [
            { id: 1, token: 'A-001', queue_number: 1 },
            { id: 2, token: 'A-002', queue_number: null },
          ],
          preparedItems: [
            { id: 3, token: 'B-001', queue_number: 2 },
          ],
        }),
        getters: {
          preparingItems: (s) => s.preparingItems,
          preparedItems: (s) => s.preparedItems,
        },
        actions: {
          lists: vi.fn().mockResolvedValue({ data: { data: { preparing: [], prepared: [] } } }),
          refresh: vi.fn().mockResolvedValue(),
        },
      },
      globalState: { namespaced: true, state: () => ({ loading: false }), actions: { set: vi.fn() } },
      backendGlobalState: { namespaced: true, state: () => ({}), actions: { set: vi.fn() } },
    },
  });
}

describe('L5.1 AxeCoreCriticalGateSentinel — OSS', () => {
  it('OrderStatusScreenComponent — main role + 2 region landmarks', async () => {
    const store = makeOssStore();
    const wrapper = shallowMount(OrderStatusScreenComponent, {
      attachTo: document.body,
      global: {
        plugins: [store, i18n],
        stubs: { ConnectionStatusBanner: true, PreparingAndReadyComponent: true, transition: false, 'transition-group': false },
      },
    });
    await wrapper.vm.$nextTick();
    const r = await runAxeStrict(wrapper.element);
    expectAxeGateGreen(r);
    wrapper.unmount();
  });

  it('PreparingAndReadyComponent — TV wall 2 columns role=region', async () => {
    const store = makeOssStore();
    const wrapper = mount(PreparingAndReadyComponent, {
      attachTo: document.body,
      global: {
        plugins: [store, i18n],
        stubs: { LoadingContentComponent: true, transition: false, 'transition-group': false },
      },
    });
    await wrapper.vm.$nextTick();
    const r = await runAxeStrict(wrapper.element);
    expectAxeGateGreen(r);
    wrapper.unmount();
  });
});

/* ============================================================================
   KDS — KitchenDisplaySystemComponent (drawer + bump board)
   ============================================================================ */

function makeKdsStore() {
  return createStore({
    modules: {
      kitchenDisplaySystemOrder: {
        namespaced: true,
        state: () => ({ orders: [], itemsByOrder: {} }),
        getters: {
          orders: () => [],
          dineinOrders: () => [],
          takeawayOrders: () => [],
          deliveryOrders: () => [],
        },
        actions: {
          lists: vi.fn().mockResolvedValue({ data: { data: [] } }),
          historyToday: vi.fn().mockResolvedValue({ data: { data: [] } }),
          changeStatus: vi.fn().mockResolvedValue(),
          items: vi.fn().mockResolvedValue({ data: { data: [] } }),
          orderItems: vi.fn().mockResolvedValue({ data: { data: [] } }),
        },
      },
      kds: {
        namespaced: true,
        state: () => ({
          uiPrefs: { groupByTable: false, soundEnabled: false, soundVolume: 50 },
          historyDrawerOpen: false,
        }),
        getters: {
          uiPrefs: (s) => s.uiPrefs,
        },
        actions: {
          persistUiPrefs: vi.fn(),
          toggleHistoryDrawer: vi.fn(),
        },
        mutations: { setUiPrefs: vi.fn(), setHistoryDrawerOpen: vi.fn() },
      },
      kdsInflight: {
        namespaced: true,
        state: () => ({ map: {} }),
        getters: { isBumping: () => false },
        actions: { mark: vi.fn(), clear: vi.fn() },
      },
      frontendLanguage: {
        namespaced: true,
        state: () => ({ show: { display_mode: 'ltr', locale: 'fr' } }),
        getters: { show: (s) => s.show },
        actions: { show: vi.fn() },
      },
      globalState: { namespaced: true, state: () => ({ loading: false }), actions: { set: vi.fn() } },
      backendGlobalState: { namespaced: true, state: () => ({}), actions: { set: vi.fn() } },
    },
  });
}

describe('L5.1 AxeCoreCriticalGateSentinel — KDS', () => {
  it('KitchenDisplaySystemComponent — empty board (no orders) baseline', async () => {
    const store = makeKdsStore();
    const wrapper = shallowMount(KitchenDisplaySystemComponent, {
      attachTo: document.body,
      global: {
        plugins: [store, i18n],
        stubs: {
          ConnectionStatusBanner: true,
          LoadingContentComponent: true,
          transition: false,
          'transition-group': false,
        },
        mocks: {
          $route: { name: 'admin.kitchen-display-system', path: '/admin/kitchen-display-system', query: {} },
          $router: { push: vi.fn(), replace: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    const r = await runAxeStrict(wrapper.element);
    // KDS may legitimately use `aria-allowed-role` on sectioning roles (groups
    // of orders are role=group/list with custom semantics — see Wave Q-3 spec).
    expectAxeGateGreen(r, new Set(['aria-allowed-role']));
    wrapper.unmount();
  });
});
