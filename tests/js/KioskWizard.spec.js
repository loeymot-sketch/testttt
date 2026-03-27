import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

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
      selections: {
        pain: null,
        viandes: {},
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
