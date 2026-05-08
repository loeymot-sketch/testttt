<!--
    KioskBurgerLayer — single layer in the burger stack (V2-2 Phase A POC).

    Pure presentational sub-component for KioskBurgerBuilder. Each layer
    represents a dropped ingredient instance and exposes a remove action
    that delegates back to the parent. Animated entry on mount; respects
    prefers-reduced-motion.
-->
<template>
    <!--
        [A11y-HEAL-2026-05-08] Ultra-review P0 fix : layer is now keyboard-focusable
        (tabindex=0) and supports keyboard reorder/delete WCAG 2.1.1 :
          - Delete / Backspace : remove this layer
          - ArrowUp : swap with previous (move up the stack)
          - ArrowDown : swap with next (move down the stack)
          - Escape : blur (defocus to source pool)
        ARIA :
          - role="listitem" (implicit via <li>)
          - aria-keyshortcuts advertises supported keys to screen readers
    -->
    <li
        class="ks-burger-layer"
        :data-index="index"
        data-testid="ks-burger-layer"
        :tabindex="0"
        :aria-label="$t('kiosk.builder.layer_focus_aria', { name: ingredient.name, position: index + 1 })"
        aria-keyshortcuts="Delete Backspace ArrowUp ArrowDown Escape"
        @keydown.delete.prevent="$emit('remove')"
        @keydown.backspace.prevent="$emit('remove')"
        @keydown.up.prevent="$emit('move-up')"
        @keydown.down.prevent="$emit('move-down')"
        @keydown.esc.prevent="$emit('blur-layer')"
    >
        <span class="ks-burger-layer-emoji" aria-hidden="true">{{ ingredient.emoji }}</span>
        <span class="ks-burger-layer-name">{{ ingredient.name }}</span>
        <span class="ks-burger-layer-price">{{ formatPrice(ingredient.price) }}</span>
        <button
            type="button"
            class="ks-burger-layer-remove"
            data-testid="ks-burger-layer-remove"
            :aria-label="$t('kiosk.builder.remove_layer_aria', { name: ingredient.name })"
            @click.stop="$emit('remove')"
        >
            ×
        </button>
    </li>
</template>

<script>
export default {
    name: 'KioskBurgerLayer',
    props: {
        ingredient: {
            type: Object,
            required: true,
        },
        index: {
            type: Number,
            required: true,
        },
    },
    emits: ['remove', 'move-up', 'move-down', 'blur-layer'],
    methods: {
        formatPrice(p) {
            return `${Number(p || 0).toFixed(2)} €`;
        },
    },
};
</script>

<style scoped>
.ks-burger-layer {
    display: flex;
    align-items: center;
    gap: var(--kiosk-space-3, 12px);
    padding: var(--kiosk-space-3, 12px) var(--kiosk-space-4, 16px);
    background: linear-gradient(135deg, #fff8e1 0%, #fff3c4 100%);
    border: 2px solid var(--kiosk-border, #EEE6D9);
    border-radius: var(--kiosk-radius-3, 8px);
    cursor: grab;
    user-select: none;
    animation: ks-burger-layer-add 0.3s ease-out;
}

.ks-burger-layer:active {
    cursor: grabbing;
}

@keyframes ks-burger-layer-add {
    from {
        transform: translateY(-8px) scale(0.95);
        opacity: 0;
    }
    to {
        transform: none;
        opacity: 1;
    }
}

.ks-burger-layer-emoji {
    font-size: 24px;
    line-height: 1;
}

.ks-burger-layer-name {
    flex: 1;
    font-weight: 700;
    color: var(--kiosk-text, #1B1B1B);
}

.ks-burger-layer-price {
    font-weight: 600;
    color: var(--kiosk-text-muted, #5A5A5A);
}

.ks-burger-layer-remove {
    background: transparent;
    border: none;
    font-size: 24px;
    line-height: 1;
    color: var(--kiosk-error, #C21E2F);
    cursor: pointer;
    padding: 4px 10px;
    border-radius: 50%;
    transition: background 0.15s ease-out;
}

.ks-burger-layer-remove:hover,
.ks-burger-layer-remove:focus-visible {
    background: rgba(194, 30, 47, 0.1);
    outline: none;
}

@media (prefers-reduced-motion: reduce) {
    .ks-burger-layer {
        animation: none;
    }
}
</style>
