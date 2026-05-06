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
      <button type="button" @click="$router.go(-1)" class="kiosk-btn-back">{{ $t('kiosk.back') }}</button>
    </div>

    <!-- Wizard complet -->
    <template v-else-if="resolvedItem">
      <h1 class="kiosk-wizard-sr-only">{{ sanitizeItemName(resolvedItem.name) }}</h1>
      <div class="kiosk-wizard-header">
        <div class="kiosk-item-info">
          <h2 class="kiosk-item-name">{{ sanitizeItemName(resolvedItem.name) }}</h2>
          <!-- Kiosk Phase 9.1.2 — Badge allergènes persistent en header.
               Safety FIC UE 1169/2011 + EAA 2025 : le client doit voir en permanence
               les allergènes avant d'ajouter au panier, sans avoir à cliquer. -->
          <KsAllergenBadge
            v-if="resolvedItem"
            class="kiosk-wizard-header-allergens"
            :item="resolvedItem"
            :selections="allergenBadgeSelections"
            :allergens="itemAllergenCodes"
            :customer-allergens="customerAllergenCodes"
            data-testid="kiosk-wizard-header-allergens"
          />
        </div>
        <button type="button"
          class="kiosk-wizard-close"
          :aria-label="$t('kiosk.wizard.close_aria')"
          @click="onAbandonClick">
          ×
        </button>
      </div>

      <div class="kiosk-step-visuals">
        <div
          v-for="(step, index) in activeSteps"
          :key="stepKey(step, index)"
          class="kiosk-step-visual"
          :class="{ active: index === currentStepIndex, done: index < currentStepIndex }"
        >
          <div class="kiosk-step-visual-icon">
            <img
              v-if="getStepVisualImage(step)"
              :src="getStepVisualImage(step)"
              :alt="getStepLabel(step)"
              class="kiosk-step-visual-img"
            />
            <span v-else class="kiosk-step-visual-fallback">{{ getStepIcon(step) }}</span>
            <span class="kiosk-step-visual-index">{{ index + 1 }}</span>
          </div>
          <span class="kiosk-step-visual-label">{{ getStepLabel(step) }}</span>
        </div>
      </div>

      <div class="kiosk-progress-bar">
        <button type="button"
          class="kiosk-progress-arrow"
          :aria-label="$t('kiosk.wizard.nav_previous')"
          @click="prevStep"
          :disabled="currentStepIndex === 0"
        >
          ‹
        </button>
        <div class="kiosk-progress-track">
          <div
            v-for="(step, index) in activeSteps"
            :key="stepKey(step, index)"
            class="kiosk-step-dot"
            :class="{ active: index === currentStepIndex, done: index < currentStepIndex }"
          >
            <span class="kiosk-step-number">{{ index + 1 }}</span>
          </div>
        </div>
        <button type="button"
          class="kiosk-progress-arrow"
          :aria-label="$t('kiosk.wizard.nav_next')"
          @click="nextStep"
          :disabled="currentStepIndex >= activeSteps.length - 1 || !canAdvance"
        >
          ›
        </button>
      </div>

      <div
        class="kiosk-live-composition"
        role="region"
        :aria-label="$t('kiosk.wizard.live_composition_label')"
        data-testid="kiosk-wizard-live-composition"
      >
        <span class="kiosk-live-composition-title">{{ $t('kiosk.wizard.live_composition_title') }}</span>
        <div class="kiosk-live-composition-list">
          <div
            v-for="chip in compositionSummaryChips"
            :key="chip.key"
            class="kiosk-live-composition-chip"
            :class="{ 'is-product': chip.kind === 'product' }"
            :data-testid="`kiosk-composition-chip-${chip.key}`"
          >
            <span class="kiosk-live-composition-thumb" aria-hidden="true">
              <img
                v-if="chip.image"
                :src="chip.image"
                :alt="''"
                class="kiosk-live-composition-img"
                loading="lazy"
              />
              <span v-else class="kiosk-live-composition-icon">{{ chip.icon }}</span>
            </span>
            <span class="kiosk-live-composition-copy">
              <span class="kiosk-live-composition-chip-label">{{ chip.label }}</span>
              <span class="kiosk-live-composition-chip-value">{{ chip.value }}</span>
            </span>
          </div>
          <span
            v-if="compositionSummaryChips.length === 0"
            class="kiosk-live-composition-empty"
            data-testid="kiosk-composition-empty"
          >
            {{ $t('kiosk.wizard.live_composition_empty') }}
          </span>
        </div>
      </div>

      <div class="kiosk-step-question">
        {{ currentStep.type === 'recap' ? $t('kiosk.wizard.recap_order_title') : getQuestionLabel(currentStep) }}
      </div>

      <div class="kiosk-step-content">
        <transition name="step-slide" mode="out-in">
          <component
            :is="currentStepComponent"
            :key="currentStepKey"
            v-bind="wizardStepBindings"
            @update="updateSelection"
          />
        </transition>
      </div>
      <div v-if="currentStep?.type === 'recap'" class="kiosk-note-block">
        <label class="kiosk-note-label" for="kiosk-note-input">{{ $t('label.special_instructions') }}</label>
        <textarea
          id="kiosk-note-input"
          v-model.trim="selections.instruction"
          class="kiosk-note-input"
          :placeholder="$t('message.add_note')"
          maxlength="190"
          rows="2"
        />
        <p class="kiosk-note-hint">{{ $t('message.special_instructions_limit') }}</p>
      </div>

      <div class="kiosk-nav" :class="{ 'kiosk-nav--recap': currentStep?.type === 'recap' }">
        <div class="kiosk-nav-actions">
          <button type="button" class="kiosk-btn-abandon" @click="onAbandonClick">
            {{ $t('kiosk.wizard.abandon_item') }}
          </button>
          <button type="button"
            class="kiosk-btn-back"
            @click="prevStep"
            :disabled="currentStepIndex === 0"
          >
            {{ $t('kiosk.wizard.nav_previous') }}
          </button>
          <button type="button"
            @click="currentStepIndex < activeSteps.length - 1 ? nextStep() : addToCart()"
            class="kiosk-btn-next"
            :class="{ 'kiosk-btn-next--cart': currentStepIndex >= activeSteps.length - 1 }"
            :disabled="!canAdvance"
            :aria-label="currentStepIndex < activeSteps.length - 1 ? $t('kiosk.wizard.nav_next') : $t('kiosk.wizard.add_to_cart')"
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
            ref="abandonModalEl"
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
import { defineAsyncComponent } from 'vue';
import KioskOrderSummary from './KioskOrderSummaryComponent.vue';
// Kiosk Phase 9.1.2 — Badge allergènes persistent dans header wizard (safety FIC).
import KsAllergenBadge from './ds/KsAllergenBadge.vue';
// Kiosk Phase 9.1.3 — SSOT pricing preview debounced (400 ms) côté wizard.
// Le helper pur `calculateKioskRunningTotal` est conservé en fallback pour
// éviter tout scénario d'affichage "0,00 €" si la requête échoue.
import { createKioskPricingPreview } from '../../../helpers/kioskPricingPreview';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { kioskResolveImageSrc, kioskVariationsForAttribute } from '../../../helpers/kioskMedia';
import {
  kioskDrinkAddonRowsFromItem,
  kioskIsDrinkAddonName,
  kioskIsGenericDrinkOptionName,
} from '../../../helpers/kioskDrinkAddons';
import { sanitizeKioskCustomerFacingText } from '../../../helpers/kioskDisplayText';
import {
  calculateKioskRunningTotal,
  getKioskMenuAddonPrice,
  normalizeKioskSelectionCount,
} from '../../../helpers/kioskPricing';
import { kioskSauceVariationRowsForItem } from '../../../helpers/kioskSauceCatalog';
import { partitionKioskExtras } from '../../../helpers/kioskExtrasPartition';
import { kioskViandeCatalogForItem } from '../../../helpers/kioskViandeCatalog';
// [P-MEGA-01] SSOT pour la détection taille / nombre de viandes : un seul
// helper centralisé remplace les 3 regex divergentes qui causaient le bug
// "Tacos M / Méga / Famille → 1 viande seulement". Tests : kioskTacosSize.spec.js.
import {
  detectTacosSize,
  viandeCountFromName,
  hasPresetSizeInName,
  tacosSizeLabel,
} from '../../../helpers/kioskTacosSize';
// Phase 8.8 — Analytics wizard (event fired on step enter/complete/abandon).
import kioskAnalytics from '../../../helpers/kioskAnalytics';

const KioskStepPain = defineAsyncComponent(() =>
  import(/* webpackChunkName: "kiosk-wizard-step" */ './steps/KioskStepPainComponent.vue')
);
const KioskStepTaille = defineAsyncComponent(() =>
  import(/* webpackChunkName: "kiosk-wizard-step" */ './steps/KioskStepTailleComponent.vue')
);
const KioskStepViande = defineAsyncComponent(() =>
  import(/* webpackChunkName: "kiosk-wizard-step" */ './steps/KioskStepViandeComponent.vue')
);
const KioskStepSauce = defineAsyncComponent(() =>
  import(/* webpackChunkName: "kiosk-wizard-step" */ './steps/KioskStepSauceComponent.vue')
);
const KioskStepGarnitures = defineAsyncComponent(() =>
  import(/* webpackChunkName: "kiosk-wizard-step" */ './steps/KioskStepGarnituresComponent.vue')
);
const KioskStepSupplements = defineAsyncComponent(() =>
  import(/* webpackChunkName: "kiosk-wizard-step" */ './steps/KioskStepSupplementsComponent.vue')
);
const KioskStepMenu = defineAsyncComponent(() =>
  import(/* webpackChunkName: "kiosk-wizard-step" */ './steps/KioskStepMenuComponent.vue')
);
const KioskStepGenericChoices = defineAsyncComponent(() =>
  import(/* webpackChunkName: "kiosk-wizard-step" */ './steps/KioskStepGenericChoicesComponent.vue')
);

// [T-WC-KIOSK-REGISTRY-01] Explicit registry — replaces heuristic substring matching.
// Audit Axe A.4 #1 : un step avec step_key arbitraire (ex. 'dessert') tombait à null
// faute de match sous-chaîne. Ce registre map explicitement chaque clé connue vers
// son composant spécialisé. Tout step inconnu mais avec `choices` non vides retombe
// sur KioskStepGenericChoicesComponent ; sans choices, il est loggué puis skippé.
const STEP_KEY_REGISTRY = Object.freeze({
    pain: 'pain',
    galette: 'pain',
    bun: 'pain',
    viande: 'viande',
    meat: 'viande',
    proteine: 'viande',
    sauce: 'sauce',
    sauces: 'sauce',
    garnitures: 'garnitures',
    garniture: 'garnitures',
    crudites: 'garnitures',
    supplements: 'supplements',
    supplement: 'supplements',
    extras: 'supplements',
    menu: 'menu',
    formule: 'menu',
    boisson: 'menu',
    drink: 'menu',
    frites: 'menu',
    side: 'menu',
    dessert: 'menu',
    taille: 'taille',
    size: 'taille',
});

const ADDON_ROLE_TO_TYPE = Object.freeze({
    drink: 'menu',
    side: 'menu',
    dessert: 'menu',
    menu_component: 'menu',
});

function resolveExplicitStepType(step) {
    if (!step) return null;

    const addonRole = String(step.addon_role || '').toLowerCase().trim();
    if (addonRole && ADDON_ROLE_TO_TYPE[addonRole]) {
        return ADDON_ROLE_TO_TYPE[addonRole];
    }

    const stepKey = String(step.step_key || '').toLowerCase().trim();
    if (stepKey && STEP_KEY_REGISTRY[stepKey]) {
        return STEP_KEY_REGISTRY[stepKey];
    }

    return null;
}

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
    KioskStepGenericChoices,
    KioskOrderSummary,
    KsAllergenBadge,
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
        composerChoices: {},
        quantity: 1,
        instruction: ''
      },
      // Kiosk Phase 9.1.3 — total SSOT renvoyé par `POST /pricing/preview`.
      // `null` tant qu'aucune réponse valide n'a été reçue → `runningTotal`
      // retombe sur le calcul local (fallback). Pas de flicker : on ne met à
      // jour qu'en cas de succès, jamais en cas d'erreur (dégradé gracieux).
      serverPreviewTotal: null,
      serverPreviewLoading: false,
    };
  },
  computed: {
    /** Tolère les tests sans module kioskFilter enregistré (liste vide). */
    activeFilters() {
      const raw = this.$store?.getters?.['kioskFilter/activeFilters'];
      return Array.isArray(raw) ? raw : [];
    },
    resolvedItem() {
      return this.item || this.fetchedItem;
    },
    // Kiosk Phase 9.1.2 — Codes allergènes de l'item courant.
    // NormalItemResource (backend) expose `allergens` sous forme d'objets
    // `{id, code, name_key, icon, is_trace}`. KsAllergenBadge attend uniquement
    // les codes string. On tolère aussi les items legacy qui pourraient
    // exposer un tableau plat de codes (rétrocompat, aucun casse).
    itemAllergenCodes() {
      const raw = this.resolvedItem && this.resolvedItem.allergens;
      if (!Array.isArray(raw)) return [];
      return raw
        .map((a) => (typeof a === 'string' ? a : (a && a.code) || null))
        .filter(Boolean);
    },
    /**
     * Variations / extras catalogue alignés sur buildCartItem() pour fusionner
     * les allergènes (extras ex. fromage → lait) dans le badge header.
     */
    allergenBadgeSelections() {
      const item = this.resolvedItem;
      const selections = this.selections;
      if (!item) return { variations: [], extras: [] };
      const variations = [];
      const extras = [];

      const painMeta = selections._painMeta;
      if (painMeta?.realId && painMeta?.attrId) {
        const list = kioskVariationsForAttribute(item, painMeta.attrId);
        const v = list?.find((x) => x.id === painMeta.realId);
        if (v) variations.push(v);
      }

      const viandeMeta = selections._viandeMeta || [];
      const firstVarViande = viandeMeta.find(
        (v) => v.source === 'variation' && typeof v.id === 'number',
      );
      if (firstVarViande && item.itemAttributes) {
        const attrs = Array.isArray(item.itemAttributes)
          ? item.itemAttributes
          : Object.values(item.itemAttributes || {});
        const viandeAttr = attrs.find((a) =>
          (a.name || '').toLowerCase().includes('viande'),
        );
        const rawVars = viandeAttr && item.variations?.[viandeAttr.id];
        const varList = Array.isArray(rawVars) ? rawVars : rawVars ? [rawVars] : [];
        const v = varList.find((x) => x.id === firstVarViande.id);
        if (v) variations.push(v);
      }

      if (selections.sauceOrder?.length > 0) {
        const v = this.kioskFindSauceVariation(item, selections.sauceOrder[0]);
        if (v) variations.push(v);
      }

      if (item.extras) {
        Object.keys(selections.garnitures || {}).forEach((id) => {
          if (selections.garnitures[id]) {
            const ex = item.extras.find((e) => e.id === parseInt(id, 10));
            if (ex) extras.push(ex);
          }
        });
        Object.keys(selections.supplements || {}).forEach((id) => {
          if (normalizeKioskSelectionCount(selections.supplements[id]) > 0) {
            const ex = item.extras.find((e) => e.id === parseInt(id, 10));
            if (ex) extras.push(ex);
          }
        });
      }

      viandeMeta.forEach((v) => {
        if (v.source !== 'extra' || typeof v.id !== 'number') return;
        const count = parseInt(v.count || 0, 10) || 0;
        const ex = item.extras?.find((e) => e.id === v.id);
        if (!ex) return;
        for (let i = 0; i < count; i++) extras.push(ex);
      });

      this.composerChoiceEntries().forEach((entry) => {
        const count = parseInt(entry.count || 0, 10) || 0;
        if (count <= 0) return;
        if (entry.source_type === 'variation') {
          const variation = this.findItemVariationById(item, entry.id);
          if (variation) variations.push(variation);
        }
        if (entry.source_type === 'extra') {
          const extra = this.findItemExtraById(item, entry.id);
          if (extra) extras.push(extra);
        }
      });

      return { variations, extras };
    },
    // Kiosk Phase 9.1.2 — Codes allergènes déclarés par le client (scan loyalty).
    // Source : `kioskSettings/customerProfile.declared_allergens` (opt-in RGPD,
    // jamais de PII dans le payload analytics). Si non scanné → []. Le badge
    // passe en `role=alert` dès intersection non vide avec `itemAllergenCodes`.
    customerAllergenCodes() {
      const profile = this.$store &&
        this.$store.getters &&
        this.$store.getters['kioskSettings/customerProfile'];
      if (!profile || !Array.isArray(profile.declared_allergens)) return [];
      return profile.declared_allergens.map(String).filter(Boolean);
    },
    activeSteps() {
      if (!this.resolvedItem) return [];
      const composerSteps = this.composerActiveSteps();
      if (composerSteps.length > 0) return composerSteps;

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
    currentStepKey() {
      return this.stepKey(this.currentStep, this.currentStepIndex);
    },
    currentStepComponent() {
      return this.currentStep?.component;
    },
    // Kiosk Phase 9.1.3 — Total local (pur, synchrone) conservé comme fallback
    // SSOT côté client. Jamais utilisé pour envoyer un prix au serveur — seul
    // le backend fait autorité via `PricingService` (`/order` + `/preview`).
    runningTotalLocal() {
      return calculateKioskRunningTotal(this.resolvedItem, this.selections);
    },
    // Total affiché dans le footer du wizard : instantané côté tactile.
    // À chaque modification, `serverPreviewTotal` est vidé avant le debounce,
    // donc l'utilisateur voit immédiatement le calcul local. Le preview serveur
    // peut confirmer un total supérieur, mais ne doit jamais masquer les deltas
    // d'options explicites du wizard (sauces multiples, formule, viandes extras)
    // que le payload preview ne représente pas intégralement.
    runningTotal() {
      const local = this.runningTotalLocal;
      return this.serverPreviewTotal != null
        ? Math.max(this.serverPreviewTotal, local)
        : local;
    },
    /** « Boisson seule » doit être disponible dès que l'article expose l'étape menu. */
    kioskShowBoissonOnlyMenuCard() {
      const item = this.resolvedItem;
      if (!item) return false;
      return item.has_menu === true || kioskDrinkAddonRowsFromItem(item).length > 0;
    },
    compositionSummaryChips() {
      if (!this.resolvedItem) return [];

      const chips = [];

      if (this.shouldShowCompositionStep('taille') && this.selections._tailleMeta?.label) {
        chips.push({
          key: 'taille',
          label: this.compositionLabel('taille'),
          value: this.selections._tailleMeta.label,
          image: kioskResolveImageSrc(this.resolvedItem),
          icon: '📏',
        });
      }

      const painChip = this.compositionPainChip();
      if (painChip) chips.push(painChip);

      const viandeChip = this.compositionViandeChip();
      if (viandeChip) chips.push(viandeChip);

      const sauceChip = this.compositionSauceChip();
      if (sauceChip) chips.push(sauceChip);

      const garnitureChip = this.compositionExtraGroupChip('garnitures');
      if (garnitureChip) chips.push(garnitureChip);

      const supplementChip = this.compositionExtraGroupChip('supplements');
      if (supplementChip) chips.push(supplementChip);

      const menuChip = this.compositionMenuChip();
      if (menuChip) chips.push(menuChip);

      this.compositionComposerChips().forEach((chip) => chips.push(chip));

      return chips;
    },
    /** Évite de passer des props inconnues aux autres étapes du wizard. */
    kioskMenuStepExtraProps() {
      if (this.currentStep?.type !== 'menu') return {};
      return { showBoissonOnlyMenuCard: this.kioskShowBoissonOnlyMenuCard };
    },
    /** Props communes + filtres catalogue (greyout) uniquement sur les étapes concernées. */
    wizardStepBindings() {
      const step = this.currentStep;
      const base = {
        step,
        item: this.resolvedItem,
        selections: this.selections,
        ...this.kioskMenuStepExtraProps,
      };
      if (['viande', 'sauce', 'garnitures', 'supplements'].includes(step?.type)) {
        base.activeFilters = this.activeFilters;
      }
      return base;
    },
    canAdvance() {
      const step = this.currentStep;
      if (!step) return false;
      
      if (step.type === 'viande') {
        const required = this.detectViandeCount();
        if (!this.hasIncludedViandeOptions()) {
          return (this.selections.totalViandes || 0) >= required;
        }
        return this.includedViandeSelectionCount() >= required;
      }
      if (step.type === 'sauce') return this.selections.sauceOrder.length > 0;
      if (step.type === 'pain') return this.selections.pain !== null;
      // [AUDIT-P2] Taille step requires an explicit choice before proceeding
      if (step.type === 'taille') return this.selections.taille !== null;
      if (step.type === 'generic_choices') return this.canAdvanceComposerChoiceStep(step);
      // [P0] Menu : choix explicite obligatoire (full / frites / boisson / none) — pas de passage « vide »
      if (step.type === 'menu') {
        const mc = this.selections.menuChoice;
        if (mc === null || mc === undefined || mc === '') return false;
        // [P1] Si des addons boisson existent et que la formule inclut une boisson → choix obligatoire
        const wantsDrink = mc === 'full' || mc === 'boisson';
        if (wantsDrink && this.kioskMenuDrinkChoiceAvailable()) {
          const bc = this.selections.boissonChoice;
          if (bc === null || bc === undefined || bc === '') return false;
        }
        // Frites (menu complet ou frites seules) : au moins une sauce frites (dont « Sans sauce »)
        // Uniquement si le catalogue expose des sauces (attribut sauce + variations) — sinon on ne bloque pas.
        if (mc === 'full' || mc === 'frites') {
          const hasSauceCatalog = kioskSauceVariationRowsForItem(this.resolvedItem).length > 0;
          if (hasSauceCatalog) {
            const order = this.selections.fritesSauceOrder || [];
            if (order.length === 0) return false;
          }
        }
        return true;
      }

      return true;
    }
  },
  methods: {
    publishedComposerProfile() {
      const profile = this.resolvedItem?.composer_profile || null;
      if (!profile || profile.is_published === false) return null;
      const steps = Array.isArray(profile.steps) ? profile.steps.filter((step) => step?.is_active !== false) : [];
      return steps.length > 0 ? { ...profile, steps } : null;
    },
    composerActiveSteps() {
      const profile = this.publishedComposerProfile();
      if (!profile) return [];

      const mapped = profile.steps
        .map((step) => {
          const type = this.composerStepType(step);
          return type ? { type, label: step.label || type, component: this.componentForStepType(type), composer_step: step } : null;
        })
        .filter((step) => step && this.shouldShowComposerStep(step));

      if (!mapped.some((step) => step.type === 'recap')) {
        mapped.push({ type: 'recap', label: 'Récap', component: 'KioskOrderSummary' });
      }

      return mapped;
    },
    hasGenericComposerChoices(step) {
      const choices = Array.isArray(step?.choices) ? step.choices : [];
      if (choices.length === 0) return false;
      return ['item_attribute', 'extra_group', 'addon'].includes(step?.source_type);
    },
    composerStepType(step) {
      const explicit = resolveExplicitStepType(step);
      if (explicit) return explicit;

      if (this.hasGenericComposerChoices(step)) return 'generic_choices';

      if (typeof console !== 'undefined' && console.warn) {
        console.warn('[kiosk-wizard.composer] step skipped (no kind match + no choices)', {
          step_key: step?.step_key,
          label: step?.label,
          source_type: step?.source_type,
        });
      }
      return null;
    },
    componentForStepType(type) {
      const map = {
        pain: 'KioskStepPain',
        taille: 'KioskStepTaille',
        viande: 'KioskStepViande',
        sauce: 'KioskStepSauce',
        garnitures: 'KioskStepGarnitures',
        supplements: 'KioskStepSupplements',
        menu: 'KioskStepMenu',
        generic_choices: 'KioskStepGenericChoices',
        recap: 'KioskOrderSummary',
      };
      return map[type] || 'KioskOrderSummary';
    },
    shouldShowComposerStep(step) {
      if (!step || step.type === 'recap') return true;
      const choices = Array.isArray(step.composer_step?.choices) ? step.composer_step.choices : [];
      if (step.type === 'generic_choices') return choices.length > 0;
      if (choices.length > 0) return true;
      if (step.type === 'taille') return true;
      if (step.type === 'menu' && (step.composer_step?.source_type === 'addon' || step.composer_step?.addon_role)) return true;
      return this.shouldShowStep(step.type);
    },
    stepKey(step, index = 0) {
      if (!step) return `step-${index}`;
      const composerId = step.composer_step?.id || step.id || null;
      if (composerId) return `composer-${composerId}-${step.type || 'step'}`;
      return `${step.type || 'step'}-${index}`;
    },
    composerChoiceStepId(step) {
      const source = step?.composer_step || step || {};
      return String(source.id || source.step_key || source.label || 'generic');
    },
    composerChoiceGroup(step) {
      const all = this.selections.composerChoices || {};
      return all[this.composerChoiceStepId(step)] || null;
    },
    composerChoiceStepCount(step) {
      const group = this.composerChoiceGroup(step);
      const choices = group?.choices || {};
      const allowedKeys = this.composerStepAllowedChoiceKeys(step);
      return Object.entries(choices).reduce((sum, [key, choice]) => {
        if (!allowedKeys.has(key)) return sum;
        return sum + (parseInt(choice?.count || 0, 10) || 0);
      }, 0);
    },
    canAdvanceComposerChoiceStep(step) {
      const composerStep = step?.composer_step || {};
      const min = parseInt(composerStep.min_select ?? 0, 10) || 0;
      const max = parseInt(composerStep.max_select ?? 0, 10) || 0;
      const count = this.composerChoiceStepCount(step);
      if (count < min) return false;
      if (max > 0 && count > max) return false;
      return true;
    },
    /**
     * Composer-first contract.
     * - Published composer profile: template comes from the profile.
     * - Backend resource with `composer_profile: null` + `wizard_template: simple`:
     *   explicit no-wizard/simple product, no name heuristic.
     * - Legacy payload with no composer_profile key: keep heuristic fallback.
     */
    effectiveWizardTemplate() {
      const item = this.resolvedItem;
      if (!item) return 'simple';
      const composerProfile = this.publishedComposerProfile();
      if (composerProfile?.template) return composerProfile.template;

      // Priority 1: item-level wizard_template (from API, derived from category)
      const raw = item.wizard_template;
      if (raw === 'simple' && Object.prototype.hasOwnProperty.call(item, 'composer_profile')) {
        return 'simple';
      }
      if (raw && raw !== 'simple') return raw;
      // Priority 2: nested category object (present on ItemResource, absent on NormalItemResource)
      const catTemplate = item.category?.wizard_template;
      if (catTemplate && catTemplate !== 'simple') return catTemplate;
      // Priority 3: name/category heuristic fallback
      kioskAnalytics?.trackHeuristicFallback?.({
        item_id: item.id,
        item_name: item.name,
        reason: 'missing_published_composer_profile',
      });
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
      // [P-MEGA-01] Sources de vérité par ordre de priorité :
      //   1. Sélection explicite Taille (selections._tailleMeta.viandeCount)
      //   2. Champ serveur item.viande_count (P-MEGA-23 — quand exposé)
      //   3. Heuristique nom centralisée (kioskTacosSize)
      //   4. Fallback à 1 — UNIQUEMENT au moment de l'usage, jamais
      //      pour décider d'afficher / cacher l'étape Taille (cf.
      //      shouldAskTacosTaille). Tracé via analytics quand utilisé.
      if (this.selections._tailleMeta?.viandeCount) {
        return this.selections._tailleMeta.viandeCount;
      }
      const item = this.resolvedItem;
      if (!item) return 1;
      if (Number.isInteger(item.viande_count) && item.viande_count >= 1) {
        return item.viande_count;
      }
      const fromName = viandeCountFromName(item.name);
      if (fromName != null) return fromName;
      // Fallback observable : l'admin n'a ni step Taille ni libellé reconnu.
      // On le trace pour pouvoir en quantifier l'incidence côté observabilité
      // (P-MEGA-15) et alerter quand un libellé bordelin apparaît en prod.
      try {
        kioskAnalytics?.track?.('wizard.viande_count_fallback', {
          item_id: item.id,
          item_name: item.name,
        });
      } catch (_) { /* analytics absente : silencieux */ }
      return 1;
    },
    // [AUDIT 2026-04-17 C2] shouldShowStep s'aligne désormais sur les helpers
    // partitionKioskExtras / kioskSauceVariationRowsForItem / kioskViandeCatalog
    // pour garantir une cohérence stricte avec ce que chaque étape va réellement
    // afficher. Une étape n'apparaît dans le bandeau que si elle aura du contenu.
    shouldShowStep(type) {
      const item = this.resolvedItem;
      if (!item) return false;

      if (type === 'supplements') {
        return partitionKioskExtras(item).supplements.length > 0;
      }
      if (type === 'garnitures') {
        return partitionKioskExtras(item).garnitures.length > 0;
      }
      if (type === 'sauce') {
        return kioskSauceVariationRowsForItem(item).length > 0;
      }
      if (type === 'menu') {
        // [AUDIT 2026-04-17] L'étape « menu » expose la question Menu vs Seul ;
        // elle doit apparaître dès que le produit est éligible (has_menu),
        // même si la carte boissons ou les upgrades frites sont vides.
        return item.has_menu === true;
      }
      if (type === 'viande') {
        return this.detectViandeCount() > 0 && kioskViandeCatalogForItem(item).length > 0;
      }
      if (type === 'pain') {
        const attrs = Array.isArray(item.itemAttributes)
          ? item.itemAttributes
          : Object.values(item.itemAttributes || {});
        const painAttr = attrs.find(a =>
          (a?.name || '').toLowerCase().includes('pain') ||
          (a?.name || '').toLowerCase().includes('galette')
        );
        if (!painAttr?.id) return false;
        const list = kioskVariationsForAttribute(item, painAttr.id);
        return Array.isArray(list) && list.some(v => v && Number(v.status) !== 10);
      }
      if (type === 'taille') return this.shouldAskTacosTaille();
      return true;
    },
    shouldAskTacosTaille() {
      // [P-MEGA-01] Cohérent avec detectViandeCount + inferTacosPresetMeta :
      // on s'appuie sur le helper SSOT kioskTacosSize. Si une taille est
      // détectable depuis le nom OU une description, l'étape n'est pas
      // demandée. Sinon on la propose pour éviter le fallback à 1.
      const item = this.resolvedItem;
      if (!item) return false;
      const template = this.effectiveWizardTemplate();
      if (template !== 'tacos') return false;
      const haystack = `${item.name || ''} ${item.description || ''}`;
      // Si le serveur expose viande_count, on ne demande plus la taille
      // (l'info est déjà disponible).
      if (Number.isInteger(item.viande_count) && item.viande_count >= 1) {
        return false;
      }
      return !hasPresetSizeInName(haystack);
    },
    inferTacosPresetMeta() {
      // [P-MEGA-01] Plus de regex dupliquée : on délègue à kioskTacosSize.
      const item = this.resolvedItem;
      if (!item || this.shouldAskTacosTaille()) return null;
      const viandeCount = this.detectViandeCount();
      const detectedSize = detectTacosSize(`${item.name || ''} ${item.description || ''}`);
      const label = tacosSizeLabel(`${item.name || ''} ${item.description || ''}`)
        || `${viandeCount} viande${viandeCount > 1 ? 's' : ''}`;
      return {
        viandeCount,
        label,
        size: detectedSize,
        attrId: null,
        realId: null,
      };
    },
    // [AUDIT 2026-04-17 C2] Remplacé par kioskViandeCatalogForItem dans
    // shouldShowStep. Conservé en alias d'entrée publique pour rétro-compat.
    hasViandeVariations() {
      return kioskViandeCatalogForItem(this.resolvedItem).length > 0;
    },
    hasIncludedViandeOptions() {
      return kioskViandeCatalogForItem(this.resolvedItem).some((row) => row.source !== 'extra');
    },
    kioskMenuDrinkChoiceAvailable() {
      if (kioskDrinkAddonRowsFromItem(this.resolvedItem).length > 0) return true;

      const items = this.$store?.getters?.['kioskMenu/allItems']
        || this.$store?.state?.kioskMenu?.items
        || [];
      if (!Array.isArray(items) || items.length === 0) return false;

      const categories = this.$store?.getters?.['kioskMenu/categories']
        || this.$store?.state?.kioskMenu?.categories
        || [];
      const drinkCategoryIds = new Set((Array.isArray(categories) ? categories : [])
        .filter((cat) => {
          const haystack = `${cat?.name || ''} ${cat?.slug || ''}`.toLowerCase();
          return /\b(boisson|boissons|drink|drinks|soda|sodas|beverage|beverages)\b/i.test(haystack);
        })
        .map((cat) => String(cat.id)));

      return items.some((row) => {
        if (!row || row.id === this.resolvedItem?.id) return false;
        if (row.is_available === false) return false;
        const status = Number(row.status);
        if (status === 0 || status === 2 || status === 10) return false;
        const catId = String(row.item_category_id ?? row.category_id ?? '');
        const name = row.name || row.item_name || '';
        if (kioskIsGenericDrinkOptionName(name)) return false;
        return (catId !== '' && drinkCategoryIds.has(catId))
          || kioskIsDrinkAddonName(name);
      });
    },
    includedViandeSelectionCount() {
      const meta = Array.isArray(this.selections._viandeMeta) ? this.selections._viandeMeta : [];
      if (meta.length === 0) return this.selections.totalViandes || 0;
      return meta
        .filter((row) => row && row.source !== 'extra')
        .reduce((sum, row) => sum + (parseInt(row.count || 0, 10) || 0), 0);
    },
    compactChoiceText(values, max = 2) {
      const clean = (Array.isArray(values) ? values : [])
        .map((v) => String(v || '').trim())
        .filter(Boolean);
      if (clean.length <= max) return clean.join(', ');
      return `${clean.slice(0, max).join(', ')} +${clean.length - max}`;
    },
    formatChoiceNameWithCount(name, count) {
      const n = parseInt(count || 0, 10) || 0;
      return n > 1 ? `${name} x${n}` : name;
    },
    stepIndexForType(type) {
      return (this.activeSteps || []).findIndex((step) => step?.type === type);
    },
    shouldShowCompositionStep(type) {
      const idx = this.stepIndexForType(type);
      if (idx < 0) return false;
      return this.currentStepIndex >= idx;
    },
    compositionLabel(type, fallback = '') {
      const keys = {
        taille: 'kiosk.wizard.live_composition_size',
        pain: 'kiosk.wizard.summary.bread_type',
        viande: 'kiosk.wizard.summary.meats',
        sauce: 'kiosk.wizard.summary.sauces',
        garnitures: 'kiosk.wizard.summary.garnishes',
        supplements: 'kiosk.wizard.summary.supplements',
        menu: 'kiosk.wizard.summary.menu',
      };
      const key = keys[type];
      if (key) {
        const translated = this.$t(key);
        if (translated !== key) return String(translated).replace(/[:?؟]+$/u, '');
      }
      const raw = fallback || this.getStepLabel(type);
      return String(raw)
        .replace(/[:?؟]+$/u, '')
        .replace(/^\s*(quel|quelle|quels|quelles|choose|any)\s+/i, '')
        .trim();
    },
    compositionPainChip() {
      const item = this.resolvedItem;
      const painId = this.selections.pain;
      if (!this.shouldShowCompositionStep('pain')) return null;
      if (!item || !painId) return null;
      const painAttr = this.kioskNormalizeItemAttributes(item.itemAttributes).find((a) =>
        (a?.name || '').toLowerCase().includes('pain') ||
        (a?.name || '').toLowerCase().includes('galette')
      );
      const variation = painAttr ? kioskVariationsForAttribute(item, painAttr.id)?.find((v) => String(v.id) === String(painId)) : null;
      const name = this.selections._painMeta?.name || variation?.name || String(painId);
      return {
        key: 'pain',
        label: this.compositionLabel('pain'),
        value: name,
        image: kioskResolveImageSrc(variation, item),
        icon: '🥖',
      };
    },
    compositionViandeChip() {
      if (!this.shouldShowCompositionStep('viande')) return null;
      const meta = Array.isArray(this.selections._viandeMeta) ? this.selections._viandeMeta : [];
      const selected = meta.filter((row) => row && (parseInt(row.count || 0, 10) || 0) > 0);
      if (selected.length === 0) return null;
      const catalog = kioskViandeCatalogForItem(this.resolvedItem);
      const first = selected[0];
      const firstCatalog = catalog.find((row) => row.key === first.key || String(row.id) === String(first.id));
      return {
        key: 'viande',
        label: this.compositionLabel('viande'),
        value: this.compactChoiceText(selected.map((row) => this.formatChoiceNameWithCount(row.name, row.count))),
        image: kioskResolveImageSrc(firstCatalog, firstCatalog?.thumb),
        icon: '🥩',
      };
    },
    compositionSauceChip() {
      if (!this.shouldShowCompositionStep('sauce')) return null;
      const order = Array.isArray(this.selections.sauceOrder) ? this.selections.sauceOrder : [];
      if (order.length === 0) return null;
      const rows = order
        .map((key) => this.kioskFindSauceVariation(this.resolvedItem, key))
        .filter(Boolean);
      return {
        key: 'sauce',
        label: this.compositionLabel('sauce'),
        value: this.compactChoiceText(rows.map((row) => row.name || 'Sauce')),
        image: kioskResolveImageSrc(rows[0], this.resolvedItem),
        icon: '🥫',
      };
    },
    compositionExtraGroupChip(group) {
      const item = this.resolvedItem;
      const type = group === 'supplements' ? 'supplements' : 'garnitures';
      if (!this.shouldShowCompositionStep(type)) return null;
      if (!item || !Array.isArray(item.extras)) return null;
      const source = this.selections[group] || {};
      const selected = Object.entries(source)
        .map(([id, raw]) => {
          const count = normalizeKioskSelectionCount(raw);
          if (count <= 0) return null;
          const extra = item.extras.find((row) => String(row.id) === String(id));
          if (!extra) return null;
          return { extra, count };
        })
        .filter(Boolean);
      if (selected.length === 0) return null;

      const isSupplement = group === 'supplements';
      return {
        key: group,
        label: this.compositionLabel(type),
        value: this.compactChoiceText(selected.map((row) => this.formatChoiceNameWithCount(row.extra.name, row.count))),
        image: kioskResolveImageSrc(selected[0].extra, item),
        icon: isSupplement ? '🧀' : '🥗',
      };
    },
    compositionMenuChip() {
      if (!this.shouldShowCompositionStep('menu')) return null;
      const mc = this.selections.menuChoice;
      if (!mc || mc === 'none') return null;
      const s = 'kiosk.wizard.summary';
      const base = {
        full: this.$t(`${s}.menu_label_full`),
        frites: this.$t(`${s}.menu_label_frites`),
        boisson: this.$t(`${s}.menu_label_boisson`),
      }[mc] || mc;
      const details = [base];
      if (this.selections._boissonMeta?.boissonName && (mc === 'full' || mc === 'boisson')) {
        details.push(this.selections._boissonMeta.boissonName);
      }
      const fryOrder = (this.selections.fritesSauceOrder || []).filter((key) => key && key !== 'sans');
      if (fryOrder.length > 0) {
        details.push(this.compactChoiceText(fryOrder.map((key) => this.kioskFritesSauceDisplayName(key)), 1));
      }
      return {
        key: 'menu',
        label: this.compositionLabel('menu'),
        value: details.join(' · '),
        image: kioskResolveImageSrc(this.resolvedItem),
        icon: '🍟',
      };
    },
    compositionComposerChips() {
      const grouped = {};
      this.composerChoiceEntries().forEach((entry) => {
        const label = entry.step_label || this.$t('kiosk.wizard.generic.step_fallback');
        if (!grouped[label]) grouped[label] = [];
        grouped[label].push(this.formatChoiceNameWithCount(entry.name || entry.id, entry.count));
      });
      return Object.entries(grouped).map(([label, values], index) => ({
        key: `composer-${index}`,
        label,
        value: this.compactChoiceText(values),
        image: kioskResolveImageSrc(this.resolvedItem),
        icon: '＋',
      }));
    },
    updateSelection(key, value, meta) {
      this.serverPreviewTotal = null;
      this.selections[key] = value;
      if (key === 'pain' && meta) {
        this.selections._painMeta = meta;
      }
      if (key === 'taille' && meta) {
        this.selections._tailleMeta = meta;
        this.selections.viandes = {};
        this.selections.totalViandes = 0;
        this.selections._viandeMeta = [];
      }
      // [AUDIT 2026-04-17 C12] Purge _boissonMeta quand menuChoice retire la
      // boisson (none/frites). Empêche un fantôme de boisson dans le récap
      // après que le client ait changé d'avis.
      if (key === 'menuChoice') {
        if (value === 'none' || value === 'frites') {
          this.selections.boissonChoice = null;
          this.selections._boissonMeta = null;
        }
        if (value === 'none' || value === 'boisson') {
          this.selections.fritesSauceOrder = [];
          this.selections.fritesSauce = null;
          this.selections._fritesSauceMeta = null;
        }
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
        if (this.selections.fritesSauceOrder.length === 0) {
          this.selections._fritesSauceMeta = null;
        }
      }
      if (key === 'fritesSauce' && meta) {
        this.selections._fritesSauceMeta = meta;
      }
      if (key === '_viandeMeta') {
        this.selections._viandeMeta = value;
      }
      if (key === 'composerChoices') {
        this.selections.composerChoices = value && typeof value === 'object' ? value : {};
      }
    },
    allItemVariationRows(item) {
      const raw = item?.variations || {};
      if (Array.isArray(raw)) return raw;
      return Object.values(raw).flatMap((rows) => {
        if (Array.isArray(rows)) return rows;
        if (rows && typeof rows === 'object') return Object.values(rows);
        return [];
      });
    },
    findItemVariationById(item, id) {
      return this.allItemVariationRows(item).find((row) => String(row?.id) === String(id)) || null;
    },
    findItemExtraById(item, id) {
      return (item?.extras || []).find((row) => String(row?.id) === String(id)) || null;
    },
    composerProjectedChoiceAvailable(choice) {
      if (!choice) return false;
      if (Object.prototype.hasOwnProperty.call(choice, 'is_available') && choice.is_available === false) return false;
      const status = Number(choice.status);
      return status !== 0 && status !== 2 && status !== 10;
    },
    composerStepAllowedChoiceKeys(step) {
      const composerStep = step?.composer_step || step || {};
      return new Set((Array.isArray(composerStep.choices) ? composerStep.choices : [])
        .filter((choice) => this.composerProjectedChoiceAvailable(choice))
        .map((choice) => `${choice.source_type || composerStep.source_type || 'choice'}:${choice.id}`));
    },
    currentComposerAllowedChoiceKeys() {
      return new Set(this.composerActiveSteps()
        .flatMap((step) => Array.isArray(step?.composer_step?.choices) ? step.composer_step.choices : [])
        .filter((choice) => this.composerProjectedChoiceAvailable(choice))
        .map((choice) => `${choice.source_type || 'choice'}:${choice.id}`));
    },
    sanitizeComposerChoicesForCurrentProfile(groups) {
      const allowedKeys = this.currentComposerAllowedChoiceKeys();
      const sanitized = {};
      Object.entries(groups || {}).forEach(([groupKey, group]) => {
        const nextChoices = {};
        Object.entries(group?.choices || {}).forEach(([choiceKey, choice]) => {
          if (allowedKeys.has(choiceKey)) {
            nextChoices[choiceKey] = choice;
          }
        });
        sanitized[groupKey] = { ...group, choices: nextChoices };
      });
      return sanitized;
    },
    attributeNameForVariation(item, variation) {
      const attrId = variation?.item_attribute_id;
      if (attrId == null) return '';
      const attrs = Array.isArray(item?.itemAttributes)
        ? item.itemAttributes
        : Object.values(item?.itemAttributes || {});
      return attrs.find((attr) => String(attr?.id) === String(attrId))?.name || '';
    },
    composerChoiceEntries() {
      const groups = this.selections.composerChoices || {};
      const allowedKeys = this.currentComposerAllowedChoiceKeys();
      return Object.values(groups).flatMap((group) => {
        const choices = group?.choices || {};
        return Object.entries(choices)
          .filter(([choiceKey]) => allowedKeys.has(choiceKey))
          .map(([, choice]) => choice)
          .map((choice) => ({
            ...choice,
            step_label: group.label || group.step_key || '',
            count: parseInt(choice?.count || 0, 10) || 0,
          }))
          .filter((choice) => choice.count > 0);
      });
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
      if (key == null || key === '') return '';
      const item = this.resolvedItem;
      const strKey = String(key);
      if (strKey.startsWith('sauce-var-')) {
        const id = strKey.replace('sauce-var-', '');
        const v = item ? this.kioskFindSauceVariation(item, id) : null;
        if (v?.name) return v.name;
      }
      if (item) {
        const v = this.kioskFindSauceVariation(item, key);
        if (v?.name) return v.name;
      }
      const k = `kiosk.wizard.frites_sauce.${key}`;
      const t = this.$t(k);
      return t !== k ? t : strKey;
    },
    sanitizeItemName(name) {
      return sanitizeKioskCustomerFacingText(name || '');
    },
    // formatPrice() provided by kioskPriceMixin
    emitWizardStepEntered(stepType, idx = null) {
      if (!stepType) return;
      try {
        kioskAnalytics.track('wizard_step_entered', {
          item_id: this.resolvedItem?.id || null,
          step: stepType,
          step_index: idx !== null ? idx : this.currentStepIndex,
        });
      } catch (_) { /* silent */ }
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
    normalizeStepArg(stepOrType) {
      if (stepOrType && typeof stepOrType === 'object') return stepOrType;
      return { type: stepOrType };
    },
    getStepIcon(stepOrType) {
      const step = this.normalizeStepArg(stepOrType);
      const type = step.type;
      if (type === 'generic_choices') return '＋';
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
    getStepVisualImage(stepOrType) {
      const step = this.normalizeStepArg(stepOrType);
      const type = step.type;
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
    getStepLabel(stepOrType) {
      const step = this.normalizeStepArg(stepOrType);
      const type = step.type;
      if (type === 'generic_choices') return step.label || step.composer_step?.label || this.$t('kiosk.wizard.generic.step_fallback');
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
      return fallbacks[type] || step.label || type;
    },
    getQuestionLabel(stepOrType) {
      const step = this.normalizeStepArg(stepOrType);
      const type = step.type;
      if (type === 'generic_choices') return step.label || step.composer_step?.label || this.$t('kiosk.wizard.generic.step_fallback');
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
      return fallbacks[type] || step.label || type;
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
      // [P-MEGA-05] Si on était en édition, ANNULE l'édition (le store
      // restaure l'état pre-édition : la cart line originale est intacte
      // car on ne l'a JAMAIS supprimée à l'ouverture).
      if (this.$store?.getters?.['kioskCart/isEditingCart']) {
        this.$store.dispatch('kioskCart/cancelEditingCartItem');
      }
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
        composerChoices: {},
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
          // [P-MEGA-05] Restore après fetch + inférences (mode edit via /wizard/:id).
          this.restoreEditingSelectionsIfAny();
        } else {
          this.fetchError = this.$t('kiosk.wizard.product_not_found');
        }
      } catch (_) {
        this.fetchError = this.$t('kiosk.wizard.product_load_error');
      } finally {
        this.fetchLoading = false;
      }
    },
    // Kiosk Phase 9.1.3 — Déclenche un preview SSOT debounced. Silencieux en
    // cas d'erreur : `serverPreviewTotal` reste à sa dernière valeur connue
    // (ou `null`) et `runningTotal` retombe sur `runningTotalLocal`.
    refreshServerPreviewTotal() {
      if (!this._kioskPricingPreview) return;
      const cartItem = this.buildCartItem();
      if (!cartItem || !cartItem.item_id) return;

      this.serverPreviewLoading = true;
      this._kioskPricingPreview
        .request({
          items: [{
            item_id: cartItem.item_id,
            quantity: cartItem.quantity,
            instruction: cartItem.instruction || '',
            item_variations: (cartItem.item_variations || []).map((v) => ({
              id: v.id,
              ...(v.quantity ? { quantity: v.quantity } : {}),
            })),
            item_extras: (cartItem.item_extras || []).map((e) => ({
              id: e.id,
              ...(e.quantity ? { quantity: e.quantity } : {}),
            })),
            item_addons: (cartItem.item_addons || []).map((a) => ({
              id: a.id,
              ...(a.quantity ? { quantity: a.quantity } : {}),
            })),
          }],
        })
        .then((res) => {
          this.serverPreviewLoading = false;
          if (res && Number.isFinite(res.total)) {
            this.serverPreviewTotal = Math.round(res.total * 100) / 100;
          }
          // res === null : on garde le total précédent (UX > affichage 0,00).
        })
        .catch(() => {
          this.serverPreviewLoading = false;
        });
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

      // [AUDIT 2026-04-17 C3] Viande : la première variation choisie alimente
      // item_variations (mappage attribut). Les viandes-extras payantes sont
      // gérées plus bas via normalizedExtras (un extra par unité).
      const viandeMeta = this.selections._viandeMeta || [];
      const firstVariationViande = viandeMeta.find(v => v.source === 'variation' && typeof v.id === 'number');
      if (firstVariationViande && item.itemAttributes) {
        const viandeAttr = item.itemAttributes.find(a =>
          (a.name || '').toLowerCase().includes('viande')
        );
        if (viandeAttr && item.variations?.[viandeAttr.id]) {
          allVariations[viandeAttr.id] = firstVariationViande.id;
          allVariationNames[viandeAttr.name] = firstVariationViande.name;
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
      const normalizedAddons = [];
      let itemExtraTotal = 0;
      let composerVariationTotal = 0;
      let composerExtraTotal = 0;

      Object.keys(this.selections.garnitures).forEach(id => {
        if (this.selections.garnitures[id]) {
          const extra = item.extras?.find(e => e.id === parseInt(id));
          normalizedExtras.push({ id: parseInt(id), name: extra?.name || '' });
        }
      });

      Object.keys(this.selections.supplements).forEach(id => {
        const count = normalizeKioskSelectionCount(this.selections.supplements[id]);
        if (count <= 0) return;

        const extra = item.extras?.find(e => e.id === parseInt(id));
        if (!extra) return;

        // @pricing-allowed-block start
        // [C0/C1] UI cart subtotal uses precomputed additive surcharge for client display/print.
        // Backend remains SSOT: totals are recomputed/sealed server-side at submit and quote preview.
        // signed-off: Tech Lead / Backend owner — date: 2026-04-28
        const price = parseFloat(extra.convert_price || extra.price || 0);
        for (let i = 0; i < count; i++) {
          normalizedExtras.push({ id: parseInt(id), name: extra?.name || '' });
        }
        itemExtraTotal += price * count;
        // @pricing-allowed-block end
      });

      // [AUDIT 2026-04-17 C3] Viandes payantes (source='extra') : ajoutées
      // à item_extras avec quantité (1 entrée par unité) pour rester
      // compatible avec le serveur qui calcule le total à partir d'un tableau
      // d'IDs d'extras, et incluses dans item_extra_total côté client pour
      // cohérence avec calculateKioskRunningTotal.
      viandeMeta.forEach(v => {
        if (v.source !== 'extra' || typeof v.id !== 'number') return;
        const count = parseInt(v.count || 0, 10) || 0;
        // @pricing-allowed-block start
        // [C1] Viandes payantes: UI keeps surcharge preview from composer profile metadata.
        // Backend is authoritative for order quote/pricing persistence.
        // signed-off: Tech Lead / Backend owner — date: 2026-04-28
        const price = parseFloat(v.price || 0) || 0;
        for (let i = 0; i < count; i++) {
          normalizedExtras.push({ id: v.id, name: v.name || '' });
          itemExtraTotal += price;
        }
        // @pricing-allowed-block end
      });

      this.composerChoiceEntries().forEach((entry) => {
        const count = Math.max(1, parseInt(entry.count || 1, 10) || 1);
        if (entry.source_type === 'variation') {
          const variation = this.findItemVariationById(item, entry.id);
          if (!variation) return;
          const attrName = this.attributeNameForVariation(item, variation) || entry.attribute_name || entry.item_attribute_name || '';
          normalizedVariations.push({
            id: parseInt(entry.id, 10),
            variation_name: attrName,
            name: variation.name || entry.name || '',
            ...(count > 1 ? { quantity: count } : {}),
          });
          composerVariationTotal += (parseFloat(variation.convert_price || variation.price || 0) || 0) * count;
        }
        if (entry.source_type === 'extra') {
          const extra = this.findItemExtraById(item, entry.id);
          if (!extra) return;
          normalizedExtras.push({
            id: parseInt(entry.id, 10),
            name: extra.name || entry.name || '',
            ...(count > 1 ? { quantity: count } : {}),
          });
          composerExtraTotal += (parseFloat(extra.convert_price || extra.price || 0) || 0) * count;
        }
        if (entry.source_type === 'addon') {
          normalizedAddons.push({
            id: parseInt(entry.id, 10),
            name: entry.name || '',
            addon_item_id: entry.addon_item_id || null,
            role: entry.role || null,
            ...(count > 1 ? { quantity: count } : {}),
          });
        }
      });

      const boissonMeta = this.selections._boissonMeta || null;
      if (
        (this.selections.menuChoice === 'full' || this.selections.menuChoice === 'boisson') &&
        boissonMeta?.addonId
      ) {
        normalizedAddons.push({
          id: parseInt(boissonMeta.addonId, 10),
          name: boissonMeta.boissonName || '',
          addon_item_id: boissonMeta.boissonId || null,
          role: 'drink',
        });
      }

      itemExtraTotal += composerExtraTotal;

      // Sauce extra price — read from DB variations, fallback 0.50€
      const extraSauceCount = Math.max(0, this.selections.sauceOrder.length - 1);
      const sauceExtraPrice = this.kioskExtraSauceUnitPrice(item);
      const sauceVariationSurcharge = extraSauceCount * sauceExtraPrice;

      const fryPaid = (this.selections.fritesSauceOrder || []).filter(k => k && k !== 'sans');
      const extraFritesSauceCount = Math.max(0, fryPaid.length - 1);
      const fritesSauceSurcharge = extraFritesSauceCount * sauceExtraPrice;

      // Menu addon price — handle all choices (full / frites / boisson)
      const menuAddonPrice = getKioskMenuAddonPrice(item, this.selections.menuChoice);

      const itemVariationTotal = sauceVariationSurcharge + fritesSauceSurcharge + menuAddonPrice + composerVariationTotal;

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
        ...(normalizedAddons.length > 0 ? { item_addons: normalizedAddons } : {}),
        item_variation_total: parseFloat(itemVariationTotal.toFixed(2)),
        item_extra_total: parseFloat(itemExtraTotal.toFixed(2)),
        // [KIOSK-17] Pre-computed line total so KioskCartComponent and KioskConfirmationComponent
        // can display it directly without inline recalculation.
        total: lineTotal,
        instruction: this.buildInstruction(),
        // [P-MEGA-05] Snapshot complet des sélections pour permettre la
        // ré-édition fidèle (cart → wizard → modify → save). Ce champ est
        // strictement client-side : `sanitizeKioskOrderItem` (kioskCart.js)
        // ne sérialise PAS ce champ vers le serveur (vérifié par tests
        // `kioskWizardEditRoundtrip.spec.js`). Donc zéro impact backend, zéro
        // risque de fuite d'état d'UI vers /api/orders.
        _wizardSelections: JSON.parse(JSON.stringify(this.selections)),
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

      const composerGroups = {};
      this.composerChoiceEntries().forEach((entry) => {
        const label = entry.step_label || this.$t('kiosk.wizard.generic.step_fallback');
        if (!composerGroups[label]) composerGroups[label] = [];
        composerGroups[label].push(`${entry.name || entry.id}${entry.count > 1 ? ` x${entry.count}` : ''}`);
      });
      Object.entries(composerGroups).forEach(([label, values]) => {
        parts.push(`${label}: ${values.join(', ')}`);
      });

      const joined = parts.join('. ');
      const manualNote = sanitizeKioskCustomerFacingText(String(this.selections.instruction || '').trim()).slice(0, 190);
      const labeledNote = manualNote ? `${this.$t('label.note')}: ${manualNote}` : '';
      const payload = [joined, labeledNote].filter(Boolean).join('. ');
      if (!payload) return null;
      return sanitizeKioskCustomerFacingText(payload);
    },
    addToCart() {
      const cartItem = this.buildCartItem();
      if (this.onAddToCart) {
        this.onAddToCart(cartItem);
        if (this.onClose) this.onClose();
      } else {
        // [P-MEGA-05] Si on est en mode édition (cart → wizard), on REMPLACE
        // la ligne en place au lieu d'ajouter une nouvelle ligne. Le store
        // gère le fallback vers ADD_ITEM si l'édition a été annulée entre-
        // temps (race rare mais couverte → pas de cartItem perdu).
        if (this.$store?.getters?.['kioskCart/isEditingCart']) {
          this.$store.dispatch('kioskCart/replaceEditingCartItem', cartItem);
        } else {
          this.$store.dispatch('kioskCart/addItem', cartItem);
        }
        this.$router.go(-1);
      }
    },
    /**
     * [P-MEGA-05] Restaure les sélections depuis le snapshot store si le
     * wizard est ouvert en mode édition. Appelé après resetSelections() +
     * inférences de taille (l'ordre garantit que le snapshot écrase les
     * inférences, pas l'inverse).
     */
    restoreEditingSelectionsIfAny() {
      const snap = this.$store?.state?.kioskCart?.editingCartSnapshot;
      if (!snap) return;
      const item = this.resolvedItem;
      if (!item || Number(snap.item_id) !== Number(item.id)) return;
      if (snap._wizardSelections && typeof snap._wizardSelections === 'object') {
        this.selections = JSON.parse(JSON.stringify(snap._wizardSelections));
        this.selections.composerChoices = this.sanitizeComposerChoicesForCurrentProfile(this.selections.composerChoices);
      } else {
        if (typeof snap.quantity === 'number') this.selections.quantity = snap.quantity;
        if (typeof snap.instruction === 'string') this.selections.instruction = snap.instruction;
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
      // [P-MEGA-05] Restore APRÈS inférences pour qu'un snapshot d'édition
      // écrase les valeurs par défaut.
      this.restoreEditingSelectionsIfAny();
    } else if (this.itemId) {
      this.fetchItemById(this.itemId);
    }
    // Phase 8.8 — fire first wizard_step_entered event.
    this.$nextTick(() => this.emitWizardStepEntered(this.currentStep?.type));

    // Kiosk Phase 9.1.3 — Initialise le debouncer SSOT (non-reactif pour éviter
    // overhead Vue). `onError` silent en prod (fallback local prend le relais).
    this._kioskPricingPreview = createKioskPricingPreview({
      onError: (err) => {
        if (typeof console !== 'undefined' && typeof console.warn === 'function') {
          console.warn('[kiosk] pricing preview failed:', err && err.message ? err.message : err);
        }
      },
    });
    // Premier appel pour initialiser le total serveur dès que l'item est connu.
    this.$nextTick(() => this.refreshServerPreviewTotal());
  },
  beforeUnmount() {
    if (this._abandonDocKeydown) {
      document.removeEventListener('keydown', this._abandonDocKeydown, true);
      this._abandonDocKeydown = null;
    }
    // [P-MEGA-05] Migration Vue 2 → Vue 3 : `beforeDestroy` n'existe plus,
    // c'est `beforeUnmount`. Le hook précédent n'était JAMAIS appelé,
    // créant des leaks silencieux : pas de cancel d'édition orpheline,
    // pas de track wizard_abandoned, pas de destroy debouncer pricing.
    // Phase 8.8 — wizard closed without completion (global abandon).
    if (this.currentStep && this.currentStepIndex < (this.activeSteps?.length || 0) - 1) {
      try {
        kioskAnalytics.track('wizard_abandoned', {
          item_id: this.resolvedItem?.id || null,
          step: this.currentStep?.type || null,
          step_index: this.currentStepIndex,
        });
      } catch (_) { /* silent */ }
    }
    // Kiosk Phase 9.1.3 — libère le debouncer (cancel timer + axios).
    if (this._kioskPricingPreview && typeof this._kioskPricingPreview.destroy === 'function') {
      this._kioskPricingPreview.destroy();
      this._kioskPricingPreview = null;
    }
    // [P-MEGA-05] Guard idle/timeout : si le wizard est démonté sans avoir
    // été validé (ex : timeout idle, navigation programmatique externe),
    // on annule l'édition pour ne pas laisser l'état orphelin dans Vuex.
    // La cart line originale est intacte (jamais supprimée à l'ouverture).
    if (this.$store?.getters?.['kioskCart/isEditingCart']) {
      this.$store.dispatch('kioskCart/cancelEditingCartItem');
    }
  },
  watch: {
    showAbandonConfirm(open) {
      if (typeof document === 'undefined') return;
      if (this._abandonDocKeydown) {
        document.removeEventListener('keydown', this._abandonDocKeydown, true);
        this._abandonDocKeydown = null;
      }
      if (!open) return;
      this._abandonDocKeydown = (e) => {
        if (!this.showAbandonConfirm) return;
        if (e.key === 'Escape') {
          e.preventDefault();
          this.onAbandonCancel();
          return;
        }
        const root = this.$refs.abandonModalEl;
        if (!root || !root.contains(document.activeElement)) return;
        if (e.key !== 'Tab') return;
        const focusables = [...root.querySelectorAll(
          'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )];
        if (focusables.length === 0) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      };
      document.addEventListener('keydown', this._abandonDocKeydown, true);
      this.$nextTick(() => {
        const root = this.$refs.abandonModalEl;
        const firstBtn = root?.querySelector('button');
        firstBtn?.focus({ preventScroll: true });
      });
    },
    // Kiosk Phase 9.1.3 — tout changement de sélection retrigger le preview
    // SSOT (debounced 400 ms côté helper, donc pas de storm réseau). `deep`
    // nécessaire car selections.viandes/sauces/supplements sont des objets.
    selections: {
      deep: true,
      handler() {
        this.serverPreviewTotal = null;
        this.refreshServerPreviewTotal();
      },
    },
    // Changement d'item (edit mode, fetch par id) → reset du total serveur
    // pour éviter d'afficher un ancien total pendant que la nouvelle requête
    // est en vol.
    resolvedItem(newItem, oldItem) {
      if ((newItem && newItem.id) !== (oldItem && oldItem.id)) {
        this.serverPreviewTotal = null;
        this.refreshServerPreviewTotal();
      }
    },
    currentStepIndex(newIdx, oldIdx) {
      if (newIdx === oldIdx) return;
      const steps = this.activeSteps || [];
      const prev = steps[oldIdx];
      const next = steps[newIdx];
      if (prev && newIdx > oldIdx) {
        try {
          kioskAnalytics.track('wizard_step_completed', {
            item_id: this.resolvedItem?.id || null,
            step: prev.type,
            step_index: oldIdx,
          });
        } catch (_) { /* silent */ }
      } else if (prev && newIdx < oldIdx) {
        try {
          kioskAnalytics.track('wizard_step_abandoned', {
            item_id: this.resolvedItem?.id || null,
            step: prev.type,
            step_index: oldIdx,
            direction: 'back',
          });
        } catch (_) { /* silent */ }
      }
      if (next) this.emitWizardStepEntered(next.type, newIdx);
    },
  },
};
</script>

<style scoped>
.kiosk-wizard {
  --kiosk-bg: #FFFBF5;
  --kiosk-bg-2: #FFFFFF;
  --kiosk-bg-3: #FFF0F2;
  --kiosk-surface: #FFFFFF;
  --kiosk-surface-alt: #F7F3EC;
  --kiosk-surface-strong: #FFFFFF;
  --kiosk-border: #EEE6D9;
  --kiosk-border-strong: #D9C9B8;
  --kiosk-text: #1A1A1A;
  --kiosk-text-2: #3F3435;
  --kiosk-text-muted: #5A5A5A;
  --kiosk-text-mute: #7D7374;
  --kiosk-product-media-bg: radial-gradient(circle at 30% 22%, #FFFFFF, #F7F3EC 66%);
  --kiosk-shadow-card: 0 4px 18px rgba(20, 20, 20, 0.06);
  --kiosk-shadow-sticky: 0 -8px 24px rgba(0, 0, 0, 0.06);
  --kiosk-shadow-cta: 0 10px 24px rgba(232, 0, 28, 0.28);
  --kiosk-focus-ring: #2563EB;

  display: flex;
  flex-direction: column;
  height: 100vh;
  width: 100vw;
  background:
    linear-gradient(180deg, rgba(255, 249, 245, 0.98) 0%, rgba(255, 252, 247, 1) 48%, rgba(255, 248, 241, 1) 100%);
  color: var(--kiosk-text, #1a1a1a);
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
  background: var(--kiosk-page-bg, var(--kiosk-bg, #070304));
  color: var(--kiosk-text-muted, #777);
  font-size: 1.05rem;
}

.kiosk-wizard-spinner {
  width: 48px;
  height: 48px;
  border: 4px solid var(--kiosk-border, #e0e0e0);
  border-top-color: var(--kiosk-primary, #e8001c);
  border-radius: 50%;
  animation: kiosk-spin 0.9s linear infinite;
}

@keyframes kiosk-spin { to { transform: rotate(360deg); } }

.kiosk-wizard-header {
  position: relative;
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 76px;
  padding: 18px 76px 10px;
  background: var(--kiosk-surface, #fff);
  border-bottom: 1px solid rgba(238, 230, 217, 0.92);
  box-shadow: 0 10px 28px rgba(20, 20, 20, 0.05);
  flex-shrink: 0;
}

.kiosk-wizard-header::before {
  content: '';
  position: absolute;
  top: 8px;
  left: 28px;
  right: 28px;
  height: 3px;
  border-radius: 999px;
  background: var(--kiosk-primary, #e8001c);
  opacity: 0.9;
}

.kiosk-item-info {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}

.kiosk-item-info::before {
  content: 'Vous composez';
  font-size: 11px;
  font-weight: 900;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: var(--kiosk-text-muted, #8a807a);
}

/* Kiosk Phase 9.1.2 — Badge allergènes persistent dans header wizard. */
.kiosk-wizard-header-allergens {
  margin-top: 2px;
}

.kiosk-item-name {
  margin: 0;
  font-size: clamp(22px, 2.35vw, 32px);
  font-weight: 900;
  color: var(--kiosk-text, #1a1a1a);
  text-transform: uppercase;
  letter-spacing: 0;
  max-width: 780px;
  line-height: 1.05;
}

.kiosk-wizard-sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.kiosk-wizard-close {
  position: absolute;
  inset-inline-end: 18px;
  top: 50%;
  transform: translateY(-50%);
  min-width: 52px;
  min-height: 52px;
  border: 2px solid var(--kiosk-border, #eee6d9);
  border-radius: 50%;
  background: #fff;
  color: var(--kiosk-text-muted, #5a5a5a);
  font-size: 26px;
  line-height: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
}

.kiosk-wizard-close:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, #2563eb);
  outline-offset: 2px;
}

.kiosk-step-visuals {
  display: flex;
  gap: 14px;
  justify-content: center;
  align-items: flex-start;
  padding: 16px 20px 10px;
  overflow-x: auto;
  background: var(--kiosk-surface, #fff);
  scrollbar-width: none;
}

.kiosk-step-visuals::-webkit-scrollbar { display: none; }

.kiosk-step-visual {
  min-width: 104px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 7px;
  opacity: 0.72;
  transition: opacity 0.18s ease, transform 0.18s ease;
}

.kiosk-step-visual.active,
.kiosk-step-visual.done {
  opacity: 1;
}

.kiosk-step-visual.active {
  transform: translateY(-1px);
}

.kiosk-step-visual-icon {
  position: relative;
  width: 78px;
  height: 78px;
  border-radius: 50%;
  border: 3px solid var(--kiosk-border, #eee6d9);
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 30px;
  overflow: hidden;
  box-shadow: 0 4px 18px rgba(20, 20, 20, 0.06);
}

.kiosk-step-visual.active .kiosk-step-visual-icon {
  border-color: var(--kiosk-primary, #e8001c);
  box-shadow: 0 0 0 6px rgba(232, 0, 28, 0.08), 0 10px 26px rgba(232, 0, 28, 0.16);
}

.kiosk-step-visual.done .kiosk-step-visual-icon {
  border-color: var(--kiosk-primary, #e8001c);
  opacity: 0.78;
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
  font-size: 34px;
  background: var(--kiosk-product-media-bg, #f7f7f8);
}

.kiosk-step-visual-index {
  position: absolute;
  inset-inline-end: -2px;
  bottom: -2px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: var(--kiosk-primary, #e8001c);
  border: 2px solid #fff;
  color: #fff;
  font-size: 0;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-step-visual-index::before {
  content: '✓';
  font-size: 10px;
}

.kiosk-step-visual:not(.done) .kiosk-step-visual-index {
  display: none;
}

.kiosk-step-visual.active .kiosk-step-visual-index,
.kiosk-step-visual.done .kiosk-step-visual-index {
  border-color: var(--kiosk-primary, #e8001c);
  color: #fff;
}

.kiosk-step-visual-label {
  text-align: center;
  font-size: 11px;
  font-weight: 900;
  color: var(--kiosk-text-muted, #8a807a);
  text-transform: uppercase;
  line-height: 1.2;
  max-width: 98px;
  letter-spacing: 0.04em;
}

.kiosk-step-visual.active .kiosk-step-visual-label {
  color: var(--kiosk-primary, #e8001c);
}

.kiosk-progress-bar {
  display: grid;
  grid-template-columns: 48px 1fr 48px;
  align-items: center;
  gap: 14px;
  padding: 0 22px 14px;
  background: var(--kiosk-surface, #fff);
  border-bottom: 1px solid rgba(238, 230, 217, 0.92);
}

.kiosk-progress-arrow {
  min-width: 46px;
  min-height: 46px;
  width: 46px;
  height: 46px;
  border: 2px solid var(--kiosk-border, #eee6d9);
  border-radius: 50%;
  background: #fff;
  color: var(--kiosk-text, #1a1a1a);
  font-size: 28px;
  line-height: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
}

.kiosk-progress-arrow:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, #2563eb);
  outline-offset: 2px;
}

.kiosk-progress-arrow:disabled {
  opacity: 0.55;
}

.kiosk-progress-track {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-width: 0;
  max-width: 620px;
  justify-self: center;
  width: 100%;
}

.kiosk-step-dot {
  display: flex;
  align-items: center;
  flex: 1 1 0;
  min-width: 18px;
}

.kiosk-step-dot:not(:last-child)::after {
  display: none;
}

.kiosk-step-dot.done:not(:last-child)::after {
  display: none;
}

.kiosk-step-number {
  width: 100%;
  height: 8px;
  border-radius: 999px;
  border: 0;
  color: transparent;
  background: var(--kiosk-border, #eee6d9);
  display: block;
  font-size: 0;
  font-weight: 800;
}

.kiosk-step-dot.active .kiosk-step-number {
  background: var(--kiosk-primary, #e8001c);
  box-shadow: 0 0 0 4px rgba(232, 0, 28, 0.08);
}

.kiosk-step-dot.done .kiosk-step-number {
  background: var(--kiosk-primary, #e8001c);
  opacity: 0.85;
}

.kiosk-live-composition {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: 12px;
  min-height: 58px;
  padding: 8px 24px 10px;
  background: rgba(255, 255, 255, 0.94);
  border-bottom: 1px solid rgba(238, 230, 217, 0.86);
  box-shadow: 0 8px 22px rgba(20, 20, 20, 0.035);
  flex-shrink: 0;
}

.kiosk-live-composition-title {
  color: var(--kiosk-text-muted, #6f6762);
  font-size: 11px;
  font-weight: 950;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  white-space: nowrap;
}

.kiosk-live-composition-list {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  overflow-x: auto;
  scrollbar-width: none;
  -webkit-overflow-scrolling: touch;
}

.kiosk-live-composition-list::-webkit-scrollbar { display: none; }

.kiosk-live-composition-chip {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  max-width: 210px;
  min-height: 42px;
  padding: 5px 10px 5px 6px;
  border: 1px solid rgba(238, 230, 217, 0.95);
  border-radius: 999px;
  background: linear-gradient(180deg, #fff, #fffaf4);
  box-shadow: 0 4px 12px rgba(20, 20, 20, 0.045);
}

.kiosk-live-composition-chip.is-product {
  max-width: 250px;
  border-color: rgba(232, 0, 28, 0.18);
  background: linear-gradient(180deg, #fff, #fff5f6);
}

.kiosk-live-composition-thumb {
  width: 32px;
  height: 32px;
  flex: 0 0 32px;
  border-radius: 50%;
  overflow: hidden;
  background: var(--kiosk-product-media-bg, #f7f3ec);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.kiosk-live-composition-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.kiosk-live-composition-icon {
  font-size: 17px;
  line-height: 1;
}

.kiosk-live-composition-copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.kiosk-live-composition-chip-label {
  color: var(--kiosk-text-mute, #837a75);
  font-size: 9px;
  font-weight: 950;
  line-height: 1;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  white-space: nowrap;
}

.kiosk-live-composition-chip-value {
  color: var(--kiosk-text, #1a1a1a);
  font-size: 12px;
  font-weight: 900;
  line-height: 1.12;
  max-width: 156px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.kiosk-live-composition-empty {
  color: var(--kiosk-text-mute, #837a75);
  font-size: 12px;
  font-weight: 800;
  white-space: nowrap;
}

.kiosk-step-question {
  padding: 18px 24px 4px;
  text-align: center;
  font-size: clamp(20px, 2.1vw, 30px);
  font-weight: 900;
  color: var(--kiosk-text, #1a1a1a);
  text-transform: uppercase;
  letter-spacing: 0;
}

.kiosk-step-content {
  flex: 1;
  overflow-y: auto;
  background: transparent;
  scrollbar-width: none;
  padding: 0 8px 8px;
}

.kiosk-step-content::-webkit-scrollbar { display: none; }

.kiosk-note-block {
  margin: 0 18px 10px;
  padding: 10px 12px;
  border: 1px solid var(--kiosk-border, #e7e7e7);
  border-radius: 12px;
  background: #fff;
}

.kiosk-note-label {
  display: block;
  margin-bottom: 6px;
  color: var(--kiosk-text, #1a1a1a);
  font-size: 12px;
  font-weight: 800;
}

.kiosk-note-input {
  width: 100%;
  resize: vertical;
  min-height: 58px;
  border: 1px solid var(--kiosk-border, #dedede);
  border-radius: 10px;
  padding: 8px 10px;
  color: var(--kiosk-text, #1a1a1a);
  background: #fff;
  font-size: 13px;
  line-height: 1.35;
}

.kiosk-note-input:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, #2563eb);
  outline-offset: 1px;
}

.kiosk-note-hint {
  margin: 6px 0 0;
  color: var(--kiosk-text-muted, #7a7a7a);
  font-size: 11px;
  font-weight: 600;
}

.kiosk-nav {
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: center;
  gap: 12px;
  min-height: 84px;
  border-top: 1px solid var(--kiosk-border, #e7e7e7);
  background: var(--kiosk-surface, #f7f7f7);
  box-shadow: var(--kiosk-shadow-sticky, 0 -8px 24px rgba(0,0,0,0.08));
  flex-shrink: 0;
  padding: 10px 12px;
}

.kiosk-nav-actions {
  display: grid;
  grid-template-columns: 1.25fr 0.8fr 1fr;
  gap: 8px;
  align-items: stretch;
}

.kiosk-nav--recap .kiosk-nav-actions {
  grid-template-columns: 1fr 0.78fr 1.55fr;
}

.kiosk-btn-abandon,
.kiosk-btn-back,
.kiosk-btn-next {
  border: 1.5px solid var(--kiosk-border-strong, #e0b0b7);
  background: var(--kiosk-surface-alt, #fff);
  font-size: 12px;
  font-weight: 800;
  color: var(--kiosk-primary, #c33345);
  letter-spacing: 0;
  border-radius: var(--kiosk-btn-radius, 12px);
  min-height: 52px;
  padding: 0 14px;
}

.kiosk-btn-back {
  color: var(--kiosk-text-muted, #b54d5c);
  border-color: var(--kiosk-border, #e3c2c7);
}

.kiosk-btn-next {
  background: var(--kiosk-primary, #e8001c);
  color: var(--kiosk-text-on-red, #fff);
  border-color: var(--kiosk-primary, #e8001c);
  box-shadow: var(--kiosk-shadow-cta, 0 14px 30px rgba(232,0,28,0.28));
}

.kiosk-btn-next--cart {
  min-height: 58px;
  font-size: 14px;
  letter-spacing: 0.02em;
  box-shadow: 0 18px 38px rgba(232,0,28,0.34);
}

.kiosk-btn-abandon:focus-visible,
.kiosk-btn-back:focus-visible,
.kiosk-btn-next:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, #2563eb);
  outline-offset: 2px;
}

.kiosk-btn-next:disabled,
.kiosk-btn-back:disabled {
  opacity: 1;
  color: var(--kiosk-text-mute, #aa7d84);
  background: var(--kiosk-surface-alt, #f4e3e6);
  border-color: var(--kiosk-border, #ecd4d8);
  box-shadow: none;
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
  color: var(--kiosk-text, #6a4047);
  background: var(--kiosk-primary-soft, #f7e5e8);
  border-radius: var(--kiosk-btn-radius, 12px);
  border: 1px solid var(--kiosk-border, #efd2d7);
}

:deep(.kiosk-step-title) {
  display: none;
}

.step-slide-enter-active,
.step-slide-leave-active {
  transition:
    opacity 0.22s cubic-bezier(0.4, 0, 0.2, 1),
    transform 0.22s cubic-bezier(0.4, 0, 0.2, 1);
}

.step-slide-enter-from {
  opacity: 0;
  transform: translateX(30px);
}

.step-slide-leave-to {
  opacity: 0;
  transform: translateX(-30px);
}

[dir="rtl"] .step-slide-enter-from {
  opacity: 0;
  transform: translateX(-30px);
}

[dir="rtl"] .step-slide-leave-to {
  opacity: 0;
  transform: translateX(30px);
}

@media (prefers-reduced-motion: reduce) {
  .step-slide-enter-active,
  .step-slide-leave-active {
    transition: none !important;
  }
  .step-slide-enter-from,
  .step-slide-leave-to,
  [dir="rtl"] .step-slide-enter-from,
  [dir="rtl"] .step-slide-leave-to {
    opacity: 1 !important;
    transform: none !important;
  }
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
  background: var(--kiosk-overlay-modal, rgba(0, 0, 0, 0.45));
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.kiosk-wizard-abandon-modal {
  background: var(--kiosk-surface, #fff);
  border-radius: 20px;
  padding: 1.75rem 1.5rem;
  max-width: 400px;
  width: 100%;
  text-align: center;
  box-shadow: var(--kiosk-shadow-modal, 0 16px 48px rgba(0, 0, 0, 0.2));
  border: 1px solid var(--kiosk-border, #ececec);
}

.kiosk-wizard-abandon-title {
  margin: 0 0 0.5rem;
  font-size: 1.25rem;
  font-weight: 800;
  color: var(--kiosk-text, #1a1a1a);
}

.kiosk-wizard-abandon-sub {
  margin: 0 0 1.35rem;
  font-size: 0.95rem;
  color: var(--kiosk-text-muted, #777);
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
  background: var(--kiosk-primary, #e8001c);
  color: var(--kiosk-text-on-red, #fff);
  font-size: 1rem;
  font-weight: 800;
  cursor: pointer;
}

.kiosk-wizard-abandon-no {
  width: 100%;
  min-height: 50px;
  border: 1.5px solid var(--kiosk-border, #e0e0e0);
  border-radius: 12px;
  background: var(--kiosk-surface-alt, #f7f7f8);
  color: var(--kiosk-text, #444);
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
}
</style>
