<template>
  <div class="kiosk-wizard">
    <!-- Chargement item depuis route (mode edit) -->
    <div v-if="fetchLoading" class="kiosk-wizard-loading">
      <div class="kiosk-wizard-spinner"></div>
      <p>{{ $t('kiosk.wizard.loading_product') }}</p>
    </div>

    <!-- Erreur chargement -->
    <div v-else-if="fetchError && !resolvedItem" class="kiosk-wizard-error">
      <p>{{ fetchError }}</p>
      <button @click="$router.go(-1)" class="kiosk-btn-back">{{ $t('kiosk.back') }}</button>
    </div>

    <!-- Wizard complet -->
    <template v-else-if="resolvedItem">
      <div class="kiosk-wizard-header">
        <div class="kiosk-item-info">
          <h2 class="kiosk-item-name">{{ sanitizeItemName(resolvedItem.name) }}</h2>
        </div>
        <button
          type="button"
          class="kiosk-wizard-close"
          :aria-label="$t('kiosk.wizard.close_aria')"
          @click="onAbandonClick"
        >
          ×
        </button>
      </div>

      <div class="kiosk-step-visuals">
        <div
          v-for="(step, index) in activeSteps"
          :key="step.type"
          class="kiosk-step-visual"
          :class="{ active: index === currentStepIndex, done: index < currentStepIndex }"
        >
          <div class="kiosk-step-visual-icon">
            <img
              v-if="getStepVisualImage(step.type)"
              :src="getStepVisualImage(step.type)"
              :alt="getStepLabel(step.type)"
              class="kiosk-step-visual-img"
            />
            <span v-else class="kiosk-step-visual-fallback">{{ getStepIcon(step.type) }}</span>
            <span class="kiosk-step-visual-index">{{ index + 1 }}</span>
          </div>
          <span class="kiosk-step-visual-label">{{ getStepLabel(step.type) }}</span>
        </div>
      </div>

      <div class="kiosk-progress-bar">
        <button
          class="kiosk-progress-arrow"
          @click="prevStep"
          :disabled="currentStepIndex === 0"
        >
          ‹
        </button>
        <div class="kiosk-progress-track">
          <div
            v-for="(step, index) in activeSteps"
            :key="step.type"
            class="kiosk-step-dot"
            :class="{ active: index === currentStepIndex, done: index < currentStepIndex }"
          >
            <span class="kiosk-step-number">{{ index + 1 }}</span>
          </div>
        </div>
        <button
          class="kiosk-progress-arrow"
          @click="nextStep"
          :disabled="currentStepIndex >= activeSteps.length - 1 || !canAdvance"
        >
          ›
        </button>
      </div>

      <div class="kiosk-step-question">
        {{ currentStep.type === 'recap' ? $t('kiosk.wizard.recap_order_title') : getQuestionLabel(currentStep.type) }}
      </div>

      <div class="kiosk-step-content">
        <transition name="step-slide" mode="out-in">
          <component
            :is="currentStepComponent"
            :key="currentStep.type"
            :step="currentStep"
            :item="resolvedItem"
            :selections="selections"
            @update="updateSelection"
          />
        </transition>
      </div>

      <div class="kiosk-nav">
        <div class="kiosk-nav-actions">
          <button type="button" class="kiosk-btn-abandon" @click="onAbandonClick">
            {{ $t('kiosk.wizard.abandon_item') }}
          </button>
          <button
            class="kiosk-btn-back"
            @click="prevStep"
            :disabled="currentStepIndex === 0"
          >
            {{ $t('kiosk.wizard.nav_previous') }}
          </button>
          <button
            @click="currentStepIndex < activeSteps.length - 1 ? nextStep() : addToCart()"
            class="kiosk-btn-next"
            :disabled="!canAdvance"
          >
            <span>{{
              currentStepIndex < activeSteps.length - 1
                ? $t('kiosk.wizard.nav_next')
                : $t('kiosk.wizard.add_to_cart')
            }}</span>
          </button>
        </div>
        <div class="kiosk-nav-total">{{ $t('kiosk.total') }} {{ formatPrice(runningTotal) }}</div>
      </div>

      <!-- P2 : confirmation avant abandon (évite erreur tactile) -->
      <transition name="fade">
        <div
          v-if="showAbandonConfirm"
          class="kiosk-wizard-abandon-overlay"
          role="presentation"
          @click.self="onAbandonCancel"
        >
          <div
            class="kiosk-wizard-abandon-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="kiosk-wizard-abandon-title"
          >
            <h2 id="kiosk-wizard-abandon-title" class="kiosk-wizard-abandon-title">
              {{ $t('kiosk.wizard.abandon_title') }}
            </h2>
            <p class="kiosk-wizard-abandon-sub">
              {{ $t('kiosk.wizard.abandon_sub') }}
            </p>
            <div class="kiosk-wizard-abandon-actions">
              <button type="button" class="kiosk-wizard-abandon-yes" @click="onAbandonConfirm">
                {{ $t('kiosk.wizard.abandon_yes') }}
              </button>
              <button type="button" class="kiosk-wizard-abandon-no" @click="onAbandonCancel">
                {{ $t('kiosk.wizard.abandon_continue') }}
              </button>
            </div>
          </div>
        </div>
      </transition>
    </template>
  </div>
</template>

<script>
import KioskStepPain from './steps/KioskStepPainComponent.vue';
import KioskStepTaille from './steps/KioskStepTailleComponent.vue';
import KioskStepViande from './steps/KioskStepViandeComponent.vue';
import KioskStepSauce from './steps/KioskStepSauceComponent.vue';
import KioskStepGarnitures from './steps/KioskStepGarnituresComponent.vue';
import KioskStepSupplements from './steps/KioskStepSupplementsComponent.vue';
import KioskStepMenu from './steps/KioskStepMenuComponent.vue';
import KioskOrderSummary from './KioskOrderSummaryComponent.vue';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { kioskResolveImageSrc, kioskVariationsForAttribute } from '../../../helpers/kioskMedia';
import { kioskDrinkAddonRowsFromItem } from '../../../helpers/kioskDrinkAddons';
import { sanitizeKioskCustomerFacingText } from '../../../helpers/kioskDisplayText';
import { calculateKioskRunningTotal, getKioskMenuAddonPrice } from '../../../helpers/kioskPricing';

export default {
  name: 'KioskWizardComponent',
  mixins: [kioskPriceMixin],
  components: {
    KioskStepPain,
    KioskStepTaille,
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
      showAbandonConfirm: false,
      currentStepIndex: 0,
      fetchedItem: null,
      fetchLoading: false,
      fetchError: null,
      selections: {
        pain: null,
        taille: null,          // [AUDIT-P2] Explicit S/M/L/XL choice — replaces name heuristic
        _painMeta: null,       // { realId, attrId, name } — set by KioskStepPain when DB data available
        _tailleMeta: null,     // { viandeCount, label, attrId, realId } — set by KioskStepTaille
        _boissonMeta: null,    // { boissonId, boissonName } — set by KioskStepMenu
        _viandeMeta: [],       // [{ id, key, name, count }] — set by KioskStepViande
        _fritesSauceMeta: null,// { fritesSauceName } — set by KioskStepMenu
        viandes: {},
        totalViandes: 0,
        sauces: {},
        sauceOrder: [],
        garnitures: {},
        supplements: {},
        menuChoice: null,
        boissonChoice: null,
        fritesSauce: null,
        fritesSauceOrder: [],
        quantity: 1,
        instruction: ''
      }
    };
  },
  computed: {
    resolvedItem() {
      return this.item || this.fetchedItem;
    },
    activeSteps() {
      if (!this.resolvedItem) return [];
      const template = this.effectiveWizardTemplate();
      const hasViandes = this.detectViandeCount() > 0;
      
      switch (template) {
        case 'tacos':
          return [
            ...(this.shouldAskTacosTaille() ? [{ type: 'taille', label: 'Taille', component: 'KioskStepTaille' }] : []),
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
        case 'snacking':
          // Chicken, tenders, nuggets — sauce + suppléments si configurés, puis récap
          return [
            { type: 'sauce', label: 'Sauce', component: 'KioskStepSauce' },
            { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
            { type: 'recap', label: 'Récap', component: 'KioskOrderSummary' }
          ].filter(s => this.shouldShowStep(s.type));
        case 'omelette':
          // Omelettes — garnitures (ingrédients) + suppléments si configurés
          return [
            { type: 'garnitures', label: 'Garnitures', component: 'KioskStepGarnitures' },
            { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
            { type: 'recap', label: 'Récap', component: 'KioskOrderSummary' }
          ].filter(s => this.shouldShowStep(s.type));
        case 'salade':
          // Salades — garnitures (ingrédients) + sauce + suppléments si configurés
          return [
            { type: 'garnitures', label: 'Garnitures', component: 'KioskStepGarnitures' },
            { type: 'sauce', label: 'Sauce', component: 'KioskStepSauce' },
            { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
            { type: 'recap', label: 'Récap', component: 'KioskOrderSummary' }
          ].filter(s => this.shouldShowStep(s.type));
        default:
          // simple + tout template non reconnu : afficher suppléments si présents, sinon recap direct
          return [
            { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
            { type: 'recap', label: 'Récap', component: 'KioskOrderSummary' }
          ].filter(s => this.shouldShowStep(s.type));
      }
    },
    currentStep() {
      return this.activeSteps[this.currentStepIndex];
    },
    currentStepComponent() {
      return this.currentStep?.component;
    },
    runningTotal() {
      return calculateKioskRunningTotal(this.resolvedItem, this.selections);
    },
    canAdvance() {
      const step = this.currentStep;
      if (!step) return false;
      
      if (step.type === 'viande') return this.selections.totalViandes >= this.detectViandeCount();
      if (step.type === 'sauce') return this.selections.sauceOrder.length > 0;
      if (step.type === 'pain') return this.selections.pain !== null;
      // [AUDIT-P2] Taille step requires an explicit choice before proceeding
      if (step.type === 'taille') return this.selections.taille !== null;
      // [P0] Menu : choix explicite obligatoire (full / frites / boisson / none) — pas de passage « vide »
      if (step.type === 'menu') {
        const mc = this.selections.menuChoice;
        if (mc === null || mc === undefined || mc === '') return false;
        // [P1] Si des addons boisson existent et que la formule inclut une boisson → choix obligatoire
        const wantsDrink = mc === 'full' || mc === 'boisson';
        const drinkRows = kioskDrinkAddonRowsFromItem(this.resolvedItem);
        if (wantsDrink && drinkRows.length > 0) {
          const bc = this.selections.boissonChoice;
          if (bc === null || bc === undefined || bc === '') return false;
        }
        return true;
      }

      return true;
    }
  },
  methods: {
    /**
     * Catégories en base ont souvent wizard_template = 'simple' (défaut migration).
     * Comme le POS : ne pas traiter « simple » comme un verrou — appliquer l’heuristique nom/catégorie.
     * Les valeurs explicites (tacos, burger, …) restent prioritaires.
     */
    effectiveWizardTemplate() {
      const item = this.resolvedItem;
      if (!item) return 'simple';
      // Priority 1: item-level wizard_template (from API, derived from category)
      const raw = item.wizard_template;
      if (raw && raw !== 'simple') return raw;
      // Priority 2: nested category object (present on ItemResource, absent on NormalItemResource)
      const catTemplate = item.category?.wizard_template;
      if (catTemplate && catTemplate !== 'simple') return catTemplate;
      // Priority 3: name/category heuristic fallback
      return this.detectTemplateFromName();
    },
    detectTemplateFromName() {
      const item = this.resolvedItem;
      if (!item) return 'simple';
      const name = (item.name || '').toLowerCase();
      const category = (item.category_name || '').toLowerCase();
      
      if (name.includes('tacos') || category.includes('tacos')) return 'tacos';
      if (name.includes('sandwich') || category.includes('sandwich')) return 'sandwich';
      if (name.includes('burger') || category.includes('burger')) return 'burger';
      if (name.includes('assiette') || category.includes('assiette')) return 'assiette';
      // [P5] Alignement heuristique ↔ wizard_template catalogue (snacking / omelette / salade)
      if (name.includes('omelette') || name.includes('omelet') || category.includes('omelette')) return 'omelette';
      if (name.includes('salade') || category.includes('salade')) return 'salade';
      if (
        name.includes('nugget') ||
        name.includes('tenders') ||
        name.includes('tender') ||
        name.includes('goujon') ||
        name.includes('goujons') ||
        name.includes('crousti') ||
        name.includes('strip') ||
        category.includes('snack')
      ) {
        return 'snacking';
      }

      return 'simple';
    },
    detectViandeCount() {
      // [AUDIT-P2] Prefer explicit taille selection over name heuristic.
      // When the customer has chosen a size on the taille step, use that count.
      // The name heuristic is kept as a fallback for templates that don't have a taille step.
      if (this.selections._tailleMeta?.viandeCount) {
        return this.selections._tailleMeta.viandeCount;
      }
      const item = this.resolvedItem;
      if (!item) return 1;
      const name = (item.name || '').toLowerCase();
      if (/\b4\s*viandes?\b/i.test(name) || /\bxxl\b/i.test(name)) return 4;
      if (/\b3\s*viandes?\b/i.test(name) || /\bxl\b/i.test(name)) return 3;
      if (/\b2\s*viandes?\b/i.test(name) || /\bl\b/i.test(name)) return 2;
      if (/\b1\s*viandes?\b/i.test(name)) return 1;
      return 1;
    },
    shouldShowStep(type) {
      const item = this.resolvedItem;
      if (!item) return false;
      if (type === 'supplements') {
        return item.extras && item.extras.some(e => {
          const price = parseFloat(e.convert_price || e.price || 0);
          const groupLabel = (e.group_label || '').toLowerCase();
          const name = (e.name || '').toLowerCase();
          const isSauce = (groupLabel !== '' ? groupLabel === 'sauce' : name.includes('sauce'));
          return price > 0 && !isSauce;
        });
      }
      if (type === 'garnitures') {
        return item.extras && item.extras.some(e => parseFloat(e.convert_price || e.price || 0) === 0);
      }
      if (type === 'sauce') {
        if (!item?.itemAttributes) return false;
        return item.itemAttributes.some(a =>
          (a.name || '').toLowerCase().includes('sauce')
        );
      }
      if (type === 'menu') {
        return item.has_menu && item.addons && item.addons.length > 0;
      }
      if (type === 'viande') {
        return this.detectViandeCount() > 0 && this.hasViandeVariations();
      }
      // Only show tacos size step when the selected product is generic and
      // does not already encode M/L/XL/XXL or number of meats in its own name.
      if (type === 'taille') return this.shouldAskTacosTaille();
      return true;
    },
    shouldAskTacosTaille() {
      const item = this.resolvedItem;
      if (!item) return false;
      const template = this.effectiveWizardTemplate();
      if (template !== 'tacos') return false;
      const lower = `${item.name || ''} ${item.description || ''}`.toLowerCase();
      const hasPresetSize =
        lower.includes('1 viande') ||
        lower.includes('2 viande') ||
        lower.includes('3 viande') ||
        lower.includes('4 viande') ||
        lower.includes('xxl') ||
        lower.includes('xl') ||
        lower.includes('tacos l') ||
        lower.includes('tacos m');
      return !hasPresetSize;
    },
    inferTacosPresetMeta() {
      const item = this.resolvedItem;
      if (!item || this.shouldAskTacosTaille()) return null;
      const viandeCount = this.detectViandeCount();
      const lower = (item.name || '').toLowerCase();
      let label = `${viandeCount} viande${viandeCount > 1 ? 's' : ''}`;
      if (lower.includes('xxl')) label = 'XXL';
      else if (lower.includes('xl')) label = 'XL';
      else if (lower.includes('tacos l')) label = 'L';
      else if (lower.includes('tacos m')) label = 'M';
      return {
        viandeCount,
        label,
        attrId: null,
        realId: null,
      };
    },
    hasViandeVariations() {
      const item = this.resolvedItem;
      if (!item) return false;
      const hasViandeAttr = item.itemAttributes?.some(a =>
        (a.name || '').toLowerCase().includes('viande')
      );
      if (hasViandeAttr) return true;
      const hasViandeExtra = item.extras?.some(e =>
        ((e.group_label || '').toLowerCase().includes('viande') ||
         (e.name || '').toLowerCase().includes('viande')) &&
        parseFloat(e.convert_price || e.price || 0) > 0
      );
      return !!hasViandeExtra;
    },
    updateSelection(key, value, meta) {
      this.selections[key] = value;
      // Steps send meta for accurate catalog ID mapping when DB data is available
      if (key === 'pain' && meta) {
        this.selections._painMeta = meta;
      }
      // [AUDIT-P2] Store taille meta so detectViandeCount() can use the explicit choice
      if (key === 'taille' && meta) {
        this.selections._tailleMeta = meta;
        // Reset viande selections when taille changes — the new count may differ
        this.selections.viandes = {};
        this.selections.totalViandes = 0;
        this.selections._viandeMeta = [];
      }
      if (key === 'boissonChoice') {
        if (meta) {
          this.selections._boissonMeta = meta;
        } else if (value === null || value === undefined || value === '') {
          this.selections._boissonMeta = null;
        }
      }
      if (key === 'fritesSauceOrder') {
        this.selections.fritesSauceOrder = Array.isArray(value) ? [...value] : [];
        this.selections.fritesSauce = this.selections.fritesSauceOrder[0] ?? null;
      }
      if (key === 'fritesSauce' && meta) {
        this.selections._fritesSauceMeta = meta;
      }
      if (key === '_viandeMeta') {
        this.selections._viandeMeta = value; // value is the meta array here
      }
    },
    kioskNormalizeItemAttributes(attrs) {
      if (attrs == null) return [];
      if (Array.isArray(attrs)) return attrs;
      return Object.values(attrs);
    },
    kioskIsSauceLikeAttributeName(name) {
      const n = (name || '').toLowerCase();
      return (
        n.includes('sauce') ||
        n.includes('condiment') ||
        n.includes('dressing') ||
        n.includes('dip')
      );
    },
    kioskSauceAttribute(item) {
      const attrs = this.kioskNormalizeItemAttributes(item?.itemAttributes);
      return attrs.find(a => this.kioskIsSauceLikeAttributeName(a.name)) || null;
    },
    kioskSauceVariationsList(item) {
      const sauceAttr = this.kioskSauceAttribute(item);
      if (!sauceAttr) return null;
      const raw = item.variations?.[String(sauceAttr.id)] ?? item.variations?.[sauceAttr.id];
      if (raw == null) return null;
      if (Array.isArray(raw)) return raw;
      if (typeof raw === 'object') return Object.values(raw);
      return null;
    },
    kioskExtraSauceUnitPrice(item) {
      const vars = this.kioskSauceVariationsList(item);
      let unit = 0.50;
      if (vars) {
        const priced = vars.find(v => parseFloat(v.convert_price || v.price || 0) > 0);
        if (priced) unit = parseFloat(priced.convert_price || priced.price || 0.50);
      }
      return unit;
    },
    kioskFindSauceVariation(item, key) {
      const vars = this.kioskSauceVariationsList(item);
      if (!vars) return null;
      const byId = vars.find(v => String(v.id) === String(key));
      if (byId) return byId;
      return vars.find(v => v.name === key) || null;
    },
    kioskFritesSauceDisplayName(key) {
      const k = `kiosk.wizard.frites_sauce.${key}`;
      const t = this.$t(k);
      return t !== k ? t : key;
    },
    sanitizeItemName(name) {
      return sanitizeKioskCustomerFacingText(name || '');
    },
    // formatPrice() provided by kioskPriceMixin
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
    getStepIcon(type) {
      const map = {
        pain: '🥖',
        taille: '📏',
        viande: '🥩',
        sauce: '🥫',
        garnitures: '🥗',
        supplements: '🧀',
        menu: '🍟',
        recap: '✓',
      };
      return map[type] || '•';
    },
    getStepVisualImage(type) {
      const item = this.resolvedItem;
      if (!item) return null;

      if (type === 'recap') {
        return kioskResolveImageSrc(item);
      }

      if (type === 'pain') {
        const painAttr = item.itemAttributes?.find(a =>
          (a.name || '').toLowerCase().includes('pain') ||
          (a.name || '').toLowerCase().includes('galette')
        );
        const list = painAttr ? kioskVariationsForAttribute(item, painAttr.id) : null;
        const variation = list?.[0];
        return kioskResolveImageSrc(variation);
      }

      if (type === 'taille') {
        return kioskResolveImageSrc(item);
      }

      if (type === 'viande') {
        const viandeAttr = item.itemAttributes?.find(a =>
          (a.name || '').toLowerCase().includes('viande')
        );
        const list = viandeAttr ? kioskVariationsForAttribute(item, viandeAttr.id) : null;
        const variation = list?.find(v => kioskResolveImageSrc(v)) || list?.[0];
        return kioskResolveImageSrc(variation);
      }

      if (type === 'sauce') {
        const sauceAttr = item.itemAttributes?.find(a =>
          (a.name || '').toLowerCase().includes('sauce')
        );
        const list = sauceAttr ? kioskVariationsForAttribute(item, sauceAttr.id) : null;
        const variation = list?.find(v => kioskResolveImageSrc(v)) || list?.[0];
        return kioskResolveImageSrc(variation);
      }

      if (type === 'garnitures') {
        const garniture = item.extras?.find(e =>
          parseFloat(e.convert_price || e.price || 0) === 0 && kioskResolveImageSrc(e)
        );
        return kioskResolveImageSrc(garniture, item);
      }

      if (type === 'supplements') {
        const supplement = item.extras?.find(e =>
          parseFloat(e.convert_price || e.price || 0) > 0 && kioskResolveImageSrc(e)
        );
        return kioskResolveImageSrc(supplement, item);
      }

      if (type === 'menu') {
        const addon = item.addons?.find(a => kioskResolveImageSrc(a));
        return kioskResolveImageSrc(addon, item);
      }

      return null;
    },
    getStepLabel(type) {
      if (type === 'recap') return this.$t('kiosk.wizard.recap_strip') || 'RÉCAP';
      const k = `kiosk.wizard.prompt.${type}`;
      const t = this.$t(k);
      // Locale-agnostic fallback if a translation key is missing
      if (t !== k) return t;
      const fallbacks = {
        pain: 'BREAD',
        taille: 'SIZE',
        viande: 'MEAT',
        sauce: 'SAUCE',
        garnitures: 'TOPPINGS',
        supplements: 'EXTRAS',
        menu: 'MENU',
        recap: 'RECAP'
      };
      return fallbacks[type] || type;
    },
    getQuestionLabel(type) {
      const k = `kiosk.wizard.prompt.${type}`;
      const t = this.$t(k);
      // Locale-agnostic fallback if a translation key is missing
      if (t !== k) return t;
      const fallbacks = {
        pain: 'CHOOSE BREAD?',
        taille: 'CHOOSE SIZE?',
        viande: 'CHOOSE MEAT?',
        sauce: 'CHOOSE SAUCE?',
        garnitures: 'CHOOSE TOPPINGS?',
        supplements: 'CHOOSE EXTRA?',
        menu: 'CHOOSE MENU?',
        recap: 'SUMMARY'
      };
      return fallbacks[type] || type;
    },
    onAbandonClick() {
      this.showAbandonConfirm = true;
    },
    onAbandonCancel() {
      this.showAbandonConfirm = false;
    },
    onAbandonConfirm() {
      this.showAbandonConfirm = false;
      this.performCloseWizard();
    },
    performCloseWizard() {
      if (this.onClose) {
        this.onClose();
        return;
      }
      this.$router.go(-1);
    },
    initGarnitures() {
      const item = this.resolvedItem;
      if (item?.extras) {
        const garnitures = {};
        item.extras.forEach(extra => {
          if (parseFloat(extra.convert_price || extra.price || 0) === 0) {
            garnitures[extra.id] = true;
          }
        });
        this.selections.garnitures = garnitures;
      }
    },
    resetSelections() {
      this.currentStepIndex = 0;
      this.selections = {
        pain: null,
        taille: null,
        _painMeta: null,
        _tailleMeta: null,
        _boissonMeta: null,
        _viandeMeta: [],
        _fritesSauceMeta: null,
        viandes: {},
        totalViandes: 0,
        sauces: {},
        sauceOrder: [],
        garnitures: {},
        supplements: {},
        menuChoice: null,
        boissonChoice: null,
        fritesSauce: null,
        fritesSauceOrder: [],
        quantity: 1,
        instruction: ''
      };
    },
    async fetchItemById(id) {
      if (!id) return;
      this.resetSelections();
      this.fetchLoading = true;
      this.fetchError = null;
      try {
        // Pass surface=kiosk so NormalItemResource filters extras/variations for kiosk only
        const res = await this.$store.dispatch('frontendItem/details', { id, surface: 'kiosk' });
        this.fetchedItem = res?.data?.data || res?.data || null;
        if (this.fetchedItem) {
          this.initGarnitures();
          const inferredTaille = this.inferTacosPresetMeta();
          if (inferredTaille) {
            this.selections._tailleMeta = inferredTaille;
            this.selections.taille = inferredTaille.label;
          } else if (!this.selections._tailleMeta?.viandeCount) {
            // Non-tacos or generic tacos needing taille step: pre-seed viandeCount
            // so the viande step component has a single source of truth.
            const count = this.detectViandeCount();
            this.selections._tailleMeta = {
              ...(this.selections._tailleMeta || {}),
              viandeCount: count,
            };
          }
        } else {
          this.fetchError = this.$t('kiosk.wizard.product_not_found');
        }
      } catch (_) {
        this.fetchError = this.$t('kiosk.wizard.product_load_error');
      } finally {
        this.fetchLoading = false;
      }
    },
    buildCartItem() {
      const item = this.resolvedItem;
      if (!item) return null;
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

      // Sauce variation (first selection only — extras are priced via sauceVariationSurcharge)
      if (this.selections.sauceOrder.length > 0) {
        const firstSauceKey = this.selections.sauceOrder[0];
        const sauceAttr = this.kioskSauceAttribute(item);
        const variation = sauceAttr ? this.kioskFindSauceVariation(item, firstSauceKey) : null;
        if (sauceAttr && variation) {
          allVariations[sauceAttr.id] = variation.id;
          allVariationNames[sauceAttr.name] = variation.name;
        }
      }

      // Build normalized item_variations array directly in server format:
      // [{ id: varId, variation_name: attrLabel, name: chosenValueName }]
      // This avoids the fragile index-based reconstruction in kioskCart.submitOrder.
      const normalizedVariations = Object.entries(allVariations)
        .filter(([, varId]) => varId)
        .map(([attrId, varId]) => {
          // attrId is the attribute id (key in allVariations); find the matching label
          // allVariationNames is keyed by attribute name (e.g. 'Pain', 'Viande', 'Sauce')
          // We stored attrId → varId and attrName → chosenName in parallel, so we look up
          // the attribute name from itemAttributes to get the label.
          const attr = item.itemAttributes?.find(a => String(a.id) === String(attrId));
          const variationName = attr?.name || Object.keys(allVariationNames).find(k =>
            allVariationNames[k] !== undefined
          ) || '';
          const name = allVariationNames[variationName] || allVariationNames[attr?.name] || '';
          return { id: parseInt(varId), variation_name: variationName, name };
        });

      // Build normalized item_extras array directly in server format:
      // [{ id: extraId, name: extraName }]
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
          if (extra) {
            itemExtraTotal += parseFloat(extra.convert_price || extra.price || 0);
          }
        }
      });

      // Sauce extra price — read from DB variations, fallback 0.50€
      const extraSauceCount = Math.max(0, this.selections.sauceOrder.length - 1);
      const sauceExtraPrice = this.kioskExtraSauceUnitPrice(item);
      const sauceVariationSurcharge = extraSauceCount * sauceExtraPrice;

      const fryPaid = (this.selections.fritesSauceOrder || []).filter(k => k && k !== 'sans');
      const extraFritesSauceCount = Math.max(0, fryPaid.length - 1);
      const fritesSauceSurcharge = extraFritesSauceCount * sauceExtraPrice;

      // Menu addon price — handle all choices (full / frites / boisson)
      const menuAddonPrice = getKioskMenuAddonPrice(item, this.selections.menuChoice);

      const itemVariationTotal = sauceVariationSurcharge + fritesSauceSurcharge + menuAddonPrice;

      const basePrice  = parseFloat(item.convert_price) || 0;
      const qty        = this.selections.quantity || 1;
      const lineTotal  = parseFloat(((basePrice + itemVariationTotal + itemExtraTotal) * qty).toFixed(2));

      return {
        item_id: item.id,
        item_category_id: item.item_category_id ?? null,
        name: item.name,
        image: item.thumb,
        quantity: qty,
        convert_price: basePrice,
        currency_price: item.currency_price,
        discount: 0,
        // Server-ready format: arrays of { id, variation_name, name } / { id, name }
        // No further normalization needed in kioskCart.submitOrder.
        item_variations: normalizedVariations,
        item_extras: normalizedExtras,
        item_variation_total: parseFloat(itemVariationTotal.toFixed(2)),
        item_extra_total: parseFloat(itemExtraTotal.toFixed(2)),
        // [KIOSK-17] Pre-computed line total so KioskCartComponent and KioskConfirmationComponent
        // can display it directly without inline recalculation.
        total: lineTotal,
        instruction: this.buildInstruction()
      };
    },
    buildInstruction() {
      const item = this.resolvedItem;
      const parts = [];
      const ti = (key, values) => this.$t(`kiosk.wizard.instruction.${key}`, values);

      if (this.selections._tailleMeta?.label) {
        parts.push(ti('taille', { label: this.selections._tailleMeta.label }));
      }

      if (this.selections.pain && item) {
        const painAttr = item.itemAttributes?.find(a =>
          (a.name || '').toLowerCase().includes('pain')
        );
        if (painAttr && item.variations?.[painAttr.id]) {
          const painVar = item.variations[painAttr.id].find(v => v.id === this.selections.pain);
          if (painVar) parts.push(ti('pain', { name: painVar.name }));
        }
      }

      if (this.selections.totalViandes > 0) {
        const meta = this.selections._viandeMeta || [];
        if (meta.length > 0) {
          const viandes = meta
            .filter((m) => (m.count || 0) > 0)
            .map((m) => `${m.name} ×${m.count}`);
          if (viandes.length > 0) parts.push(ti('viandes', { list: viandes.join(', ') }));
        } else {
          const viandes = Object.entries(this.selections.viandes)
            .filter(([_, count]) => count > 0)
            .map(([key, count]) => `${key} ×${count}`);
          if (viandes.length > 0) parts.push(ti('viandes', { list: viandes.join(', ') }));
        }
      }

      if (this.selections.sauceOrder.length > 1 && item) {
        const extraSauces = this.selections.sauceOrder.slice(1).map(id => {
          const v = this.kioskFindSauceVariation(item, id);
          return v ? v.name : null;
        }).filter(Boolean);
        if (extraSauces.length > 0) parts.push(ti('sauces_extra', { list: extraSauces.join(', ') }));
      }

      const mc = this.selections.menuChoice;
      if (mc && mc !== 'none') {
        const s = 'kiosk.wizard.summary';
        const menuBase = {
          full: this.$t(`${s}.menu_label_full`),
          frites: this.$t(`${s}.menu_label_frites`),
          boisson: this.$t(`${s}.menu_label_boisson`),
        }[mc] || mc;
        let menuDetail = menuBase;
        const boissonMeta = this.selections._boissonMeta;
        if (boissonMeta?.boissonName && (mc === 'full' || mc === 'boisson')) {
          menuDetail += ` (${boissonMeta.boissonName})`;
        }
        parts.push(ti('menu', { detail: menuDetail }));
      }

      const hasFrites = mc === 'full' || mc === 'frites';
      const fryOrder = (this.selections.fritesSauceOrder || []).filter(k => k && k !== 'sans');
      if (hasFrites && fryOrder.length > 0) {
        const labels = fryOrder.map(k => this.kioskFritesSauceDisplayName(k));
        parts.push(ti('frites_sauce', { list: labels.join(', ') }));
      }

      const joined = parts.join('. ');
      if (!joined) return null;
      return sanitizeKioskCustomerFacingText(joined);
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
    if (this.item) {
      this.resetSelections();
      this.initGarnitures();
      const inferredTaille = this.inferTacosPresetMeta();
      if (inferredTaille) {
        this.selections._tailleMeta = inferredTaille;
        this.selections.taille = inferredTaille.label;
      }
    } else if (this.itemId) {
      this.fetchItemById(this.itemId);
    }
  },
};
</script>

<style scoped>
.kiosk-wizard {
  display: flex;
  flex-direction: column;
  height: 100vh;
  width: 100vw;
  background: #fff;
  overflow: hidden;
}

.kiosk-wizard-loading,
.kiosk-wizard-error {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1.5rem;
  color: #777;
  font-size: 1.05rem;
}

.kiosk-wizard-spinner {
  width: 48px;
  height: 48px;
  border: 4px solid #e0e0e0;
  border-top-color: #e8001c;
  border-radius: 50%;
  animation: kiosk-spin 0.9s linear infinite;
}

@keyframes kiosk-spin { to { transform: rotate(360deg); } }

.kiosk-wizard-header {
  position: relative;
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 84px;
  padding: 16px 56px 12px;
  background: #fff;
  border-bottom: 1px solid #ececec;
  flex-shrink: 0;
}

.kiosk-item-info {
  text-align: center;
}

.kiosk-item-name {
  margin: 0;
  font-size: 24px;
  font-weight: 800;
  color: #222;
  text-transform: uppercase;
  letter-spacing: -0.03em;
  max-width: 780px;
  line-height: 1.1;
}

.kiosk-wizard-close {
  position: absolute;
  right: 18px;
  top: 50%;
  transform: translateY(-50%);
  width: 34px;
  height: 34px;
  border: 1.5px solid #dadada;
  border-radius: 50%;
  background: #fff;
  color: #666;
  font-size: 26px;
  line-height: 1;
}

.kiosk-step-visuals {
  display: flex;
  gap: 18px;
  justify-content: center;
  align-items: flex-start;
  padding: 14px 18px 8px;
  overflow-x: auto;
  background: #fff;
  scrollbar-width: none;
}

.kiosk-step-visuals::-webkit-scrollbar { display: none; }

.kiosk-step-visual {
  min-width: 92px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 7px;
  opacity: 0.68;
  transition: opacity 0.18s ease;
}

.kiosk-step-visual.active,
.kiosk-step-visual.done {
  opacity: 1;
}

.kiosk-step-visual-icon {
  position: relative;
  width: 64px;
  height: 64px;
  border-radius: 50%;
  border: 2px solid #dbdbdb;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26px;
  overflow: hidden;
  box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.kiosk-step-visual.active .kiosk-step-visual-icon {
  border-color: #e8001c;
  box-shadow: 0 0 0 4px rgba(232,0,28,0.08), 0 3px 12px rgba(232,0,28,0.08);
}

.kiosk-step-visual.done .kiosk-step-visual-icon {
  border-color: #e8001c;
}

.kiosk-step-visual-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.kiosk-step-visual-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 30px;
  background: #f7f7f8;
}

.kiosk-step-visual-index {
  position: absolute;
  right: -2px;
  bottom: -2px;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #fff;
  border: 1.5px solid #d7d7d7;
  color: #777;
  font-size: 11px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-step-visual.active .kiosk-step-visual-index,
.kiosk-step-visual.done .kiosk-step-visual-index {
  border-color: #e8001c;
  color: #e8001c;
}

.kiosk-step-visual-label {
  text-align: center;
  font-size: 10px;
  font-weight: 700;
  color: #777;
  text-transform: uppercase;
  line-height: 1.2;
  max-width: 88px;
}

.kiosk-progress-bar {
  display: grid;
  grid-template-columns: 42px 1fr 42px;
  align-items: center;
  gap: 8px;
  padding: 0 18px 10px;
  background: #fff;
  border-bottom: 1px solid #efefef;
}

.kiosk-progress-arrow {
  width: 36px;
  height: 36px;
  border: none;
  background: transparent;
  color: #999;
  font-size: 30px;
  line-height: 1;
}

.kiosk-progress-arrow:disabled {
  opacity: 0.55;
}

.kiosk-progress-track {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0;
}

.kiosk-step-dot {
  display: flex;
  align-items: center;
}

.kiosk-step-dot:not(:last-child)::after {
  content: '';
  width: 46px;
  height: 2px;
  background: #e0e0e0;
}

.kiosk-step-dot.done:not(:last-child)::after {
  background: #e8001c;
}

.kiosk-step-number {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  border: 2px solid #dcdcdc;
  color: #999;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
}

.kiosk-step-dot.active .kiosk-step-number {
  border-color: #e8001c;
  color: #e8001c;
}

.kiosk-step-dot.done .kiosk-step-number {
  background: #fff3f5;
  border-color: #e8001c;
  color: #e8001c;
}

.kiosk-step-question {
  padding: 14px 24px 2px;
  text-align: center;
  font-size: 18px;
  font-weight: 600;
  color: #333;
  text-transform: uppercase;
  letter-spacing: 0.01em;
}

.kiosk-step-content {
  flex: 1;
  overflow-y: auto;
  background: #fff;
  scrollbar-width: none;
  padding: 0 8px 8px;
}

.kiosk-step-content::-webkit-scrollbar { display: none; }

.kiosk-nav {
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: center;
  gap: 12px;
  min-height: 84px;
  border-top: 1px solid #e7e7e7;
  background: #f7f7f7;
  flex-shrink: 0;
  padding: 10px 12px;
}

.kiosk-nav-actions {
  display: grid;
  grid-template-columns: 1.25fr 0.8fr 1fr;
  gap: 8px;
  align-items: stretch;
}

.kiosk-btn-abandon,
.kiosk-btn-back,
.kiosk-btn-next {
  border: 1.5px solid #e0b0b7;
  background: #fff;
  font-size: 12px;
  font-weight: 800;
  color: #c33345;
  letter-spacing: 0.03em;
  border-radius: 10px;
  min-height: 52px;
  padding: 0 14px;
}

.kiosk-btn-back {
  color: #b54d5c;
  border-color: #e3c2c7;
}

.kiosk-btn-next {
  background: #e8001c;
  color: #fff;
  border-color: #e8001c;
}

.kiosk-btn-next:disabled,
.kiosk-btn-back:disabled {
  opacity: 1;
  color: #aa7d84;
  background: #f4e3e6;
  border-color: #ecd4d8;
}

.kiosk-nav-total {
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 160px;
  min-height: 52px;
  padding: 0 14px;
  white-space: nowrap;
  font-size: 15px;
  font-weight: 700;
  color: #6a4047;
  background: #f7e5e8;
  border-radius: 10px;
  border: 1px solid #efd2d7;
}

:deep(.kiosk-step-title) {
  display: none;
}

.step-slide-enter-active,
.step-slide-leave-active {
  transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
}

.step-slide-enter-from {
  opacity: 0;
  transform: translateX(30px);
}

.step-slide-leave-to {
  opacity: 0;
  transform: translateX(-30px);
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.kiosk-wizard-abandon-overlay {
  position: fixed;
  inset: 0;
  z-index: 120;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.kiosk-wizard-abandon-modal {
  background: #fff;
  border-radius: 20px;
  padding: 1.75rem 1.5rem;
  max-width: 400px;
  width: 100%;
  text-align: center;
  box-shadow: 0 16px 48px rgba(0, 0, 0, 0.2);
  border: 1px solid #ececec;
}

.kiosk-wizard-abandon-title {
  margin: 0 0 0.5rem;
  font-size: 1.25rem;
  font-weight: 800;
  color: #1a1a1a;
}

.kiosk-wizard-abandon-sub {
  margin: 0 0 1.35rem;
  font-size: 0.95rem;
  color: #777;
  line-height: 1.4;
}

.kiosk-wizard-abandon-actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.kiosk-wizard-abandon-yes {
  width: 100%;
  min-height: 50px;
  border: none;
  border-radius: 12px;
  background: #e8001c;
  color: #fff;
  font-size: 1rem;
  font-weight: 800;
  cursor: pointer;
}

.kiosk-wizard-abandon-no {
  width: 100%;
  min-height: 50px;
  border: 1.5px solid #e0e0e0;
  border-radius: 12px;
  background: #f7f7f8;
  color: #444;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
}
</style>
