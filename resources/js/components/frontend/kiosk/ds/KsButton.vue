<template>
  <button :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading || null"
    :aria-disabled="disabled || null"
    :class="[
      'ks-btn',
      `ks-btn--${variant}`,
      `ks-btn--${size}`,
      { 'ks-btn--full': fullWidth, 'ks-btn--loading': loading }
    ]"
    @click="onClick">
    <span v-if="loading" class="ks-btn__spinner" aria-hidden="true" />
    <span v-else-if="$slots.icon || icon" class="ks-btn__icon" aria-hidden="true">
      <slot name="icon">{{ icon }}</slot>
    </span>
    <span class="ks-btn__label"><slot /></span>
  </button>
</template>

<script>
/**
 * KsButton — CTA primaire Kiosk (aligné FkButton React).
 *
 * Variants :
 *   - primary | secondary | ghost | danger | dark           (V1 baseline)
 *   - hero | ghost-bold | pop                               (V1.5 Bold Appétissant)
 *     hero       : CTA hero (Fraunces, ombre cta-bold, scale spring, light/dark)
 *     ghost-bold : border 2px warm dark, hover fill warm
 *     pop        : micro-anim succès post-tap (scale spring + ombre flash)
 *
 * Sizes :
 *   - lg (110 px CTA plein écran) | md (82) | sm (60)
 *   - hero-xl (variant hero only — 128 px hero CTA panier/idle)
 *
 * Accessibilité :
 *  - min-height respecte --kiosk-touch-* selon size
 *  - aria-busy quand loading, disabled bloque click
 *  - focus-visible ring (WCAG 2.4.7)
 *  - Spring transition neutralisée si reduced-motion
 */
export default {
    name: 'KsButton',
    props: {
        variant: {
            type: String,
            default: 'primary',
            validator: (v) => [
                'primary', 'secondary', 'ghost', 'danger', 'dark',
                'hero', 'ghost-bold', 'pop',
            ].includes(v),
        },
        size: {
            type: String,
            default: 'lg',
            validator: (v) => ['lg', 'md', 'sm', 'hero-xl'].includes(v),
        },
        disabled: { type: Boolean, default: false },
        loading: { type: Boolean, default: false },
        fullWidth: { type: Boolean, default: false },
        icon: { type: String, default: null },
        type: {
            type: String,
            default: 'button',
            validator: (v) => ['button', 'submit', 'reset'].includes(v),
        },
    },
    emits: ['click'],
    methods: {
        onClick(e) {
            if (this.disabled || this.loading) return;
            this.$emit('click', e);
        },
    },
};
</script>

<style scoped>
.ks-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--kiosk-space-3);
    border: none;
    border-radius: var(--kiosk-radius-xl);
    font-family: inherit;
    font-weight: var(--kiosk-font-weight-bold);
    letter-spacing: var(--kiosk-letter-spacing-caps);
    text-transform: uppercase;
    /* [Wave Y A-005 2026-05-21] Prevent line overlap when long labels wrap
       inside a fixed-height button (e.g. "Réessayer le paiement" on the
       payment-refused error screen). line-height tightening + text-align
       keeps single-line buttons unchanged but allows clean 2-line layout
       in the wrap case. */
    line-height: 1.15;
    text-align: center;
    cursor: pointer;
    transition: transform var(--kiosk-duration-fast) var(--kiosk-ease-standard),
                box-shadow var(--kiosk-duration-fast) var(--kiosk-ease-standard),
                background-color var(--kiosk-duration-fast) var(--kiosk-ease-standard);
    user-select: none;
    -webkit-tap-highlight-color: transparent;
}

.ks-btn:focus-visible {
    outline: var(--kiosk-focus-width) solid var(--kiosk-focus-ring);
    outline-offset: var(--kiosk-focus-offset);
}

.ks-btn:active:not(:disabled) {
    transform: scale(0.97);
}

.ks-btn:disabled,
.ks-btn[aria-disabled="true"] {
    opacity: 0.42;
    cursor: not-allowed;
    box-shadow: none;
}

.ks-btn--full { width: 100%; }

/* ---------- Sizes ---------- */
.ks-btn--lg {
    /* [Wave Y A-005] Allow growth when long labels wrap to 2 lines.
       Single-line buttons still render at --kiosk-touch-hero exactly. */
    min-height: var(--kiosk-touch-hero);
    padding: var(--kiosk-space-3) var(--kiosk-space-10);
    font-size: calc(30px * var(--kiosk-text-scale));
}
.ks-btn--md {
    min-height: var(--kiosk-touch-comfortable);
    padding: var(--kiosk-space-3) var(--kiosk-space-8);
    font-size: calc(22px * var(--kiosk-text-scale));
}
.ks-btn--sm {
    min-height: var(--kiosk-touch-min);
    padding: var(--kiosk-space-2) var(--kiosk-space-5);
    font-size: calc(18px * var(--kiosk-text-scale));
}

/* ---------- Variants ---------- */
.ks-btn--primary {
    background: var(--kiosk-primary);
    color: var(--kiosk-text-on-red);
    box-shadow: var(--kiosk-shadow-cta);
}
.ks-btn--primary:hover:not(:disabled) {
    background: var(--kiosk-primary-dark);
}

.ks-btn--secondary {
    background: var(--kiosk-surface);
    color: var(--kiosk-text);
    border: 2px solid var(--kiosk-border);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}

.ks-btn--ghost {
    background: transparent;
    color: var(--kiosk-text-muted);
    border: 2px solid var(--kiosk-border);
}

.ks-btn--danger {
    background: var(--kiosk-surface);
    color: var(--kiosk-error);
    border: 2px solid var(--kiosk-error);
}

.ks-btn--dark {
    background: var(--kiosk-text);
    color: var(--kiosk-text-on-red);
}

/* ---------- Bold Appétissant variants (V1.5) ---------- */
/* Consomment --kiosk-bold-* (light + dark via [data-kiosk-theme]).
   Coexistent avec les variants legacy : aucun écran existant impacté. */

.ks-btn--hero {
    font-family: var(--kiosk-font-display, 'Fraunces', Georgia, serif);
    font-weight: var(--kiosk-display-weight-black, 900);
    letter-spacing: -0.01em;
    text-transform: none;
    background: var(--kiosk-bold-primary);
    color: var(--kiosk-bold-text-on-primary);
    border-radius: var(--kiosk-radius-3xl, 48px);
    box-shadow: var(--kiosk-shadow-cta-bold);
    transition: transform var(--kiosk-duration-tap) var(--kiosk-motion-spring),
                box-shadow var(--kiosk-duration-card) var(--kiosk-motion-smooth),
                background-color var(--kiosk-duration-tap) var(--kiosk-motion-smooth);
}
.ks-btn--hero:hover:not(:disabled) {
    background: var(--kiosk-bold-primary-hover);
    transform: translateY(-2px) scale(1.01);
    box-shadow: var(--kiosk-shadow-cta-bold-hover);
}
.ks-btn--hero:active:not(:disabled) {
    transform: scale(0.97);
    box-shadow: var(--kiosk-shadow-cta-bold);
}

.ks-btn--ghost-bold {
    background: transparent;
    color: var(--kiosk-bold-text-primary);
    border: 2px solid var(--kiosk-bold-border-strong);
    border-radius: var(--kiosk-radius-pill);
    font-weight: var(--kiosk-font-weight-bold, 700);
    transition: background var(--kiosk-duration-tap) var(--kiosk-motion-smooth),
                color var(--kiosk-duration-tap) var(--kiosk-motion-smooth),
                transform var(--kiosk-duration-tap) var(--kiosk-motion-spring);
}
.ks-btn--ghost-bold:hover:not(:disabled) {
    background: var(--kiosk-bold-text-primary);
    color: var(--kiosk-bold-text-inverse);
}

.ks-btn--pop {
    background: var(--kiosk-bold-primary);
    color: var(--kiosk-bold-text-on-primary);
    border-radius: var(--kiosk-radius-pill);
    box-shadow: var(--kiosk-shadow-pop);
    transition: transform var(--kiosk-duration-tap) var(--kiosk-motion-spring),
                box-shadow var(--kiosk-duration-card) var(--kiosk-motion-smooth);
}
.ks-btn--pop:hover:not(:disabled) {
    transform: translateY(-2px) scale(1.03);
    box-shadow: var(--kiosk-shadow-cta-bold);
}
.ks-btn--pop:active:not(:disabled) {
    transform: scale(0.94);
}

/* ---------- New size hero-xl (Bold) ---------- */
.ks-btn--hero-xl {
    height: 128px;
    min-height: 128px;
    padding: 0 var(--kiosk-space-12);
    font-size: calc(36px * var(--kiosk-text-scale, 1));
    border-radius: var(--kiosk-radius-3xl, 48px);
}

/* ---------- Reduced motion guard pour les nouveaux variants ---------- */
[data-kiosk-reduced-motion='true'] .ks-btn--hero,
[data-kiosk-reduced-motion='true'] .ks-btn--ghost-bold,
[data-kiosk-reduced-motion='true'] .ks-btn--pop {
    transition: none;
    transform: none !important;
}

/* ---------- Loading ---------- */
.ks-btn--loading { cursor: progress; }
.ks-btn__spinner {
    width: 1.1em;
    height: 1.1em;
    border-radius: 50%;
    border: 3px solid currentColor;
    border-inline-end-color: transparent;
    animation: ks-spin var(--kiosk-duration-slow) linear infinite;
}
@keyframes ks-spin { to { transform: rotate(360deg); } }

@media (prefers-reduced-motion: reduce) {
    .ks-btn__spinner {
        animation-duration: 1.5s;
    }
}

.ks-btn__icon {
    font-size: 1.2em;
    line-height: 1;
    display: inline-flex;
    align-items: center;
}

.ks-btn__label { display: inline-block; }
</style>
