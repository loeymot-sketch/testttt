<template>
  <div :class="rootClass" role="group" :aria-label="ariaLabel">
    <button
      type="button"
      class="pos-v5-qty__btn pos-v5-qty__btn--minus"
      :class="{ 'pos-v5-qty__btn--trash': showTrash }"
      :disabled="disabledMinus"
      :aria-label="showTrash ? removeAriaLabel : decrementAriaLabel"
      @click.prevent="handleDecrement"
    >
      <span aria-hidden="true">{{ showTrash ? '🗑' : '−' }}</span>
    </button>
    <input
      type="number"
      class="pos-v5-qty__value pos-v5-tabular"
      :value="modelValue"
      :min="min"
      :max="max"
      :readonly="readonly"
      :aria-label="ariaLabel || 'quantity'"
      @keypress="onKeypress"
      @input="onInput"
    />
    <button
      type="button"
      class="pos-v5-qty__btn pos-v5-qty__btn--plus"
      :disabled="disabledPlus"
      :aria-label="incrementAriaLabel"
      @click.prevent="handleIncrement"
    >
      <span aria-hidden="true">+</span>
    </button>
  </div>
</template>

<script>
/**
 * PosV5QtyStepper — stepper +/- de quantité.
 *
 * Mission : CV1-POS-DESIGN-CONVERGENCE-001
 * Doc plan : §4.7
 *
 * Sizes  : sm (28px) | md (36px) | lg (48px)
 *
 * Behavior :
 *   - Bouton "-" devient icône poubelle quand value === minTrash (cf. carts UX existante)
 *   - Disabled si > max ou < min
 *   - Émission @increment / @decrement (parent gère persistance)
 *   - v-model value display (number input)
 *
 * Usage :
 *   <PosV5QtyStepper :model-value="cart.quantity"
 *                    :show-trash="cart.quantity === 1"
 *                    @increment="cartQuantityIncrement(index)"
 *                    @decrement="cartQuantityDecrement(index)" />
 */
export default {
    name: "PosV5QtyStepper",
    props: {
        modelValue: { type: [Number, String], required: true },
        min: { type: Number, default: 0 },
        max: { type: Number, default: 999 },
        size: {
            type: String,
            default: "md",
            validator: (v) => ["sm", "md", "lg"].includes(v),
        },
        showTrash: { type: Boolean, default: false },
        readonly: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
        ariaLabel: { type: String, default: "" },
        incrementAriaLabel: { type: String, default: "Augmenter la quantité" },
        decrementAriaLabel: { type: String, default: "Diminuer la quantité" },
        removeAriaLabel: { type: String, default: "Retirer cet article" },
    },
    emits: ["update:modelValue", "increment", "decrement", "remove"],
    computed: {
        rootClass() {
            return [
                "pos-v5-qty",
                `pos-v5-qty--${this.size}`,
                { "pos-v5-qty--disabled": this.disabled },
            ];
        },
        disabledMinus() {
            return this.disabled || (Number(this.modelValue) <= this.min && !this.showTrash);
        },
        disabledPlus() {
            return this.disabled || Number(this.modelValue) >= this.max;
        },
    },
    methods: {
        handleIncrement() {
            if (this.disabledPlus) return;
            this.$emit("increment");
        },
        handleDecrement() {
            if (this.disabledMinus) return;
            if (this.showTrash) {
                this.$emit("remove");
            } else {
                this.$emit("decrement");
            }
        },
        onInput(e) {
            const value = parseInt(e.target.value, 10);
            if (!Number.isNaN(value)) {
                this.$emit("update:modelValue", value);
            }
        },
        onKeypress(e) {
            // numeric only — let parent inject onlyNumber if needed
            if (e.key && !/[0-9]/.test(e.key) && e.key !== "Backspace" && e.key !== "Delete") {
                e.preventDefault();
            }
        },
    },
};
</script>

<style scoped>
.pos-v5-qty {
    display: inline-flex;
    align-items: center;
    gap: var(--pos-v5-space-1);
    padding: 3px;
    background: var(--pos-v5-bg-subtle);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-pill);
}

.pos-v5-qty--disabled {
    opacity: 0.5;
    pointer-events: none;
}

.pos-v5-qty__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--pos-v5-radius-pill);
    background: var(--pos-v5-bg-panel);
    color: var(--pos-v5-brand-red);
    border: 1px solid var(--pos-v5-brand-red);
    font-weight: var(--pos-v5-weight-extrabold);
    line-height: 1;
    cursor: pointer;
    appearance: none;
    transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                border-color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                transform var(--pos-v5-duration-fast) var(--pos-v5-ease-bounce);
}

.pos-v5-qty__btn:hover:not(:disabled) {
    background: var(--pos-v5-brand-red);
    color: var(--pos-v5-ink-on-red);
    transform: scale(1.06);
}
.pos-v5-qty__btn:active:not(:disabled) {
    transform: scale(0.96);
}
.pos-v5-qty__btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.pos-v5-qty__btn--trash {
    color: var(--pos-v5-danger);
    border-color: var(--pos-v5-danger);
}
.pos-v5-qty__btn--trash:hover:not(:disabled) {
    background: var(--pos-v5-danger);
    color: var(--pos-v5-ink-on-red);
}

.pos-v5-qty__value {
    border: 0;
    background: transparent;
    text-align: center;
    font-family: var(--pos-v5-font-sans);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-ink);
    appearance: textfield;
    -moz-appearance: textfield;
    outline: none;
}
.pos-v5-qty__value::-webkit-outer-spin-button,
.pos-v5-qty__value::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.pos-v5-qty__value:focus {
    background: var(--pos-v5-bg-panel);
    border-radius: var(--pos-v5-radius-sm);
    box-shadow: 0 0 0 2px var(--pos-v5-brand-red-soft);
}

/* === SIZES === */
/* [ux-perfection 2026-06-18 P1 caisse-16"] The cart-line stepper is the single most-repeated cashier
   gesture and the minus button IS the destructive remove-line trash — 22px was a sub-touch-floor mis-tap
   hazard for a seated one-handed cashier at ~50cm. Raised to a real 40px target (qty column has ~178px
   headroom at 1920, no cart-line overflow). 'sm' is used only by the POS cart line. */
.pos-v5-qty--sm .pos-v5-qty__btn { width: 40px; height: 40px; font-size: 15px; }
.pos-v5-qty--sm .pos-v5-qty__value { width: 34px; font-size: 15px; padding: 0 4px; }

.pos-v5-qty--md .pos-v5-qty__btn { width: 28px; height: 28px; font-size: 13px; }
.pos-v5-qty--md .pos-v5-qty__value { width: 36px; font-size: 14px; padding: 0 4px; }

.pos-v5-qty--lg .pos-v5-qty__btn { width: 36px; height: 36px; font-size: 16px; }
.pos-v5-qty--lg .pos-v5-qty__value { width: 48px; font-size: 16px; padding: 0 6px; }
</style>
