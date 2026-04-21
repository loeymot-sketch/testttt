<template>
  <span
    :class="[
      'ks-badge',
      `ks-badge--${color}`,
      `ks-badge--${size}`,
      { 'ks-badge--soft': soft, 'ks-badge--icon-only': iconOnly }
    ]"
    :role="ariaLabel ? 'img' : null"
    :aria-label="ariaLabel || null"
  >
    <span v-if="$slots.icon || icon" class="ks-badge__icon" aria-hidden="true">
      <slot name="icon">{{ icon }}</slot>
    </span>
    <span v-if="!iconOnly" class="ks-badge__label"><slot /></span>
  </span>
</template>

<script>
/**
 * KsBadge — Pastille de statut / label produit.
 *
 * Colors :
 *  - red | green | amber | gray | info
 *  - veg (végétarien) | halal | spicy | new | chef-pick (is_chef_pick admin)
 * Attention : aucune stat dynamique (cf. invariant §1.5).
 * Le badge "chef-pick" = booléen admin statique, rien d'algorithmique.
 *
 * Sizes : sm | md
 * Soft  : variante pastel (fond tinté, texte couleur)
 */
export default {
    name: 'KsBadge',
    props: {
        color: {
            type: String,
            default: 'red',
            validator: (v) => [
                'red', 'green', 'amber', 'gray', 'info',
                'veg', 'halal', 'spicy', 'new', 'chef-pick',
            ].includes(v),
        },
        size: {
            type: String,
            default: 'md',
            validator: (v) => ['sm', 'md'].includes(v),
        },
        soft: { type: Boolean, default: false },
        icon: { type: String, default: null },
        iconOnly: { type: Boolean, default: false },
        ariaLabel: { type: String, default: null },
    },
    mounted() {
        this._warnIconOnly();
    },
    updated() {
        this._warnIconOnly();
    },
    methods: {
        _warnIconOnly() {
            if (!this.iconOnly || this.ariaLabel) return;
            if (typeof console !== 'undefined' && console.warn) {
                console.warn('[KsBadge] iconOnly requires ariaLabel for an accessible name');
            }
        },
    },
};
</script>

<style scoped>
.ks-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--kiosk-space-2);
    border-radius: var(--kiosk-radius-pill);
    font-weight: var(--kiosk-font-weight-black);
    letter-spacing: var(--kiosk-letter-spacing-wide);
    text-transform: uppercase;
    white-space: nowrap;
    line-height: 1;
}

/* ---------- Sizes ---------- */
.ks-badge--sm {
    padding: 4px 10px;
    font-size: calc(12px * var(--kiosk-text-scale));
}
.ks-badge--md {
    padding: var(--kiosk-space-2) var(--kiosk-space-3);
    font-size: calc(14px * var(--kiosk-text-scale));
}

.ks-badge--icon-only {
    padding: var(--kiosk-space-2);
    width: calc(var(--kiosk-font-size-micro) * var(--kiosk-text-scale) + 12px);
    height: calc(var(--kiosk-font-size-micro) * var(--kiosk-text-scale) + 12px);
    justify-content: center;
}

/* ---------- Colors : solid ---------- */
.ks-badge--red       { background: var(--kiosk-primary);          color: var(--kiosk-text-on-red); }
.ks-badge--green     { background: var(--kiosk-success);          color: #FFFFFF; }
.ks-badge--amber     { background: var(--kiosk-warning);          color: #FFFFFF; }
.ks-badge--gray      { background: var(--kiosk-border);           color: var(--kiosk-text); }
.ks-badge--info      { background: var(--kiosk-info);             color: #FFFFFF; }
.ks-badge--veg       { background: var(--kiosk-badge-veg);        color: #FFFFFF; }
.ks-badge--halal     { background: var(--kiosk-badge-halal);      color: #FFFFFF; }
.ks-badge--spicy     { background: var(--kiosk-badge-spicy);      color: #FFFFFF; }
.ks-badge--new       { background: var(--kiosk-badge-new);        color: var(--kiosk-text-on-red); }
.ks-badge--chef-pick { background: var(--kiosk-badge-chef-pick);  color: #FFFFFF; }

/* ---------- Soft variants (fond tinté, texte couleur) ---------- */
.ks-badge--soft.ks-badge--red       { background: var(--kiosk-primary-soft); color: var(--kiosk-primary); }
.ks-badge--soft.ks-badge--green     { background: #E5F6EC; color: var(--kiosk-success); }
.ks-badge--soft.ks-badge--amber     { background: #FDF1DC; color: var(--kiosk-warning); }
.ks-badge--soft.ks-badge--gray      { background: var(--kiosk-surface-alt); color: var(--kiosk-text-muted); }
.ks-badge--soft.ks-badge--info      { background: #E6ECFB; color: var(--kiosk-info); }
.ks-badge--soft.ks-badge--veg       { background: #E5F6EC; color: var(--kiosk-badge-veg); }
.ks-badge--soft.ks-badge--halal     { background: #E0F1E5; color: var(--kiosk-badge-halal); }
.ks-badge--soft.ks-badge--spicy     { background: #FCE6E8; color: var(--kiosk-badge-spicy); }
.ks-badge--soft.ks-badge--new       { background: var(--kiosk-primary-soft); color: var(--kiosk-primary); }
.ks-badge--soft.ks-badge--chef-pick { background: #FDF1DC; color: var(--kiosk-badge-chef-pick); }

.ks-badge__icon {
    display: inline-flex;
    font-size: 1.15em;
    line-height: 1;
}
</style>
