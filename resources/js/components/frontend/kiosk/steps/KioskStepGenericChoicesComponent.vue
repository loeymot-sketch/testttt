<template>
  <section class="kiosk-step-generic">
    <!-- [GOAL-WIZARD-BEST B5 2026-06-10] The step label is already rendered by the
         frozen wizard banner (KioskWizardComponent:1584). The local <h3> now carries
         the min/max guidance instead of duplicating the label. -->
    <h3 class="kiosk-step-title" data-testid="kiosk-generic-guidance">{{ stepGuidance }}</h3>

    <!-- [B2] Live "selected / max" counter, visible on multi-select steps. -->
    <div
      v-if="maxSelect > 1"
      class="kiosk-generic-progress"
      role="status"
      aria-live="polite"
    >
      <span
        :key="selectedTotal"
        class="kiosk-generic-counter"
        data-testid="kiosk-generic-counter"
      >{{ selectedTotal }} / {{ maxSelect }}</span>
    </div>

    <p v-if="showValidationHint" class="kiosk-validation-hint" role="status" aria-live="polite">
      {{ validationHint }}
    </p>

    <div
      class="kiosk-generic-grid"
      :class="{ 'kiosk-generic-grid--media': mediaMode }"
      role="group"
      :aria-label="stepLabel"
    >
      <div
        v-for="choice in availableChoices"
        :key="choiceKey(choice)"
        class="kiosk-generic-cell"
      >
        <button
          type="button"
          class="kiosk-generic-choice"
          :class="{
            selected: selectedCount(choice) > 0,
            unavailable: !isChoiceAvailable(choice),
            'kiosk-generic-choice--media': mediaMode,
            'has-stepper': showStepper(choice),
          }"
          :disabled="!isChoiceAvailable(choice)"
          :aria-pressed="selectedCount(choice) > 0 ? 'true' : 'false'"
          @click="toggleChoice(choice)"
        >
          <!-- [Wizard builder W6] Per-option photo (from the bound catalog construct,
               projected in W2). Box pages + any builder-authored generic page now
               show real images instead of plain text. -->
          <img
            v-if="choiceImage(choice)"
            :src="choiceImage(choice)"
            alt=""
            class="kiosk-generic-choice-img"
            loading="lazy"
          />
          <span class="kiosk-generic-choice-body">
            <span class="kiosk-generic-choice-name">{{ choice.name || choice.label || choice.id }}</span>
            <span v-if="choice.description" class="kiosk-generic-choice-desc">{{ choice.description }}</span>
            <!-- [B4] Explicit out-of-stock badge (greyout alone was ambiguous). -->
            <span
              v-if="!isChoiceAvailable(choice)"
              class="kiosk-generic-oos-badge"
              data-testid="kiosk-generic-oos-badge"
            >{{ oosLabel }}</span>
            <!-- [B1] Client-side price join (id × source_type → item catalog).
                 Display only — backend PricingService stays the pricing SSOT. -->
            <span
              class="kiosk-generic-choice-price"
              :class="{ 'is-included': choicePrice(choice) <= 0 }"
              data-testid="kiosk-generic-choice-price"
            >{{ choicePriceLabel(choice) }}</span>
          </span>
          <!-- [B8] Single-select steps show a ✓ checkmark (pattern KioskStepTaille). -->
          <span
            v-if="maxSelect === 1 && selectedCount(choice) > 0"
            class="kiosk-generic-choice-check"
            data-testid="kiosk-generic-choice-check"
            aria-hidden="true"
          >✓</span>
          <span
            v-else-if="selectedCount(choice) > 0 && !showStepper(choice)"
            class="kiosk-generic-choice-count"
          >
            x{{ selectedCount(choice) }}
          </span>
          <!-- [rush-100 WA-R1-03/04 heal 2026-05-13] Visible "+" affordance so
               customers can tell composer-step cards (Frites/Riz, Nature/Cheddar)
               are tappable. Was a P1 affordance gap — cards previously rendered
               as plain text rectangles. -->
          <span
            v-if="selectedCount(choice) === 0"
            class="kiosk-generic-choice-add"
            aria-hidden="true"
          >+</span>
        </button>
        <!-- [B3] −/+ stepper for repeatable choices (pattern Supplements). Rendered
             as a SIBLING of the card button (never nested — invalid HTML). The −
             button decrements by 1, fixing the wipe-at-max behaviour for fine
             quantity control. -->
        <div
          v-if="showStepper(choice)"
          class="kiosk-generic-qty"
          role="group"
          :aria-label="qtyGroupLabel(choice)"
        >
          <button
            type="button"
            class="kiosk-generic-qty-btn"
            data-testid="kiosk-generic-qty-minus"
            :disabled="selectedCount(choice) <= 0"
            :aria-label="`− ${choiceName(choice)}`"
            @click="decrementChoice(choice)"
          >−</button>
          <span class="kiosk-generic-qty-value" aria-live="polite">{{ selectedCount(choice) }}</span>
          <button
            type="button"
            class="kiosk-generic-qty-btn active"
            data-testid="kiosk-generic-qty-plus"
            :disabled="atMaxTotal"
            :aria-label="`+ ${choiceName(choice)}`"
            @click="incrementChoice(choice)"
          >+</button>
        </div>
      </div>
    </div>
  </section>
</template>

<script>
import { kioskResolveImageSrc } from '../../../../helpers/kioskMedia';
import { kioskPriceMixin } from '../../../../helpers/kioskFormatPrice';

export default {
  name: 'KioskStepGenericChoicesComponent',
  mixins: [kioskPriceMixin],
  props: {
    step: {
      type: Object,
      required: true,
    },
    selections: {
      type: Object,
      required: true,
    },
    // [B1] Catalog item (bound by the frozen wizard via wizardStepBindings —
    // KioskWizardComponent:724). Optional: the component stays functional
    // without it (prices then render as "Inclus").
    item: {
      type: Object,
      default: null,
    },
  },
  emits: ['update'],
  computed: {
    composerStep() {
      return this.step?.composer_step || this.step || {};
    },
    stepId() {
      return String(this.composerStep.id || this.composerStep.step_key || this.composerStep.label || 'generic');
    },
    stepLabel() {
      return this.composerStep.label || this.step?.label || this.fallbackText('kiosk.wizard.generic.step_fallback', 'Choisissez vos options');
    },
    minSelect() {
      return Math.max(0, parseInt(this.composerStep.min_select ?? 0, 10) || 0);
    },
    maxSelect() {
      return Math.max(0, parseInt(this.composerStep.max_select ?? 0, 10) || 0);
    },
    allowRepeat() {
      return this.composerStep.allow_repeat === true;
    },
    availableChoices() {
      return (Array.isArray(this.composerStep.choices) ? this.composerStep.choices : [])
        .filter((choice) => choice && choice.id !== undefined && choice.id !== null);
    },
    currentGroup() {
      return (this.selections.composerChoices || {})[this.stepId] || {
        step_id: this.composerStep.id || null,
        step_key: this.composerStep.step_key || null,
        label: this.stepLabel,
        source_type: this.composerStep.source_type || null,
        choices: {},
      };
    },
    selectedTotal() {
      return Object.values(this.currentGroup.choices || {}).reduce((sum, choice) => {
        return sum + (parseInt(choice?.count || 0, 10) || 0);
      }, 0);
    },
    atMaxTotal() {
      return this.maxSelect > 0 && this.selectedTotal >= this.maxSelect;
    },
    showValidationHint() {
      return this.minSelect > 0 && this.selectedTotal < this.minSelect;
    },
    validationHint() {
      return this.fallbackText('kiosk.wizard.generic.min_hint', `Minimum ${this.minSelect} choix`);
    },
    // [B5/B2] Min/max guidance shown instead of the duplicated step label.
    stepGuidance() {
      const min = this.minSelect;
      const max = this.maxSelect;
      if (max === 1) {
        return min > 0 ? 'Choisissez 1 option' : 'Choisissez 1 option (facultatif)';
      }
      if (max > 1) {
        if (min > 0 && min === max) return `Choisissez ${max} options`;
        if (min > 0) return `Choisissez entre ${min} et ${max} options`;
        return `Choisissez jusqu'à ${max} options`;
      }
      if (min > 0) return `Choisissez au moins ${min} option${min > 1 ? 's' : ''}`;
      return this.fallbackText('kiosk.wizard.generic.step_fallback', 'Choisissez vos options');
    },
    oosLabel() {
      return this.fallbackText('pos.item_86_d', 'Épuisé');
    },
    includedLabel() {
      return this.fallbackText('kiosk.wizard.summary.included', 'Inclus');
    },
    // [B6] Media mode: when every visible choice carries an image, switch to a
    // vertical-card photo grid (3-4 columns).
    mediaMode() {
      return this.availableChoices.length > 0
        && this.availableChoices.every((choice) => !!this.choiceImage(choice));
    },
    // [B1] price map keyed by choiceKey — joined from the catalog item
    // (projection is price-free per NF525 contract, display join only).
    choicePriceByKey() {
      const map = {};
      this.availableChoices.forEach((choice) => {
        map[this.choiceKey(choice)] = this.resolveChoicePrice(choice);
      });
      return map;
    },
  },
  methods: {
    fallbackText(key, fallback) {
      const translated = this.$t ? this.$t(key, { min: this.minSelect }) : key;
      return translated !== key ? translated : fallback;
    },
    choiceImage(choice) {
      return kioskResolveImageSrc(choice);
    },
    choiceKey(choice) {
      return `${choice.source_type || this.composerStep.source_type || 'choice'}:${choice.id}`;
    },
    choiceName(choice) {
      return choice.name || choice.label || String(choice.id);
    },
    qtyGroupLabel(choice) {
      return `${this.choiceName(choice)} — ${this.fallbackText('kiosk.wizard.generic.qty', 'quantité')}`;
    },
    // ——— [B1] price join helpers (mirror the frozen wizard's catalog lookups:
    // allItemVariationRows / findItemExtraById / getKioskMenuAddonPrice shapes).
    allItemVariationRows() {
      const raw = this.item?.variations || {};
      if (Array.isArray(raw)) return raw;
      return Object.values(raw).flatMap((rows) => {
        if (Array.isArray(rows)) return rows;
        if (rows && typeof rows === 'object') return Object.values(rows);
        return [];
      });
    },
    resolveChoicePrice(choice) {
      if (!choice || !this.item) return 0;
      const sourceType = choice.source_type || this.composerStep.source_type || '';
      if (sourceType === 'variation') {
        const row = this.allItemVariationRows().find((r) => String(r?.id) === String(choice.id));
        return row ? (parseFloat(row.convert_price ?? row.price ?? 0) || 0) : 0;
      }
      if (sourceType === 'extra') {
        const row = (this.item.extras || []).find((r) => String(r?.id) === String(choice.id));
        return row ? (parseFloat(row.convert_price ?? row.price ?? 0) || 0) : 0;
      }
      if (sourceType === 'addon') {
        const rows = this.item.addons || [];
        const row = rows.find((r) => String(r?.id) === String(choice.id))
          || (choice.addon_item_id != null
            ? rows.find((r) => String(r?.addon_item_id ?? r?.item_addon_id) === String(choice.addon_item_id))
            : null);
        if (!row) return 0;
        const price = row.addon_item_convert_price
          ?? row.addon_item_currency_price
          ?? row.price
          ?? row.convert_price
          ?? 0;
        return parseFloat(price) || 0;
      }
      return 0;
    },
    choicePrice(choice) {
      return this.choicePriceByKey[this.choiceKey(choice)] || 0;
    },
    choicePriceLabel(choice) {
      const price = this.choicePrice(choice);
      if (price > 0) return `+${this.formatPrice(price)}`;
      return this.includedLabel;
    },
    isChoiceAvailable(choice) {
      if (choice && Object.prototype.hasOwnProperty.call(choice, 'is_available') && choice.is_available === false) {
        return false;
      }
      const status = Number(choice.status);
      return status !== 0 && status !== 2 && status !== 10;
    },
    selectedCount(choice) {
      return parseInt(this.currentGroup.choices?.[this.choiceKey(choice)]?.count || 0, 10) || 0;
    },
    showStepper(choice) {
      return this.allowRepeat && this.selectedCount(choice) > 0 && this.isChoiceAvailable(choice);
    },
    emitChoices(nextChoices) {
      const all = { ...(this.selections.composerChoices || {}) };
      all[this.stepId] = {
        step_id: this.composerStep.id || null,
        step_key: this.composerStep.step_key || null,
        label: this.stepLabel,
        source_type: this.composerStep.source_type || null,
        choices: nextChoices,
      };
      this.$emit('update', 'composerChoices', all);
    },
    normalizeChoice(choice, count) {
      return {
        id: choice.id,
        name: choice.name || choice.label || '',
        source_type: choice.source_type,
        item_attribute_id: choice.item_attribute_id || null,
        addon_item_id: choice.addon_item_id || null,
        role: choice.role || null,
        count,
      };
    },
    toggleChoice(choice) {
      if (!this.isChoiceAvailable(choice)) return;
      const key = this.choiceKey(choice);
      const current = this.selectedCount(choice);
      const nextChoices = { ...(this.currentGroup.choices || {}) };

      if (this.maxSelect === 1 && !this.allowRepeat) {
        if (current > 0 && this.minSelect === 0) {
          delete nextChoices[key];
        } else {
          Object.keys(nextChoices).forEach((choiceKey) => delete nextChoices[choiceKey]);
          nextChoices[key] = this.normalizeChoice(choice, 1);
        }
        this.emitChoices(nextChoices);
        return;
      }

      if (current > 0 && !this.allowRepeat) {
        if (this.selectedTotal > this.minSelect) delete nextChoices[key];
        this.emitChoices(nextChoices);
        return;
      }

      if (this.maxSelect > 0 && this.selectedTotal >= this.maxSelect) {
        if (current > 0 && this.allowRepeat) delete nextChoices[key];
        this.emitChoices(nextChoices);
        return;
      }

      nextChoices[key] = this.normalizeChoice(choice, current + 1);
      this.emitChoices(nextChoices);
    },
    // [B3] Stepper handlers — decrement by exactly 1 (no wipe), increment
    // capped by maxSelect.
    decrementChoice(choice) {
      const key = this.choiceKey(choice);
      const current = this.selectedCount(choice);
      if (current <= 0) return;
      const nextChoices = { ...(this.currentGroup.choices || {}) };
      if (current - 1 <= 0) {
        delete nextChoices[key];
      } else {
        nextChoices[key] = this.normalizeChoice(choice, current - 1);
      }
      this.emitChoices(nextChoices);
    },
    incrementChoice(choice) {
      if (!this.isChoiceAvailable(choice)) return;
      if (this.atMaxTotal) return;
      const key = this.choiceKey(choice);
      const current = this.selectedCount(choice);
      const nextChoices = { ...(this.currentGroup.choices || {}) };
      nextChoices[key] = this.normalizeChoice(choice, current + 1);
      this.emitChoices(nextChoices);
    },
  },
};
</script>

<style scoped>
.kiosk-step-generic {
  padding: 8px 18px 28px;
  /* [BU-01 2026-06-10 heal] centrage vertical du canvas portrait — ce step (rendu
     par item 33 + tout wizard CMS retombant sur generic_choices) avait été omis du
     lot BU-01 initial : le vide était dumpé en bas. Même pattern que les steps nommés. */
  min-height: 100%;
  display: flex;
  flex-direction: column;
}

.kiosk-step-title {
  margin: 0 0 10px;
  text-align: center;
  font-size: 16px;
  font-weight: 700;
  color: var(--kiosk-text, #1a1a1a);
}

/* [B2] selected/max counter */
.kiosk-generic-progress {
  display: flex;
  justify-content: center;
  margin: 0 0 12px;
}

.kiosk-generic-counter {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 64px;
  padding: 6px 16px;
  border-radius: 999px;
  background: var(--kiosk-primary-soft, rgba(244, 80, 30, 0.08));
  border: 1px solid var(--kiosk-border, rgba(244, 80, 30, 0.2));
  color: var(--kiosk-primary, #f4501e);
  font-size: 14px;
  font-weight: 800;
  /* [B7] pop on update — the :key swap re-creates the node so the animation replays */
  animation: kiosk-generic-counter-pop 0.15s ease;
}

@keyframes kiosk-generic-counter-pop {
  0% { transform: scale(1); }
  50% { transform: scale(1.12); }
  100% { transform: scale(1); }
}

.kiosk-validation-hint {
  margin: 0 auto 14px;
  max-width: 520px;
  border: 1px solid rgba(244, 80, 30, 0.18);
  border-radius: 8px;
  background: rgba(244, 80, 30, 0.06);
  color: var(--kiosk-primary, #f4501e);
  padding: 10px 14px;
  font-size: 13px;
  font-weight: 700;
  text-align: center;
}

.kiosk-generic-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  /* [BU-01 2026-06-10 heal] centre la grille dans le canvas flex-column ; margin
     auto retombe à 0 quand le contenu est haut (longues listes scrollent depuis le haut). */
  margin: auto;
  align-content: center;
  width: 100%;
}

/* [B6] photo grid when every choice carries an image — 3-4 columns */
.kiosk-generic-grid--media {
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 16px;
}

.kiosk-generic-cell {
  position: relative;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

/* [BU-01 2026-06-10] Slightly larger, more readable choice cards.
   [W-INT 2026-06-12] union §0.4 : classes B6 cms (--media, cell) conservées,
   .kiosk-generic-choice aux valeurs spine BU-01 (84px / 2px / 10px) + flex
   cms requis dans .kiosk-generic-cell. */
.kiosk-generic-choice {
  flex: 1 1 auto;
  min-height: 84px;
  border: 2px solid var(--kiosk-border, #eae2d4);
  border-radius: 10px;
  background: var(--kiosk-surface, #fff);
  color: var(--kiosk-text, #1a1a1a);
  padding: 12px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  text-align: left;
  font: inherit;
  font-weight: 700;
  box-shadow: var(--kiosk-shadow-card, 0 4px 18px rgba(20, 20, 20, 0.06));
  /* [B7] micro-animations on selection */
  transition: border-color 0.15s ease, background-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
}

.kiosk-generic-choice:active {
  transform: scale(0.98);
}

/* [BU-02 2026-06-10] Clear selected affordance — solid brand border + ring. */
.kiosk-generic-choice.selected {
  border-color: var(--kiosk-primary, #f4501e);
  background: rgba(244, 80, 30, 0.08);
  box-shadow: 0 0 0 3px rgba(244, 80, 30, 0.16);
}

.kiosk-generic-choice.unavailable {
  opacity: 0.45;
  filter: grayscale(0.7);
}

/* [B3] reserve room for the overlapping stepper pill */
.kiosk-generic-choice.has-stepper {
  padding-bottom: 38px;
}

.kiosk-generic-choice-img {
  width: 48px;
  height: 48px;
  flex: 0 0 auto;
  border-radius: 8px;
  object-fit: cover;
  background: var(--kiosk-surface-2, #f7f2ea);
}

/* [B6] vertical media card */
.kiosk-generic-choice--media {
  flex-direction: column;
  align-items: stretch;
  justify-content: flex-start;
  text-align: center;
  padding: 12px 12px 14px;
  border-radius: 14px;
  position: relative;
}

.kiosk-generic-choice--media.has-stepper {
  padding-bottom: 40px;
}

.kiosk-generic-choice--media .kiosk-generic-choice-img {
  width: 100%;
  height: 128px;
  min-height: 120px;
  border-radius: 10px;
  margin-bottom: 8px;
}

.kiosk-generic-choice--media .kiosk-generic-choice-body {
  align-items: center;
  text-align: center;
}

.kiosk-generic-choice--media .kiosk-generic-choice-count,
.kiosk-generic-choice--media .kiosk-generic-choice-add,
.kiosk-generic-choice--media .kiosk-generic-choice-check {
  position: absolute;
  top: 10px;
  right: 10px;
}

.kiosk-generic-choice-body {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1 1 auto;
  min-width: 0;
}

.kiosk-generic-choice-name {
  overflow-wrap: anywhere;
}

.kiosk-generic-choice-desc {
  font-size: 11px;
  font-weight: 500;
  color: var(--kiosk-text-muted, #7a7a7a);
  overflow-wrap: anywhere;
  /* [B9] clamp to 2 lines */
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* [B1] price tag */
.kiosk-generic-choice-price {
  margin-top: 2px;
  font-size: 12px;
  font-weight: 800;
  color: var(--kiosk-primary, #f4501e);
}

.kiosk-generic-choice-price.is-included {
  color: var(--kiosk-text-muted, #7a7a7a);
  font-weight: 600;
}

/* [B4] out-of-stock badge (pattern kiosk-extra-oos-badge) */
.kiosk-generic-oos-badge {
  display: inline-block;
  align-self: flex-start;
  margin-top: 2px;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--kiosk-primary-soft, rgba(244, 80, 30, 0.1));
  color: var(--kiosk-primary, #f4501e);
  border: 1px solid var(--kiosk-border, rgba(244, 80, 30, 0.25));
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.kiosk-generic-choice--media .kiosk-generic-oos-badge {
  align-self: center;
}

.kiosk-generic-choice-count {
  min-width: 34px;
  border-radius: 999px;
  background: var(--kiosk-primary, #f4501e);
  color: #fff;
  padding: 4px 8px;
  text-align: center;
  font-size: 12px;
  line-height: 1;
}

/* [B8] single-select checkmark (pattern kiosk-taille-action) */
.kiosk-generic-choice-check {
  min-width: 34px;
  height: 34px;
  border-radius: 999px;
  background: var(--kiosk-primary, #f4501e);
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  font-weight: 800;
  line-height: 1;
  box-shadow: 0 3px 10px rgba(244, 80, 30, 0.2);
}

/* [rush-100 WA-R1-03/04 heal 2026-05-13] "+" badge affordance on unselected
   composer choice cards. Mirrors the .kiosk-product-card-add pattern used on
   meat-step cards so customers can tell they are tappable. */
.kiosk-generic-choice-add {
  min-width: 34px;
  height: 34px;
  border-radius: 999px;
  background: var(--kiosk-primary, #f4501e);
  color: #fff;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  line-height: 1;
  font-weight: 700;
}

/* [B3] −/+ stepper pill, overlapping the card bottom (sibling of the button —
   valid HTML, no nested interactive content). Buttons ≥44px touch targets. */
.kiosk-generic-qty {
  position: absolute;
  bottom: -8px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 4px;
  border-radius: 999px;
  background: var(--kiosk-surface-alt, #f5f5f6);
  border: 1px solid var(--kiosk-border, #ececec);
  box-shadow: 0 4px 12px rgba(20, 20, 20, 0.08);
}

.kiosk-generic-qty-btn {
  width: 44px;
  height: 44px;
  border-radius: 999px;
  border: 1px solid var(--kiosk-border-strong, #d8d8d8);
  background: var(--kiosk-surface, #fff);
  color: var(--kiosk-text-muted, #777);
  font-size: 22px;
  line-height: 1;
  font-weight: 800;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  touch-action: manipulation;
}

.kiosk-generic-qty-btn.active {
  border-color: var(--kiosk-primary, #f4501e);
  background: var(--kiosk-primary, #f4501e);
  color: var(--kiosk-text-on-red, #fff);
  box-shadow: var(--kiosk-shadow-cta, 0 10px 20px rgba(244, 80, 30, 0.24));
}

.kiosk-generic-qty-btn:disabled {
  opacity: 0.46;
  cursor: not-allowed;
}

.kiosk-generic-qty-btn:focus-visible {
  outline: 3px solid rgba(244, 80, 30, 0.55);
  outline-offset: 2px;
}

.kiosk-generic-qty-value {
  min-width: 28px;
  text-align: center;
  font-size: 18px;
  font-weight: 900;
  color: var(--kiosk-text, #222);
}
</style>
