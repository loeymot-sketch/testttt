<!--
    KioskBurgerBuilder — POC standalone (V2-2 Phase A)

    Greenfield drag-and-drop builder POC. Provides a side-by-side source
    pool of ingredients (drag origin, clone-on-drag) and a stack-style
    drop zone where each layer can be reordered or removed. Includes a
    fallback button that escapes back to the classic wizard mode and a
    keyboard-only path (Enter / Space on a focused ingredient adds it
    to the burger) so the POC remains a11y-compliant on day one.

    Phase A discipline:
      - No touch to any frozen wizard component.
      - Uses the codebase-installed `vue-draggable-next` (v-for inside
        the draggable, default slot only — that's the API contract for
        this library, NOT the `#item` slot of `vuedraggable`).
      - Self-contained styles using the kiosk DS tokens.
-->
<template>
    <div class="ks-burger-builder" role="application" :aria-label="$t('kiosk.builder.aria_label')">
        <!-- Source pool : drag origin (clone) -->
        <section class="ks-burger-source" role="region" :aria-label="$t('kiosk.builder.source_label')">
            <h3 class="ks-burger-source-title">{{ $t('kiosk.builder.ingredients') }}</h3>
            <draggable
                v-model="sourceIngredients"
                :group="{ name: 'ingredients', pull: 'clone', put: false }"
                :sort="false"
                :clone="cloneIngredient"
                tag="ul"
                class="ks-burger-source-list"
                ghost-class="ks-burger-ghost"
                chosen-class="ks-burger-chosen"
                drag-class="ks-burger-dragging"
                :animation="150"
                data-testid="ks-burger-source"
            >
                <li
                    v-for="element in sourceIngredients"
                    :key="element.id"
                    class="ks-burger-ingredient"
                    :tabindex="0"
                    :aria-label="$t('kiosk.builder.ingredient_aria', { name: element.name, price: formatPriceValue(element.price) })"
                    data-testid="ks-burger-ingredient"
                    @keydown.enter.prevent="handleKeyboardAdd(element)"
                    @keydown.space.prevent="handleKeyboardAdd(element)"
                >
                    <span class="ks-burger-ingredient-emoji" aria-hidden="true">{{ element.emoji }}</span>
                    <span class="ks-burger-ingredient-name">{{ element.name }}</span>
                    <span class="ks-burger-ingredient-price">+{{ formatPrice(element.price) }}</span>
                </li>
            </draggable>
        </section>

        <!-- Drop zone : burger stack -->
        <section class="ks-burger-target" role="region" :aria-label="$t('kiosk.builder.target_label')">
            <h3 class="ks-burger-target-title">{{ $t('kiosk.builder.your_burger') }}</h3>
            <draggable
                v-model="droppedIngredients"
                :group="{ name: 'ingredients' }"
                tag="ul"
                class="ks-burger-target-list"
                ghost-class="ks-burger-ghost"
                :animation="150"
                data-testid="ks-burger-target"
                @change="onChange"
            >
                <KioskBurgerLayer
                    v-for="(element, index) in droppedIngredients"
                    :key="element.instanceId"
                    :ingredient="element"
                    :index="index"
                    @remove="removeLayer(index)"
                />
            </draggable>

            <!-- Empty state -->
            <div v-if="!droppedIngredients.length" class="ks-burger-empty" aria-live="polite" data-testid="ks-burger-empty">
                <span aria-hidden="true">🍔</span>
                <p>{{ $t('kiosk.builder.empty_state') }}</p>
            </div>

            <!-- Total -->
            <div
                v-if="droppedIngredients.length"
                class="ks-burger-total"
                role="status"
                aria-live="polite"
                data-testid="ks-burger-total"
            >
                <span>{{ $t('kiosk.builder.total') }}</span>
                <strong>{{ formatPrice(totalPrice) }}</strong>
            </div>
        </section>

        <!-- A11y / fallback : escape to classic flow -->
        <button
            type="button"
            class="ks-burger-fallback"
            data-testid="ks-burger-fallback"
            :aria-label="$t('kiosk.builder.switch_to_classic_aria')"
            @click="$emit('switch-to-classic')"
        >
            {{ $t('kiosk.builder.use_classic_mode') }}
        </button>
    </div>
</template>

<script>
import { VueDraggableNext } from 'vue-draggable-next';
import KioskBurgerLayer from './KioskBurgerLayer.vue';

export default {
    name: 'KioskBurgerBuilder',
    components: {
        draggable: VueDraggableNext,
        KioskBurgerLayer,
    },
    props: {
        availableIngredients: {
            type: Array,
            default: () => [],
        },
    },
    emits: ['update:extras', 'switch-to-classic'],
    data() {
        return {
            // Local mutable copies to avoid mutating prop array.
            sourceIngredients: [...this.availableIngredients],
            droppedIngredients: [],
            // Monotonic counter ensures unique instanceId even for the same ingredient.
            instanceCounter: 0,
        };
    },
    computed: {
        totalPrice() {
            return this.droppedIngredients.reduce(
                (sum, layer) => sum + (Number(layer.price) || 0),
                0,
            );
        },
    },
    watch: {
        availableIngredients: {
            handler(next) {
                this.sourceIngredients = Array.isArray(next) ? [...next] : [];
            },
            deep: false,
        },
    },
    methods: {
        cloneIngredient(original) {
            this.instanceCounter += 1;
            return {
                ...original,
                instanceId: `${original.id}-${this.instanceCounter}-${Date.now()}`,
            };
        },
        handleKeyboardAdd(ingredient) {
            const cloned = this.cloneIngredient(ingredient);
            this.droppedIngredients.push(cloned);
            this.emitUpdate();
        },
        removeLayer(index) {
            this.droppedIngredients.splice(index, 1);
            this.emitUpdate();
        },
        onChange() {
            this.emitUpdate();
        },
        emitUpdate() {
            this.$emit('update:extras', [...this.droppedIngredients]);
        },
        formatPriceValue(p) {
            return Number(p || 0).toFixed(2);
        },
        formatPrice(p) {
            return `${this.formatPriceValue(p)} €`;
        },
    },
};
</script>

<style scoped>
.ks-burger-builder {
    display: grid;
    grid-template-columns: 1fr 1fr;
    grid-template-rows: 1fr auto;
    gap: var(--kiosk-space-6, 24px);
    padding: var(--kiosk-space-6, 24px);
    background: var(--kiosk-bg, #FFFBF5);
    min-height: 60vh;
}

@media (max-width: 768px) {
    .ks-burger-builder {
        grid-template-columns: 1fr;
    }
}

.ks-burger-source,
.ks-burger-target {
    background: var(--kiosk-surface, #fff);
    border-radius: var(--kiosk-radius-4, 12px);
    padding: var(--kiosk-space-5, 20px);
    box-shadow: var(--kiosk-shadow-sm, 0 1px 3px rgba(0, 0, 0, 0.08));
}

.ks-burger-source-title,
.ks-burger-target-title {
    font-size: 22px;
    font-weight: 800;
    color: var(--kiosk-text, #1B1B1B);
    margin: 0 0 var(--kiosk-space-4, 16px);
}

.ks-burger-source-list,
.ks-burger-target-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: var(--kiosk-space-2, 8px);
    min-height: 200px;
}

.ks-burger-ingredient {
    display: flex;
    align-items: center;
    gap: var(--kiosk-space-3, 12px);
    padding: var(--kiosk-space-3, 12px);
    background: var(--kiosk-surface-alt, #F7F3EC);
    border: 2px solid transparent;
    border-radius: var(--kiosk-radius-3, 8px);
    cursor: grab;
    user-select: none;
    transition: transform 0.15s ease-out, border-color 0.15s ease-out, box-shadow 0.15s ease-out;
}

.ks-burger-ingredient:hover,
.ks-burger-ingredient:focus-visible {
    border-color: var(--kiosk-primary, #E8001C);
    box-shadow: 0 4px 12px rgba(232, 0, 28, 0.15);
    outline: none;
}

.ks-burger-ingredient:active {
    cursor: grabbing;
}

.ks-burger-ghost {
    opacity: 0.4;
}

.ks-burger-chosen {
    transform: scale(0.96);
}

.ks-burger-dragging {
    opacity: 0.85;
    transform: rotate(-1deg);
}

.ks-burger-ingredient-emoji {
    font-size: 32px;
    line-height: 1;
}

.ks-burger-ingredient-name {
    flex: 1;
    font-weight: 700;
    color: var(--kiosk-text, #1B1B1B);
}

.ks-burger-ingredient-price {
    font-weight: 700;
    color: var(--kiosk-primary, #E8001C);
}

.ks-burger-empty {
    text-align: center;
    padding: var(--kiosk-space-10, 40px);
    color: var(--kiosk-text-muted, #5A5A5A);
}

.ks-burger-empty span {
    font-size: 64px;
    line-height: 1;
    display: block;
    margin-bottom: var(--kiosk-space-3, 12px);
}

.ks-burger-empty p {
    margin: 0;
}

.ks-burger-total {
    margin-top: var(--kiosk-space-4, 16px);
    padding-top: var(--kiosk-space-4, 16px);
    border-top: 2px dashed var(--kiosk-border, #EEE6D9);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 24px;
    font-weight: 900;
}

.ks-burger-total strong {
    color: var(--kiosk-primary, #E8001C);
}

.ks-burger-fallback {
    grid-column: 1 / -1;
    background: transparent;
    border: 1px solid var(--kiosk-border, #EEE6D9);
    border-radius: var(--kiosk-radius-3, 8px);
    padding: var(--kiosk-space-3, 12px);
    color: var(--kiosk-text-muted, #5A5A5A);
    cursor: pointer;
    transition: background 0.15s ease-out;
    font-size: 16px;
}

.ks-burger-fallback:hover,
.ks-burger-fallback:focus-visible {
    background: var(--kiosk-surface-alt, #F7F3EC);
    outline: none;
}

@media (prefers-reduced-motion: reduce) {
    .ks-burger-ingredient,
    .ks-burger-fallback {
        transition: none;
    }

    .ks-burger-chosen,
    .ks-burger-dragging {
        transform: none;
    }
}
</style>
