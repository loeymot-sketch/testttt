<template>
  <div
    class="ks-theme-toggle"
    role="radiogroup"
    :aria-label="ariaLabel"
    :data-testid="testid"
  >
    <button
      v-for="opt in options"
      :key="opt.value"
      type="button"
      class="ks-theme-toggle__opt"
      :class="{ 'is-selected': preference === opt.value }"
      role="radio"
      :aria-checked="String(preference === opt.value)"
      :aria-label="opt.ariaLabel"
      :title="opt.label"
      :data-testid="testid + '-' + opt.value"
      @click="select(opt.value)"
    >
      <!-- eslint-disable-next-line vue/no-v-html -->
      <span class="ks-theme-toggle__icon" aria-hidden="true" v-html="safeHtml(opt.icon)" />
      <span class="ks-theme-toggle__label">{{ opt.label }}</span>
    </button>
  </div>
</template>

<script>
import { safeHtml } from '../../../../utils/safeHtml.js';

/**
 * KsThemeToggle — Sélecteur de thème "Bold Appétissant" pour la borne.
 * -----------------------------------------------------------------------------
 * Plan : plans/PLAN_CV1-KIOSK-VISUAL-REDESIGN-001_2026-05-02.md §V1.4
 *
 * 3 états : 'light' | 'dark' | 'auto'.
 * Source de vérité : kioskSettings.theme (store) — propagé sur <html> par
 * le composable useKioskTheme (V1.3).
 *
 * Usage :
 *   <KsThemeToggle v-model="theme" />     (avec v-model, équivalent contrôlé)
 *   ou intégré dans KsA11ySettings, lit/dispatch directement le store.
 *
 * Props :
 *  - modelValue : 'light' | 'dark' | 'auto' — préférence courante
 *  - ariaLabel  : label radiogroup (par défaut FR)
 *  - testid     : préfixe data-testid pour sentinels Vitest
 *  - labels     : surcharge i18n des labels (sinon FR par défaut)
 *
 * Émissions :
 *  - update:modelValue : nouvelle préférence
 *  - change            : (nouvelle préférence)
 *
 * A11y :
 *  - role=radiogroup + role=radio + aria-checked
 *  - Touch target ≥ 44×44 (WCAG 2.5.5)
 *  - Focus ring 3px via --kiosk-focus-* (cv1-tokens)
 *  - Icônes décoratives (aria-hidden) — label texte toujours présent
 *
 * Discipline :
 *  - Aucune logique de prix / business
 *  - Aucun side-effect DOM hors l'attribut <html data-kiosk-theme>
 *    (géré par useKioskTheme, pas ici)
 */
export default {
    name: 'KsThemeToggle',
    props: {
        modelValue: {
            type: String,
            default: 'auto',
            validator: (v) => ['light', 'dark', 'auto'].includes(v),
        },
        ariaLabel: {
            type: String,
            default: 'Sélection du thème',
        },
        testid: {
            type: String,
            default: 'ks-theme-toggle',
        },
        labels: {
            type: Object,
            default: () => ({}),
        },
    },
    emits: ['update:modelValue', 'change'],
    computed: {
        preference() {
            return this.modelValue;
        },
        options() {
            const fallbackLabels = {
                light: 'Clair',
                dark: 'Sombre',
                auto: 'Auto',
            };
            const fallbackAria = {
                light: 'Activer le thème clair',
                dark: 'Activer le thème sombre',
                auto: 'Suivre les préférences système',
            };
            const merged = (key) => this.labels?.[key] || fallbackLabels[key];
            const ariaFor = (key) => this.labels?.[`${key}_aria`] || fallbackAria[key];

            return [
                {
                    value: 'light',
                    label: merged('light'),
                    ariaLabel: ariaFor('light'),
                    /* Sun icon — aria-hidden, decorative */
                    icon: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="6.34" y2="6.34"/><line x1="17.66" y1="17.66" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="6.34" y2="17.66"/><line x1="17.66" y1="6.34" x2="19.07" y2="4.93"/></svg>',
                },
                {
                    value: 'dark',
                    label: merged('dark'),
                    ariaLabel: ariaFor('dark'),
                    /* Moon icon */
                    icon: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
                },
                {
                    value: 'auto',
                    label: merged('auto'),
                    ariaLabel: ariaFor('auto'),
                    /* Half-circle (auto) icon */
                    icon: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 3v18" fill="currentColor" stroke="none"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" stroke="none"/></svg>',
                },
            ];
        },
    },
    methods: {
        safeHtml,
        select(value) {
            if (value === this.preference) return;
            this.$emit('update:modelValue', value);
            this.$emit('change', value);
        },
    },
};
</script>

<style scoped>
.ks-theme-toggle {
    display: inline-flex;
    gap: 4px;
    padding: 4px;
    border-radius: var(--kiosk-radius-pill);
    background: var(--kiosk-bold-surface-subtle, var(--kiosk-surface-alt));
    border: 1px solid var(--kiosk-bold-border, var(--kiosk-border));
}

.ks-theme-toggle__opt {
    display: inline-flex;
    align-items: center;
    gap: var(--kiosk-space-2);
    min-width: var(--kiosk-touch-min);
    min-height: var(--kiosk-touch-min);
    padding: 0 var(--kiosk-space-4);
    border-radius: var(--kiosk-radius-pill);
    border: 0;
    background: transparent;
    color: var(--kiosk-bold-text-secondary, var(--kiosk-text-muted));
    font-family: inherit;
    font-weight: var(--kiosk-font-weight-bold, 700);
    font-size: calc(14px * var(--kiosk-text-scale, 1));
    cursor: pointer;
    transition: background var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-smooth, var(--kiosk-ease-standard)),
                color var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-smooth, var(--kiosk-ease-standard)),
                transform var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-spring, var(--kiosk-ease-bounce));
    -webkit-tap-highlight-color: transparent;
    user-select: none;
    line-height: 1;
}

.ks-theme-toggle__opt:hover:not(.is-selected) {
    color: var(--kiosk-bold-text-primary, var(--kiosk-text));
    background: rgba(255, 255, 255, 0.05);
}

.ks-theme-toggle__opt.is-selected {
    background: var(--kiosk-bold-surface, var(--kiosk-surface));
    color: var(--kiosk-bold-text-primary, var(--kiosk-text));
    box-shadow: var(--kiosk-shadow-card-bold, var(--kiosk-shadow-card));
}

[data-kiosk-theme='dark'] .ks-theme-toggle__opt.is-selected {
    background: var(--kiosk-bold-surface);
    color: var(--kiosk-bold-text-primary);
}

.ks-theme-toggle__opt:active:not(:disabled) {
    transform: scale(0.95);
}

.ks-theme-toggle__opt:focus-visible {
    outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-focus-ring, var(--kiosk-primary));
    outline-offset: var(--kiosk-focus-offset, 2px);
}

.ks-theme-toggle__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    color: currentColor;
}
.ks-theme-toggle__icon :deep(svg) {
    display: block;
    width: 100%;
    height: 100%;
}

.ks-theme-toggle__label {
    display: inline-block;
}

/* Variant compact : icône seule (pas de label visible) — utilisable depuis
   floating toolbar idle. Le label reste accessible via aria-label. */
.ks-theme-toggle--compact .ks-theme-toggle__opt {
    padding: 0;
    width: var(--kiosk-touch-min);
    justify-content: center;
}
.ks-theme-toggle--compact .ks-theme-toggle__label {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
    white-space: nowrap;
}
</style>
