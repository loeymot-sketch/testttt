<template>
  <component
    :is="tag"
    :type="tag === 'button' ? type : null"
    :to="tag === 'router-link' ? to : null"
    :href="tag === 'a' ? href : null"
    :target="tag === 'a' || tag === 'router-link' ? target : null"
    :rel="tag === 'a' && target === '_blank' ? 'noopener' : rel"
    :disabled="(tag === 'button' && (disabled || loading)) || null"
    :aria-disabled="disabled || loading ? 'true' : null"
    :aria-busy="loading ? 'true' : null"
    :class="rootClass"
    @click="onClick"
  >
    <span v-if="$slots.icon || icon" class="pos-v5-btn__icon" aria-hidden="true">
      <span v-if="loading" class="pos-v5-btn__spinner"></span>
      <slot v-else name="icon">{{ icon }}</slot>
    </span>
    <span class="pos-v5-btn__label"><slot /></span>
    <span v-if="$slots.badge || (badge !== null && badge !== undefined && badge !== '')" class="pos-v5-btn__badge">
      <slot name="badge">{{ badge }}</slot>
    </span>
  </component>
</template>

<script>
/**
 * PosV5Button — bouton unifié POS V5.
 *
 * Mission : CV1-POS-DESIGN-CONVERGENCE-001
 * Doc plan : plans/PLAN_POS_V5_DESIGN_CONVERGENCE_2026-05-02.md §4.1
 *
 * Variants : primary | primary-pay | secondary | ghost | ghost-counter
 *           | danger | danger-ghost | success | kiosk-cash | tracker
 * Sizes    : sm (32px) | md (40px) | lg (48px) | xl (56px)
 * Tones    : applicables sur tracker (neutral | ready)
 *
 * Slots    : icon (icône leading), default (label), badge (compteur trailing)
 *
 * Usage    : <PosV5Button variant="primary-pay" size="lg" icon="💳" @click="...">
 *              Encaisser 8,90 €
 *            </PosV5Button>
 */
export default {
    name: "PosV5Button",
    props: {
        variant: {
            type: String,
            default: "primary",
            validator: (v) => [
                "primary",
                "primary-pay",
                "secondary",
                "ghost",
                "ghost-counter",
                "danger",
                "danger-ghost",
                "success",
                "kiosk-cash",
                "tracker",
            ].includes(v),
        },
        size: {
            type: String,
            default: "md",
            validator: (v) => ["sm", "md", "lg", "xl"].includes(v),
        },
        tone: {
            type: String,
            default: "neutral",
            validator: (v) => ["neutral", "ready", "live"].includes(v),
        },
        type: {
            type: String,
            default: "button",
        },
        as: {
            type: String,
            default: "button",
            validator: (v) => ["button", "router-link", "a"].includes(v),
        },
        to: { type: [String, Object], default: null },
        href: { type: String, default: null },
        target: { type: String, default: null },
        rel: { type: String, default: null },
        disabled: { type: Boolean, default: false },
        loading: { type: Boolean, default: false },
        icon: { type: String, default: "" },
        badge: { type: [String, Number], default: null },
        block: { type: Boolean, default: false },
    },
    emits: ["click"],
    computed: {
        tag() {
            return this.as;
        },
        rootClass() {
            return [
                "pos-v5-btn",
                `pos-v5-btn--${this.variant}`,
                `pos-v5-btn--${this.size}`,
                {
                    "pos-v5-btn--block": this.block,
                    "is-loading": this.loading,
                    "is-disabled": this.disabled,
                    [`is-tone-${this.tone}`]: this.tone !== "neutral",
                },
            ];
        },
    },
    methods: {
        onClick(event) {
            if (this.disabled || this.loading) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }
            this.$emit("click", event);
        },
    },
};
</script>

<style scoped>
.pos-v5-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--pos-v5-space-2);
    padding: 0 var(--pos-v5-space-3);
    border: 1px solid transparent;
    border-radius: var(--pos-v5-radius-md);
    font-family: var(--pos-v5-font-sans);
    font-weight: var(--pos-v5-weight-semibold);
    line-height: 1;
    text-decoration: none;
    cursor: pointer;
    appearance: none;
    user-select: none;
    transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                border-color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                box-shadow var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                transform var(--pos-v5-duration-fast) var(--pos-v5-ease-bounce);
    white-space: nowrap;
    position: relative;
}

.pos-v5-btn:focus-visible {
    outline: var(--pos-v5-focus-width) solid var(--pos-v5-focus-color);
    outline-offset: var(--pos-v5-focus-offset);
}

.pos-v5-btn.is-disabled,
.pos-v5-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.pos-v5-btn.is-loading {
    cursor: progress;
}

.pos-v5-btn:active:not(.is-disabled):not(:disabled) {
    transform: translateY(1px);
}

/* === SIZES === */
.pos-v5-btn--sm { min-height: 32px; font-size: var(--pos-v5-text-caption); padding: 0 var(--pos-v5-space-3); }
.pos-v5-btn--md { min-height: 40px; font-size: var(--pos-v5-text-body); padding: 0 var(--pos-v5-space-3); }
.pos-v5-btn--lg { min-height: 48px; font-size: var(--pos-v5-text-body-lg); padding: 0 var(--pos-v5-space-4); border-radius: var(--pos-v5-radius-lg); }
.pos-v5-btn--xl { min-height: 56px; font-size: var(--pos-v5-text-h6); padding: 0 var(--pos-v5-space-5); border-radius: var(--pos-v5-radius-lg); font-weight: var(--pos-v5-weight-bold); }

.pos-v5-btn--block { width: 100%; }

/* === LABEL & SLOTS === */
.pos-v5-btn__label {
    display: inline-flex;
    align-items: center;
    gap: var(--pos-v5-space-1);
    min-width: 0;
}
.pos-v5-btn__label:empty { display: none; }

.pos-v5-btn__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1.1em;
    line-height: 1;
}

.pos-v5-btn__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    margin-left: var(--pos-v5-space-1);
    border-radius: var(--pos-v5-radius-pill);
    background: rgba(255, 255, 255, 0.85);
    color: var(--pos-v5-ink);
    font-size: 11px;
    font-weight: var(--pos-v5-weight-extrabold);
    line-height: 1;
    flex-shrink: 0;
}

/* Spinner inline */
.pos-v5-btn__spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: pos-v5-btn-spin 0.7s linear infinite;
    opacity: 0.7;
}
@keyframes pos-v5-btn-spin {
    to { transform: rotate(360deg); }
}
@media (prefers-reduced-motion: reduce) {
    .pos-v5-btn__spinner { animation: none; }
}

/* =============================================================================
   VARIANT: primary (CTA brand)
   ============================================================================= */
.pos-v5-btn--primary {
    background: var(--pos-v5-brand-red);
    border-color: var(--pos-v5-brand-red);
    color: var(--pos-v5-ink-on-red);
    box-shadow: var(--pos-v5-shadow-cta-soft);
}
.pos-v5-btn--primary:hover:not(.is-disabled):not(:disabled) {
    background: var(--pos-v5-brand-red-dark);
    border-color: var(--pos-v5-brand-red-dark);
    box-shadow: var(--pos-v5-shadow-cta);
}
.pos-v5-btn--primary .pos-v5-btn__badge {
    background: rgba(255, 255, 255, 0.95);
    color: var(--pos-v5-brand-red);
}

/* =============================================================================
   VARIANT: primary-pay (CTA paiement principal — encaisser X €)
   ============================================================================= */
.pos-v5-btn--primary-pay {
    background: linear-gradient(135deg, var(--pos-v5-brand-red), var(--pos-v5-brand-red-dark));
    border-color: var(--pos-v5-brand-red-dark);
    color: var(--pos-v5-ink-on-red);
    box-shadow: var(--pos-v5-shadow-cta);
    font-weight: var(--pos-v5-weight-extrabold);
    letter-spacing: var(--pos-v5-tracking-tight);
}
.pos-v5-btn--primary-pay:hover:not(.is-disabled):not(:disabled) {
    box-shadow: 0 12px 28px rgba(244, 80, 30, 0.32);
    transform: translateY(-1px);
}

/* =============================================================================
   VARIANT: secondary (subtle warm — actions secondaires comme "Mettre en attente")
   ============================================================================= */
.pos-v5-btn--secondary {
    background: var(--pos-v5-bg-subtle);
    border-color: var(--pos-v5-border);
    color: var(--pos-v5-ink);
}
.pos-v5-btn--secondary:hover:not(.is-disabled):not(:disabled) {
    background: var(--pos-v5-brand-red-faint);
    border-color: var(--pos-v5-brand-red);
    color: var(--pos-v5-brand-red);
}

/* =============================================================================
   VARIANT: ghost (transparent, bordure default)
   ============================================================================= */
.pos-v5-btn--ghost {
    background: var(--pos-v5-bg-panel);
    border-color: var(--pos-v5-border);
    color: var(--pos-v5-ink);
}
.pos-v5-btn--ghost:hover:not(.is-disabled):not(:disabled) {
    background: var(--pos-v5-bg-subtle);
    border-color: var(--pos-v5-border-strong);
}

/* =============================================================================
   VARIANT: ghost-counter (avec badge count à droite — "Tickets en attente (3)")
   ============================================================================= */
.pos-v5-btn--ghost-counter {
    background: var(--pos-v5-bg-panel);
    border-color: var(--pos-v5-border);
    color: var(--pos-v5-ink);
}
.pos-v5-btn--ghost-counter:hover:not(.is-disabled):not(:disabled) {
    background: var(--pos-v5-bg-subtle);
    border-color: var(--pos-v5-brand-red);
    color: var(--pos-v5-brand-red);
}
.pos-v5-btn--ghost-counter .pos-v5-btn__badge {
    background: var(--pos-v5-brand-red);
    color: var(--pos-v5-ink-on-red);
}

/* =============================================================================
   VARIANT: danger
   ============================================================================= */
.pos-v5-btn--danger {
    background: var(--pos-v5-danger);
    border-color: var(--pos-v5-danger);
    color: var(--pos-v5-ink-on-red);
}
.pos-v5-btn--danger:hover:not(.is-disabled):not(:disabled) {
    background: var(--pos-v5-danger-dark);
    border-color: var(--pos-v5-danger-dark);
}

/* =============================================================================
   VARIANT: danger-ghost (lien danger discret)
   ============================================================================= */
.pos-v5-btn--danger-ghost {
    background: transparent;
    border-color: transparent;
    color: var(--pos-v5-danger);
    font-weight: var(--pos-v5-weight-semibold);
}
.pos-v5-btn--danger-ghost:hover:not(.is-disabled):not(:disabled) {
    background: var(--pos-v5-danger-soft);
    color: var(--pos-v5-danger-dark);
}

/* =============================================================================
   VARIANT: success
   ============================================================================= */
.pos-v5-btn--success {
    background: var(--pos-v5-success);
    border-color: var(--pos-v5-success);
    color: var(--pos-v5-ink-on-dark);
    box-shadow: var(--pos-v5-shadow-success);
}
.pos-v5-btn--success:hover:not(.is-disabled):not(:disabled) {
    background: var(--pos-v5-success-dark);
    border-color: var(--pos-v5-success-dark);
}

/* =============================================================================
   VARIANT: kiosk-cash (bouton "Borne en attente" pulsant)
   ============================================================================= */
.pos-v5-btn--kiosk-cash {
    background: linear-gradient(135deg, var(--pos-v5-brand-red), var(--pos-v5-brand-red-dark));
    border-color: var(--pos-v5-brand-red-dark);
    color: var(--pos-v5-ink-on-red);
    box-shadow: var(--pos-v5-shadow-cta-soft);
    animation: pos-v5-btn-pulse 2.4s ease-in-out infinite;
}
.pos-v5-btn--kiosk-cash:hover:not(.is-disabled):not(:disabled) {
    box-shadow: var(--pos-v5-shadow-cta);
    transform: translateY(-1px);
}
.pos-v5-btn--kiosk-cash .pos-v5-btn__badge {
    background: rgba(255, 255, 255, 0.95);
    color: var(--pos-v5-brand-red);
}
@keyframes pos-v5-btn-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(244, 80, 30, 0); }
    50%      { box-shadow: 0 0 0 6px rgba(244, 80, 30, 0.18); }
}
@media (prefers-reduced-motion: reduce) {
    .pos-v5-btn--kiosk-cash { animation: none; }
}

/* =============================================================================
   VARIANT: tracker (suivi commandes — neutral / ready)
   ============================================================================= */
.pos-v5-btn--tracker {
    background: var(--pos-v5-bg-panel);
    border-color: var(--pos-v5-border);
    color: var(--pos-v5-ink);
}
.pos-v5-btn--tracker:hover:not(.is-disabled):not(:disabled) {
    background: var(--pos-v5-bg-subtle);
    border-color: var(--pos-v5-border-strong);
}
.pos-v5-btn--tracker.is-tone-ready {
    background: var(--pos-v5-success-soft);
    border-color: var(--pos-v5-success);
    color: var(--pos-v5-success-dark);
    animation: pos-v5-btn-glow-success 2.6s ease-in-out infinite;
}
.pos-v5-btn--tracker.is-tone-ready .pos-v5-btn__badge {
    background: var(--pos-v5-success);
    color: var(--pos-v5-ink-on-dark);
}
@keyframes pos-v5-btn-glow-success {
    0%, 100% { box-shadow: 0 0 0 0 rgba(27, 138, 58, 0); }
    50%      { box-shadow: 0 0 0 5px rgba(27, 138, 58, 0.18); }
}
@media (prefers-reduced-motion: reduce) {
    .pos-v5-btn--tracker.is-tone-ready { animation: none; }
}
</style>
