<template>
  <div
    role="button"
    :tabindex="disabled ? -1 : 0"
    :aria-pressed="String(!!selected)"
    :aria-disabled="disabled || null"
    :class="[
      'ks-chip',
      `ks-chip--${size}`,
      `ks-chip--${variant}`,
      { 'ks-chip--selected': selected, 'ks-chip--disabled': disabled }
    ]"
    @click="onClick"
    @keydown.enter.prevent="onClick"
    @keydown.space.prevent="onClick"
  >
    <span v-if="$slots.icon || icon" class="ks-chip__icon" aria-hidden="true">
      <slot name="icon">{{ icon }}</slot>
    </span>
    <span class="ks-chip__label"><slot /></span>
    <span
      v-if="count != null && count > 0"
      class="ks-chip__count"
      :aria-label="countAriaLabel || `× ${count}`"
    >× {{ count }}</span>
    <button type="button"
      v-if="removable && selected && !disabled"
      class="ks-chip__remove"
      :aria-label="removeAriaLabel || 'Retirer'"
      @click.stop="onRemove"
      @keydown.stop>×</button>
  </div>
</template>

<script>
/**
 * KsChip — Option toggle (sauces, viandes, garnitures, suppléments).
 *
 * ⚠ Implémenté en `<div role="button">` et PAS en bouton natif (`button`) : l'atome
 *   supporte un sous-bouton optionnel "retirer" (`removable=true`). Imbriquer
 *   deux éléments `button` HTML5 est invalide. Le pattern
 *   `div[role=button]` + `tabindex=0` + `aria-pressed` est le WCAG-safe pattern
 *   officiel (Authoring Practices 1.2, "Button (Disclosure)").
 *
 * Props :
 *  - selected  : état actif (bg rouge + check)
 *  - disabled  : grise + bloque events
 *  - count     : nombre d'occurrences (ex. viande × 2) — visible si > 0
 *  - removable : affiche un × sur l'état selected (émet `remove`)
 *  - icon      : emoji/SVG string optionnel (slot `icon` préférable)
 *  - size      : sm | md | lg
 *
 * Accessibilité : role=button + aria-pressed, Enter/Space cliquent,
 *                 Escape sans effet, aria-disabled bloque le tabindex.
 */
export default {
    name: 'KsChip',
    props: {
        selected: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
        count: { type: Number, default: null },
        removable: { type: Boolean, default: false },
        icon: { type: String, default: null },
        size: {
            type: String,
            default: 'md',
            validator: (v) => ['sm', 'md', 'lg'].includes(v),
        },
        /**
         * V1.7 Bold Appétissant — variant 'composition' rend une chip dense
         * pour la composition live wizard (warm, dot accent, padding réduit).
         * Variants : 'default' (legacy) | 'composition' (bold) | 'included' (bold).
         */
        variant: {
            type: String,
            default: 'default',
            validator: (v) => ['default', 'composition', 'included'].includes(v),
        },
        countAriaLabel: { type: String, default: null },
        removeAriaLabel: { type: String, default: null },
    },
    emits: ['click', 'remove'],
    methods: {
        onClick(e) {
            if (this.disabled) return;
            this.$emit('click', e);
        },
        onRemove(e) {
            if (this.disabled) return;
            this.$emit('remove', e);
        },
    },
};
</script>

<style scoped>
.ks-chip {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: var(--kiosk-space-2);
    border-radius: var(--kiosk-radius-pill);
    border: 2px solid var(--kiosk-border);
    background: var(--kiosk-surface);
    color: var(--kiosk-text);
    font-family: inherit;
    font-weight: var(--kiosk-font-weight-bold);
    cursor: pointer;
    transition: transform var(--kiosk-duration-fast) var(--kiosk-ease-standard),
                background var(--kiosk-duration-fast) var(--kiosk-ease-standard),
                border-color var(--kiosk-duration-fast) var(--kiosk-ease-standard),
                color var(--kiosk-duration-fast) var(--kiosk-ease-standard);
    -webkit-tap-highlight-color: transparent;
    user-select: none;
    line-height: 1;
}

.ks-chip:focus-visible {
    outline: var(--kiosk-focus-width) solid var(--kiosk-focus-ring);
    outline-offset: var(--kiosk-focus-offset);
}

.ks-chip:active:not(.ks-chip--disabled) { transform: scale(0.97); }

/* ---------- Sizes (tap target compliant) ---------- */
.ks-chip--sm {
    min-height: var(--kiosk-touch-min);
    padding: 0 var(--kiosk-space-4);
    font-size: calc(16px * var(--kiosk-text-scale));
}
.ks-chip--md {
    min-height: var(--kiosk-touch-comfortable);
    padding: 0 var(--kiosk-space-5);
    font-size: calc(18px * var(--kiosk-text-scale));
}
.ks-chip--lg {
    min-height: var(--kiosk-touch-large);
    padding: 0 var(--kiosk-space-6);
    font-size: calc(20px * var(--kiosk-text-scale));
}

/* ---------- States ---------- */
.ks-chip--selected {
    background: var(--kiosk-primary);
    border-color: var(--kiosk-primary);
    color: var(--kiosk-text-on-red);
    box-shadow: 0 4px 12px rgba(244, 80, 30, 0.24);
}

.ks-chip--disabled {
    opacity: 0.42;
    cursor: not-allowed;
    filter: grayscale(0.3);
}

/* ---------- Parts ---------- */
.ks-chip__icon {
    display: inline-flex;
    font-size: 1.25em;
    line-height: 1;
}
.ks-chip__count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    padding: 0 var(--kiosk-space-2);
    height: 28px;
    border-radius: var(--kiosk-radius-pill);
    background: rgba(255, 255, 255, 0.2);
    font-size: 0.85em;
    font-weight: var(--kiosk-font-weight-black);
}
.ks-chip:not(.ks-chip--selected) .ks-chip__count {
    background: var(--kiosk-surface-alt);
    color: var(--kiosk-text);
}

.ks-chip__remove {
    margin-inline-start: var(--kiosk-space-2);
    min-width: 44px;
    min-height: 44px;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.24);
    color: inherit;
    border: none;
    cursor: pointer;
    font-size: 18px;
    line-height: 1;
    font-weight: var(--kiosk-font-weight-bold);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    box-sizing: border-box;
}
.ks-chip__remove:focus-visible {
    outline: 2px solid var(--kiosk-focus-ring);
    outline-offset: 1px;
}

/* ---------- V1.7 Bold Appétissant variants ---------- */
/* composition : dense chip pour wizard composition live (warm subtle + dot accent) */
.ks-chip--composition {
    min-height: auto;
    padding: 6px 12px;
    border-radius: var(--kiosk-radius-pill);
    border: 1px solid var(--kiosk-bold-border);
    background: var(--kiosk-bold-surface-subtle);
    color: var(--kiosk-bold-text-primary);
    font-size: calc(13px * var(--kiosk-text-scale, 1));
    font-weight: var(--kiosk-font-weight-bold, 700);
    text-transform: none;
    letter-spacing: 0;
    gap: 6px;
}
.ks-chip--composition::before {
    content: '';
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--kiosk-bold-accent);
    flex-shrink: 0;
}
.ks-chip--composition.ks-chip--selected {
    background: var(--kiosk-bold-primary-soft);
    border-color: var(--kiosk-bold-primary);
    color: var(--kiosk-bold-primary);
    box-shadow: none;
}

/* included : chip "inclus" (vert success, soft) */
.ks-chip--included {
    background: var(--kiosk-bold-success-soft);
    color: var(--kiosk-bold-success-text);
    border: 1px solid var(--kiosk-bold-success);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-size: calc(11px * var(--kiosk-text-scale, 1));
    padding: 4px 10px;
    min-height: auto;
}
</style>
