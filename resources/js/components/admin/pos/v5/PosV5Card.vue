<template>
  <component :is="tag" :class="rootClass" :data-tone="tone">
    <header v-if="$slots.head || title || eyebrow" class="pos-v5-card__head">
      <slot name="head">
        <p v-if="eyebrow" class="pos-v5-card__eyebrow">{{ eyebrow }}</p>
        <h3 v-if="title" class="pos-v5-card__title">{{ title }}</h3>
      </slot>
      <div v-if="$slots.headActions" class="pos-v5-card__head-actions">
        <slot name="headActions" />
      </div>
    </header>
    <div :class="['pos-v5-card__body', { 'pos-v5-card__body--flush': flushBody }]">
      <slot />
    </div>
    <footer v-if="$slots.foot" class="pos-v5-card__foot">
      <slot name="foot" />
    </footer>
  </component>
</template>

<script>
/**
 * PosV5Card — conteneur warm unifié POS V5.
 *
 * Mission : CV1-POS-DESIGN-CONVERGENCE-001
 * Doc plan : §4.2
 *
 * Tones    : default | brand | success | warning | danger | inverse
 * Padding  : sm | md | lg | none (flushBody pour body sans padding)
 * Tag      : article | section | div
 *
 * Slots    : default (body), head, headActions, foot
 */
export default {
    name: "PosV5Card",
    props: {
        tone: {
            type: String,
            default: "default",
            validator: (v) => ["default", "brand", "success", "warning", "danger", "inverse"].includes(v),
        },
        padding: {
            type: String,
            default: "md",
            validator: (v) => ["sm", "md", "lg", "none"].includes(v),
        },
        title: { type: String, default: "" },
        eyebrow: { type: String, default: "" },
        as: {
            type: String,
            default: "article",
            validator: (v) => ["article", "section", "div", "aside"].includes(v),
        },
        elevated: { type: Boolean, default: false },
        flushBody: { type: Boolean, default: false },
    },
    computed: {
        tag() { return this.as; },
        rootClass() {
            return [
                "pos-v5-card",
                `pos-v5-card--${this.tone}`,
                `pos-v5-card--p-${this.padding}`,
                { "pos-v5-card--elevated": this.elevated },
            ];
        },
    },
};
</script>

<style scoped>
.pos-v5-card {
    display: flex;
    flex-direction: column;
    background: var(--pos-v5-bg-panel);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-lg);
    box-shadow: var(--pos-v5-shadow-sm);
    overflow: hidden;
    transition: box-shadow var(--pos-v5-duration-base) var(--pos-v5-ease-standard);
}

.pos-v5-card--elevated {
    box-shadow: var(--pos-v5-shadow-md);
}

/* === TONES === */
.pos-v5-card--default { /* default */ }

.pos-v5-card--brand {
    border-color: var(--pos-v5-brand-red);
    border-left-width: 4px;
}

.pos-v5-card--success {
    border-color: var(--pos-v5-success);
    background: var(--pos-v5-success-soft);
}

.pos-v5-card--warning {
    border-color: var(--pos-v5-warning);
    background: var(--pos-v5-warning-soft);
}

.pos-v5-card--danger {
    border-color: var(--pos-v5-danger);
    background: var(--pos-v5-danger-soft);
}

.pos-v5-card--inverse {
    background: var(--pos-v5-bg-strong);
    color: var(--pos-v5-ink-on-dark);
    border-color: var(--pos-v5-bg-strong);
}

/* === HEAD === */
.pos-v5-card__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--pos-v5-space-3);
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5);
    border-bottom: 1px solid var(--pos-v5-border);
    background: var(--pos-v5-bg-panel);
}
.pos-v5-card--brand .pos-v5-card__head {
    background: linear-gradient(180deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel) 70%);
}

.pos-v5-card__eyebrow {
    margin: 0;
    font-size: var(--pos-v5-text-eyebrow);
    font-weight: var(--pos-v5-weight-bold);
    letter-spacing: var(--pos-v5-tracking-caps);
    text-transform: uppercase;
    color: var(--pos-v5-ink-soft);
    line-height: 1;
}
.pos-v5-card--brand .pos-v5-card__eyebrow {
    color: var(--pos-v5-brand-red);
}

.pos-v5-card__title {
    margin: 4px 0 0;
    font-size: var(--pos-v5-text-h5);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-ink);
    line-height: var(--pos-v5-leading-tight);
}

.pos-v5-card__head-actions {
    display: inline-flex;
    align-items: center;
    gap: var(--pos-v5-space-2);
    flex-shrink: 0;
}

/* === BODY paddings === */
.pos-v5-card--p-none .pos-v5-card__body { padding: 0; }
.pos-v5-card--p-sm .pos-v5-card__body { padding: var(--pos-v5-space-3); }
.pos-v5-card--p-md .pos-v5-card__body { padding: var(--pos-v5-space-4) var(--pos-v5-space-5); }
.pos-v5-card--p-lg .pos-v5-card__body { padding: var(--pos-v5-space-6); }

.pos-v5-card__body--flush { padding: 0 !important; }

/* === FOOT === */
.pos-v5-card__foot {
    padding: var(--pos-v5-space-3) var(--pos-v5-space-5);
    border-top: 1px solid var(--pos-v5-border);
    background: var(--pos-v5-bg-panel);
}
</style>
