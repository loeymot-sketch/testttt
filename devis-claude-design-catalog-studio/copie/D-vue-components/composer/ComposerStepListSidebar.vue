<template>
    <section class="space-y-3" data-testid="composer-step-list-sidebar">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-[0.08em] text-[#587065]">
                {{ t('label.composer.pages', 'Pages') }}
            </h2>
            <span class="rounded-full bg-[#eef2ef] px-2 py-1 text-xs font-semibold text-[#587065]">
                {{ modelValue.length }}
            </span>
        </div>

        <draggable
            v-if="modelValue.length"
            v-model="stepsProxy"
            item-key="_uid"
            handle=".composer-step-drag-handle"
            class="space-y-2"
            ghost-class="opacity-40"
            @end="emitReorder"
        >
            <template #item="{ element, index }">
                <article
                    class="rounded-lg border p-3 transition"
                    :class="element._uid === selectedKey ? 'border-[#1ab759] bg-[#f3fbf6]' : 'border-[#d9dfdc] bg-[#fbfcfb]'"
                    :data-testid="`composer-step-row-${element.id || index}`"
                >
                    <div class="flex items-start gap-2">
                        <button
                            type="button"
                            class="composer-step-drag-handle mt-1 cursor-grab text-[#87958e]"
                            :aria-label="t('label.composer.reorder_page', 'Reordonner')"
                            :data-testid="`composer-step-drag-${element.id || index}`"
                        >
                            <i class="lab lab-menu" aria-hidden="true"></i>
                        </button>
                        <button
                            type="button"
                            class="min-w-0 flex-1 text-left"
                            :data-testid="`composer-step-select-${element.id || index}`"
                            @click="$emit('select', element)"
                        >
                            <span class="flex items-center gap-2">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-[#14743a]">
                                    <i :class="iconFor(element)" aria-hidden="true"></i>
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold text-[#202824]">{{ element.label }}</span>
                                    <span class="block truncate text-xs text-[#66756e]">{{ sourceLabel(element) }}</span>
                                </span>
                            </span>
                        </button>
                        <button
                            type="button"
                            class="mt-1 text-[#b42318]"
                            :aria-label="t('label.composer.remove_page', 'Supprimer la page')"
                            :data-testid="`composer-step-remove-${element.id || index}`"
                            @click="$emit('remove', element)"
                        >
                            <i class="lab lab-trash" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-white px-2 py-1 text-[11px] font-semibold text-[#405149]">
                            {{ element.min_select }} / {{ element.max_select }}
                        </span>
                        <span
                            v-if="isVisibleOn(element, 'pos')"
                            class="rounded-full bg-[#e8f2ff] px-2 py-1 text-[11px] font-semibold text-[#24528f]"
                        >
                            POS
                        </span>
                        <span
                            v-if="isVisibleOn(element, 'kiosk')"
                            class="rounded-full bg-[#fff2df] px-2 py-1 text-[11px] font-semibold text-[#8a5b12]"
                        >
                            Kiosk
                        </span>
                        <span
                            v-if="!element.is_active"
                            class="rounded-full bg-[#f1f2f3] px-2 py-1 text-[11px] font-semibold text-[#6b7370]"
                        >
                            {{ t('label.composer.inactive', 'Inactive') }}
                        </span>
                    </div>
                </article>
            </template>
        </draggable>

        <div v-else class="rounded-lg border border-dashed border-[#ccd5d0] bg-[#f8faf9] p-4 text-sm text-[#66756e]" data-testid="composer-step-list-empty">
            {{ t('message.composer.no_steps', 'Ajoutez une page pour commencer.') }}
        </div>
    </section>
</template>

<script>
import { VueDraggableNext } from 'vue-draggable-next';

export default {
    name: 'ComposerStepListSidebar',
    components: {
        draggable: VueDraggableNext,
    },
    props: {
        modelValue: {
            type: Array,
            default: () => [],
        },
        selectedKey: {
            type: String,
            default: null,
        },
        sourceLabels: {
            type: Object,
            default: () => ({}),
        },
    },
    emits: ['update:modelValue', 'select', 'remove', 'reorder'],
    data() {
        return {
            lastOrderedSteps: null,
        };
    },
    computed: {
        stepsProxy: {
            get() {
                return this.modelValue;
            },
            set(value) {
                const positioned = (value || []).map((step, index) => ({ ...step, position: index }));
                this.lastOrderedSteps = positioned;
                this.$emit('update:modelValue', positioned);
            },
        },
    },
    methods: {
        t(key, fallback) {
            return typeof this.$t === 'function' ? this.$t(key) : fallback;
        },
        emitReorder() {
            const ordered = this.lastOrderedSteps || this.stepsProxy;
            this.$emit('reorder', ordered.map((step, index) => ({ ...step, position: index })));
            this.lastOrderedSteps = null;
        },
        sourceLabel(step) {
            const key = `${step.source_type}:${String(step.source_ref ?? '')}`;
            return this.sourceLabels[key] || step.source_type;
        },
        iconFor(step) {
            if (step.source_type === 'addon') return 'lab lab-addon';
            if (step.source_type === 'extra_group') return 'lab lab-extra';
            return 'lab lab-variation';
        },
        isVisibleOn(step, surface) {
            return Array.isArray(step.visible_on) && step.visible_on.includes(surface);
        },
    },
};
</script>
