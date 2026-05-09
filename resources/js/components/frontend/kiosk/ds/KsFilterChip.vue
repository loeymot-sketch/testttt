<template>
  <div
    :class="[
      'ks-filter-chip',
      { 'ks-filter-chip--active': active, 'ks-filter-chip--disabled': disabled }
    ]"
    role="checkbox"
    :aria-checked="active ? 'true' : 'false'"
    :aria-disabled="disabled ? 'true' : null"
    :aria-labelledby="labelId"
    :tabindex="disabled ? -1 : 0"
    @click="onToggle"
    @keydown.enter.prevent="onToggle"
    @keydown.space.prevent="onToggle"
  >
    <span v-if="icon" class="ks-filter-chip__icon" aria-hidden="true">{{ icon }}</span>
    <span :id="labelId" class="ks-filter-chip__label"><slot>{{ label }}</slot></span>
  </div>
</template>

<script>
let _ksFilterChipUid = 0;
/**
 * KsFilterChip — Chip de filtre diététique / prix pour la grille produits.
 *
 * Usage attendu (DATA_CONTRACT §9.3) :
 *   <KsFilterChip filter="halal" :active="..." icon="🕌" label="Halal"
 *                 @toggle="onFilterToggle('halal')" />
 *
 * Filtres acceptés : 'vegetarian' | 'halal' | 'pork_free' | 'gluten_free' |
 *                    'spicy' | 'under_10'
 *
 * Accessibilité : `role=checkbox` + aria-checked, tabulable, focus ring via
 * tokens-*.css (--kiosk-focus-ring).
 */
export default {
    name: 'KsFilterChip',
    data() {
        return { labelId: `ks-filter-chip-label-${++_ksFilterChipUid}` };
    },
    props: {
        filter: {
            type: String,
            required: true,
            validator: (v) => [
                'vegetarian', 'halal', 'pork_free',
                'gluten_free', 'spicy', 'under_10',
            ].includes(v),
        },
        label: { type: String, default: null },
        icon:  { type: String, default: null },
        active: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
    },
    emits: ['toggle'],
    methods: {
        onToggle() {
            if (this.disabled) return;
            this.$emit('toggle', this.filter);
        },
    },
};
</script>

<style scoped>
.ks-filter-chip {
    display: inline-flex;
    align-items: center;
    gap: var(--kiosk-space-2, 8px);
    padding: var(--kiosk-space-2, 8px) var(--kiosk-space-4, 16px);
    border-radius: var(--kiosk-radius-pill, 9999px);
    background: var(--kiosk-surface, #FFFFFF);
    border: 2px solid var(--kiosk-border, #EEE6D9);
    color: var(--kiosk-text, #1A1A1A);
    font-weight: 600;
    font-size: calc(14px * var(--kiosk-text-scale, 1));
    cursor: pointer;
    user-select: none;
    transition: background-color 120ms ease, border-color 120ms ease, color 120ms ease;
    min-height: 44px;
}

.ks-filter-chip:focus-visible {
    outline: var(--kiosk-focus-width, 4px) solid var(--kiosk-focus-ring, #2563EB);
    outline-offset: var(--kiosk-focus-offset, 2px);
}

.ks-filter-chip--active {
    background: var(--kiosk-primary-soft, #FFF0F2);
    border-color: var(--kiosk-primary, #E8001C);
    color: var(--kiosk-primary, #E8001C);
}

.ks-filter-chip--disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.ks-filter-chip__icon {
    font-size: 1.2em;
    line-height: 1;
}

.ks-filter-chip__label {
    line-height: 1;
    letter-spacing: 0.01em;
}

/* PMR : cibles tactiles ≥ 64px */
:global([data-kiosk-pmr="true"]) .ks-filter-chip {
    min-height: 64px;
    padding: 14px 22px;
    font-size: calc(16px * var(--kiosk-text-scale, 1));
}
</style>
