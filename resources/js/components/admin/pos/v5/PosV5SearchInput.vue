<template>
  <form class="pos-v5-search-input" role="search" @submit.prevent="onSubmit">
    <span class="pos-v5-search-input__icon" aria-hidden="true">
      <slot name="icon">🔍</slot>
    </span>
    <input
      ref="input"
      :type="type"
      :value="modelValue"
      :placeholder="placeholder"
      :aria-label="ariaLabel || placeholder"
      class="pos-v5-search-input__field"
      autocomplete="off"
      @input="onInput"
      @keydown="onKeydown"
    />
    <kbd v-if="showKbd" class="pos-v5-search-input__kbd">{{ kbd }}</kbd>
    <button
      v-if="modelValue && clearable"
      type="button"
      class="pos-v5-search-input__clear"
      :aria-label="clearAriaLabel"
      @click.prevent="onClear"
    >
      <span aria-hidden="true">✕</span>
    </button>
    <slot name="trailing" />
  </form>
</template>

<script>
/**
 * PosV5SearchInput — input recherche unifié POS V5.
 *
 * Mission : CV1-POS-DESIGN-CONVERGENCE-001
 * Doc plan : §4.8
 *
 * Slots  : icon (override loupe), trailing (boutons additionnels droite)
 *
 * Usage :
 *   <PosV5SearchInput
 *     v-model="props.search.name"
 *     placeholder="Rechercher un article du menu..."
 *     show-kbd kbd="⌘K"
 *     @submit="search"
 *     @clear="resetName" />
 */
export default {
    name: "PosV5SearchInput",
    props: {
        modelValue: { type: String, default: "" },
        placeholder: { type: String, default: "Rechercher..." },
        ariaLabel: { type: String, default: "" },
        type: { type: String, default: "search" },
        showKbd: { type: Boolean, default: false },
        kbd: { type: String, default: "⌘K" },
        clearable: { type: Boolean, default: true },
        clearAriaLabel: { type: String, default: "Effacer la recherche" },
    },
    emits: ["update:modelValue", "submit", "clear", "input"],
    methods: {
        focus() {
            this.$refs.input?.focus();
        },
        onInput(e) {
            this.$emit("update:modelValue", e.target.value);
            this.$emit("input", e);
        },
        onKeydown(e) {
            if (e.key === "Escape" && this.modelValue) {
                e.preventDefault();
                this.onClear();
            }
        },
        onSubmit() {
            this.$emit("submit", this.modelValue);
        },
        onClear() {
            this.$emit("update:modelValue", "");
            this.$emit("clear");
        },
    },
};
</script>

<style scoped>
.pos-v5-search-input {
    display: flex;
    align-items: center;
    gap: var(--pos-v5-space-2);
    height: 44px;
    padding: 0 var(--pos-v5-space-3);
    background: var(--pos-v5-bg-panel);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-md);
    box-shadow: var(--pos-v5-shadow-sm);
    transition: border-color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                box-shadow var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}

.pos-v5-search-input:focus-within {
    border-color: var(--pos-v5-brand-red);
    box-shadow: 0 0 0 3px var(--pos-v5-brand-red-soft);
}

.pos-v5-search-input__icon {
    flex-shrink: 0;
    color: var(--pos-v5-ink-soft);
    font-size: 14px;
    line-height: 1;
}

.pos-v5-search-input__field {
    flex: 1;
    min-width: 0;
    height: 100%;
    background: transparent;
    border: 0;
    outline: 0;
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-body);
    color: var(--pos-v5-ink);
}
.pos-v5-search-input__field::placeholder {
    color: var(--pos-v5-ink-muted);
    font-weight: var(--pos-v5-weight-medium);
}
.pos-v5-search-input__field::-webkit-search-cancel-button {
    appearance: none;
}

.pos-v5-search-input__kbd {
    display: none;
    padding: 2px var(--pos-v5-space-2);
    border: 1px solid var(--pos-v5-border-strong);
    border-radius: var(--pos-v5-radius-xs);
    background: var(--pos-v5-bg-subtle);
    font-family: var(--pos-v5-font-mono);
    font-size: 10px;
    font-weight: var(--pos-v5-weight-semibold);
    color: var(--pos-v5-ink-soft);
}
@media (min-width: 1024px) {
    .pos-v5-search-input__kbd { display: inline-flex; }
}

.pos-v5-search-input__clear {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: var(--pos-v5-radius-pill);
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink-soft);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.pos-v5-search-input__clear:hover {
    background: var(--pos-v5-danger-soft);
    color: var(--pos-v5-danger);
}
</style>
