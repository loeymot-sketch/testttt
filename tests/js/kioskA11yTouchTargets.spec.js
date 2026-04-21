/**
 * W6.A — Cibles tactiles ≥ 44×44 (WCAG 2.5.5) sur contrôles kiosk critiques.
 * happy-dom ne fournit pas de layout fiable : on combine présence DOM + contrat CSS
 * (fichier tokens + styles SFC) au lieu de getBoundingClientRect() seul.
 */
import { describe, it, expect, vi } from 'vitest';
import { shallowMount, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { readFileSync } from 'fs';
import { resolve } from 'path';

import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import KioskCategoriesComponent from '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue';
import KsChip from '../../resources/js/components/frontend/kiosk/ds/KsChip.vue';
import { createStore } from 'vuex';
import { kioskSettings } from '../../resources/js/store/modules/kioskSettings';
import frMessages from '../../resources/js/languages/fr.json';

const ROOT = process.cwd();
const MIN = 44;

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

/** @param {string} val */
function pxValue(val) {
  const m = String(val).trim().match(/^(\d+(?:\.\d+)?)px$/i);
  return m ? parseFloat(m[1]) : null;
}

/**
 * Vérifie qu’un bloc de règle CSS garantit une cible tactile ≥ MIN (hauteur ou largeur min).
 * Accepte min-height / min-width en px ou var(--kiosk-touch*).
 * @param {string} blockBody
 * @param {string} label
 */
function assertCssBlockTouchMin(blockBody, label) {
  expect(blockBody, `${label}: bloc CSS manquant`).toBeTruthy();
  const minH = blockBody.match(/min-height:\s*([^;]+);?/);
  const minW = blockBody.match(/min-width:\s*([^;]+);?/);
  const height = blockBody.match(/(?:^|[\s{;])height:\s*([^;]+);?/m);
  const width = blockBody.match(/(?:^|[\s{;])width:\s*([^;]+);?/m);

  const candidates = [];
  if (minH) candidates.push(minH[1].trim());
  if (minW) candidates.push(minW[1].trim());
  if (height) candidates.push(height[1].trim());
  if (width) candidates.push(width[1].trim());

  expect(candidates.length, `${label}: aucune dimension tactile`).toBeGreaterThan(0);

  let ok = false;
  for (const c of candidates) {
    if (/var\(\s*--kiosk-touch/i.test(c)) {
      ok = true;
      break;
    }
    const px = pxValue(c);
    if (px != null && px >= MIN) {
      ok = true;
      break;
    }
  }
  expect(ok, `${label}: attendu min-height/min-width ≥ ${MIN}px ou var(--kiosk-touch*)`).toBe(true);
}

function readVueStyle(relPath) {
  const full = resolve(ROOT, relPath);
  const raw = readFileSync(full, 'utf8');
  const m = raw.match(/<style[^>]*>([\s\S]*?)<\/style>/);
  return m ? m[1] : '';
}

const wizardStubs = Object.fromEntries(
  [
    'KioskStepPain',
    'KioskStepTaille',
    'KioskStepViande',
    'KioskStepSauce',
    'KioskStepGarnitures',
    'KioskStepSupplements',
    'KioskStepMenu',
    'KioskOrderSummary',
    'KsAllergenBadge',
  ].map((n) => [n, true])
);

describe('kiosk A11y — touch targets ≥ 44px', () => {
  it('kiosk-wizard.css — classes contractuelles tactiles (min ≥ 44px ou token)', () => {
    const cssPath = resolve(ROOT, 'resources/css/kiosk-wizard.css');
    const css = readFileSync(cssPath, 'utf8');

    const rules = [
      [/\.kiosk-touch-btn\s*\{([^}]+)\}/, '.kiosk-touch-btn'],
      [/\.kiosk-touch-option\s*\{([^}]+)\}/, '.kiosk-touch-option'],
      [/\.kiosk-counter-btn\s*\{([^}]+)\}/, '.kiosk-counter-btn'],
      [/\.kiosk-input\s*\{([^}]+)\}/, '.kiosk-input'],
    ];
    for (const [re, label] of rules) {
      const m = css.match(re);
      assertCssBlockTouchMin(m ? m[1] : '', label);
    }
  });

  it('KioskWizardComponent.vue — styles header / stepper / nav (contrat CSS ≥ 44px)', () => {
    const css = readVueStyle('resources/js/components/frontend/kiosk/KioskWizardComponent.vue');
    const pairs = [
      [/\.kiosk-progress-arrow\s*\{([^}]+)\}/, '.kiosk-progress-arrow'],
      [/\.kiosk-wizard-close\s*\{([^}]+)\}/, '.kiosk-wizard-close'],
      [/\.kiosk-btn-abandon,\s*\.kiosk-btn-back,\s*\.kiosk-btn-next\s*\{([^}]+)\}/, '.kiosk-btn-abandon/.kiosk-btn-back/.kiosk-btn-next'],
    ];
    for (const [re, label] of pairs) {
      const m = css.match(re);
      assertCssBlockTouchMin(m ? m[1] : '', label);
    }
  });

  it('KioskCategoriesComponent.vue — .kiosk-top-chip (contrat CSS)', () => {
    const css = readVueStyle('resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue');
    const m = css.match(/\.kiosk-top-chip\s*\{([^}]+)\}/);
    assertCssBlockTouchMin(m ? m[1] : '', '.kiosk-top-chip');
  });

  it('KsChip.vue — .ks-chip__remove (contrat CSS)', () => {
    const css = readVueStyle('resources/js/components/frontend/kiosk/ds/KsChip.vue');
    const m = css.match(/\.ks-chip__remove\s*\{([^}]+)\}/);
    assertCssBlockTouchMin(m ? m[1] : '', '.ks-chip__remove');
  });

  it('KioskWizardComponent — boutons header / stepper présents + classes contractuelles', async () => {
    const item = {
      id: 9001,
      name: 'Tacos XL',
      category_name: 'Tacos',
      wizard_template: 'tacos',
      convert_price: 9,
      itemAttributes: [{ id: 1, name: 'Viande' }],
      variations: {
        1: [{ id: 11, name: 'Boeuf', convert_price: '0', price: 0, status: 5 }],
      },
      extras: [],
    };
    const wrapper = shallowMount(KioskWizardComponent, {
      props: { item, onAddToCart: vi.fn(), onClose: vi.fn() },
      global: {
        plugins: [i18n],
        stubs: { ...wizardStubs, transition: false },
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();

    const required = [
      '.kiosk-progress-arrow',
      '.kiosk-wizard-close',
      '.kiosk-btn-abandon',
      '.kiosk-btn-back',
      '.kiosk-btn-next',
    ];
    for (const sel of required) {
      const w = wrapper.find(sel);
      expect(w.exists(), `Selector "${sel}" must exist for touch target check`).toBe(true);
      const cls = w.classes();
      const token = sel.replace(/^\./, '');
      expect(
        cls.includes(token) || w.element.className.includes(token),
        `${sel} must carry class ${token}`
      ).toBe(true);
    }
    wrapper.unmount();
  });

  it('KioskCategoriesComponent — chips en-tête (role=button)', async () => {
    const store = createStore({
      modules: {
        kioskSettings,
        kioskFilter: {
          namespaced: true,
          getters: { activeFilters: () => [], hydrated: () => true },
          actions: { init: vi.fn(), toggle: vi.fn(), reset: vi.fn() },
        },
        kioskMenu: {
          namespaced: true,
          state: () => ({ kioskSandwichSubcolumn: null }),
          getters: {
            categories: () => [{ id: 1, name: 'Tacos', kioskRowKey: 't' }],
            allItems: () => [],
            selectedCategoryId: () => 1,
            loading: () => false,
            isStale: () => false,
            fromCache: () => false,
            sidebarCategories: () => [{ id: 1, name: 'Tacos', kioskRowKey: 't' }],
            kioskCatalogItems: () => [
              {
                id: 10,
                name: 'Item A',
                convert_price: 8,
                category_id: 1,
                status: 5,
              },
            ],
          },
          actions: {
            fetchMenu: vi.fn().mockResolvedValue(),
            selectKioskCategory: vi.fn(),
          },
        },
        kioskCart: {
          namespaced: true,
          state: () => ({ branchId: 1, kioskToken: 't' }),
          getters: {
            count: () => 0,
            total: () => 0,
            isEmpty: () => true,
          },
          actions: { addItem: vi.fn(), reset: vi.fn() },
        },
        frontendItem: {
          namespaced: true,
          actions: { details: vi.fn().mockResolvedValue({ data: { data: { id: 1, name: 'Mock' } } }) },
        },
      },
    });
    const wrapper = mount(KioskCategoriesComponent, {
      global: {
        plugins: [store, i18n],
        mocks: {
          $router: { push: vi.fn(), replace: vi.fn() },
          $route: { query: {}, params: {} },
        },
        stubs: { KioskWizardComponent: true, transition: false },
      },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();
    const chips = wrapper.findAll('.kiosk-top-chip');
    expect(chips.length, 'At least one .kiosk-top-chip').toBeGreaterThan(0);
    chips.forEach((c, i) => {
      expect(c.classes().includes('kiosk-top-chip'), `chip ${i} must have kiosk-top-chip`).toBe(true);
    });
    wrapper.unmount();
  });

  it('KsChip — bouton retirer présent + classe contractuelle', () => {
    const wrapper = mount(KsChip, {
      props: { selected: true, removable: true },
      slots: { default: 'Tag' },
      global: { plugins: [i18n] },
      attachTo: document.body,
    });
    const rm = wrapper.find('.ks-chip__remove');
    expect(rm.exists(), '.ks-chip__remove must exist').toBe(true);
    expect(rm.classes().includes('ks-chip__remove')).toBe(true);
    wrapper.unmount();
  });
});
