<template>
  <section class="kiosk-step-generic">
    <h3 class="kiosk-step-title">{{ stepLabel }}</h3>

    <p v-if="showValidationHint" class="kiosk-validation-hint">
      {{ validationHint }}
    </p>

    <div class="kiosk-generic-grid" role="group" :aria-label="stepLabel">
      <button
        v-for="choice in availableChoices"
        :key="choiceKey(choice)"
        type="button"
        class="kiosk-generic-choice"
        :class="{ selected: selectedCount(choice) > 0, unavailable: !isChoiceAvailable(choice) }"
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
        </span>
        <span v-if="selectedCount(choice) > 0" class="kiosk-generic-choice-count">
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
    </div>
  </section>
</template>

<script>
import { kioskResolveImageSrc } from '../../../../helpers/kioskMedia';

export default {
  name: 'KioskStepGenericChoicesComponent',
  props: {
    step: {
      type: Object,
      required: true,
    },
    selections: {
      type: Object,
      required: true,
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
    showValidationHint() {
      return this.minSelect > 0 && this.selectedTotal < this.minSelect;
    },
    validationHint() {
      return this.fallbackText('kiosk.wizard.generic.min_hint', `Minimum ${this.minSelect} choix`);
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
  margin: 0 0 14px;
  text-align: center;
  font-size: 16px;
  font-weight: 700;
  color: var(--kiosk-text, #1a1a1a);
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

/* [BU-01 2026-06-10] Slightly larger, more readable choice cards. */
.kiosk-generic-choice {
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

.kiosk-generic-choice-img {
  width: 48px;
  height: 48px;
  flex: 0 0 auto;
  border-radius: 8px;
  object-fit: cover;
  background: var(--kiosk-surface-2, #f7f2ea);
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
</style>
