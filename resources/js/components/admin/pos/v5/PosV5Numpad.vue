<template>
  <div class="pos-v5-numpad-wrap">
    <!-- [T-4.4 BILLETS-ABSENTS 2026-08-15 · GOAL_CONFORT_MAX] Opt-in via la prop
         `denominations` (défaut []) — rien ne change pour les consommateurs qui ne la
         passent pas (PaymentComponent.vue FROZEN §7 notamment, jamais touché). -->
    <div v-if="denominations.length" class="pos-v5-numpad__bills" role="group" :aria-label="billsAriaLabel">
      <button
        v-for="d in denominations"
        :key="`bill-${d}`"
        type="button"
        class="pos-v5-numpad__bill"
        :aria-label="`${d} €`"
        @click.prevent="$emit('denomination', d)"
      >
        {{ d }}&nbsp;€
      </button>
    </div>
    <div class="pos-v5-numpad" role="group" :aria-label="ariaLabel">
      <button
        v-for="key in keys"
        :key="key.id"
        type="button"
        :class="['pos-v5-numpad__key', `pos-v5-numpad__key--${key.kind}`, key.span ? `pos-v5-numpad__key--span-${key.span}` : '']"
        :aria-label="key.aria || key.label"
        @click.prevent="onKey(key)"
      >
        <slot v-if="key.slot" :name="key.slot" :keyData="key">{{ key.label }}</slot>
        <span v-else aria-hidden="true">{{ key.label }}</span>
      </button>
    </div>
  </div>
</template>

<script>
/**
 * PosV5Numpad — pavé numérique unifié POS V5.
 *
 * Mission : CV1-POS-DESIGN-CONVERGENCE-001
 * Doc plan : §4.5
 *
 * Réutilisé en : paiement (PaymentComponent), futurs override prix admin.
 *
 * Layout 4×4 :
 *   [1] [2] [3] [⌫]   ← backspace span 1
 *   [4] [5] [6] [⌫]
 *   [7] [8] [9] [C]   ← clear span 1
 *   [00][0] [.] [C]
 *
 * Émissions :
 *   - @input(value) — touche numérique pressée (1, 2, ..., '00', '.')
 *   - @back        — backspace
 *   - @clear       — clear total
 *
 * L'API est intentionnellement event-only (pas de v-model) pour permettre
 * au parent de cibler un input spécifique (cash vs card).
 */
export default {
    name: "PosV5Numpad",
    props: {
        ariaLabel: { type: String, default: "Pavé numérique" },
        decimalSeparator: { type: String, default: "." },
        // [T-4.4 BILLETS-ABSENTS 2026-08-15] Coupures rapides (ex. [5, 10, 20, 50]).
        // Vide par défaut = aucune ligne rendue, comportement 100% inchangé pour les
        // consommateurs existants qui ne passent pas cette prop.
        denominations: { type: Array, default: () => [] },
        billsAriaLabel: { type: String, default: "Coupures rapides" },
    },
    emits: ["input", "back", "clear", "denomination"],
    computed: {
        keys() {
            return [
                { id: "1", label: "1", kind: "num", value: "1" },
                { id: "2", label: "2", kind: "num", value: "2" },
                { id: "3", label: "3", kind: "num", value: "3" },
                { id: "back", label: "⌫", kind: "back", aria: "Effacer le dernier chiffre" },
                { id: "4", label: "4", kind: "num", value: "4" },
                { id: "5", label: "5", kind: "num", value: "5" },
                { id: "6", label: "6", kind: "num", value: "6" },
                { id: "back2", label: "⌫", kind: "back", aria: "Effacer le dernier chiffre" },
                { id: "7", label: "7", kind: "num", value: "7" },
                { id: "8", label: "8", kind: "num", value: "8" },
                { id: "9", label: "9", kind: "num", value: "9" },
                { id: "clear", label: "C", kind: "clear", aria: "Effacer tout" },
                { id: "00", label: "00", kind: "num", value: "00" },
                { id: "0", label: "0", kind: "num", value: "0" },
                { id: ".", label: this.decimalSeparator, kind: "num", value: this.decimalSeparator },
                { id: "clear2", label: "C", kind: "clear", aria: "Effacer tout" },
            ];
        },
    },
    methods: {
        onKey(key) {
            if (key.kind === "num") {
                this.$emit("input", key.value);
            } else if (key.kind === "back") {
                this.$emit("back");
            } else if (key.kind === "clear") {
                this.$emit("clear");
            }
        },
    },
};
</script>

<style scoped>
.pos-v5-numpad-wrap {
    display: flex;
    flex-direction: column;
    gap: var(--pos-v5-space-2);
}

.pos-v5-numpad__bills {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--pos-v5-space-2);
}

.pos-v5-numpad__bill {
    min-height: 44px;
    padding: var(--pos-v5-space-2);
    font-weight: 600;
    border-radius: var(--pos-v5-radius-md);
    border: 1px solid var(--pos-v5-border);
    background: #fff;
    cursor: pointer;
}

.pos-v5-numpad {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--pos-v5-space-2);
    padding: var(--pos-v5-space-2);
    background: var(--pos-v5-bg-subtle);
    border-radius: var(--pos-v5-radius-md);
    border: 1px solid var(--pos-v5-border);
}

.pos-v5-numpad__key {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 56px;
    padding: var(--pos-v5-space-2);
    background: var(--pos-v5-bg-panel);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-md);
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h5);
    font-weight: var(--pos-v5-weight-bold);
    color: var(--pos-v5-ink);
    cursor: pointer;
    appearance: none;
    transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                border-color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                box-shadow var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                transform var(--pos-v5-duration-fast) var(--pos-v5-ease-bounce);
    box-shadow: var(--pos-v5-shadow-sm);
    line-height: 1;
}

.pos-v5-numpad__key:hover {
    background: var(--pos-v5-brand-red-soft);
    border-color: var(--pos-v5-brand-red);
    color: var(--pos-v5-brand-red);
    box-shadow: var(--pos-v5-shadow-md);
}

.pos-v5-numpad__key:active {
    transform: scale(0.96);
    box-shadow: var(--pos-v5-shadow-inset);
}

.pos-v5-numpad__key:focus-visible {
    outline: var(--pos-v5-focus-width) solid var(--pos-v5-focus-color);
    outline-offset: var(--pos-v5-focus-offset);
}

/* === KEY VARIANTS === */
.pos-v5-numpad__key--back,
.pos-v5-numpad__key--clear {
    background: var(--pos-v5-bg-panel);
    color: var(--pos-v5-danger);
    font-size: var(--pos-v5-text-body-lg);
}

.pos-v5-numpad__key--back:hover,
.pos-v5-numpad__key--clear:hover {
    background: var(--pos-v5-danger-soft);
    border-color: var(--pos-v5-danger);
    color: var(--pos-v5-danger-dark);
}

.pos-v5-numpad__key--span-2 {
    grid-row: span 2;
}
</style>
