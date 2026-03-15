# KIMI SPRINT 10 — WIZARD BORNE TACTILE
**Émetteur :** Claude (Architecte)
**Destinataire :** KIMI (Builder)
**Priorité :** 🟡 P2 — UX borne — après MVP caisse
**Test type :** Kimi-test (Vitest/Jest sur composants Vue)
**Dépendances :** Sprint 6 (wizard POS complet) + Sprint 9 (sécurité borne) terminés

---

## CONTEXTE ARCHITECTURAL (lire avant de coder)

La borne (Kiosk) utilise la même API que le web client (`POST /api/frontend/order/`).
Le `pos-wizard.js` est **explicitement limité au POS** (ligne 20 : `if (!window.location.pathname.includes('/admin/pos')) return;`).

La borne a besoin d'un wizard dédié, en Vue natif, adapté au tactile. Ce wizard doit :
- Reproduire la même logique de personnalisation que le wizard POS
- Être adapté à un écran tactile (boutons 64px+, grandes cartes visuelles)
- Utiliser les mêmes données API (`NormalItemResource`) que le POS
- Soumettre au même endpoint (`POST /api/frontend/order/`) avec le même format JSON

**Règle absolue :** Ne pas modifier `pos-wizard.js` (POS uniquement). Ne pas modifier `FrontendOrderService.php`. Le nouveau composant doit être autonome et ne pas affecter le POS existant.

---

## ARCHITECTURE DU WIZARD BORNE

### Composants à créer

```
resources/js/components/frontend/kiosk/
├── KioskWizardComponent.vue          ← Wizard principal (étapes)
├── KioskOrderSummaryComponent.vue    ← Récapitulatif avant paiement
├── KioskConfirmationComponent.vue    ← Écran de confirmation post-commande
└── steps/
    ├── KioskStepPain.vue             ← Étape Pain/Galette (sandwichs)
    ├── KioskStepViande.vue           ← Étape Viandes (multi-select avec compteur)
    ├── KioskStepSauce.vue            ← Étape Sauces (multi-select, 1ère gratuite)
    ├── KioskStepGarnitures.vue       ← Étape Garnitures (toggle individuel)
    ├── KioskStepSupplements.vue      ← Étape Suppléments (multi-select payant)
    └── KioskStepMenu.vue             ← Étape Menu combo (frites+boisson)
```

---

## COMPOSANT PRINCIPAL — `KioskWizardComponent.vue`

### Structure générale

```vue
<template>
  <div class="kiosk-wizard" v-if="item">
    <!-- Header : image item + nom + prix courant -->
    <div class="kiosk-wizard-header">
      <img :src="item.thumb" class="kiosk-item-img" />
      <div class="kiosk-item-info">
        <h2>{{ item.name }}</h2>
        <p class="kiosk-running-total">{{ formatPrice(runningTotal) }}</p>
      </div>
    </div>

    <!-- Barre de progression numérotée -->
    <div class="kiosk-progress-bar">
      <div
        v-for="(step, index) in activeSteps"
        :key="step.type"
        class="kiosk-step-dot"
        :class="{ active: index === currentStepIndex, done: index < currentStepIndex }"
      >
        <span class="kiosk-step-number">{{ index + 1 }}</span>
        <span class="kiosk-step-label">{{ step.label }}</span>
      </div>
    </div>

    <!-- Contenu de l'étape courante -->
    <div class="kiosk-step-content">
      <component
        :is="currentStepComponent"
        :step="currentStep"
        :item="item"
        :selections="selections"
        @update="updateSelection"
      />
    </div>

    <!-- Navigation -->
    <div class="kiosk-nav">
      <button v-if="currentStepIndex > 0" @click="prevStep" class="kiosk-btn-back">
        ← Retour
      </button>
      <button
        @click="currentStepIndex < activeSteps.length - 1 ? nextStep() : addToCart()"
        class="kiosk-btn-next"
        :disabled="!canAdvance"
      >
        {{ currentStepIndex < activeSteps.length - 1 ? 'Suivant →' : 'Ajouter au panier' }}
      </button>
    </div>
  </div>
</template>
```

### Script — logique principale

```javascript
export default {
  name: 'KioskWizardComponent',
  props: {
    item: { type: Object, required: true },
    onAddToCart: { type: Function, required: true },
    onClose: { type: Function, required: true }
  },
  data() {
    return {
      currentStepIndex: 0,
      selections: {
        pain: null,          // ID de la variation Pain/Galette
        viandes: {},         // { key: count } — ex: { poulet: 2, merguez: 1 }
        totalViandes: 0,
        sauces: {},          // { id: true/false }
        sauceOrder: [],      // [id1, id2, ...] — ordre de sélection
        garnitures: {},      // { id: true/false } — pré-cochées
        supplements: {},     // { id: true/false }
        menuChoice: null,    // 'full' | 'frites' | 'boisson' | 'none'
        boissonChoice: null, // ID de l'addon boisson
        quantity: 1,
        instruction: ''
      }
    };
  },
  computed: {
    // Déterminer les étapes selon la catégorie de l'item
    activeSteps() {
      const template = this.item.wizard_template || 'simple';
      const hasViandes = this.detectViandeCount() > 0;
      
      switch (template) {
        case 'tacos':
          return [
            { type: 'viande', label: 'Viande(s)', component: 'KioskStepViande' },
            { type: 'sauce', label: 'Sauce', component: 'KioskStepSauce' },
            { type: 'garnitures', label: 'Garnitures', component: 'KioskStepGarnitures' },
            { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
            { type: 'menu', label: 'Menu', component: 'KioskStepMenu' },
            { type: 'recap', label: 'Récap', component: 'KioskOrderSummaryComponent' }
          ].filter(s => this.shouldShowStep(s.type));
        case 'sandwich':
          return [
            { type: 'pain', label: 'Pain', component: 'KioskStepPain' },
            ...(hasViandes ? [{ type: 'viande', label: 'Viande(s)', component: 'KioskStepViande' }] : []),
            { type: 'sauce', label: 'Sauce', component: 'KioskStepSauce' },
            { type: 'garnitures', label: 'Garnitures', component: 'KioskStepGarnitures' },
            { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
            { type: 'menu', label: 'Menu', component: 'KioskStepMenu' },
            { type: 'recap', label: 'Récap', component: 'KioskOrderSummaryComponent' }
          ].filter(s => this.shouldShowStep(s.type));
        // ... autres catégories
        default:
          return [{ type: 'recap', label: 'Récap', component: 'KioskOrderSummaryComponent' }];
      }
    },
    currentStep() {
      return this.activeSteps[this.currentStepIndex];
    },
    currentStepComponent() {
      return this.currentStep?.component;
    },
    // Total courant (base + variations + extras)
    runningTotal() {
      let total = parseFloat(this.item.convert_price) || 0;
      // Ajouter prix sauces supplémentaires
      if (this.selections.sauceOrder.length > 1) {
        total += (this.selections.sauceOrder.length - 1) * 0.50;
      }
      // Ajouter prix suppléments
      if (this.item.extras) {
        this.item.extras.forEach(extra => {
          if (this.selections.supplements[extra.id]) {
            total += parseFloat(extra.convert_price) || 0;
          }
        });
      }
      // Ajouter prix menu
      if (this.selections.menuChoice === 'full' && this.item.addons) {
        const menuAddon = this.item.addons.find(a => a.addon_item_name?.toLowerCase().includes('menu'));
        if (menuAddon) total += parseFloat(menuAddon.addon_item_convert_price) || 0;
      }
      return total * this.selections.quantity;
    },
    canAdvance() {
      // Validation par étape
      const step = this.currentStep;
      if (!step) return false;
      if (step.type === 'viande') return this.selections.totalViandes >= this.detectViandeCount();
      if (step.type === 'sauce') return this.selections.sauceOrder.length > 0;
      if (step.type === 'pain') return this.selections.pain !== null;
      return true; // Autres étapes sont optionnelles
    }
  },
  methods: {
    detectViandeCount() {
      const name = (this.item.name || '').toLowerCase();
      if (name.includes('xxl') || name.includes('4 viande')) return 4;
      if (name.includes('xl') || name.includes('3 viande')) return 3;
      if (name.includes(' l ') || name.includes('2 viande')) return 2;
      return 1;
    },
    shouldShowStep(type) {
      if (type === 'supplements') {
        return this.item.extras && this.item.extras.some(e => parseFloat(e.convert_price) > 0);
      }
      if (type === 'garnitures') {
        return this.item.extras && this.item.extras.some(e => parseFloat(e.convert_price) <= 0);
      }
      if (type === 'menu') {
        return this.item.has_menu && this.item.addons && this.item.addons.length > 0;
      }
      return true;
    },
    updateSelection(key, value) {
      this.selections[key] = value;
    },
    nextStep() {
      if (this.currentStepIndex < this.activeSteps.length - 1) {
        this.currentStepIndex++;
      }
    },
    prevStep() {
      if (this.currentStepIndex > 0) {
        this.currentStepIndex--;
      }
    },
    // Pré-cocher les garnitures par défaut
    initGarnitures() {
      if (this.item.extras) {
        this.item.extras.forEach(extra => {
          if (parseFloat(extra.convert_price) <= 0) {
            this.selections.garnitures[extra.id] = true;
          }
        });
      }
    },
    // Construire le payload pour l'API
    buildCartItem() {
      const sauceVariations = {};
      const sauceNames = {};
      if (this.selections.sauceOrder.length > 0) {
        const firstSauceId = this.selections.sauceOrder[0];
        // Trouver l'attribut sauce
        if (this.item.itemAttributes) {
          const sauceAttr = this.item.itemAttributes.find(a =>
            (a.name || '').toLowerCase().includes('sauce')
          );
          if (sauceAttr && this.item.variations && this.item.variations[sauceAttr.id]) {
            const variation = this.item.variations[sauceAttr.id].find(v => v.id === firstSauceId);
            if (variation) {
              sauceVariations[sauceAttr.id] = firstSauceId;
              sauceNames[sauceAttr.name] = variation.name;
            }
          }
        }
      }

      const selectedExtras = [];
      const extraNames = [];
      Object.keys(this.selections.garnitures).forEach(id => {
        if (this.selections.garnitures[id]) {
          selectedExtras.push(parseInt(id));
          const extra = this.item.extras?.find(e => e.id === parseInt(id));
          if (extra) extraNames.push(extra.name);
        }
      });
      Object.keys(this.selections.supplements).forEach(id => {
        if (this.selections.supplements[id]) {
          selectedExtras.push(parseInt(id));
          const extra = this.item.extras?.find(e => e.id === parseInt(id));
          if (extra) extraNames.push(extra.name);
        }
      });

      return {
        item_id: this.item.id,
        name: this.item.name,
        image: this.item.thumb,
        quantity: this.selections.quantity,
        convert_price: parseFloat(this.item.convert_price),
        currency_price: this.item.currency_price,
        discount: 0,
        item_variations: { variations: sauceVariations, names: sauceNames },
        item_extras: { extras: selectedExtras, names: extraNames },
        item_variation_total: 0,
        item_extra_total: 0,
        instruction: this.buildInstruction()
      };
    },
    buildInstruction() {
      const parts = [];
      // Pain
      if (this.selections.pain) {
        const painAttr = this.item.itemAttributes?.find(a => (a.name || '').toLowerCase().includes('pain'));
        if (painAttr && this.item.variations?.[painAttr.id]) {
          const painVar = this.item.variations[painAttr.id].find(v => v.id === this.selections.pain);
          if (painVar) parts.push('PAIN: ' + painVar.name);
        }
      }
      // Sauces supplémentaires
      if (this.selections.sauceOrder.length > 1) {
        const extraSauces = this.selections.sauceOrder.slice(1).map(id => {
          const sauceAttr = this.item.itemAttributes?.find(a => (a.name || '').toLowerCase().includes('sauce'));
          if (sauceAttr && this.item.variations?.[sauceAttr.id]) {
            const v = this.item.variations[sauceAttr.id].find(v => v.id === id);
            return v ? v.name : null;
          }
          return null;
        }).filter(Boolean);
        if (extraSauces.length > 0) parts.push('SAUCES SUPPL: ' + extraSauces.join(', '));
      }
      return parts.join('. ') || null;
    },
    addToCart() {
      const cartItem = this.buildCartItem();
      this.onAddToCart(cartItem);
      this.onClose();
    }
  },
  mounted() {
    this.initGarnitures();
  }
};
```

---

## ÉTAPE VIANDE — `KioskStepViande.vue`

```vue
<template>
  <div class="kiosk-step-viande">
    <h3>Choisissez {{ maxViandes }} viande{{ maxViandes > 1 ? 's' : '' }}</h3>
    <div class="kiosk-viande-counter">
      {{ totalSelected }} / {{ maxViandes }}
      <span v-if="totalSelected === maxViandes" class="kiosk-complete-badge">✅ Complet</span>
    </div>
    <div class="kiosk-viande-list">
      <div
        v-for="viande in viandeList"
        :key="viande.id"
        class="kiosk-viande-row"
        :class="{ active: (localSelections[viande.key] || 0) > 0 }"
      >
        <div class="kiosk-viande-info">
          <img v-if="viande.thumb" :src="viande.thumb" class="kiosk-viande-img" />
          <span class="kiosk-viande-emoji" v-else>{{ viande.emoji }}</span>
          <span class="kiosk-viande-name">{{ viande.name }}</span>
        </div>
        <div class="kiosk-viande-controls">
          <button
            @click="decrement(viande.key)"
            class="kiosk-qty-btn"
            :disabled="(localSelections[viande.key] || 0) === 0"
          >−</button>
          <span class="kiosk-qty-value">{{ localSelections[viande.key] || 0 }}</span>
          <button
            @click="increment(viande.key)"
            class="kiosk-qty-btn"
            :disabled="totalSelected >= maxViandes"
          >+</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'KioskStepViande',
  props: {
    step: Object,
    item: Object,
    selections: Object
  },
  emits: ['update'],
  data() {
    return {
      localSelections: { ...this.selections.viandes }
    };
  },
  computed: {
    maxViandes() {
      const name = (this.item.name || '').toLowerCase();
      if (name.includes('xxl') || name.includes('4 viande')) return 4;
      if (name.includes('xl') || name.includes('3 viande')) return 3;
      if (name.includes(' l ') || name.includes('2 viande')) return 2;
      return 1;
    },
    totalSelected() {
      return Object.values(this.localSelections).reduce((sum, v) => sum + (v || 0), 0);
    },
    // Lire les viandes depuis les variations DB (attribut "Viande")
    viandeList() {
      if (!this.item.itemAttributes) return [];
      const viandeAttr = this.item.itemAttributes.find(a =>
        (a.name || '').toLowerCase().includes('viande')
      );
      if (!viandeAttr || !this.item.variations?.[viandeAttr.id]) return [];
      return this.item.variations[viandeAttr.id].map(v => ({
        id: v.id,
        key: v.name.toLowerCase().replace(/\s+/g, '_'),
        name: v.name,
        thumb: v.thumb || null,
        emoji: '🥩'
      }));
    }
  },
  methods: {
    increment(key) {
      if (this.totalSelected < this.maxViandes) {
        this.localSelections[key] = (this.localSelections[key] || 0) + 1;
        this.$emit('update', 'viandes', { ...this.localSelections });
        this.$emit('update', 'totalViandes', this.totalSelected);
      }
    },
    decrement(key) {
      if ((this.localSelections[key] || 0) > 0) {
        this.localSelections[key]--;
        this.$emit('update', 'viandes', { ...this.localSelections });
        this.$emit('update', 'totalViandes', this.totalSelected);
      }
    }
  }
};
</script>
```

---

## CSS BORNE — Standards tactiles

**Fichier :** `public/css/kiosk-wizard.css` (nouveau fichier)

```css
/* === KIOSK WIZARD — Standards tactiles === */
/* Boutons minimum 64px pour les doigts */
.kiosk-btn-back,
.kiosk-btn-next,
.kiosk-qty-btn {
    min-height: 64px;
    min-width: 64px;
    font-size: 18px;
    border-radius: 16px;
    cursor: pointer;
    touch-action: manipulation;
}

.kiosk-btn-next {
    background: #E93C3C;
    color: white;
    padding: 0 48px;
    font-weight: 700;
}

.kiosk-btn-back {
    background: white;
    border: 2px solid #E93C3C;
    color: #E93C3C;
    padding: 0 32px;
}

/* Cartes de sélection */
.kiosk-option-card {
    min-height: 100px;
    border-radius: 16px;
    border: 3px solid #EFF0F6;
    background: white;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 16px;
    cursor: pointer;
    touch-action: manipulation;
    transition: all 0.2s;
}

.kiosk-option-card.selected {
    border-color: #E93C3C;
    background: #FFF0F0;
}

/* Images produits */
.kiosk-item-img {
    width: 80px;
    height: 80px;
    border-radius: 12px;
    object-fit: cover;
}

/* Barre de progression */
.kiosk-progress-bar {
    display: flex;
    gap: 8px;
    padding: 16px;
    overflow-x: auto;
}

.kiosk-step-dot {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 60px;
}

.kiosk-step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #EFF0F6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.kiosk-step-dot.active .kiosk-step-number {
    background: #E93C3C;
    color: white;
}

.kiosk-step-dot.done .kiosk-step-number {
    background: #43C6AC;
    color: white;
}

/* Total courant */
.kiosk-running-total {
    font-size: 24px;
    font-weight: 800;
    color: #E93C3C;
}

/* Viande row */
.kiosk-viande-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    border-radius: 12px;
    border: 2px solid #EFF0F6;
    margin-bottom: 8px;
    min-height: 72px;
}

.kiosk-viande-row.active {
    border-color: #E93C3C;
    background: #FFF5F5;
}
```

---

## TESTS OBLIGATOIRES Sprint 10

### Tests Vitest à créer

**Fichier :** `tests/js/KioskWizard.spec.js`

```javascript
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
// import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';

describe('KioskWizardComponent', () => {
    it('should show pain step for sandwich category', () => {
        // Test que l'étape Pain est présente pour wizard_template=sandwich
        expect(true).toBe(true); // placeholder — implémenter avec import réel
    });

    it('should pre-check all garnitures by default', () => {
        // Test que les garnitures sont pré-cochées au montage
        expect(true).toBe(true);
    });

    it('should calculate running total correctly', () => {
        // Test que le total courant inclut les sauces supplémentaires
        expect(true).toBe(true);
    });

    it('should not allow advancing viande step until maxViandes reached', () => {
        // Test que canAdvance = false si totalViandes < maxViandes
        expect(true).toBe(true);
    });
});
```

---

## VÉRIFICATIONS DE SYNCHRONISATION Sprint 10

1. `php artisan test` — 0 régression (le wizard borne est Vue-only, pas de PHP modifié)
2. Ouvrir la borne, sélectionner un Tacos → le wizard doit s'ouvrir avec l'étape Viande
3. Sélectionner un Sandwich → l'étape Pain doit être la première
4. Vérifier que les garnitures sont pré-cochées
5. Vérifier que le total courant se met à jour à chaque sélection
6. Passer une commande complète → vérifier en DB que `order_type = 25` (KIOSK)
7. Vérifier que le KDS reçoit la commande avec les bonnes informations

---

## RÉSUMÉ DES FICHIERS À CRÉER

| Fichier | Description |
|---------|-------------|
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | NOUVEAU — Wizard principal |
| `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` | NOUVEAU — Récapitulatif |
| `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` | NOUVEAU — Confirmation |
| `resources/js/components/frontend/kiosk/steps/KioskStepViande.vue` | NOUVEAU — Étape viandes |
| `resources/js/components/frontend/kiosk/steps/KioskStepSauce.vue` | NOUVEAU — Étape sauces |
| `resources/js/components/frontend/kiosk/steps/KioskStepGarnitures.vue` | NOUVEAU — Étape garnitures |
| `resources/js/components/frontend/kiosk/steps/KioskStepSupplements.vue` | NOUVEAU — Étape suppléments |
| `resources/js/components/frontend/kiosk/steps/KioskStepMenu.vue` | NOUVEAU — Étape menu combo |
| `resources/js/components/frontend/kiosk/steps/KioskStepPain.vue` | NOUVEAU — Étape pain |
| `public/css/kiosk-wizard.css` | NOUVEAU — Styles tactiles |
| `tests/js/KioskWizard.spec.js` | NOUVEAU — Tests |

**Ne pas toucher :** `pos-wizard.js`, `FrontendOrderService.php`, aucun fichier POS existant
