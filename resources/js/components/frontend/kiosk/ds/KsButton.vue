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
 * Variants : primary (rouge) | secondary (surface bord) | ghost | danger | dark.
 * Sizes    : lg (110 px CTA plein écran) | md (82) | sm (60).
 * Accessibilité :
 *  - min-height respecte --kiosk-touch-* selon size
 *  - aria-busy quand loading, disabled bloque click
 *  - focus-visible ring (WCAG 2.4.7)
 */
export default {
    name: 'KsButton',
    props: {
        variant: {
            type: String,
            default: 'primary',
            validator: (v) => ['primary', 'secondary', 'ghost', 'danger', 'dark'].includes(v),
        },
        size: {
            type: String,
            default: 'lg',
            validator: (v) => ['lg', 'md', 'sm'].includes(v),
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
    height: var(--kiosk-touch-hero);
    min-height: var(--kiosk-touch-hero);
    padding: 0 var(--kiosk-space-10);
    font-size: calc(30px * var(--kiosk-text-scale));
}
.ks-btn--md {
    height: 82px;
    min-height: var(--kiosk-touch-comfortable);
    padding: 0 var(--kiosk-space-8);
    font-size: calc(22px * var(--kiosk-text-scale));
}
.ks-btn--sm {
    height: 60px;
    min-height: var(--kiosk-touch-min);
    padding: 0 var(--kiosk-space-5);
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
        animation: none;
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
