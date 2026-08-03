import { describe, it, expect, vi } from 'vitest';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import { mount, shallowMount } from '@vue/test-utils';
import KioskStepViandeComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue';
import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import { kioskCart } from '../../resources/js/store/modules/kioskCart.js';
import frMessages from '../../resources/js/languages/fr.json';

/**
 * [VIANDE-SUPPL UNIFIÉ 2026-07-24 · LOCK_POS_WIZARD_VIANDE_SUPPL_UNIFIE] BORNE — jumeau du geste
 * caisse (posWizardViandeSupplementUnified.spec.js) : les MÊMES tuiles viande gèrent les viandes
 * INCLUSES (gratuites jusqu'au max produit) PUIS le supplément (au-delà = +2,50 € NOMMÉ). L'extra
 * générique « Viande supplémentaire » n'est PAS une tuile ; le nom réel des viandes en plus part
 * dans l'instruction « Viandes en plus : … » (résolu au ticket par extraViandeNames). Approche A =
 * extra générique + nom dans l'instruction. Le backend PricingService scelle @2,50 (display==sealed).
 */

vi.mock('../../resources/js/helpers/kioskOfflineQueue', () => ({
  saveOrder: vi.fn(),
  getPendingCount: vi.fn(() => 0),
  startAutoSync: vi.fn(),
}));
vi.mock('../../resources/js/helpers/kioskMenuCache', () => ({
  isSnapshotStale: vi.fn(() => false),
  loadSnapshot: vi.fn(() => null),
}));

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

// Item Tacos-like : 2 viandes incluses (Viande 1 / Viande 2) déclinées sous les 2 attributs +
// ItemExtra « Viande supplémentaire » @2,50 (SANS group_label → prouve que le filtre du STEP
// l'exclut des tuiles même quand kioskIsViandePaidExtra le remonterait). Base 8,00 €.
function tacos2ViandesItem() {
  return {
    id: 97,
    name: 'Tacos L',
    wizard_template: 'tacos',
    convert_price: 8.0,
    currency_price: '8,00 €',
    itemAttributes: [
      { id: 301, name: 'Viande 1' },
      { id: 302, name: 'Viande 2' },
      { id: 311, name: 'Sauce (1ère Gratuite)' },
    ],
    variations: {
      301: [
        { id: 9001, name: 'Poulet', item_attribute_id: 301 },
        { id: 9002, name: 'Mexicanos', item_attribute_id: 301 },
        { id: 9003, name: 'Kefta', item_attribute_id: 301 },
        { id: 9004, name: 'Merguez', item_attribute_id: 301 },
      ],
      302: [
        { id: 9011, name: 'Poulet', item_attribute_id: 302 },
        { id: 9012, name: 'Mexicanos', item_attribute_id: 302 },
        { id: 9013, name: 'Kefta', item_attribute_id: 302 },
        { id: 9014, name: 'Merguez', item_attribute_id: 302 },
      ],
      311: [{ id: 9101, name: 'Algérienne', item_attribute_id: 311 }],
    },
    extras: [
      { id: 61, name: 'Cheddar', convert_price: 0.9, currency_price: '€0.90' },
      { id: 398, name: 'Viande supplémentaire', convert_price: 2.5, currency_price: '€2.50' },
    ],
  };
}

// ─────────────────────────────────────────────────────────────────────────────
// STEP — KioskStepViandeComponent : sélection au-delà du max + émission supplCount
// ─────────────────────────────────────────────────────────────────────────────
describe('BORNE STEP viande — dépassement du quota inclus', () => {
  const mountStep = (viandeCount = 2) =>
    mount(KioskStepViandeComponent, {
      props: {
        step: {},
        item: tacos2ViandesItem(),
        selections: { viandes: {}, _tailleMeta: { viandeCount } },
        activeFilters: [],
      },
      global: { plugins: [i18n] },
    });

  const lastMeta = (wrapper) => {
    const evts = (wrapper.emitted('update') || []).filter((e) => e[0] === '_viandeMeta');
    return evts.length ? evts[evts.length - 1][1] : null;
  };

  it('l\'ItemExtra « Viande supplémentaire » n\'est PAS une tuile viande sélectionnable', () => {
    const wrapper = mountStep(2);
    const names = wrapper.vm.viandeList.map((v) => v.name.toLowerCase());
    expect(names.some((n) => /viande\s*suppl/.test(n))).toBe(false);
    // Les vraies viandes restent présentes.
    expect(names).toContain('poulet');
    expect(names).toContain('merguez');
  });

  it('AU MAX exactement (2/2) → aucun supplément, supplCount=0', async () => {
    const wrapper = mountStep(2);
    wrapper.vm.increment('9001'); // Poulet
    wrapper.vm.increment('9002'); // Mexicanos
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.includedQuotaSelected).toBe(2);
    expect(wrapper.vm.supplementViandeCount).toBe(0);
    const meta = lastMeta(wrapper);
    expect(meta.every((m) => (m.supplCount || 0) === 0)).toBe(true);
    expect(meta.reduce((s, m) => s + (m.supplCount || 0), 0)).toBe(0);
  });

  it('max+1 (3ᵉ viande) → 1 supplément @2,50 nommé, compteur inclus plafonné à 2', async () => {
    const wrapper = mountStep(2);
    wrapper.vm.increment('9001'); // Poulet (inclus)
    wrapper.vm.increment('9002'); // Mexicanos (inclus)
    // Le 3ᵉ clic (Kefta) est autorisé (dépassement) car l'item a l'extra « Viande supplémentaire ».
    expect(wrapper.vm.canIncrement(wrapper.vm.viandeList.find((v) => v.key === '9003'))).toBe(true);
    wrapper.vm.increment('9003'); // Kefta (supplément)
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.includedQuotaSelected).toBe(2); // plafonné
    expect(wrapper.vm.supplementViandeCount).toBe(1);
    expect(wrapper.vm.supplementViandeTotalPrice).toBeCloseTo(2.5, 2);

    const meta = lastMeta(wrapper);
    const kefta = meta.find((m) => m.name === 'Kefta');
    expect(kefta.supplCount).toBe(1);
    expect(kefta.source).toBe('variation');
    // Les 2 incluses restent supplCount 0.
    expect(meta.find((m) => m.name === 'Poulet').supplCount).toBe(0);
    expect(meta.find((m) => m.name === 'Mexicanos').supplCount).toBe(0);
  });

  it('sans ItemExtra « Viande supplémentaire » → plafond DUR historique (pas de dépassement)', () => {
    const item = tacos2ViandesItem();
    item.extras = item.extras.filter((e) => !/viande\s*suppl/i.test(e.name)); // retire le mécanisme
    const wrapper = mount(KioskStepViandeComponent, {
      props: { step: {}, item, selections: { viandes: { 9001: 1, 9002: 1 }, _tailleMeta: { viandeCount: 2 } }, activeFilters: [] },
      global: { plugins: [i18n] },
    });
    expect(wrapper.vm.viandeSupplementsEnabled).toBe(false);
    // À 2/2 sans mécanisme d'extra → canIncrement bloque (comportement d'avant la feature).
    expect(wrapper.vm.canIncrement(wrapper.vm.viandeList.find((v) => v.key === '9003'))).toBe(false);
  });

  // [OWNER 2026-07-28] CTA supplément permanent — la borne DOIT toujours proposer d'ajouter une
  // viande en plus une fois le quota inclus atteint (plainte « la borne ne propose pas de supplément »).
  it('CTA supplément CACHÉ tant que le quota inclus n\'est pas atteint (1/2)', async () => {
    const wrapper = mountStep(2);
    wrapper.vm.increment('9001'); // 1/2 seulement
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.includedQuotaComplete).toBe(false);
    expect(wrapper.find('[data-testid="kiosk-viande-suppl-cta"]').exists()).toBe(false);
  });

  it('CTA supplément VISIBLE à 2/2 quand l\'item porte l\'extra (nomme le prix @2,50)', async () => {
    const wrapper = mountStep(2);
    wrapper.vm.increment('9001'); // Poulet
    wrapper.vm.increment('9002'); // Mexicanos → 2/2
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.includedQuotaComplete).toBe(true);
    expect(wrapper.vm.viandeSupplementsEnabled).toBe(true);
    const cta = wrapper.find('[data-testid="kiosk-viande-suppl-cta"]');
    expect(cta.exists()).toBe(true);
    expect(cta.text()).toMatch(/supplément/i);
    expect(cta.text()).toMatch(/2[.,]50/); // prix unitaire nommé
  });

  it('CTA supplément ABSENT à 2/2 si l\'item n\'a PAS l\'extra (dépassement impossible)', async () => {
    const item = tacos2ViandesItem();
    item.extras = item.extras.filter((e) => !/viande\s*suppl/i.test(e.name));
    const wrapper = mount(KioskStepViandeComponent, {
      props: { step: {}, item, selections: { viandes: { 9001: 1, 9002: 1 }, _tailleMeta: { viandeCount: 2 } }, activeFilters: [] },
      global: { plugins: [i18n] },
    });
    expect(wrapper.vm.includedQuotaComplete).toBe(true);
    expect(wrapper.vm.viandeSupplementsEnabled).toBe(false);
    // Pas d'extra ⇒ pas de CTA trompeur (on ne propose pas un supplément non facturable).
    expect(wrapper.find('[data-testid="kiosk-viande-suppl-cta"]').exists()).toBe(false);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// WIZARD — buildCartItem / buildInstruction / runningTotalLocal
// ─────────────────────────────────────────────────────────────────────────────
describe('BORNE WIZARD — buildCartItem route le dépassement viande vers l\'extra générique', () => {
  const stubs = Object.fromEntries(
    ['KioskStepPain', 'KioskStepTaille', 'KioskStepViande', 'KioskStepSauce',
     'KioskStepGarnitures', 'KioskStepSupplements', 'KioskStepMenu', 'KioskOrderSummary']
      .map((n) => [n, true])
  );

  const cloneModule = () => ({ ...kioskCart, state: JSON.parse(JSON.stringify(kioskCart.state)) });

  const mountWizard = () => {
    const store = createStore({ modules: { kioskCart: cloneModule() } });
    return shallowMount(KioskWizardComponent, {
      props: { item: tacos2ViandesItem(), onAddToCart: null, onClose: null },
      global: {
        plugins: [i18n, store],
        stubs,
        mocks: { $router: { go: vi.fn(), push: vi.fn() } },
      },
    });
  };

  // Applique une sélection viande directement (simule ce que KioskStepViande émet).
  const applyViandeMeta = async (wrapper, meta) => {
    await wrapper.vm.$nextTick();
    wrapper.vm.selections._tailleMeta = { viandeCount: 2, label: 'L' };
    wrapper.vm.selections.sauceOrder = [];
    wrapper.vm.selections.quantity = 1;
    wrapper.vm.selections.viandes = meta.reduce((o, m) => { o[m.key] = m.count; return o; }, {});
    wrapper.vm.selections.totalViandes = meta.reduce((s, m) => s + m.count, 0);
    wrapper.vm.selections._viandeMeta = meta;
    await wrapper.vm.$nextTick();
  };

  const V = (id, name, count, supplCount) =>
    ({ id, key: String(id), name, price: 0, source: 'variation', attrId: 301, count, supplCount });

  it('(a) max+1 → 2 variations gratuites + 1 extra « Viande supplémentaire » + instruction + total +2,50', async () => {
    const wrapper = mountWizard();
    await applyViandeMeta(wrapper, [
      V(9001, 'Poulet', 1, 0),
      V(9002, 'Mexicanos', 1, 0),
      V(9003, 'Kefta', 1, 1),
    ]);
    const cart = wrapper.vm.buildCartItem();

    // 2 variations INCLUSES gratuites (Poulet@Viande 1, Mexicanos@Viande 2), Kefta NON en variation.
    const varNames = cart.item_variations.map((v) => v.name);
    expect(varNames).toEqual(expect.arrayContaining(['Poulet', 'Mexicanos']));
    expect(varNames).not.toContain('Kefta');

    // 1 seul extra = « Viande supplémentaire » (id 398), aucun autre.
    const supplExtras = cart.item_extras.filter((e) => e.id === 398);
    expect(supplExtras).toHaveLength(1);
    expect(supplExtras[0].name).toBe('Viande supplémentaire');
    expect(cart.item_extras).toHaveLength(1);

    // Money-path : inclus = 0, extra = +2,50 exact.
    expect(cart.item_extra_total).toBeCloseTo(2.5, 2);
    expect(cart.total).toBeCloseTo(10.5, 2); // 8,00 base + 2,50 supplément

    // Instruction : format EXACT « Viandes en plus : Kefta ».
    expect(cart.instruction).toContain('Viandes en plus : Kefta');

    // Affiché == scellé.
    expect(wrapper.vm.runningTotalLocal).toBeCloseTo(10.5, 2);
  });

  it('(b) AU MAX exactement (2 incluses, supplCount=0) → 0 extra, 0 ligne « Viandes en plus »', async () => {
    const wrapper = mountWizard();
    await applyViandeMeta(wrapper, [
      V(9001, 'Poulet', 1, 0),
      V(9002, 'Mexicanos', 1, 0),
    ]);
    const cart = wrapper.vm.buildCartItem();
    expect(cart.item_extras.filter((e) => e.id === 398)).toHaveLength(0);
    expect(cart.item_extra_total).toBeCloseTo(0, 2);
    expect(cart.total).toBeCloseTo(8.0, 2);
    expect(cart.instruction || '').not.toContain('Viandes en plus');
    expect(wrapper.vm.runningTotalLocal).toBeCloseTo(8.0, 2);
  });

  it('(c) 2 en plus (2 viandes distinctes) → extra qty 2 + 2 noms dans « Viandes en plus »', async () => {
    const wrapper = mountWizard();
    // maxViandes=2, 4 viandes sélectionnées : 2 incluses + 2 supplément (Kefta, Merguez).
    await applyViandeMeta(wrapper, [
      V(9001, 'Poulet', 1, 0),
      V(9002, 'Mexicanos', 1, 0),
      V(9003, 'Kefta', 1, 1),
      V(9004, 'Merguez', 1, 1),
    ]);
    const cart = wrapper.vm.buildCartItem();

    // Extra générique poussé 2× (qty = dépassement).
    expect(cart.item_extras.filter((e) => e.id === 398)).toHaveLength(2);
    expect(cart.item_extra_total).toBeCloseTo(5.0, 2); // 2 × 2,50
    expect(cart.total).toBeCloseTo(13.0, 2); // 8,00 + 5,00

    // 2 noms dans la ligne dédiée.
    expect(cart.instruction).toContain('Viandes en plus : Kefta, Merguez');
    expect(wrapper.vm.runningTotalLocal).toBeCloseTo(13.0, 2);
  });

  it('(c-bis) 2 en plus de la MÊME viande → extra qty 2 + format « 2× Nom » (parité caisse)', async () => {
    const wrapper = mountWizard();
    await applyViandeMeta(wrapper, [
      V(9001, 'Poulet', 1, 0),
      V(9002, 'Mexicanos', 1, 0),
      V(9003, 'Kefta', 2, 2), // 2 Kefta en supplément
    ]);
    const cart = wrapper.vm.buildCartItem();
    expect(cart.item_extras.filter((e) => e.id === 398)).toHaveLength(2);
    expect(cart.item_extra_total).toBeCloseTo(5.0, 2);
    expect(cart.instruction).toContain('Viandes en plus : 2× Kefta');
  });
});
