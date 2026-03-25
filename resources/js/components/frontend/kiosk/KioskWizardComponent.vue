<template>
  <div class="kiosk-wizard" v-if="item">
    <!-- Header : image item + nom + prix courant -->
    <div class="kiosk-wizard-header">
      <img :src="item.thumb" class="kiosk-item-img" v-if="item.thumb" />
      <div class="kiosk-item-info">
        <h2 class="kiosk-item-name">{{ item.name }}</h2>
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

<script>
import KioskStepPain from './steps/KioskStepPainComponent.vue';
import KioskStepViande from './steps/KioskStepViandeComponent.vue';
import KioskStepSauce from './steps/KioskStepSauceComponent.vue';
import KioskStepGarnitures from './steps/KioskStepGarnituresComponent.vue';
import KioskStepSupplements from './steps/KioskStepSupplementsComponent.vue';
import KioskStepMenu from './steps/KioskStepMenuComponent.vue';
import KioskOrderSummary from './KioskOrderSummaryComponent.vue';

export default {
  name: 'KioskWizardComponent',
  components: {
    KioskStepPain,
    KioskStepViande,
    KioskStepSauce,
    KioskStepGarnitures,
    KioskStepSupplements,
    KioskStepMenu,
    KioskOrderSummary
  },
  props: {
    item: { type: Object, default: null },
    onAddToCart: { type: Function, default: null },
    onClose: { type: Function, default: null },
    itemId: { type: [String, Number], default: null },
  },
  data() {
    return {
      currentStepIndex: 0,
      selections: {
        pain: null,
        _painMeta: null,    // { realId, attrId, name } — set by KioskStepPain when DB data available
        _boissonMeta: null, // { boissonId, boissonName } — set by KioskStepMenu
        _viandeMeta: [],    // [{ id, key, name, count }] — set by KioskStepViande
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
        case 'tacos':
          return [
            { type: 'viande', label: 'Viande(s)', component: 'KioskStepViande' },
            { type: 'sauce', label: 'Sauce', component: 'KioskStepSauce' },
            { type: 'garnitures', label: 'Garnitures', component: 'KioskStepGarnitures' },
            { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
            { type: 'menu', label: 'Menu', component: 'KioskStepMenu' },
            { type: 'recap', label: 'Récap', component: 'KioskOrderSummary' }
          ].filter(s => this.shouldShowStep(s.type));
        case 'sandwich':
          return [
            { type: 'pain', label: 'Pain', component: 'KioskStepPain' },
            ...(hasViandes ? [{ type: 'viande', label: 'Viande(s)', component: 'KioskStepViande' }] : []),
            { type: 'sauce', label: 'Sauce', component: 'KioskStepSauce' },
            { type: 'garnitures', label: 'Garnitures', component: 'KioskStepGarnitures' },
            { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
            { type: 'menu', label: 'Menu', component: 'KioskStepMenu' },
            { type: 'recap', label: 'Récap', component: 'KioskOrderSummary' }
          ].filter(s => this.shouldShowStep(s.type));
        case 'burger':
          return [
            ...(hasViandes ? [{ type: 'viande', label: 'Viande(s)', component: 'KioskStepViande' }] : []),
            { type: 'sauce', label: 'Sauce', component: 'KioskStepSauce' },
            { type: 'garnitures', label: 'Garnitures', component: 'KioskStepGarnitures' },
            { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
            { type: 'menu', label: 'Menu', component: 'KioskStepMenu' },
            { type: 'recap', label: 'Récap', component: 'KioskOrderSummary' }
          ].filter(s => this.shouldShowStep(s.type));
        case 'assiette':
          return [
            ...(hasViandes ? [{ type: 'viande', label: 'Viande(s)', component: 'KioskStepViande' }] : []),
            { type: 'sauce', label: 'Sauce', component: 'KioskStepSauce' },
            { type: 'garnitures', label: 'Garnitures', component: 'KioskStepGarnitures' },
            { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
            { type: 'recap', label: 'Récap', component: 'KioskOrderSummary' }
          ].filter(s => this.shouldShowStep(s.type));
        default:
          return [{ type: 'recap', label: 'Récap', component: 'KioskOrderSummary' }];
      }
    },
    currentStep() {
      return this.activeSteps[this.currentStepIndex];
    },
    currentStepComponent() {
      return this.currentStep?.component;
    },
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
        const menuAddon = this.item.addons.find(a => 
          (a.addon_item_name || '').toLowerCase().includes('menu')
        );
        if (menuAddon) total += parseFloat(menuAddon.addon_item_convert_price) || 0;
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
      if (name.includes('xxl') || name.includes('4 viande')) return 4;
      if (name.includes('xl') || name.includes('3 viande')) return 3;
      if (name.includes(' l ') || name.includes('2 viande')) return 2;
      return 1;
    },
    shouldShowStep(type) {
      if (type === 'supplements') {
        return this.item.extras && this.item.extras.some(e => parseFloat(e.convert_price || e.price || 0) > 0);
      }
      if (type === 'garnitures') {
        return this.item.extras && this.item.extras.some(e => parseFloat(e.convert_price || e.price || 0) === 0);
      }
      if (type === 'menu') {
        return this.item.has_menu && this.item.addons && this.item.addons.length > 0;
      }
      if (type === 'viande') {
        return this.detectViandeCount() > 0 && this.hasViandeVariations();
      }
      return true;
    },
    hasViandeVariations() {
      if (!this.item.itemAttributes) return false;
      return this.item.itemAttributes.some(a => 
        (a.name || '').toLowerCase().includes('viande')
      );
    },
    updateSelection(key, value, meta) {
      this.selections[key] = value;
      // Steps send meta for accurate catalog ID mapping when DB data is available
      if (key === 'pain' && meta) {
        this.selections._painMeta = meta;
      }
      if (key === 'boissonChoice' && meta) {
        this.selections._boissonMeta = meta;
      }
      if (key === '_viandeMeta') {
        this.selections._viandeMeta = value; // value is the meta array here
      }
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
    prevStep() {
      if (this.currentStepIndex > 0) {
        this.currentStepIndex--;
      }
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
    buildCartItem() {
      const allVariations = {};
      const allVariationNames = {};

      // Pain variation — only when real catalog ID exists (not fallback null)
      const painMeta = this.selections._painMeta;
      if (painMeta?.realId && painMeta?.attrId) {
        allVariations[painMeta.attrId] = painMeta.realId;
        allVariationNames['Pain'] = painMeta.name;
      }

      // Viande variation — first selected viande maps to item_variations when DB ID exists
      const viandeMeta = this.selections._viandeMeta || [];
      if (viandeMeta.length > 0 && this.item.itemAttributes) {
        const viandeAttr = this.item.itemAttributes.find(a =>
          (a.name || '').toLowerCase().includes('viande')
        );
        if (viandeAttr && this.item.variations?.[viandeAttr.id]) {
          const firstViande = viandeMeta[0];
          if (typeof firstViande.id === 'number') {
            allVariations[viandeAttr.id] = firstViande.id;
            allVariationNames[viandeAttr.name] = firstViande.name;
          }
        }
      }

      // Sauce variation (first selection only — extras are priced via sauceVariationSurcharge)
      if (this.selections.sauceOrder.length > 0) {
        const firstSauceKey = this.selections.sauceOrder[0];
        if (this.item.itemAttributes) {
          const sauceAttr = this.item.itemAttributes.find(a =>
            (a.name || '').toLowerCase().includes('sauce')
          );
          if (sauceAttr && this.item.variations?.[sauceAttr.id]) {
            // firstSauceKey may be an integer ID (DB-driven) or a name string (fallback)
            const variation = typeof firstSauceKey === 'number'
              ? this.item.variations[sauceAttr.id].find(v => v.id === firstSauceKey)
              : this.item.variations[sauceAttr.id].find(v => v.name === firstSauceKey);
            if (variation) {
              allVariations[sauceAttr.id] = variation.id;
              allVariationNames[sauceAttr.name] = variation.name;
            }
          }
        }
      }

      // Alias for readability
      const sauceVariations = allVariations;
      const sauceNames = allVariationNames;

      const selectedExtras = [];
      const extraNames = [];
      let itemExtraTotal = 0;

      Object.keys(this.selections.garnitures).forEach(id => {
        if (this.selections.garnitures[id]) {
          selectedExtras.push(parseInt(id));
          const extra = this.item.extras?.find(e => e.id === parseInt(id));
          if (extra) {
            extraNames.push(extra.name);
            // garnitures are free — no price add
          }
        }
      });

      Object.keys(this.selections.supplements).forEach(id => {
        if (this.selections.supplements[id]) {
          selectedExtras.push(parseInt(id));
          const extra = this.item.extras?.find(e => e.id === parseInt(id));
          if (extra) {
            extraNames.push(extra.name);
            itemExtraTotal += parseFloat(extra.convert_price || extra.price || 0);
          }
        }
      });

      // Extra sauces (beyond first): each adds 0.50€ as variation surcharge
      const extraSauceCount = Math.max(0, this.selections.sauceOrder.length - 1);
      const sauceVariationSurcharge = extraSauceCount * 0.50;

      // Menu addon price
      let menuAddonPrice = 0;
      if (this.selections.menuChoice === 'full' && this.item.addons) {
        const menuAddon = this.item.addons.find(a =>
          (a.addon_item_name || '').toLowerCase().includes('menu')
        );
        if (menuAddon) menuAddonPrice = parseFloat(menuAddon.addon_item_convert_price) || 0;
      }

      const itemVariationTotal = sauceVariationSurcharge + menuAddonPrice;

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
        item_variation_total: parseFloat(itemVariationTotal.toFixed(2)),
        item_extra_total: parseFloat(itemExtraTotal.toFixed(2)),
        instruction: this.buildInstruction()
      };
    },
    buildInstruction() {
      const parts = [];
      
      // Pain
      if (this.selections.pain) {
        const painAttr = this.item.itemAttributes?.find(a => 
          (a.name || '').toLowerCase().includes('pain')
        );
        if (painAttr && this.item.variations?.[painAttr.id]) {
          const painVar = this.item.variations[painAttr.id].find(v => v.id === this.selections.pain);
          if (painVar) parts.push('PAIN: ' + painVar.name);
        }
      }
      
      // Viandes
      if (this.selections.totalViandes > 0) {
        const viandes = Object.entries(this.selections.viandes)
          .filter(([_, count]) => count > 0)
          .map(([key, count]) => `${key} x${count}`);
        if (viandes.length > 0) parts.push('VIANDES: ' + viandes.join(', '));
      }
      
      // Sauces supplémentaires
      if (this.selections.sauceOrder.length > 1) {
        const extraSauces = this.selections.sauceOrder.slice(1).map(id => {
          const sauceAttr = this.item.itemAttributes?.find(a => 
            (a.name || '').toLowerCase().includes('sauce')
          );
          if (sauceAttr && this.item.variations?.[sauceAttr.id]) {
            const v = this.item.variations[sauceAttr.id].find(v => v.id === id);
            return v ? v.name : null;
          }
          return null;
        }).filter(Boolean);
        if (extraSauces.length > 0) parts.push('SAUCES SUPPL: ' + extraSauces.join(', '));
      }
      
      // Menu
      if (this.selections.menuChoice && this.selections.menuChoice !== 'none') {
        const choices = {
          full: 'Menu complet (frites + boisson)',
          frites: '+ Frites',
          boisson: '+ Boisson',
        };
        let menuLabel = choices[this.selections.menuChoice] || ('+ ' + this.selections.menuChoice);
        // Append specific drink name when known
        const boissonMeta = this.selections._boissonMeta;
        if (boissonMeta?.boissonName &&
            (this.selections.menuChoice === 'full' || this.selections.menuChoice === 'boisson')) {
          menuLabel += ` (${boissonMeta.boissonName})`;
        }
        parts.push('MENU: ' + menuLabel);
      }
      
      return parts.join('. ') || null;
    },
    addToCart() {
      const cartItem = this.buildCartItem();
      if (this.onAddToCart) {
        this.onAddToCart(cartItem);
        if (this.onClose) this.onClose();
      } else {
        this.$store.dispatch('kioskCart/addItem', cartItem);
        this.$router.go(-1);
      }
    }
  },
  mounted() {
    this.initGarnitures();
  },
};
</script>

<style scoped>
.kiosk-wizard {
  display: flex;
  flex-direction: column;
  height: 100vh;
  max-width: 800px;
  margin: 0 auto;
  background: #F8F9FA;
}

.kiosk-wizard-header {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px 24px;
  background: white;
  border-bottom: 2px solid #EFF0F6;
}

.kiosk-item-img {
  width: 80px;
  height: 80px;
  border-radius: 12px;
  object-fit: cover;
}

.kiosk-item-info {
  flex: 1;
}

.kiosk-item-name {
  font-size: 22px;
  font-weight: 700;
  color: #1a1a2e;
  margin: 0 0 4px 0;
}

.kiosk-running-total {
  font-size: 28px;
  font-weight: 800;
  color: #E93C3C;
  margin: 0;
}

.kiosk-progress-bar {
  display: flex;
  gap: 8px;
  padding: 16px 24px;
  background: white;
  overflow-x: auto;
  border-bottom: 2px solid #EFF0F6;
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
  font-size: 16px;
  color: #666;
}

.kiosk-step-dot.active .kiosk-step-number {
  background: #E93C3C;
  color: white;
}

.kiosk-step-dot.done .kiosk-step-number {
  background: #43C6AC;
  color: white;
}

.kiosk-step-label {
  font-size: 12px;
  margin-top: 4px;
  color: #666;
  white-space: nowrap;
}

.kiosk-step-dot.active .kiosk-step-label {
  color: #E93C3C;
  font-weight: 600;
}

.kiosk-step-content {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
}

.kiosk-nav {
  display: flex;
  gap: 16px;
  padding: 16px 24px;
  background: white;
  border-top: 2px solid #EFF0F6;
}

.kiosk-btn-back,
.kiosk-btn-next {
  min-height: 64px;
  min-width: 64px;
  font-size: 18px;
  border-radius: 16px;
  cursor: pointer;
  touch-action: manipulation;
  border: none;
  font-weight: 600;
  transition: all 0.2s ease;
}

.kiosk-btn-back {
  background: white;
  border: 2px solid #E93C3C;
  color: #E93C3C;
  padding: 0 32px;
}

.kiosk-btn-back:active {
  background: #FFF0F0;
}

.kiosk-btn-next {
  background: #E93C3C;
  color: white;
  padding: 0 48px;
  flex: 1;
}

.kiosk-btn-next:disabled {
  background: #ddd;
  color: #999;
  cursor: not-allowed;
}

.kiosk-btn-next:not(:disabled):active {
  background: #d43535;
  transform: scale(0.98);
}
</style>
