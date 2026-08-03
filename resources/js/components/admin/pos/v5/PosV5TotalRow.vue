<template>
  <div :class="rootClass">
    <span class="pos-v5-total-row__label">{{ label }}</span>
    <span class="pos-v5-total-row__value">
      <span v-if="sign && sign !== 'none'" class="pos-v5-total-row__sign" aria-hidden="true">{{ sign }}</span>
      <span class="pos-v5-total-row__amount pos-v5-tabular">{{ value }}</span>
    </span>
  </div>
</template>

<script>
/**
 * PosV5TotalRow — ligne de total / sub-total / discount.
 *
 * Mission : CV1-POS-DESIGN-CONVERGENCE-001
 * Doc plan : §4.6
 *
 * Tones    : default | hero (total final, plus gros) | muted (discount) | info (delivery) | success
 * Sign     : + | - | none
 *
 * Usage    : <PosV5TotalRow label="Sous-total" :value="subtotalDisplay" />
 *            <PosV5TotalRow label="Total" :value="totalDisplay" tone="hero" />
 *            <PosV5TotalRow label="Remise" :value="discountDisplay" sign="-" tone="muted" />
 */
export default {
    name: "PosV5TotalRow",
    props: {
        label: { type: String, required: true },
        value: { type: [String, Number], required: true },
        tone: {
            type: String,
            default: "default",
            validator: (v) => ["default", "hero", "muted", "info", "success", "danger"].includes(v),
        },
        sign: {
            type: String,
            default: "none",
            validator: (v) => ["none", "+", "-"].includes(v),
        },
    },
    computed: {
        rootClass() {
            return [
                "pos-v5-total-row",
                `pos-v5-total-row--${this.tone}`,
            ];
        },
    },
};
</script>

<style scoped>
.pos-v5-total-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--pos-v5-space-3);
    padding: var(--pos-v5-space-2) 0;
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-body);
    color: var(--pos-v5-ink);
    line-height: var(--pos-v5-leading-tight);
}

.pos-v5-total-row__label {
    font-weight: var(--pos-v5-weight-medium);
    color: var(--pos-v5-ink-soft);
}

.pos-v5-total-row__value {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    font-weight: var(--pos-v5-weight-bold);
    color: var(--pos-v5-ink);
}

.pos-v5-total-row__sign {
    font-weight: var(--pos-v5-weight-bold);
    margin-right: 2px;
    opacity: 0.85;
}

.pos-v5-total-row__amount {
    font-feature-settings: "tnum";
    font-variant-numeric: tabular-nums;
}

/* === TONES === */
.pos-v5-total-row--muted .pos-v5-total-row__value {
    color: var(--pos-v5-ink-soft);
    font-weight: var(--pos-v5-weight-medium);
}

.pos-v5-total-row--info .pos-v5-total-row__value {
    color: var(--pos-v5-info);
}

.pos-v5-total-row--success .pos-v5-total-row__value {
    color: var(--pos-v5-success-dark);
}

.pos-v5-total-row--danger .pos-v5-total-row__value {
    color: var(--pos-v5-danger);
}

.pos-v5-total-row--hero {
    margin-top: var(--pos-v5-space-2);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
    background: linear-gradient(135deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel));
    border: 1px solid var(--pos-v5-brand-red-soft);
    border-radius: var(--pos-v5-radius-md);
}
.pos-v5-total-row--hero .pos-v5-total-row__label {
    font-size: var(--pos-v5-text-h6);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-ink);
    letter-spacing: var(--pos-v5-tracking-tight);
    text-transform: none;
}
.pos-v5-total-row--hero .pos-v5-total-row__value {
    font-size: var(--pos-v5-text-h3);
    font-weight: var(--pos-v5-weight-black);
    color: var(--pos-v5-brand-red);
    letter-spacing: var(--pos-v5-tracking-tight);
}
</style>
