import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount, shallowMount } from '@vue/test-utils';
import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import KioskStepMenuComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue';
import KioskStepSauceComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue';
import KioskStepPainComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue';
import KioskStepViandeComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue';
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
        if (this.selections.supplements[id]) {
          const extra = item.extras?.find(e => e.id === parseInt(id));
          normalizedExtras.push({ id: parseInt(id), name: extra?.name || '' });
          if (extra) itemExtraTotal += parseFloat(extra.convert_price || extra.price || 0);
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

  it('menuPrice applies 0.6 / 0.4 ratios like runningTotal', async () => {
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
    expect(wrapper.vm.menuPrice).toBeCloseTo(6);
    await wrapper.vm.selectChoice('boisson');
    expect(wrapper.vm.menuPrice).toBeCloseTo(4);
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
    const k = wrapper.vm.fritesSauceList.find((s) => s.key === 'ketchup');
    const m = wrapper.vm.fritesSauceList.find((s) => s.key === 'mayo');
    wrapper.vm.toggleFritesSauce(k);
    wrapper.vm.toggleFritesSauce(m);
    const orderEvents = wrapper.emitted('update').filter((e) => e[0] === 'fritesSauceOrder');
    const last = orderEvents[orderEvents.length - 1][1];
    expect(last).toEqual(['ketchup', 'mayo']);
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

  it('sauceList uses fallback when toutes les variations sont inactives (étape non bloquée)', () => {
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
    expect(names.length).toBeGreaterThan(0);
    expect(names).not.toContain('Hidden');
    expect(names).toContain(frMessages.kiosk.wizard.step.sauce.fallback_ketchup);
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
    const menuIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'menu');
    wrapper.vm.currentStepIndex = menuIdx;
    wrapper.vm.selections.menuChoice = 'full';
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
    const menuIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'menu');
    wrapper.vm.currentStepIndex = menuIdx;
    wrapper.vm.selections.menuChoice = 'full';
    wrapper.vm.selections.boissonChoice = 44;
    wrapper.vm.selections.fritesSauceOrder = ['sans'];
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
    const menuIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'menu');
    wrapper.vm.currentStepIndex = menuIdx;
    wrapper.vm.selections.menuChoice = 'boisson';
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
    const menuIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'menu');
    wrapper.vm.currentStepIndex = menuIdx;
    wrapper.vm.selections.menuChoice = 'boisson';
    wrapper.vm.selections.boissonChoice = 44;
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
    const item = minimalForTemplate({
      name: 'Classic Burger',
      category_name: 'Burgers',
    });
    const w = mountWizardTemplateProbe(item);
    await w.vm.$nextTick();
    expect(w.vm.detectTemplateFromName()).toBe('burger');
    const types = w.vm.activeSteps.map((s) => s.type);
    expect(types).toContain('sauce');
    expect(types).toContain('recap');
  });

  it('wizard_template « simple » (défaut API) n’empêche pas l’heuristique burger → étape sauce', async () => {
    const item = minimalForTemplate({
      name: 'Classic Burger',
      category_name: 'Burgers',
      wizard_template: 'simple',
    });
    const w = mountWizardTemplateProbe(item);
    await w.vm.$nextTick();
    expect(w.vm.effectiveWizardTemplate()).toBe('burger');
    const types = w.vm.activeSteps.map((s) => s.type);
    expect(types).toContain('sauce');
  });
});

describe('KioskWizardComponent — P4 i18n étapes pain/sauce (réel)', () => {
  it('KioskStepPain affiche titres et pain par défaut (fr)', () => {
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
    expect(wrapper.text()).toContain(st.pain.default_bread);
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

describe('Kiosk step fallbacks — missing catalog attributes', () => {
  it('KioskStepPain falls back to default list when itemAttributes exist but no pain attribute is present', () => {
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

    const names = wrapper.vm.painList.map((p) => p.name);
    expect(names).toContain(frMessages.kiosk.wizard.step.pain.default_bread);
    expect(names).toContain(frMessages.kiosk.wizard.step.pain.default_galette);
  });

  it('KioskStepViande falls back to default list when itemAttributes exist but no viande attribute is present', () => {
    const wrapper = mount(KioskStepViandeComponent, {
      global: { plugins: [kioskWizardTestI18n] },
      props: {
        step: {},
        item: {
          name: 'Produit test',
          itemAttributes: [{ id: 88, name: 'Sauce' }],
          variations: { 88: [{ id: 1, name: 'Algérienne' }] },
        },
        selections: { viandes: {}, _tailleMeta: null },
      },
    });

    const names = wrapper.vm.viandeList.map((v) => v.name);
    expect(names).toContain(frMessages.kiosk.wizard.step.viande.fallback_poulet);
    expect(names).toContain(frMessages.kiosk.wizard.step.viande.fallback_boeuf);
  });
});
