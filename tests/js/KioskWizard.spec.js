import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount, shallowMount } from '@vue/test-utils';
import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import KioskStepMenuComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue';
import KioskStepSauceComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue';
import KioskStepPainComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue';
import KioskStepViandeComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue';
import KioskStepSupplementsComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

/** i18n pour composants wizard réels (clés kiosk.wizard.*) */
const kioskWizardTestI18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

/**
 * Tests du Wizard Borne Tactile (Kiosk)
 * 
 * Ces tests vérifient le fonctionnement du wizard de personnalisation
 * des commandes pour les bornes tactiles.
 */

// Mock des composants enfants pour les tests
const createKioskWizardMock = () => ({
  name: 'KioskWizardComponent',
  template: `
    <div class="kiosk-wizard" v-if="item">
      <div class="kiosk-wizard-header">
        <h2>{{ item.name }}</h2>
        <p class="kiosk-running-total">{{ formatPrice(runningTotal) }}</p>
      </div>
      <div class="kiosk-progress-bar">
        <div v-for="(step, index) in activeSteps" :key="step.type" 
             class="kiosk-step-dot" :class="{ active: index === currentStepIndex }">
          <span>{{ step.label }}</span>
        </div>
      </div>
      <div class="kiosk-step-content">
        <span data-testid="current-step">{{ currentStep?.type }}</span>
      </div>
      <button class="kiosk-btn-next" :disabled="!canAdvance" @click="nextStep">Suivant</button>
    </div>
  `,
  props: {
    item: { type: Object, required: true },
    onAddToCart: { type: Function, required: true },
    onClose: { type: Function, required: true }
  },
  data() {
    return {
      currentStepIndex: 0,
      resolvedItem: null, // set by tests for buildCartItem
      selections: {
        pain: null,
        _painMeta: null,
        viandes: {},
        _viandeMeta: [],
        totalViandes: 0,
        sauces: {},
        sauceOrder: [],
        garnitures: {},
        supplements: {},
        menuChoice: null,
        boissonChoice: null,
        quantity: 1,
        instruction: ''
      }
    };
  },
  computed: {
    activeSteps() {
      const template = this.item.wizard_template || this.detectTemplateFromName();
      const hasViandes = this.detectViandeCount() > 0;
      
      switch (template) {
        case 'sandwich':
          return [
            { type: 'pain', label: 'Pain' },
            ...(hasViandes ? [{ type: 'viande', label: 'Viande(s)' }] : []),
            { type: 'sauce', label: 'Sauce' },
            { type: 'recap', label: 'Récap' }
          ];
        case 'tacos':
          return [
            { type: 'viande', label: 'Viande(s)' },
            { type: 'sauce', label: 'Sauce' },
            { type: 'recap', label: 'Récap' }
          ];
        default:
          return [{ type: 'recap', label: 'Récap' }];
      }
    },
    currentStep() {
      return this.activeSteps[this.currentStepIndex];
    },
    runningTotal() {
      let total = parseFloat(this.item.convert_price) || 0;
      if (this.selections.sauceOrder.length > 1) {
        total += (this.selections.sauceOrder.length - 1) * 0.50;
      }
      return total * this.selections.quantity;
    },
    canAdvance() {
      const step = this.currentStep;
      if (!step) return false;
      if (step.type === 'viande') return this.selections.totalViandes >= this.detectViandeCount();
      if (step.type === 'sauce') return this.selections.sauceOrder.length > 0;
      if (step.type === 'pain') return this.selections.pain !== null;
      return true;
    }
  },
  methods: {
    detectTemplateFromName() {
      const name = (this.item.name || '').toLowerCase();
      const category = (this.item.category_name || '').toLowerCase();
      if (name.includes('tacos') || category.includes('tacos')) return 'tacos';
      if (name.includes('sandwich') || category.includes('sandwich')) return 'sandwich';
      if (name.includes('burger') || category.includes('burger')) return 'burger';
      if (name.includes('assiette') || category.includes('assiette')) return 'assiette';
      return 'simple';
    },
    detectViandeCount() {
      const name = (this.item.name || '').toLowerCase();
      // [KIOSK-18] Check '4 viande' and 'xxl' BEFORE 'xl' to avoid false match
      if (name.includes('4 viande') || name.includes('xxl')) return 4;
      if (name.includes('3 viande') || name.includes('xl')) return 3;
      if (name.includes('2 viande') || name.includes(' l ')) return 2;
      return 1;
    },
    formatPrice(price) {
      return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'EUR'
      }).format(price || 0);
    },
    nextStep() {
      if (this.currentStepIndex < this.activeSteps.length - 1) {
        this.currentStepIndex++;
      }
    },
    updateSelection(key, value) {
      this.selections[key] = value;
    },
    initGarnitures() {
      if (this.item.extras) {
        const garnitures = {};
        this.item.extras.forEach(extra => {
          if (parseFloat(extra.convert_price || extra.price || 0) === 0) {
            garnitures[extra.id] = true;
          }
        });
        this.selections.garnitures = garnitures;
      }
    },
    // C1: mirror of the real buildCartItem — produces server-ready arrays
    buildCartItem() {
      const item = this.resolvedItem || this.item;
      if (!item) return null;

      const allVariations = {};
      const allVariationNames = {};

      // Pain
      const painMeta = this.selections._painMeta;
      if (painMeta?.realId && painMeta?.attrId) {
        allVariations[painMeta.attrId] = painMeta.realId;
        allVariationNames['Pain'] = painMeta.name;
      }

      // Viande
      const viandeMeta = this.selections._viandeMeta || [];
      if (viandeMeta.length > 0 && item.itemAttributes) {
        const viandeAttr = item.itemAttributes.find(a =>
          (a.name || '').toLowerCase().includes('viande')
        );
        if (viandeAttr && item.variations?.[viandeAttr.id]) {
          const firstViande = viandeMeta[0];
          if (typeof firstViande.id === 'number') {
            allVariations[viandeAttr.id] = firstViande.id;
            allVariationNames[viandeAttr.name] = firstViande.name;
          }
        }
      }

      // Sauce
      if (this.selections.sauceOrder.length > 0) {
        const firstSauceKey = this.selections.sauceOrder[0];
        if (item.itemAttributes) {
          const sauceAttr = item.itemAttributes.find(a =>
            (a.name || '').toLowerCase().includes('sauce')
          );
          if (sauceAttr && item.variations?.[sauceAttr.id]) {
            const variation = typeof firstSauceKey === 'number'
              ? item.variations[sauceAttr.id].find(v => v.id === firstSauceKey)
              : item.variations[sauceAttr.id].find(v => v.name === firstSauceKey);
            if (variation) {
              allVariations[sauceAttr.id] = variation.id;
              allVariationNames[sauceAttr.name] = variation.name;
            }
          }
        }
      }

      // Normalize to server arrays
      const normalizedVariations = Object.entries(allVariations)
        .filter(([, varId]) => varId)
        .map(([attrId, varId]) => {
          const attr = item.itemAttributes?.find(a => String(a.id) === String(attrId));
          const variationName = attr?.name || Object.keys(allVariationNames)[0] || '';
          const name = allVariationNames[variationName] || allVariationNames[attr?.name] || '';
          return { id: parseInt(varId), variation_name: variationName, name };
        });

      const normalizedExtras = [];
      let itemExtraTotal = 0;

      Object.keys(this.selections.garnitures).forEach(id => {
        if (this.selections.garnitures[id]) {
          const extra = item.extras?.find(e => e.id === parseInt(id));
          normalizedExtras.push({ id: parseInt(id), name: extra?.name || '' });
        }
      });

      Object.keys(this.selections.supplements).forEach(id => {
        const rawCount = this.selections.supplements[id];
        const count = rawCount === true ? 1 : (parseInt(rawCount, 10) || 0);
        if (count > 0) {
          const extra = item.extras?.find(e => e.id === parseInt(id));
          if (extra) {
            for (let i = 0; i < count; i++) {
              normalizedExtras.push({ id: parseInt(id), name: extra?.name || '' });
            }
            itemExtraTotal += parseFloat(extra.convert_price || extra.price || 0) * count;
          }
        }
      });

      const basePrice = parseFloat(item.convert_price) || 0;
      const qty = this.selections.quantity || 1;

      return {
        item_id: item.id,
        name: item.name,
        image: item.thumb,
        quantity: qty,
        convert_price: basePrice,
        currency_price: item.currency_price,
        discount: 0,
        item_variations: normalizedVariations,
        item_extras: normalizedExtras,
        item_variation_total: 0,
        item_extra_total: parseFloat(itemExtraTotal.toFixed(2)),
        total: parseFloat(((basePrice + itemExtraTotal) * qty).toFixed(2)),
        instruction: '',
      };
    }
  },
  mounted() {
    this.initGarnitures();
  }
});

describe('KioskWizardComponent', () => {
  let wrapper;
  const mockOnAddToCart = vi.fn();
  const mockOnClose = vi.fn();

  const mockItemTacos = {
    id: 1,
    name: 'Tacos XL 3 viandes',
    convert_price: 8.50,
    thumb: '/images/tacos.jpg',
    wizard_template: 'tacos',
    itemAttributes: [
      { id: 1, name: 'Viande' },
      { id: 2, name: 'Sauce' }
    ],
    variations: {
      1: [{ id: 101, name: 'Poulet' }, { id: 102, name: 'Boeuf' }, { id: 103, name: 'Merguez' }],
      2: [{ id: 201, name: 'Algérienne' }, { id: 202, name: 'Blanche' }]
    },
    extras: [
      { id: 301, name: 'Salade', convert_price: 0 },
      { id: 302, name: 'Tomate', convert_price: 0 },
      { id: 303, name: 'Fromage', convert_price: 1.00 }
    ]
  };

  const mockItemSandwich = {
    id: 2,
    name: 'Le Terminator',
    convert_price: 6.50,
    thumb: '/images/sandwich.jpg',
    wizard_template: 'sandwich',
    itemAttributes: [
      { id: 3, name: 'Type de Pain' },
      { id: 4, name: 'Viande' },
      { id: 5, name: 'Sauce' }
    ],
    variations: {
      3: [{ id: 401, name: 'Pain' }, { id: 402, name: 'Galette' }],
      4: [{ id: 501, name: 'Poulet Pané' }],
      5: [{ id: 601, name: 'Ketchup' }, { id: 602, name: 'Mayonnaise' }]
    },
    extras: [
      { id: 701, name: 'Salade', convert_price: 0 },
      { id: 702, name: 'Oignon', convert_price: 0 }
    ]
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('should show pain step for sandwich category', () => {
    const Component = createKioskWizardMock();
    wrapper = mount(Component, {
      props: {
        item: mockItemSandwich,
        onAddToCart: mockOnAddToCart,
        onClose: mockOnClose
      }
    });

    const steps = wrapper.vm.activeSteps;
    const hasPainStep = steps.some(s => s.type === 'pain');
    
    expect(hasPainStep).toBe(true);
    expect(steps[0].type).toBe('pain');
  });

  it('should not show pain step for tacos category', () => {
    const Component = createKioskWizardMock();
    wrapper = mount(Component, {
      props: {
        item: mockItemTacos,
        onAddToCart: mockOnAddToCart,
        onClose: mockOnClose
      }
    });

    const steps = wrapper.vm.activeSteps;
    const hasPainStep = steps.some(s => s.type === 'pain');
    
    expect(hasPainStep).toBe(false);
    expect(steps[0].type).toBe('viande');
  });

  it('should pre-check all garnitures by default', () => {
    const Component = createKioskWizardMock();
    wrapper = mount(Component, {
      props: {
        item: mockItemTacos,
        onAddToCart: mockOnAddToCart,
        onClose: mockOnClose
      }
    });

    // Les garnitures sont celles avec prix = 0
    const expectedGarnitures = {
      301: true, // Salade (prix 0)
      302: true  // Tomate (prix 0)
    };

    expect(wrapper.vm.selections.garnitures).toEqual(expectedGarnitures);
    expect(wrapper.vm.selections.garnitures[303]).toBeUndefined(); // Fromage (payant) ne doit pas être pré-coché
  });

  it('should calculate running total correctly', () => {
    const Component = createKioskWizardMock();
    wrapper = mount(Component, {
      props: {
        item: mockItemTacos,
        onAddToCart: mockOnAddToCart,
        onClose: mockOnClose
      }
    });

    // Total de base
    expect(wrapper.vm.runningTotal).toBe(8.50);

    // Simuler l'ajout de sauces supplémentaires
    wrapper.vm.selections.sauceOrder = [201, 202]; // 2 sauces = 1 supplémentaire
    expect(wrapper.vm.runningTotal).toBe(9.00); // 8.50 + 0.50

    wrapper.vm.selections.sauceOrder = [201, 202, 203]; // 3 sauces = 2 supplémentaires
    expect(wrapper.vm.runningTotal).toBe(9.50); // 8.50 + 1.00
  });

  it('should not allow advancing viande step until maxViandes reached', () => {
    const Component = createKioskWizardMock();
    wrapper = mount(Component, {
      props: {
        item: mockItemTacos, // Tacos XL 3 viandes
        onAddToCart: mockOnAddToCart,
        onClose: mockOnClose
      }
    });

    // maxViandes pour "Tacos XL 3 viandes" = 3
    expect(wrapper.vm.detectViandeCount()).toBe(3);

    // Aucune viande sélectionnée
    wrapper.vm.selections.totalViandes = 0;
    wrapper.vm.currentStepIndex = 0; // Étape viande
    expect(wrapper.vm.canAdvance).toBe(false);

    // 2 viandes sur 3
    wrapper.vm.selections.totalViandes = 2;
    expect(wrapper.vm.canAdvance).toBe(false);

    // 3 viandes = complet
    wrapper.vm.selections.totalViandes = 3;
    expect(wrapper.vm.canAdvance).toBe(true);
  });

  it('should format prices correctly in EUR', () => {
    const Component = createKioskWizardMock();
    wrapper = mount(Component, {
      props: {
        item: mockItemTacos,
        onAddToCart: mockOnAddToCart,
        onClose: mockOnClose
      }
    });

    // Intl.NumberFormat output varies by Node.js version (narrow no-break space vs regular space).
    // Test the semantic content rather than exact whitespace.
    const normalize = (s) => s.replace(/[\u00a0\u202f\s]+/g, ' ').trim();
    expect(normalize(wrapper.vm.formatPrice(8.50))).toBe('8,50 \u20ac');
    expect(normalize(wrapper.vm.formatPrice(12))).toBe('12,00 \u20ac');
    expect(normalize(wrapper.vm.formatPrice(0))).toBe('0,00 \u20ac');
  });

  it('should detect template from item name', () => {
    const Component = createKioskWizardMock();
    
    const testCases = [
      { name: 'Tacos XL', expected: 'tacos' },
      { name: 'Le Terminator Sandwich', expected: 'sandwich' },
      { name: 'Burger Classic', expected: 'burger' },
      { name: 'Assiette Kebab', expected: 'assiette' },
      { name: 'Frites', expected: 'simple' }
    ];

    testCases.forEach(testCase => {
      wrapper = mount(Component, {
        props: {
          item: { ...mockItemTacos, name: testCase.name, wizard_template: null },
          onAddToCart: mockOnAddToCart,
          onClose: mockOnClose
        }
      });

      expect(wrapper.vm.detectTemplateFromName()).toBe(testCase.expected);
    });
  });

  it('should handle quantity multiplier in running total', () => {
    const Component = createKioskWizardMock();
    wrapper = mount(Component, {
      props: {
        item: mockItemTacos,
        onAddToCart: mockOnAddToCart,
        onClose: mockOnClose
      }
    });

    // Quantité = 1
    expect(wrapper.vm.runningTotal).toBe(8.50);

    // Quantité = 2
    wrapper.vm.selections.quantity = 2;
    expect(wrapper.vm.runningTotal).toBe(17.00);

    // Quantité = 3 avec sauces supplémentaires
    wrapper.vm.selections.quantity = 3;
    wrapper.vm.selections.sauceOrder = [201, 202]; // +0.50 par unité
    expect(wrapper.vm.runningTotal).toBe(27.00); // (8.50 + 0.50) * 3
  });

  it('should allow advancing pain step only when pain is selected', () => {
    const Component = createKioskWizardMock();
    wrapper = mount(Component, {
      props: {
        item: mockItemSandwich,
        onAddToCart: mockOnAddToCart,
        onClose: mockOnClose
      }
    });

    wrapper.vm.currentStepIndex = 0; // Étape pain
    
    // Aucun pain sélectionné
    wrapper.vm.selections.pain = null;
    expect(wrapper.vm.canAdvance).toBe(false);

    // Pain sélectionné
    wrapper.vm.selections.pain = 401;
    expect(wrapper.vm.canAdvance).toBe(true);

    // Galette sélectionnée
    wrapper.vm.selections.pain = 402;
    expect(wrapper.vm.canAdvance).toBe(true);
  });

  it('should allow advancing sauce step only when at least one sauce selected', () => {
    const Component = createKioskWizardMock();
    wrapper = mount(Component, {
      props: {
        item: mockItemTacos,
        onAddToCart: mockOnAddToCart,
        onClose: mockOnClose
      }
    });

    // Aller à l'étape sauce
    wrapper.vm.currentStepIndex = 1;
    
    // Aucune sauce sélectionnée
    wrapper.vm.selections.sauceOrder = [];
    expect(wrapper.vm.canAdvance).toBe(false);

    // Une sauce sélectionnée
    wrapper.vm.selections.sauceOrder = [201];
    expect(wrapper.vm.canAdvance).toBe(true);

    // Plusieurs sauces
    wrapper.vm.selections.sauceOrder = [201, 202, 203];
    expect(wrapper.vm.canAdvance).toBe(true);
  });
});

describe('KioskWizardComponent - Navigation', () => {
  it('should navigate to next step when canAdvance is true', async () => {
    const Component = createKioskWizardMock();
    const wrapper = mount(Component, {
      props: {
        item: {
          id: 1,
          name: 'Tacos',
          convert_price: 8.00,
          wizard_template: 'tacos',
          itemAttributes: [{ id: 1, name: 'Viande' }],
          variations: { 1: [{ id: 101, name: 'Poulet' }] },
          extras: []
        },
        onAddToCart: vi.fn(),
        onClose: vi.fn()
      }
    });

    // Configurer pour pouvoir avancer
    wrapper.vm.selections.totalViandes = 1;
    await wrapper.vm.$nextTick(); // Wait for reactive update before checking disabled state
    
    // Début à l'étape 0
    expect(wrapper.vm.currentStepIndex).toBe(0);
    
    // Naviguer directement via la méthode (button :disabled may block trigger in happy-dom)
    wrapper.vm.nextStep();
    await wrapper.vm.$nextTick();
    
    // Vérifier que l'index a changé
    expect(wrapper.vm.currentStepIndex).toBe(1);
  });

  it('should not navigate when canAdvance is false', async () => {
    const Component = createKioskWizardMock();
    const wrapper = mount(Component, {
      props: {
        item: {
          id: 1,
          name: 'Tacos XL 3 viandes',
          convert_price: 8.00,
          wizard_template: 'tacos',
          itemAttributes: [{ id: 1, name: 'Viande' }],
          variations: { 1: [{ id: 101, name: 'Poulet' }] },
          extras: []
        },
        onAddToCart: vi.fn(),
        onClose: vi.fn()
      }
    });

    // 0 viandes sur 3 requises - ne peut pas avancer
    wrapper.vm.selections.totalViandes = 0;
    
    const startIndex = wrapper.vm.currentStepIndex;
    
    // Le bouton doit être disabled
    const btn = wrapper.find('.kiosk-btn-next');
    expect(btn.attributes('disabled')).toBeDefined();
  });
});

describe('KioskWizardComponent - Garnitures Initiales', () => {
  it('should initialize garnitures from extras with zero price', () => {
    const Component = createKioskWizardMock();
    const wrapper = mount(Component, {
      props: {
        item: {
          id: 1,
          name: 'Item Test',
          convert_price: 10.00,
          extras: [
            { id: 1, name: 'Salade', convert_price: 0, price: 0 },
            { id: 2, name: 'Tomate', convert_price: '0.00', price: '0.00' },
            { id: 3, name: 'Fromage', convert_price: 1.50, price: 1.50 },
            { id: 4, name: 'Oignon', price: 0 }, // sans convert_price
            { id: 5, name: 'Bacon', price: 2.00 }
          ]
        },
        onAddToCart: vi.fn(),
        onClose: vi.fn()
      }
    });

    const garnitures = wrapper.vm.selections.garnitures;
    
    // Les gratuits doivent être pré-cochés
    expect(garnitures[1]).toBe(true);  // Salade
    expect(garnitures[2]).toBe(true);  // Tomate
    expect(garnitures[4]).toBe(true);  // Oignon
    
    // Les payants ne doivent pas être pré-cochés
    expect(garnitures[3]).toBeUndefined(); // Fromage
    expect(garnitures[5]).toBeUndefined(); // Bacon
  });

  it('should handle empty extras array', () => {
    const Component = createKioskWizardMock();
    const wrapper = mount(Component, {
      props: {
        item: {
          id: 1,
          name: 'Item Simple',
          convert_price: 5.00,
          extras: []
        },
        onAddToCart: vi.fn(),
        onClose: vi.fn()
      }
    });

    expect(wrapper.vm.selections.garnitures).toEqual({});
  });

  it('should handle missing extras property', () => {
    const Component = createKioskWizardMock();
    const wrapper = mount(Component, {
      props: {
        item: {
          id: 1,
          name: 'Item Sans Extras',
          convert_price: 5.00
        },
        onAddToCart: vi.fn(),
        onClose: vi.fn()
      }
    });

    expect(wrapper.vm.selections.garnitures).toEqual({});
  });
});

// ─── C1: buildCartItem output format tests ────────────────────────────────────
// Verify that buildCartItem() produces server-ready arrays (not wizard objects).
// These tests guard against regressions in the normalization refactor.

describe('KioskWizardComponent - buildCartItem server format (C1)', () => {
  // Minimal item fixture with itemAttributes and variations
  const makeItem = (overrides = {}) => ({
    id: 42,
    name: 'Tacos L',
    convert_price: '8.00',
    currency_price: '8,00 €',
    thumb: null,
    itemAttributes: [
      { id: 10, name: 'Viande' },
      { id: 20, name: 'Sauce' },
    ],
    variations: {
      10: [{ id: 101, name: 'Poulet', convert_price: '0', price: 0 }],
      20: [
        { id: 201, name: 'Harissa', convert_price: '0', price: 0 },
        { id: 202, name: 'Blanche', convert_price: '0.50', price: 0.5 },
      ],
    },
    extras: [
      { id: 301, name: 'Tomate',    convert_price: '0',    price: 0 },
      { id: 302, name: 'Fromage',   convert_price: '1.00', price: 1 },
    ],
    addons: [],
    ...overrides,
  });

  it('item_variations is an array of { id, variation_name, name }', () => {
    const Component = createKioskWizardMock();
    const wrapper = mount(Component, {
      props: { item: makeItem(), onAddToCart: vi.fn(), onClose: vi.fn() },
    });
    // Simulate viande selection stored in _viandeMeta
    wrapper.vm.selections._viandeMeta = [{ id: 101, name: 'Poulet' }];
    wrapper.vm.selections.sauceOrder = [201];
    wrapper.vm.resolvedItem = makeItem();

    const cartItem = wrapper.vm.buildCartItem();

    expect(Array.isArray(cartItem.item_variations)).toBe(true);
    cartItem.item_variations.forEach(v => {
      expect(typeof v.id).toBe('number');
      expect(typeof v.variation_name).toBe('string');
      expect(typeof v.name).toBe('string');
    });
  });

  it('item_extras is an array of { id, name }', () => {
    const Component = createKioskWizardMock();
    const wrapper = mount(Component, {
      props: { item: makeItem(), onAddToCart: vi.fn(), onClose: vi.fn() },
    });
    wrapper.vm.selections.garnitures = { 301: true };
    wrapper.vm.selections.supplements = { 302: true };
    wrapper.vm.resolvedItem = makeItem();

    const cartItem = wrapper.vm.buildCartItem();

    expect(Array.isArray(cartItem.item_extras)).toBe(true);
    cartItem.item_extras.forEach(e => {
      expect(typeof e.id).toBe('number');
      expect(typeof e.name).toBe('string');
    });
  });

  it('paid supplements contribute to item_extra_total, free garnitures do not', () => {
    const Component = createKioskWizardMock();
    const wrapper = mount(Component, {
      props: { item: makeItem(), onAddToCart: vi.fn(), onClose: vi.fn() },
    });
    wrapper.vm.selections.garnitures  = { 301: true }; // free
    wrapper.vm.selections.supplements = { 302: true }; // 1.00€
    wrapper.vm.resolvedItem = makeItem();

    const cartItem = wrapper.vm.buildCartItem();

    expect(cartItem.item_extra_total).toBeCloseTo(1.0);
    expect(cartItem.item_extras.length).toBe(2);
  });

  it('paid supplements support multiple units in cart payload and totals', () => {
    const Component = createKioskWizardMock();
    const wrapper = mount(Component, {
      props: { item: makeItem(), onAddToCart: vi.fn(), onClose: vi.fn() },
    });
    wrapper.vm.selections.supplements = { 302: 2 };
    wrapper.vm.resolvedItem = makeItem();

    const cartItem = wrapper.vm.buildCartItem();

    expect(cartItem.item_extra_total).toBeCloseTo(2.0);
    expect(cartItem.item_extras.filter((e) => e.id === 302)).toHaveLength(2);
    expect(cartItem.total).toBeCloseTo(10.0);
  });

  it('no variations selected → item_variations is empty array', () => {
    const Component = createKioskWizardMock();
    const wrapper = mount(Component, {
      props: { item: makeItem(), onAddToCart: vi.fn(), onClose: vi.fn() },
    });
    wrapper.vm.resolvedItem = makeItem();

    const cartItem = wrapper.vm.buildCartItem();

    expect(cartItem.item_variations).toEqual([]);
  });

  it('no extras selected → item_extras is empty array and extra_total is 0', () => {
    const Component = createKioskWizardMock();
    const wrapper = mount(Component, {
      props: { item: makeItem(), onAddToCart: vi.fn(), onClose: vi.fn() },
    });
    // Reset garnitures to none selected (initGarnitures auto-selects free ones on mount)
    wrapper.vm.selections.garnitures = {};
    wrapper.vm.selections.supplements = {};
    wrapper.vm.resolvedItem = makeItem();

    const cartItem = wrapper.vm.buildCartItem();

    expect(cartItem.item_extras).toEqual([]);
    expect(cartItem.item_extra_total).toBe(0);
  });
});

describe('KioskWizardComponent - Detection du nombre de viandes', () => {
  const testCases = [
    { name: 'Tacos L', expected: 1 },
    { name: 'Tacos M', expected: 1 },
    { name: 'Tacos L 2 viandes', expected: 2 },
    { name: 'Tacos XL 3 viandes', expected: 3 },
    { name: 'Tacos XXL 4 viandes', expected: 4 },
    { name: 'Sandwich Simple', expected: 1 },
    { name: 'Assiette 2 viandes', expected: 2 }
  ];

  testCases.forEach(({ name, expected }) => {
    it(`should detect ${expected} viande(s) for "${name}"`, () => {
      const Component = createKioskWizardMock();
      const wrapper = mount(Component, {
        props: {
          item: {
            id: 1,
            name: name,
            convert_price: 8.00,
            extras: []
          },
          onAddToCart: vi.fn(),
          onClose: vi.fn()
        }
      });

      expect(wrapper.vm.detectViandeCount()).toBe(expected);
    });
  });
});

// ─── Wizard kiosk step components (sauces / menu / boisson heuristics) ───────

describe('KioskStepMenuComponent — wizard kiosk fixes', () => {
  const menuItem = () => ({
    // [AUDIT 2026-04-17 C5] Plus de fallback hardcodé : la liste des sauces
    // frites doit venir EXCLUSIVEMENT du catalogue (variations sauce du produit).
    itemAttributes: [{ id: 77, name: 'Sauce' }],
    variations: {
      77: [
        { id: 'ketchup', name: 'Ketchup' },
        { id: 'mayo', name: 'Mayo' },
      ],
    },
    addons: [
      { addon_item_name: 'Menu', addon_item_convert_price: 10 },
      { addon_item_name: 'Coca-Cola', addon_item_id: 1 },
      { addon_item_name: 'Menu frites boisson', addon_item_id: 2 },
      { addon_item_name: 'Frites à part', addon_item_id: 3 },
      { addon_item_name: 'Jus d\'orange', addon_item_id: 4 },
    ],
  });

  const baseSelections = () => ({
    menuChoice: 'none',
    boissonChoice: null,
    fritesSauce: null,
    fritesSauceOrder: [],
  });

  it('showFritesSauce is false when menuChoice is none', () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: menuItem(),
        selections: baseSelections(),
      },
    });
    expect(wrapper.vm.showFritesSauce).toBe(false);
  });

  it('showFritesSauce is true for full or frites-only menu choice', async () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: menuItem(),
        selections: { ...baseSelections(), menuChoice: 'full' },
      },
    });
    expect(wrapper.vm.showFritesSauce).toBe(true);

    await wrapper.vm.selectChoice('frites');
    expect(wrapper.vm.showFritesSauce).toBe(true);

    await wrapper.vm.selectChoice('none');
    expect(wrapper.vm.showFritesSauce).toBe(false);
  });

  it('menuPrice applies 0.76 / 0.76 ratios (G-PRIX 1,90) like runningTotal', async () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: menuItem(),
        selections: baseSelections(),
      },
    });
    await wrapper.vm.selectChoice('full');
    expect(wrapper.vm.menuPrice).toBeCloseTo(10);
    await wrapper.vm.selectChoice('frites');
    expect(wrapper.vm.menuPrice).toBeCloseTo(7.6);
    await wrapper.vm.selectChoice('boisson');
    expect(wrapper.vm.menuPrice).toBeCloseTo(7.6);
    await wrapper.vm.selectChoice('none');
    expect(wrapper.vm.menuPrice).toBe(0);
  });

  it('menuInfoBadge is null when sans menu (no false "Frites + Boisson" banner)', () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: menuItem(),
        selections: baseSelections(),
      },
    });
    expect(wrapper.vm.menuInfoBadge).toBe(null);
  });

  it('frites sauces support multi-select and emit fritesSauceOrder', () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: menuItem(),
        selections: { ...baseSelections(), menuChoice: 'frites' },
      },
    });
    const k = wrapper.vm.fritesSauceList.find((s) => /ketchup/i.test(s.name));
    const m = wrapper.vm.fritesSauceList.find((s) => /mayo/i.test(s.name));
    wrapper.vm.toggleFritesSauce(k);
    wrapper.vm.toggleFritesSauce(m);
    const orderEvents = wrapper.emitted('update').filter((e) => e[0] === 'fritesSauceOrder');
    const last = orderEvents[orderEvents.length - 1][1];
    expect(last).toEqual([k.key, m.key]);
  });

  it('boissonList excludes addons whose name contains menu or frites', () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: menuItem(),
        selections: { ...baseSelections(), menuChoice: 'full' },
      },
    });
    const names = wrapper.vm.boissonList.map((b) => b.name);
    expect(names).toContain('Coca-Cola');
    expect(names).toContain('Jus d\'orange');
    expect(names.some((n) => n.toLowerCase().includes('frite'))).toBe(false);
    expect(names.some((n) => n.toLowerCase().includes('menu'))).toBe(false);
  });

  it('boissonList excludes generic formula labels and keeps real drinks only', () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: {
          ...menuItem(),
          addons: [
            { addon_item_name: 'Boisson seule', group_label: 'boisson', addon_item_id: 10 },
            { addon_item_name: '+ Boisson', group_label: 'boisson', addon_item_id: 11 },
            { addon_item_name: 'Coca-Cola', group_label: 'boisson', addon_item_id: 12 },
          ],
        },
        selections: { ...baseSelections(), menuChoice: 'boisson' },
      },
    });

    expect(wrapper.vm.boissonList.map((b) => b.name)).toEqual(['Coca-Cola']);
  });

  it('boissonList falls back to the central kiosk drink catalog when item addons have no real drinks', () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: {
        plugins: [kioskWizardTestI18n],
        mocks: {
          $store: {
            getters: {
              'kioskMenu/categories': [
                { id: 9, name: 'Boissons', slug: 'boissons' },
                { id: 10, name: 'Tacos', slug: 'tacos' },
              ],
              'kioskMenu/allItems': [
                { id: 901, item_category_id: 9, name: 'Coca-Cola 33cl', status: 5 },
                { id: 902, item_category_id: 9, name: 'Fanta Orange', status: 5 },
                { id: 903, item_category_id: 9, name: 'Boisson seule', status: 5 },
                { id: 904, item_category_id: 9, name: 'Sprite', is_available: false, status: 5 },
                { id: 905, item_category_id: 10, name: 'Tacos M', status: 5 },
              ],
            },
            state: { kioskMenu: {} },
          },
        },
      },
      props: {
        step: {},
        item: {
          ...menuItem(),
          addons: [{ addon_item_name: 'Menu', addon_item_convert_price: 3 }],
        },
        selections: { ...baseSelections(), menuChoice: 'boisson' },
      },
    });

    expect(wrapper.vm.boissonList.map((b) => b.name)).toEqual(['Coca-Cola 33cl', 'Fanta Orange']);
  });
});

describe('KioskWizardComponent — active wizard UX fixes', () => {
  const wizardComponentStubs = Object.fromEntries([
    'KioskStepPain',
    'KioskStepTaille',
    'KioskStepViande',
    'KioskStepSauce',
    'KioskStepGarnitures',
    'KioskStepSupplements',
    'KioskStepMenu',
    'KioskOrderSummary',
    'KsAllergenBadge',
  ].map((n) => [n, true]));

  const mountRealWizard = (item, storeOverrides = {}) => shallowMount(KioskWizardComponent, {
    props: {
      item,
      onAddToCart: vi.fn(),
      onClose: vi.fn(),
    },
    global: {
      plugins: [kioskWizardTestI18n],
      stubs: wizardComponentStubs,
      mocks: {
        $store: {
          getters: {
            'kioskFilter/activeFilters': [],
            'kioskSettings/customerProfile': null,
            ...(storeOverrides.getters || {}),
          },
          state: { globalState: { lists: {} }, ...(storeOverrides.state || {}) },
          dispatch: vi.fn(),
        },
        $router: { go: vi.fn() },
      },
    },
  });

  const wizardItem = () => ({
    id: 1901,
    name: 'Tacos S 1 viande',
    category_name: 'Tacos',
    wizard_template: 'tacos',
    has_menu: true,
    convert_price: '8.50',
    currency_price: '8,50 €',
    itemAttributes: [
      { id: 1, name: 'Viande' },
      { id: 2, name: 'Sauce' },
    ],
    variations: {
      1: [
        { id: 11, name: 'Poulet', convert_price: '0', price: 0, status: 5 },
      ],
      2: [
        { id: 21, name: 'Algérienne', convert_price: '0', price: 0, status: 5 },
        { id: 22, name: 'Blanche', convert_price: '0.50', price: 0.5, status: 5 },
      ],
    },
    extras: [
      { id: 301, name: 'Salade', convert_price: '0', price: 0 },
      { id: 401, name: 'Double Steak', group_label: 'viande', convert_price: '2.50', price: 2.5 },
      { id: 501, name: 'Cheddar', convert_price: '1.00', price: 1 },
      // [COMPOSITION-SAUCE BORNE 2026-07-15] Sauce en plus facturée via cet ItemExtra
      // (group_label='sauce', @0,50) — même convention que le backend réel (migration
      // 2026_07_15_180000). getKioskExtraSauceUnitPrice le lit ; 2 sauces → +0,50 scellé & affiché.
      { id: 431, name: 'Sauce supplémentaire', group_label: 'sauce', convert_price: '0.50', price: 0.5, status: 5 },
    ],
    addons: [
      { addon_item_name: 'Menu', addon_item_convert_price: '3.00', price: 3 },
      { addon_item_name: 'Boisson seule', group_label: 'boisson', addon_item_id: 777 },
      { addon_item_name: 'Coca-Cola', group_label: 'boisson', addon_item_id: 778 },
    ],
  });

  it('clears stale server preview immediately so sauce/supplement/menu prices update live', async () => {
    const wrapper = mountRealWizard(wizardItem());
    await wrapper.vm.$nextTick();

    wrapper.vm.serverPreviewTotal = 8.5;
    wrapper.vm.updateSelection('sauceOrder', [21, 22]);
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.serverPreviewTotal).toBe(null);
    expect(wrapper.vm.runningTotal).toBe(9);

    wrapper.vm.updateSelection('supplements', { 501: true });
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.runningTotal).toBe(10);

    wrapper.vm.updateSelection('supplements', { 501: 2 });
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.runningTotal).toBe(11);

    wrapper.vm.updateSelection('menuChoice', 'boisson');
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.runningTotal).toBe(13.28); // [G-PRIX] boisson 3.0×0.76=2.28
  });

  it('does not let a lower pricing-preview response hide explicit wizard option deltas', async () => {
    const wrapper = mountRealWizard(wizardItem());
    await wrapper.vm.$nextTick();

    wrapper.vm.updateSelection('sauceOrder', [21, 22]);
    wrapper.vm.updateSelection('menuChoice', 'boisson');
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.runningTotalLocal).toBe(11.28); // [G-PRIX]
    wrapper.vm.serverPreviewTotal = 6.5;
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.runningTotal).toBe(11.28);
  });

  it('requires included meat quota but still allows paid meat extras after the free choice', async () => {
    const wrapper = mountRealWizard(wizardItem());
    await wrapper.vm.$nextTick();
    const viandeIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'viande');
    wrapper.vm.currentStepIndex = viandeIdx;

    wrapper.vm.updateSelection('viandes', { 'extra-401': 1 });
    wrapper.vm.updateSelection('totalViandes', 1);
    wrapper.vm.updateSelection('_viandeMeta', [
      { id: 401, key: 'extra-401', name: 'Double Steak', price: 2.5, source: 'extra', count: 1 },
    ]);
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(false);

    wrapper.vm.updateSelection('viandes', { 11: 1, 'extra-401': 1 });
    wrapper.vm.updateSelection('totalViandes', 2);
    wrapper.vm.updateSelection('_viandeMeta', [
      { id: 11, key: '11', name: 'Poulet', price: 0, source: 'variation', count: 1 },
      { id: 401, key: 'extra-401', name: 'Double Steak', price: 2.5, source: 'extra', count: 1 },
    ]);
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(true);
    expect(wrapper.vm.runningTotal).toBe(11);
  });

  it('renders a compact live composition summary as choices are made', async () => {
    const wrapper = mountRealWizard(wizardItem());
    await wrapper.vm.$nextTick();

    expect(wrapper.find('[data-testid="kiosk-wizard-live-composition"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="kiosk-composition-chip-product"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="kiosk-composition-chip-taille"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="kiosk-composition-chip-viande"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="kiosk-composition-empty"]').exists()).toBe(true);

    wrapper.vm.updateSelection('viandes', { 11: 1 });
    wrapper.vm.updateSelection('totalViandes', 1);
    wrapper.vm.updateSelection('_viandeMeta', [
      { id: 11, key: '11', name: 'Poulet', price: 0, source: 'variation', count: 1 },
    ]);
    wrapper.vm.updateSelection('sauceOrder', [21]);
    wrapper.vm.updateSelection('menuChoice', 'boisson');
    wrapper.vm.updateSelection('boissonChoice', 778, { boissonName: 'Coca-Cola', boissonId: 778, addonId: 778 });
    await wrapper.vm.$nextTick();

    expect(wrapper.find('[data-testid="kiosk-composition-empty"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="kiosk-composition-chip-viande"]').text()).toContain('Poulet');

    wrapper.vm.currentStepIndex = wrapper.vm.activeSteps.findIndex((s) => s.type === 'sauce');
    await wrapper.vm.$nextTick();
    expect(wrapper.find('[data-testid="kiosk-composition-chip-sauce"]').text()).toContain('Algérienne');

    wrapper.vm.currentStepIndex = wrapper.vm.activeSteps.findIndex((s) => s.type === 'menu');
    await wrapper.vm.$nextTick();
    expect(wrapper.find('[data-testid="kiosk-composition-chip-menu"]').text()).toContain('Coca-Cola');
  });

  it('KioskStepViande keeps the plus button usable for paid meat after included quota is complete', async () => {
    const wrapper = mount(KioskStepViandeComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: wizardItem(),
        selections: {
          viandes: {},
          _tailleMeta: { viandeCount: 1 },
        },
      },
    });

    wrapper.vm.increment('11');
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.includedQuotaComplete).toBe(true);
    expect(wrapper.vm.canIncrement(wrapper.vm.viandeList.find((v) => v.key === 'extra-401'))).toBe(true);

    wrapper.vm.increment('extra-401');
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.localSelections).toMatchObject({ 11: 1, 'extra-401': 1 });
    expect(wrapper.vm.paidSelected).toBe(1);
    const viandeMetaEvents = wrapper.emitted('update').filter((e) => e[0] === '_viandeMeta');
    const lastMeta = viandeMetaEvents[viandeMetaEvents.length - 1][1];
    expect(lastMeta).toEqual(expect.arrayContaining([
      expect.objectContaining({ id: 401, key: 'extra-401', source: 'extra', count: 1, price: 2.5 }),
    ]));
  });

  it('KioskStepViande selects the first unit from a card tap, then uses plus for repeats', async () => {
    const wrapper = mount(KioskStepViandeComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: wizardItem(),
        selections: {
          viandes: {},
          _tailleMeta: { viandeCount: 2 },
        },
      },
    });

    // Heal 2026-05-17: copy was rewritten by commit 7322940a3 (kiosk-i18n
    // rush-100 VS-A-01) — "Votre tacos comprend" was tacos-specific copy
    // leaking across templates (sandwich/galette/bols). New neutral copy
    // is `instruction_many` = "Choisissez {n} portions de viande : ...".
    // i18n JSON is exempt from the wizard frozen-zone (component untouched).
    expect(wrapper.text()).toContain('Choisissez 2 portions de viande');
    expect(wrapper.text()).not.toContain('Inclus');
    expect(wrapper.text()).toContain('Supplément');

    const card = wrapper.find('.kiosk-viande-card');
    await card.trigger('click');
    expect(wrapper.vm.localSelections).toMatchObject({ 11: 1 });
    expect(wrapper.text()).toContain('Encore 1 portion');
    expect(wrapper.text()).toContain('Choisi');

    await card.trigger('click');
    expect(wrapper.vm.localSelections).toMatchObject({ 11: 1 });

    const plus = wrapper.find('.kiosk-viande-card.active .kiosk-viande-qty-btn.plus');
    expect(plus.exists()).toBe(true);
    await plus.trigger('click');
    expect(wrapper.vm.localSelections).toMatchObject({ 11: 2 });
    expect(wrapper.text()).toContain('Choix validé');
    expect(wrapper.emitted('update').filter((e) => e[0] === 'totalViandes').at(-1)).toEqual(['totalViandes', 2]);
  });

  it('shows the boisson-only formula card for menu-capable tacos without turning it into a fake drink', async () => {
    const wrapper = mountRealWizard(wizardItem());
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.kioskShowBoissonOnlyMenuCard).toBe(true);
    wrapper.vm.currentStepIndex = wrapper.vm.activeSteps.findIndex((s) => s.type === 'menu');
    await wrapper.vm.$nextTick();
    // [W-SPLIT 2026-07-22] Le step menu (template, non-composer) reçoit désormais
    // sectionMode:'formule' pour n'afficher que les cartes formule (boisson/sauce-frites
    // = étapes dédiées). showBoissonOnlyMenuCard reste inchangé.
    expect(wrapper.vm.kioskMenuStepExtraProps).toEqual({ showBoissonOnlyMenuCard: true, sectionMode: 'formule' });
  });

  it('requires a drink selection when only the central kiosk drink catalog supplies the drink list', async () => {
    const wrapper = mountRealWizard({
      ...wizardItem(),
      addons: [{ addon_item_name: 'Menu', addon_item_convert_price: '3.00', price: 3 }],
    }, {
      getters: {
        'kioskMenu/categories': [{ id: 9, name: 'Boissons', slug: 'boissons' }],
        'kioskMenu/allItems': [{ id: 901, item_category_id: 9, name: 'Coca-Cola 33cl', status: 5 }],
      },
    });
    await wrapper.vm.$nextTick();
    // [W-SPLIT 2026-07-22] La formule (menuChoice) se choisit sur l'étape 'menu' ; la
    // boisson se valide désormais sur l'étape DÉDIÉE 'boisson' (min 1). On pose la
    // formule puis on navigue vers l'étape boisson pour tester son gating.
    wrapper.vm.updateSelection('menuChoice', 'boisson');
    await wrapper.vm.$nextTick();
    const boissonIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'boisson');
    expect(boissonIdx).toBeGreaterThanOrEqual(0);
    wrapper.vm.currentStepIndex = boissonIdx;
    wrapper.vm.updateSelection('boissonChoice', null);
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.kioskMenuDrinkChoiceAvailable()).toBe(true);
    expect(wrapper.vm.canAdvance).toBe(false);

    wrapper.vm.updateSelection('boissonChoice', 901, { boissonName: 'Coca-Cola 33cl', boissonId: 901 });
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(true);
  });

  it('KioskStepSupplements exposes plus/minus quantities for repeated extras', async () => {
    const wrapper = mount(KioskStepSupplementsComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: wizardItem(),
        selections: { supplements: {} },
      },
    });

    wrapper.vm.incrementSupplement(501);
    wrapper.vm.incrementSupplement(501);
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.supplementCount(501)).toBe(2);
    expect(wrapper.vm.totalPrice).toBe(2);
    const events = wrapper.emitted('update');
    expect(events[events.length - 1]).toEqual(['supplements', { 501: 2 }]);

    wrapper.vm.decrementSupplement(501);
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.supplementCount(501)).toBe(1);
  });

  it('KioskStepSupplements selects the first unit from a card tap, then uses plus for repeats', async () => {
    const wrapper = mount(KioskStepSupplementsComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: wizardItem(),
        selections: { supplements: {} },
      },
    });

    const row = wrapper.find('.kiosk-supplement-row');
    await row.trigger('click');
    expect(wrapper.vm.supplementCount(501)).toBe(1);

    await row.trigger('click');
    expect(wrapper.vm.supplementCount(501)).toBe(1);

    const plus = wrapper.find('.kiosk-supplement-row.selected .kiosk-supplement-qty-btn.active');
    expect(plus.exists()).toBe(true);
    await plus.trigger('click');
    expect(wrapper.vm.supplementCount(501)).toBe(2);
    expect(wrapper.vm.totalPrice).toBe(2);
    expect(wrapper.emitted('update').at(-1)).toEqual(['supplements', { 501: 2 }]);
  });
});

describe('KioskStepSauceComponent — sauce key / order normalization', () => {
  const sauceItem = () => ({
    itemAttributes: [{ id: 99, name: 'Sauce' }],
    variations: {
      99: [
        { id: 201, name: 'Algérienne', convert_price: '0', price: 0 },
        { id: 202, name: 'Blanche', convert_price: '0.50', price: 0.5 },
      ],
    },
  });

  it('getSauceOrder matches order when sauceOrder mixes number and string ids', () => {
    const wrapper = mount(KioskStepSauceComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: sauceItem(),
        selections: {
          sauces: { 201: true, 202: true },
          sauceOrder: [201, '202'],
        },
      },
    });
    expect(wrapper.vm.getSauceOrder(201)).toBe(1);
    expect(wrapper.vm.getSauceOrder('201')).toBe(1);
    expect(wrapper.vm.getSauceOrder(202)).toBe(2);
    expect(wrapper.vm.getSauceOrder('202')).toBe(2);
  });

  it('sauceList reads variations when attribute id is numeric but variation keys are strings', () => {
    const item = {
      itemAttributes: [{ id: 99, name: 'Sauce' }],
      variations: {
        '99': [
          { id: 301, name: 'Harissa', thumb: null },
          { id: 302, name: 'Mayo', thumb: null },
        ],
      },
    };
    const wrapper = mount(KioskStepSauceComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item,
        selections: { sauces: {}, sauceOrder: [] },
      },
    });
    const names = wrapper.vm.sauceList.map((s) => s.name);
    expect(names).toContain('Harissa');
    expect(names).toContain('Mayo');
    expect(names.length).toBe(2);
  });

  it('sauceList hides variations with status INACTIVE (10) — ACTIVE en base = 5', () => {
    const item = {
      itemAttributes: [{ id: 99, name: 'Sauce' }],
      variations: {
        99: [
          { id: 401, name: 'Active', status: 5, convert_price: '0' },
          { id: 402, name: 'Hidden', status: 10, convert_price: '0' },
        ],
      },
    };
    const wrapper = mount(KioskStepSauceComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item,
        selections: { sauces: {}, sauceOrder: [] },
      },
    });
    const names = wrapper.vm.sauceList.map((s) => s.name);
    expect(names).toContain('Active');
    expect(names).not.toContain('Hidden');
  });

  // [AUDIT 2026-04-17 C5] La liste de sauces ne dispose plus de fallback hardcodé :
  // si toutes les variations sont inactives, la liste est vide et l'étape expose
  // un état vide + un bouton « Continuer sans sauce » (cf. KioskStepSauce empty_hint).
  it('sauceList renvoie [] quand toutes les variations sont inactives (sans fallback hardcodé)', () => {
    const item = {
      itemAttributes: [{ id: 99, name: 'Sauce' }],
      variations: {
        99: [{ id: 402, name: 'Hidden', status: 10, convert_price: '0' }],
      },
    };
    const wrapper = mount(KioskStepSauceComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item,
        selections: { sauces: {}, sauceOrder: [] },
      },
    });
    const names = wrapper.vm.sauceList.map((s) => s.name);
    expect(names).toEqual([]);
    expect(wrapper.text()).toContain(frMessages.kiosk.wizard.step.sauce.empty_hint);
  });
});

/** P0 : même règle que le wizard réel (étape menu) */
const wizardStubNames = [
  'KioskStepPain',
  'KioskStepTaille',
  'KioskStepViande',
  'KioskStepSauce',
  'KioskStepGarnitures',
  'KioskStepSupplements',
  'KioskStepMenu',
  'KioskOrderSummary',
];

describe('KioskWizardComponent — P0 menu obligatoire (réel)', () => {
  const burgerWithMenuOnlySteps = {
    id: 901,
    name: 'Burger Test Menu',
    convert_price: 6.5,
    currency_price: '6,50 €',
    wizard_template: 'burger',
    category_name: 'Burgers',
    has_menu: true,
    itemAttributes: [{ id: 10, name: 'Sauce' }],
    variations: {
      10: [{ id: 201, name: 'Ketchup', convert_price: '0', price: 0 }],
    },
    extras: [],
    addons: [
      {
        addon_item_name: 'Menu',
        addon_item_convert_price: 2,
        price: 2,
      },
    ],
  };

  const stubs = Object.fromEntries(wizardStubNames.map((n) => [n, true]));

  it('canAdvance false on menu step when menuChoice is null', async () => {
    const wrapper = shallowMount(KioskWizardComponent, {
      props: {
        item: burgerWithMenuOnlySteps,
        onAddToCart: vi.fn(),
        onClose: vi.fn(),
      },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    const steps = wrapper.vm.activeSteps;
    const menuIdx = steps.findIndex((s) => s.type === 'menu');
    expect(menuIdx).toBeGreaterThanOrEqual(0);
    wrapper.vm.currentStepIndex = menuIdx;
    wrapper.vm.selections.menuChoice = null;
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(false);
  });

  it('canAdvance true on menu step for none, or full when aucun addon boisson', async () => {
    const wrapper = shallowMount(KioskWizardComponent, {
      props: {
        item: burgerWithMenuOnlySteps,
        onAddToCart: vi.fn(),
        onClose: vi.fn(),
      },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    const menuIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'menu');
    wrapper.vm.currentStepIndex = menuIdx;

    wrapper.vm.selections.menuChoice = 'none';
    wrapper.vm.selections.boissonChoice = null;
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(true);

    wrapper.vm.selections.menuChoice = 'full';
    wrapper.vm.selections.boissonChoice = null;
    wrapper.vm.selections.fritesSauceOrder = ['sans'];
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(true);
  });
});

describe('KioskWizardComponent — P1 boisson obligatoire si addons boisson (réel)', () => {
  const burgerMenuPlusDrink = {
    id: 902,
    name: 'Burger Menu Boisson',
    convert_price: 6.5,
    currency_price: '6,50 €',
    wizard_template: 'burger',
    category_name: 'Burgers',
    has_menu: true,
    itemAttributes: [{ id: 10, name: 'Sauce' }],
    variations: {
      10: [{ id: 201, name: 'Ketchup', convert_price: '0', price: 0 }],
    },
    extras: [],
    addons: [
      { addon_item_name: 'Menu', addon_item_convert_price: 2, price: 2 },
      { addon_item_name: 'Coca-Cola', addon_item_id: 44 },
    ],
  };

  const stubs = Object.fromEntries(wizardStubNames.map((n) => [n, true]));

  it('canAdvance false on menu when full + liste boisson mais boissonChoice vide', async () => {
    const wrapper = shallowMount(KioskWizardComponent, {
      props: {
        item: burgerMenuPlusDrink,
        onAddToCart: vi.fn(),
        onClose: vi.fn(),
      },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    // [W-SPLIT 2026-07-22] La formule 'full' se pose sur l'étape menu ; la boisson se
    // valide sur l'étape DÉDIÉE 'boisson'. boissonChoice vide → canAdvance false LÀ.
    wrapper.vm.selections.menuChoice = 'full';
    await wrapper.vm.$nextTick();
    const boissonIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'boisson');
    expect(boissonIdx).toBeGreaterThanOrEqual(0);
    wrapper.vm.currentStepIndex = boissonIdx;
    wrapper.vm.selections.boissonChoice = null;
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(false);
  });

  it('canAdvance true when full + boissonChoice renseigné', async () => {
    const wrapper = shallowMount(KioskWizardComponent, {
      props: {
        item: burgerMenuPlusDrink,
        onAddToCart: vi.fn(),
        onClose: vi.fn(),
      },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    // [W-SPLIT 2026-07-22] Formule 'full' sur l'étape menu ; la boisson (44) se valide
    // sur l'étape dédiée 'boisson' → canAdvance true quand renseignée.
    wrapper.vm.selections.menuChoice = 'full';
    wrapper.vm.selections.boissonChoice = 44;
    await wrapper.vm.$nextTick();
    const boissonIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'boisson');
    expect(boissonIdx).toBeGreaterThanOrEqual(0);
    wrapper.vm.currentStepIndex = boissonIdx;
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(true);
  });

  it('canAdvance false for boisson-only formula when boissonChoice vide', async () => {
    const wrapper = shallowMount(KioskWizardComponent, {
      props: {
        item: burgerMenuPlusDrink,
        onAddToCart: vi.fn(),
        onClose: vi.fn(),
      },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    // [W-SPLIT 2026-07-22] Formule 'boisson' (boisson seule) posée sur l'étape menu ;
    // la boisson se valide sur l'étape DÉDIÉE 'boisson'.
    wrapper.vm.selections.menuChoice = 'boisson';
    await wrapper.vm.$nextTick();
    const boissonIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'boisson');
    expect(boissonIdx).toBeGreaterThanOrEqual(0);
    wrapper.vm.currentStepIndex = boissonIdx;
    wrapper.vm.selections.boissonChoice = null;
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(false);
  });

  it('canAdvance true for boisson-only menu formula with drink selected', async () => {
    const wrapper = shallowMount(KioskWizardComponent, {
      props: {
        item: burgerMenuPlusDrink,
        onAddToCart: vi.fn(),
        onClose: vi.fn(),
      },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    // [W-SPLIT 2026-07-22] Formule 'boisson' sur l'étape menu ; la boisson (44) se valide
    // sur l'étape dédiée 'boisson' → canAdvance true quand choisie.
    wrapper.vm.selections.menuChoice = 'boisson';
    wrapper.vm.selections.boissonChoice = 44;
    await wrapper.vm.$nextTick();
    const boissonIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'boisson');
    expect(boissonIdx).toBeGreaterThanOrEqual(0);
    wrapper.vm.currentStepIndex = boissonIdx;
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(true);
  });
});

describe('KioskStepMenuComponent — P0 hint when no choice yet', () => {
  it('shows validation hint when localChoice is null', () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: {
          addons: [{ addon_item_name: 'Menu', addon_item_convert_price: 1 }],
        },
        selections: {
          menuChoice: null,
          boissonChoice: null,
          fritesSauceOrder: [],
        },
      },
    });
    expect(wrapper.find('.kiosk-menu-validation-hint').exists()).toBe(true);
  });

  it('hides validation hint after sans menu selected', async () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: {
          addons: [{ addon_item_name: 'Menu', addon_item_convert_price: 1 }],
        },
        selections: {
          menuChoice: null,
          boissonChoice: null,
          fritesSauceOrder: [],
        },
      },
    });
    await wrapper.vm.selectChoice('none');
    await wrapper.vm.$nextTick();
    expect(wrapper.find('.kiosk-menu-validation-hint').exists()).toBe(false);
  });

  it('P1: shows boisson hint when menu complet + liste boisson sans sélection', async () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: {
          addons: [
            { addon_item_name: 'Menu', addon_item_convert_price: 1 },
            { addon_item_name: 'Coca-Cola', addon_item_id: 9 },
          ],
        },
        selections: {
          menuChoice: null,
          boissonChoice: null,
          fritesSauceOrder: [],
        },
      },
    });
    await wrapper.vm.selectChoice('full');
    await wrapper.vm.$nextTick();
    expect(wrapper.find('.kiosk-boisson-validation-hint').exists()).toBe(true);
    await wrapper.vm.selectBoisson({
      id: 9,
      name: 'Coca-Cola',
      emoji: '🥤',
      displayThumb: null,
    });
    await wrapper.vm.$nextTick();
    expect(wrapper.find('.kiosk-boisson-validation-hint').exists()).toBe(false);
  });
});

describe('KioskWizardComponent — P2 modale abandon (réel)', () => {
  const stubs = Object.fromEntries(wizardStubNames.map((n) => [n, true]));

  const minimalItem = {
    id: 1,
    name: 'Item',
    convert_price: 5,
    currency_price: '5',
    wizard_template: 'simple',
    itemAttributes: [],
    variations: {},
    extras: [],
  };

  it('onAbandonClick ouvre la modale sans appeler onClose', async () => {
    const onClose = vi.fn();
    const wrapper = shallowMount(KioskWizardComponent, {
      props: {
        item: minimalItem,
        onAddToCart: vi.fn(),
        onClose,
      },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    wrapper.vm.onAbandonClick();
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.showAbandonConfirm).toBe(true);
    expect(onClose).not.toHaveBeenCalled();
  });

  it('onAbandonCancel ferme la modale', async () => {
    const wrapper = shallowMount(KioskWizardComponent, {
      props: {
        item: minimalItem,
        onAddToCart: vi.fn(),
        onClose: vi.fn(),
      },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    wrapper.vm.showAbandonConfirm = true;
    wrapper.vm.onAbandonCancel();
    expect(wrapper.vm.showAbandonConfirm).toBe(false);
  });

  it('onAbandonConfirm appelle onClose', async () => {
    const onClose = vi.fn();
    const wrapper = shallowMount(KioskWizardComponent, {
      props: {
        item: minimalItem,
        onAddToCart: vi.fn(),
        onClose,
      },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    wrapper.vm.showAbandonConfirm = true;
    wrapper.vm.onAbandonConfirm();
    expect(wrapper.vm.showAbandonConfirm).toBe(false);
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});

describe('KioskWizardComponent — P3 i18n wizard (réel)', () => {
  const stubs = Object.fromEntries(wizardStubNames.map((n) => [n, true]));
  const minimalItem = {
    id: 1,
    name: 'Item',
    convert_price: 5,
    currency_price: '5',
    wizard_template: 'simple',
    itemAttributes: [],
    variations: {},
    extras: [],
  };

  it('modale abandon affiche les libellés kiosk.wizard (fr)', async () => {
    const w = frMessages.kiosk.wizard;
    const wrapper = shallowMount(KioskWizardComponent, {
      props: {
        item: minimalItem,
        onAddToCart: vi.fn(),
        onClose: vi.fn(),
      },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    wrapper.vm.onAbandonClick();
    await wrapper.vm.$nextTick();
    const txt = wrapper.text();
    expect(txt).toContain(w.abandon_title);
    expect(txt).toContain(w.abandon_yes);
    expect(txt).toContain(w.abandon_continue);
  });
});

// [P-MEGA-01] Tests SUR LE VRAI COMPOSANT (pas sur le mock local).
// Reproduit puis verrouille le bug rapporté : "Tacos 2/3/4 viandes →
// on ne peut sélectionner qu'une viande". Cause racine : 3 fonctions
// (detectViandeCount / shouldAskTacosTaille / inferTacosPresetMeta)
// utilisaient des regex incohérentes. Refactor : helper SSOT
// kioskTacosSize.
describe('KioskWizardComponent (réel) — P-MEGA-01 viandeCount cohérent', () => {
  const stubs = Object.fromEntries(wizardStubNames.map((n) => [n, true]));

  const mountWithItem = (item) =>
    shallowMount(KioskWizardComponent, {
      props: { item, onAddToCart: vi.fn(), onClose: vi.fn() },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });

  // Cas qui DEVRAIENT marcher d'après le libellé admin.
  const sizeCases = [
    { name: 'Tacos M', expected: 1 },
    { name: 'Tacos L', expected: 2 },
    { name: 'Tacos XL', expected: 3 },
    { name: 'Tacos XXL', expected: 4 },
    { name: 'Tacos Méga', expected: 4 },
    { name: 'Tacos Famille', expected: 4 },
    { name: 'Tacos 2 viandes', expected: 2 },
    { name: 'Tacos 3 viandes', expected: 3 },
    { name: 'Tacos 4 viandes', expected: 4 },
    { name: 'Tacos L 3 viandes', expected: 3 }, // digit gagne sur lettre
  ];

  sizeCases.forEach(({ name, expected }) => {
    it(`detectViandeCount("${name}") = ${expected} (sans step Taille préalable)`, async () => {
      const item = {
        id: 1000 + expected,
        name,
        category_name: 'Tacos',
        wizard_template: 'tacos',
        convert_price: 7.5,
        currency_price: '7,50 €',
        itemAttributes: [{ id: 1, name: 'Viande' }],
        variations: { 1: [{ id: 11, name: 'Boeuf' }, { id: 12, name: 'Poulet' }] },
        extras: [],
      };
      const wrapper = mountWithItem(item);
      await wrapper.vm.$nextTick();
      expect(wrapper.vm.detectViandeCount()).toBe(expected);
    });
  });

  it('shouldAskTacosTaille = false quand le nom contient une taille reconnue', async () => {
    const wrapper = mountWithItem({
      id: 2001,
      name: 'Tacos Méga',
      category_name: 'Tacos',
      wizard_template: 'tacos',
      convert_price: 12,
      currency_price: '12,00 €',
      itemAttributes: [],
      variations: {},
      extras: [],
    });
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.shouldAskTacosTaille()).toBe(false);
  });

  it('shouldAskTacosTaille = true sur un libellé bordelin (Tacos Spécial), pour éviter le fallback à 1', async () => {
    const wrapper = mountWithItem({
      id: 2002,
      name: 'Tacos Spécial Maison',
      category_name: 'Tacos',
      wizard_template: 'tacos',
      convert_price: 9,
      currency_price: '9,00 €',
      itemAttributes: [],
      variations: {},
      extras: [],
    });
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.shouldAskTacosTaille()).toBe(true);
  });

  it('item.viande_count serveur prime sur tout (P-MEGA-23 future SSOT)', async () => {
    const wrapper = mountWithItem({
      id: 2003,
      name: 'Tacos Spécial Maison', // libellé non reconnu
      category_name: 'Tacos',
      wizard_template: 'tacos',
      viande_count: 3, // serveur dit "3 viandes"
      convert_price: 11,
      currency_price: '11,00 €',
      itemAttributes: [],
      variations: {},
      extras: [],
    });
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.detectViandeCount()).toBe(3);
    expect(wrapper.vm.shouldAskTacosTaille()).toBe(false);
  });

  it('selections._tailleMeta.viandeCount prime sur tout le reste', async () => {
    const wrapper = mountWithItem({
      id: 2004,
      name: 'Tacos M', // donnerait 1 par heuristique
      category_name: 'Tacos',
      wizard_template: 'tacos',
      convert_price: 7,
      currency_price: '7,00 €',
      itemAttributes: [],
      variations: {},
      extras: [],
    });
    await wrapper.vm.$nextTick();
    wrapper.vm.selections._tailleMeta = { viandeCount: 4, label: 'XXL' };
    expect(wrapper.vm.detectViandeCount()).toBe(4);
  });

  it('inferTacosPresetMeta retourne label cohérent pour Tacos Méga', async () => {
    const wrapper = mountWithItem({
      id: 2005,
      name: 'Tacos Méga',
      category_name: 'Tacos',
      wizard_template: 'tacos',
      convert_price: 12,
      currency_price: '12,00 €',
      itemAttributes: [],
      variations: {},
      extras: [],
    });
    await wrapper.vm.$nextTick();
    const meta = wrapper.vm.inferTacosPresetMeta();
    expect(meta).not.toBeNull();
    expect(meta.viandeCount).toBe(4);
    expect(meta.label).toBe('Méga');
    expect(meta.size).toBe('MEGA');
  });
});

describe('KioskWizardComponent — P5 detectTemplateFromName (alignement wizard_template)', () => {
  const stubs = Object.fromEntries(wizardStubNames.map((n) => [n, true]));
  const minimalForTemplate = (overrides) => ({
    id: 1,
    name: 'Produit',
    category_name: '',
    convert_price: 5,
    currency_price: '5',
    extras: [],
    itemAttributes: [],
    variations: {},
    ...overrides,
  });

  const mountWizardTemplateProbe = (item) =>
    shallowMount(KioskWizardComponent, {
      props: {
        item,
        onAddToCart: vi.fn(),
        onClose: vi.fn(),
      },
      global: {
        plugins: [kioskWizardTestI18n],
        stubs,
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });

  const cases = [
    { name: 'Tacos XL 2 viandes', category_name: 'Tacos', exp: 'tacos' },
    { name: 'Wrap poulet', category_name: 'Sandwichs', exp: 'sandwich' },
    { name: 'Double Cheese', category_name: 'Burgers', exp: 'burger' },
    { name: 'Assiette kebab', category_name: '', exp: 'assiette' },
    { name: 'Nuggets x9', category_name: 'Snacking', exp: 'snacking' },
    { name: 'Goujons de poulet', category_name: 'Accompagnements', exp: 'snacking' },
    { name: 'Omelette complète', category_name: 'Petit déjeuner', exp: 'omelette' },
    { name: 'Salade César', category_name: 'Salades', exp: 'salade' },
    { name: 'Coca-Cola 33cl', category_name: 'Boissons', exp: 'simple' },
  ];

  it.each(cases)(
    'heuristique: « $name » / cat « $category_name » → $exp',
    ({ name, category_name, exp }) => {
      const item = minimalForTemplate({ name, category_name });
      const w = mountWizardTemplateProbe(item);
      expect(w.vm.detectTemplateFromName()).toBe(exp);
    }
  );

  it('wizard_template explicite prime sur le nom (override catalogue)', async () => {
    const item = minimalForTemplate({
      name: 'Nuggets',
      category_name: 'Snacking',
      wizard_template: 'burger',
    });
    const w = mountWizardTemplateProbe(item);
    await w.vm.$nextTick();
    const resolved =
      w.vm.resolvedItem.wizard_template || w.vm.detectTemplateFromName();
    expect(resolved).toBe('burger');
  });

  it('sans wizard_template, activeSteps suit la même branche que l’heuristique (burger)', async () => {
    // [AUDIT 2026-04-17 C2] shouldShowStep('sauce') exige désormais des variations
    // sauce dans le catalogue (plus de fallback hardcodé). On en provisionne pour
    // valider la branche burger sans casser l'invariant SSOT.
    const item = minimalForTemplate({
      name: 'Classic Burger',
      category_name: 'Burgers',
      itemAttributes: [{ id: 33, name: 'Sauce' }],
      variations: { 33: [{ id: 1, name: 'Ketchup' }] },
    });
    const w = mountWizardTemplateProbe(item);
    await w.vm.$nextTick();
    expect(w.vm.detectTemplateFromName()).toBe('burger');
    const types = w.vm.activeSteps.map((s) => s.type);
    expect(types).toContain('sauce');
    expect(types).toContain('recap');
  });

  it('wizard_template « simple » + composer_profile null verrouille le produit sans wizard heuristique', async () => {
    const item = minimalForTemplate({
      name: 'Classic Burger',
      category_name: 'Burgers',
      wizard_template: 'simple',
      composer_profile: null,
      itemAttributes: [{ id: 33, name: 'Sauce' }],
      variations: { 33: [{ id: 1, name: 'Ketchup' }] },
    });
    const w = mountWizardTemplateProbe(item);
    await w.vm.$nextTick();
    expect(w.vm.effectiveWizardTemplate()).toBe('simple');
    const types = w.vm.activeSteps.map((s) => s.type);
    expect(types).not.toContain('sauce');
    expect(types).toEqual(['recap']);
  });

  it('legacy simple payload without composer_profile key keeps heuristic fallback', async () => {
    const item = minimalForTemplate({
      name: 'Classic Burger',
      category_name: 'Burgers',
      wizard_template: 'simple',
      itemAttributes: [{ id: 33, name: 'Sauce' }],
      variations: { 33: [{ id: 1, name: 'Ketchup' }] },
    });
    const w = mountWizardTemplateProbe(item);
    await w.vm.$nextTick();
    expect(w.vm.effectiveWizardTemplate()).toBe('burger');
    expect(w.vm.activeSteps.map((s) => s.type)).toContain('sauce');
  });
});

describe('KioskWizardComponent — P4 i18n étapes pain/sauce (réel)', () => {
  // [AUDIT 2026-04-17 C6] KioskStepPain n'expose plus de fallback hardcodé.
  // Quand le catalogue ne fournit aucun pain, on affiche le titre + l'empty_hint.
  it('KioskStepPain affiche titre et empty_hint quand catalogue vide (fr)', () => {
    const st = frMessages.kiosk.wizard.step;
    const wrapper = mount(KioskStepPainComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: {},
        selections: { pain: null },
      },
    });
    expect(wrapper.text()).toContain(st.pain.title);
    expect(wrapper.text()).toContain(st.pain.empty_hint);
  });

  it('KioskStepPain liste dynamiquement les variations Pain du catalogue', () => {
    const st = frMessages.kiosk.wizard.step;
    const wrapper = mount(KioskStepPainComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: {
          itemAttributes: [{ id: 4, name: 'Pain' }],
          variations: {
            4: [
              { id: 100, name: 'Tortilla' },
              { id: 101, name: 'Galette' },
            ],
          },
        },
        selections: { pain: null },
      },
    });
    const text = wrapper.text();
    expect(text).toContain(st.pain.title);
    expect(text).toContain('Tortilla');
    expect(text).toContain('Galette');
  });

  it('KioskStepSauce affiche le titre (fr, liste fallback)', () => {
    const st = frMessages.kiosk.wizard.step;
    const wrapper = mount(KioskStepSauceComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: {},
        selections: { sauces: {}, sauceOrder: [] },
      },
    });
    expect(wrapper.text()).toContain(st.sauce.title);
  });
});

// [AUDIT 2026-04-17 C6/C7] Plus de fallback hardcodé : si le catalogue ne fournit
// pas de variations pour pain/viande, la liste retournée est vide. C'est
// `KioskWizard.shouldShowStep` qui se charge de masquer l'étape; les composants
// affichent l'empty_hint sans tenter de combler avec des défauts inventés.
describe('Kiosk step empty-state — missing catalog attributes', () => {
  it('KioskStepPain returns empty list (no hardcoded fallback) when no pain attribute exists', () => {
    const wrapper = mount(KioskStepPainComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: {
          itemAttributes: [{ id: 99, name: 'Sauce' }],
          variations: { 99: [{ id: 1, name: 'Harissa' }] },
        },
        selections: { pain: null },
      },
    });

    expect(wrapper.vm.painList.map((p) => p.name)).toEqual([]);
    expect(wrapper.text()).toContain(frMessages.kiosk.wizard.step.pain.empty_hint);
  });

  it('KioskStepViande returns empty list (no hardcoded fallback) when no viande attribute and no paid viande extra exist', () => {
    const wrapper = mount(KioskStepViandeComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: {
          name: 'Produit test',
          itemAttributes: [{ id: 88, name: 'Sauce' }],
          variations: { 88: [{ id: 1, name: 'Algérienne' }] },
          extras: [],
        },
        selections: { viandes: {}, _tailleMeta: null },
      },
    });

    expect(wrapper.vm.viandeList.map((v) => v.name)).toEqual([]);
    expect(wrapper.text()).toContain(frMessages.kiosk.wizard.step.viande.empty_hint);
  });

  it('KioskStepViande surfaces paid meat extras in the dynamic catalog', () => {
    const wrapper = mount(KioskStepViandeComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: {
          name: 'Burger XL',
          itemAttributes: [{ id: 12, name: 'Viande' }],
          variations: { 12: [{ id: 1, name: 'Steak' }] },
          extras: [
            { id: 99, name: 'Double Steak', group_label: 'viande', convert_price: 2.5 },
          ],
        },
        selections: { viandes: {}, _tailleMeta: null },
      },
    });

    const names = wrapper.vm.viandeList.map((v) => v.name);
    expect(names).toContain('Steak');
    expect(names).toContain('Double Steak');
  });
});
