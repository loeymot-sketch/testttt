<template>
  <span :class="rootClass">
    <span v-if="icon || $slots.icon" class="pos-v5-stat__icon" aria-hidden="true">
      <slot name="icon">{{ icon }}</slot>
    </span>
    <span v-if="label" class="pos-v5-stat__label">{{ label }}</span>
    <span v-if="value !== null && value !== undefined && value !== ''" class="pos-v5-stat__value">{{ value }}</span>
    <slot />
  </span>
</template>

<script>
/**
 * PosV5StatChip — chip stat compact pour operator bar / cart head.
 *
 * Mission : CV1-POS-DESIGN-CONVERGENCE-001
 * Doc plan : §4.4
 *
 * Format   : [icon] [label] [value]
 * Tones    : neutral | live (pulse vert) | brand | success | warning | danger | ghost
 *
 * Usage    : <PosV5StatChip icon="🟢" label="Ticket en cours" tone="live" />
 *            <PosV5StatChip label="Filiale" value="#1" />
 */
export default {
    name: "PosV5StatChip",
    props: {
        icon: { type: String, default: "" },
        label: { type: String, default: "" },
        value: { type: [String, Number], default: null },
        tone: {
            type: String,
            default: "neutral",
            validator: (v) => ["neutral", "live", "brand", "success", "warning", "danger", "ghost"].includes(v),
        },
    },
    computed: {
        rootClass() {
            return [
                "pos-v5-stat",
                `pos-v5-stat--${this.tone}`,
            ];
        },
    },
};
</script>

<style scoped>
.pos-v5-stat {
    display: inline-flex;
    align-items: center;
    gap: var(--pos-v5-space-1);
    min-height: 26px;
    padding: 0 var(--pos-v5-space-3);
    border-radius: var(--pos-v5-radius-pill);
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-caption);
    line-height: 1;
    white-space: nowrap;
}

.pos-v5-stat__icon {
    display: inline-flex;
    align-items: center;
    font-size: 13px;
    line-height: 1;
}

.pos-v5-stat__label {
    font-weight: var(--pos-v5-weight-semibold);
}

.pos-v5-stat__value {
    font-weight: var(--pos-v5-weight-extrabold);
    font-feature-settings: "tnum";
    font-variant-numeric: tabular-nums;
}

/* === TONES === */
.pos-v5-stat--neutral {
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink-soft);
}
.pos-v5-stat--ghost {
    background: transparent;
    color: var(--pos-v5-ink-muted);
}
.pos-v5-stat--brand {
    background: var(--pos-v5-brand-red-soft);
    color: var(--pos-v5-brand-red);
}
.pos-v5-stat--success {
    background: var(--pos-v5-success-soft);
    color: var(--pos-v5-success-dark);
}
.pos-v5-stat--warning {
    background: var(--pos-v5-warning-soft);
    color: var(--pos-v5-warning-dark);
}
.pos-v5-stat--danger {
    background: var(--pos-v5-danger-soft);
    color: var(--pos-v5-danger-dark);
}
.pos-v5-stat--live {
    background: var(--pos-v5-success-soft);
    color: var(--pos-v5-success-dark);
    position: relative;
    padding-left: var(--pos-v5-space-4);
}
.pos-v5-stat--live::before {
    content: "";
    position: absolute;
    left: var(--pos-v5-space-2);
    top: 50%;
    transform: translateY(-50%);
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--pos-v5-success);
    animation: pos-v5-stat-blink 1.6s ease-in-out infinite;
}
.pos-v5-stat--live .pos-v5-stat__icon { display: none; } /* dot remplace icon */
@keyframes pos-v5-stat-blink {
    0%, 100% { box-shadow: 0 0 0 0 rgba(27, 138, 58, 0.0); }
    50%      { box-shadow: 0 0 0 4px rgba(27, 138, 58, 0.30); }
}
@media (prefers-reduced-motion: reduce) {
    .pos-v5-stat--live::before { animation: none; }
}
</style>
