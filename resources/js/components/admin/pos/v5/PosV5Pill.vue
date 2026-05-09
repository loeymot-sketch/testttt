<template>
  <span :class="rootClass" :role="role">
    <span v-if="$slots.icon || icon" class="pos-v5-pill__icon" aria-hidden="true">
      <slot name="icon">{{ icon }}</slot>
    </span>
    <span class="pos-v5-pill__label"><slot /></span>
    <span v-if="$slots.suffix || suffix" class="pos-v5-pill__suffix">
      <slot name="suffix">{{ suffix }}</slot>
    </span>
  </span>
</template>

<script>
/**
 * PosV5Pill — chip / badge unifié POS V5.
 *
 * Mission : CV1-POS-DESIGN-CONVERGENCE-001
 * Doc plan : §4.3
 *
 * Variants : default | brand | success | warning | danger | info | ghost | inverse
 * Sizes    : xs (18px) | sm (22px) | md (26px) | lg (32px)
 *
 * Slots    : icon (leading), default (label), suffix (trailing)
 */
export default {
    name: "PosV5Pill",
    props: {
        variant: {
            type: String,
            default: "default",
            validator: (v) => ["default", "brand", "success", "warning", "danger", "info", "ghost", "inverse", "live"].includes(v),
        },
        size: {
            type: String,
            default: "md",
            validator: (v) => ["xs", "sm", "md", "lg"].includes(v),
        },
        icon: { type: String, default: "" },
        suffix: { type: [String, Number], default: null },
        role: { type: String, default: null },
        outlined: { type: Boolean, default: false },
    },
    computed: {
        rootClass() {
            return [
                "pos-v5-pill",
                `pos-v5-pill--${this.variant}`,
                `pos-v5-pill--${this.size}`,
                { "pos-v5-pill--outlined": this.outlined },
            ];
        },
    },
};
</script>

<style scoped>
.pos-v5-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 0 var(--pos-v5-space-2);
    border-radius: var(--pos-v5-radius-pill);
    font-family: var(--pos-v5-font-sans);
    font-weight: var(--pos-v5-weight-bold);
    line-height: 1;
    white-space: nowrap;
    border: 1px solid transparent;
}

/* === SIZES === */
.pos-v5-pill--xs { min-height: 18px; font-size: 10px; padding: 0 6px; }
.pos-v5-pill--sm { min-height: 22px; font-size: 11px; padding: 0 8px; }
.pos-v5-pill--md { min-height: 26px; font-size: 12px; padding: 0 10px; }
.pos-v5-pill--lg { min-height: 32px; font-size: 13px; padding: 0 12px; }

/* === ICON / SUFFIX === */
.pos-v5-pill__icon {
    display: inline-flex;
    align-items: center;
    line-height: 1;
    font-size: 1.1em;
}
.pos-v5-pill__suffix {
    display: inline-flex;
    align-items: center;
    margin-left: var(--pos-v5-space-1);
    padding: 1px 5px;
    border-radius: var(--pos-v5-radius-pill);
    background: rgba(255, 255, 255, 0.65);
    font-size: 0.92em;
    font-weight: var(--pos-v5-weight-extrabold);
}
.pos-v5-pill__label:empty { display: none; }

/* === VARIANTS === */
.pos-v5-pill--default {
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink-soft);
}
.pos-v5-pill--brand {
    background: var(--pos-v5-brand-red-soft);
    color: var(--pos-v5-brand-red);
}
.pos-v5-pill--success {
    background: var(--pos-v5-success-soft);
    color: var(--pos-v5-success-dark);
}
.pos-v5-pill--warning {
    background: var(--pos-v5-warning-soft);
    color: var(--pos-v5-warning-dark);
}
.pos-v5-pill--danger {
    background: var(--pos-v5-danger-soft);
    color: var(--pos-v5-danger-dark);
}
.pos-v5-pill--info {
    background: var(--pos-v5-info-soft);
    color: var(--pos-v5-info);
}
.pos-v5-pill--ghost {
    background: transparent;
    color: var(--pos-v5-ink-soft);
    border-color: var(--pos-v5-border);
}
.pos-v5-pill--inverse {
    background: var(--pos-v5-bg-strong);
    color: var(--pos-v5-ink-on-dark);
}
.pos-v5-pill--live {
    background: var(--pos-v5-success);
    color: var(--pos-v5-ink-on-dark);
    animation: pos-v5-pill-pulse 1.8s ease-in-out infinite;
}
@keyframes pos-v5-pill-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(27, 138, 58, 0.0); }
    50%      { box-shadow: 0 0 0 4px rgba(27, 138, 58, 0.30); }
}
@media (prefers-reduced-motion: reduce) {
    .pos-v5-pill--live { animation: none; }
}

/* === Outlined option === */
.pos-v5-pill--outlined {
    background: transparent !important;
    border-width: 1.5px;
    border-style: solid;
}
.pos-v5-pill--outlined.pos-v5-pill--brand   { border-color: var(--pos-v5-brand-red); color: var(--pos-v5-brand-red); }
.pos-v5-pill--outlined.pos-v5-pill--success { border-color: var(--pos-v5-success); color: var(--pos-v5-success-dark); }
.pos-v5-pill--outlined.pos-v5-pill--warning { border-color: var(--pos-v5-warning); color: var(--pos-v5-warning-dark); }
.pos-v5-pill--outlined.pos-v5-pill--danger  { border-color: var(--pos-v5-danger); color: var(--pos-v5-danger-dark); }
.pos-v5-pill--outlined.pos-v5-pill--info    { border-color: var(--pos-v5-info); color: var(--pos-v5-info); }
</style>
