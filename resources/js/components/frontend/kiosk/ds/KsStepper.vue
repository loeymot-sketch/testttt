<template>
  <nav
    class="ks-stepper"
    role="progressbar"
    :aria-valuemin="1"
    :aria-valuemax="steps.length"
    :aria-valuenow="current + 1"
    :aria-valuetext="currentAriaText"
    :aria-label="ariaLabel"
  >
    <!-- Rangée icônes + labels (fenêtre glissante 5 éléments autour du current) -->
    <ol class="ks-stepper__icons">
      <li
        v-for="(s, i) in visibleSteps"
        :key="s.absIdx"
        :class="[
          'ks-stepper__icon-item',
          {
            'is-active': s.absIdx === current,
            'is-done': s.absIdx < current,
          }
        ]"
        :aria-current="s.absIdx === current ? 'step' : null"
      >
        <span class="ks-stepper__icon" aria-hidden="true">
          <span class="ks-stepper__icon-glyph">{{ s.icon || '•' }}</span>
          <span v-if="s.absIdx < current" class="ks-stepper__check">✓</span>
        </span>
        <span class="ks-stepper__label">{{ s.label }}</span>
      </li>
    </ol>

    <!-- Rangée numérique segmentée (max 7 points visibles) -->
    <ol
      v-if="showNumeric"
      class="ks-stepper__numeric"
      aria-hidden="true"
    >
      <template v-for="(absIdx, localIdx) in numericWindow" :key="absIdx">
        <li
          :class="[
            'ks-stepper__dot',
            {
              'is-active': absIdx === current,
              'is-done': absIdx < current,
            }
          ]"
        >{{ absIdx + 1 }}</li>
        <span
          v-if="localIdx < numericWindow.length - 1"
          class="ks-stepper__link"
          :class="{ 'is-done': absIdx < current }"
        />
      </template>
      <li v-if="steps.length > 7" class="ks-stepper__counter">
        {{ current + 1 }}/{{ steps.length }}
      </li>
    </ol>
  </nav>
</template>

<script>
/**
 * KsStepper — Indicateur de progression wizard (aligné FkProgress React).
 *
 * Props :
 *  - steps   : [{ id, label, icon }] — ordre linéaire
 *  - current : index numérique de l'étape active
 *  - ariaLabel : label global (par défaut 'Progression')
 *
 * Fenêtres :
 *  - icons  : 5 éléments visibles max, glissants autour du current
 *  - numeric: 7 points visibles max (désactivable via prop numeric=false)
 *
 * Aucun event émis — composant purement présentationnel.
 */
export default {
    name: 'KsStepper',
    props: {
        steps: {
            type: Array,
            required: true,
            validator: (arr) => Array.isArray(arr) && arr.every((s) => s && typeof s.label === 'string'),
        },
        current: { type: Number, default: 0 },
        numeric: { type: Boolean, default: true },
        ariaLabel: { type: String, default: 'Progression de la commande' },
        /**
         * Hook i18n : ({ current: Number, total: Number, label: String }) => String.
         * Laissé en français par défaut pour compat Phase 0. À surcharger en
         * Phase 4 depuis le store i18n (fr/en/ar).
         */
        formatAriaValueText: {
            type: Function,
            default: ({ current, total, label }) => `Étape ${current} sur ${total} : ${label}`,
        },
    },
    computed: {
        clampedCurrent() {
            if (!this.steps.length) return 0;
            return Math.max(0, Math.min(this.current, this.steps.length - 1));
        },
        visibleSteps() {
            const n = this.steps.length;
            const windowSize = Math.min(n, 5);
            let end = Math.min(n, this.clampedCurrent + 3);
            let start = Math.max(0, end - windowSize);
            end = Math.min(n, start + windowSize);
            start = Math.max(0, end - windowSize);
            return this.steps.slice(start, end).map((s, i) => ({
                ...s,
                absIdx: start + i,
            }));
        },
        numericWindow() {
            const n = this.steps.length;
            const max = 7;
            let segStart = 0;
            let segEnd = n;
            if (n > max) {
                segStart = Math.max(0, this.clampedCurrent - 3);
                segEnd = Math.min(n, segStart + max);
                segStart = Math.max(0, segEnd - max);
            }
            const arr = [];
            for (let i = segStart; i < segEnd; i++) arr.push(i);
            return arr;
        },
        showNumeric() { return this.numeric && this.steps.length > 1; },
        currentAriaText() {
            const s = this.steps[this.clampedCurrent];
            if (!s) return '';
            return this.formatAriaValueText({
                current: this.clampedCurrent + 1,
                total: this.steps.length,
                label: s.label,
            });
        },
    },
};
</script>

<style scoped>
.ks-stepper {
    padding: var(--kiosk-space-6) var(--kiosk-space-8) var(--kiosk-space-8);
    background: var(--kiosk-surface);
    border-bottom: 1px solid var(--kiosk-border);
}

/* ---------- Icons row ---------- */
.ks-stepper__icons {
    list-style: none;
    margin: 0 0 var(--kiosk-space-4);
    padding: 0;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--kiosk-space-3);
}

.ks-stepper__icon-item {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--kiosk-space-2);
    min-width: 0;
}

.ks-stepper__icon {
    position: relative;
    width: 84px;
    height: 84px;
    border-radius: 50%;
    background: var(--kiosk-surface);
    border: 3px solid var(--kiosk-border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: calc(30px * var(--kiosk-text-scale));
    transition: border-color var(--kiosk-duration-fast) var(--kiosk-ease-standard),
                opacity var(--kiosk-duration-fast) var(--kiosk-ease-standard);
}

.ks-stepper__icon-item.is-active .ks-stepper__icon {
    border-color: var(--kiosk-primary);
}
.ks-stepper__icon-item.is-done .ks-stepper__icon {
    border-color: var(--kiosk-primary);
    opacity: 0.7;
}

.ks-stepper__check {
    position: absolute;
    top: -4px;
    right: -4px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--kiosk-success);
    color: #FFFFFF;
    font-size: 15px;
    font-weight: var(--kiosk-font-weight-black);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--kiosk-surface);
}

.ks-stepper__label {
    font-size: calc(13px * var(--kiosk-text-scale));
    font-weight: var(--kiosk-font-weight-bold);
    color: var(--kiosk-text-muted);
    text-transform: uppercase;
    letter-spacing: var(--kiosk-letter-spacing-wide);
    text-align: center;
    min-height: 32px;
    line-height: 1.2;
}
.ks-stepper__icon-item.is-active .ks-stepper__label {
    color: var(--kiosk-primary);
}

/* ---------- Numeric row ---------- */
.ks-stepper__numeric {
    list-style: none;
    margin: 0;
    padding: 0 var(--kiosk-space-5);
    display: flex;
    align-items: center;
    gap: var(--kiosk-space-2);
}

.ks-stepper__dot {
    flex-shrink: 0;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: var(--kiosk-surface);
    border: 2px solid var(--kiosk-border);
    color: var(--kiosk-text-mute);
    font-weight: var(--kiosk-font-weight-black);
    font-size: calc(16px * var(--kiosk-text-scale));
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background var(--kiosk-duration-fast) var(--kiosk-ease-standard),
                color var(--kiosk-duration-fast) var(--kiosk-ease-standard),
                border-color var(--kiosk-duration-fast) var(--kiosk-ease-standard);
}
.ks-stepper__dot.is-active,
.ks-stepper__dot.is-done {
    background: var(--kiosk-primary);
    border-color: var(--kiosk-primary);
    color: var(--kiosk-text-on-red);
}

.ks-stepper__link {
    flex: 1;
    height: 3px;
    background: var(--kiosk-border);
    border-radius: 2px;
    transition: background var(--kiosk-duration-fast) var(--kiosk-ease-standard);
}
.ks-stepper__link.is-done { background: var(--kiosk-primary); }

.ks-stepper__counter {
    margin-inline-start: var(--kiosk-space-3);
    font-size: calc(12px * var(--kiosk-text-scale));
    font-weight: var(--kiosk-font-weight-bold);
    color: var(--kiosk-text-mute);
    white-space: nowrap;
}
</style>
