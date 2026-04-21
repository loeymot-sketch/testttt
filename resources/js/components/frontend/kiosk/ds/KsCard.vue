<template>
  <component
    :is="tag"
    :role="interactive ? 'button' : null"
    :tabindex="interactive && !disabled ? 0 : null"
    :aria-label="interactive ? (ariaLabel || null) : null"
    :aria-disabled="disabled || null"
    :aria-pressed="interactive && selected != null ? String(!!selected) : null"
    :class="[
      'ks-card',
      `ks-card--${surface}`,
      `ks-card--${elevation}`,
      `ks-card--pad-${padding}`,
      {
        'ks-card--interactive': interactive,
        'ks-card--selected': selected,
        'ks-card--disabled': disabled,
      }
    ]"
    @click="onClick"
    @keydown.enter.prevent="onClick"
    @keydown.space.prevent="onClick"
  >
    <div v-if="$slots.media" class="ks-card__media"><slot name="media" /></div>
    <div v-if="$slots.header" class="ks-card__header"><slot name="header" /></div>
    <div class="ks-card__body"><slot /></div>
    <div v-if="$slots.footer" class="ks-card__footer"><slot name="footer" /></div>
  </component>
</template>

<script>
/**
 * KsCard — Surface conteneur produit / option.
 *
 * Props principales :
 *  - surface    : default | alt (warm) — background
 *  - elevation  : flat | card | lift — shadow level
 *  - padding    : none | sm | md | lg
 *  - interactive: expose role=button + click/keydown (ENTER/SPACE)
 *  - selected   : état visuel actif (bord rouge)
 *  - disabled   : grise + bloque events
 *
 * Slots : media (haut, ex. image), header (titre/badges), default (corps),
 *         footer (prix, actions).
 */
export default {
    name: 'KsCard',
    props: {
        tag: { type: String, default: 'div' },
        surface: {
            type: String,
            default: 'default',
            validator: (v) => ['default', 'alt'].includes(v),
        },
        elevation: {
            type: String,
            default: 'card',
            validator: (v) => ['flat', 'card', 'lift'].includes(v),
        },
        padding: {
            type: String,
            default: 'md',
            validator: (v) => ['none', 'sm', 'md', 'lg'].includes(v),
        },
        interactive: { type: Boolean, default: false },
        selected: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
        ariaLabel: { type: String, default: null },
    },
    emits: ['click'],
    mounted() {
        this._warnInteractiveA11y();
    },
    updated() {
        this._warnInteractiveA11y();
    },
    methods: {
        onClick(e) {
            if (!this.interactive || this.disabled) return;
            this.$emit('click', e);
        },
        _warnInteractiveA11y() {
            if (process.env.NODE_ENV === 'production' || !this.interactive || this.disabled || this.ariaLabel) return;
            const txt = (this.$el && this.$el.textContent) ? this.$el.textContent.replace(/\s+/g, ' ').trim() : '';
            if (txt.length < 1 && typeof console !== 'undefined' && console.warn) {
                console.warn('[KsCard] interactive card needs visible text or ariaLabel');
            }
        },
    },
};
</script>

<style scoped>
.ks-card {
    position: relative;
    display: flex;
    flex-direction: column;
    border-radius: var(--kiosk-radius-xl);
    border: 2px solid transparent;
    transition: transform var(--kiosk-duration-fast) var(--kiosk-ease-standard),
                box-shadow var(--kiosk-duration-fast) var(--kiosk-ease-standard),
                border-color var(--kiosk-duration-fast) var(--kiosk-ease-standard);
}

/* ---------- Surface ---------- */
.ks-card--default { background: var(--kiosk-surface); }
.ks-card--alt     { background: var(--kiosk-surface-alt); }

/* ---------- Elevation ---------- */
.ks-card--flat { box-shadow: none; }
.ks-card--card { box-shadow: var(--kiosk-shadow-card); }
.ks-card--lift { box-shadow: var(--kiosk-shadow-lift); }

/* ---------- Padding ---------- */
.ks-card--pad-none { padding: 0; }
.ks-card--pad-sm   { padding: var(--kiosk-space-4); }
.ks-card--pad-md   { padding: var(--kiosk-space-6); }
.ks-card--pad-lg   { padding: var(--kiosk-space-8); }

/* ---------- Interactive ---------- */
.ks-card--interactive {
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    min-height: var(--kiosk-touch-comfortable);
}
.ks-card--interactive:hover:not(.ks-card--disabled) {
    box-shadow: var(--kiosk-shadow-lift);
}
.ks-card--interactive:active:not(.ks-card--disabled) {
    transform: scale(0.99);
}
.ks-card--interactive:focus-visible {
    outline: var(--kiosk-focus-width) solid var(--kiosk-focus-ring);
    outline-offset: var(--kiosk-focus-offset);
}

/* ---------- States ---------- */
.ks-card--selected {
    border-color: var(--kiosk-primary);
    background: var(--kiosk-primary-soft);
}

.ks-card--disabled {
    opacity: 0.48;
    filter: grayscale(0.4);
    cursor: not-allowed;
    pointer-events: none;
}

/* ---------- Subregions ---------- */
.ks-card__media {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: var(--kiosk-space-4);
}
.ks-card__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--kiosk-space-3);
    margin-bottom: var(--kiosk-space-3);
}
.ks-card__body  { flex: 1; min-width: 0; }
.ks-card__footer {
    margin-top: var(--kiosk-space-4);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--kiosk-space-3);
}
</style>
