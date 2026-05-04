<template>
    <teleport to="body">
        <div v-if="isOpen" class="fixed inset-0 z-50" @keydown.esc="close" @keydown.tab.prevent="trapFocus">
            <button
                ref="backdrop"
                type="button"
                class="absolute inset-0 h-full w-full bg-slate-900/40"
                aria-label="Close"
                data-testid="ingredient-usage-backdrop"
                @click="close"
            ></button>

            <aside
                ref="dialog"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-xl"
                tabindex="-1"
                data-testid="ingredient-usage-drawer"
            >
                <header class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 :id="titleId" class="text-base font-semibold text-slate-900">
                            {{ $t('label.ingredient.usage_drawer_title') }}
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">{{ globalId }}</p>
                    </div>
                    <button
                        ref="closeButton"
                        type="button"
                        class="rounded px-2 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-100"
                        @click="close"
                    >
                        {{ $t('label.ingredient.usage_drawer_close') }}
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto px-5 py-4">
                    <p v-if="loading" class="text-sm text-slate-500">
                        {{ $t('label.ingredient.loading') }}
                    </p>
                    <p v-else-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        {{ $t('label.ingredient.error') }}
                    </p>
                    <p v-else class="rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                        {{ $t('label.ingredient.usage_count', { count: usedByCount }) }}
                    </p>

                    <p class="mt-3 text-xs text-slate-500">
                        Drill-down produits/catégories impactés différé V1.5.
                    </p>
                </div>
            </aside>
        </div>
    </teleport>
</template>

<script>
import { showIngredient } from '../../../services/ingredientService';

export default {
    name: 'IngredientUsageDrawer',
    props: {
        globalId: { type: String, default: null },
        isOpen: { type: Boolean, default: false },
    },
    emits: ['close'],
    data() {
        return {
            loading: false,
            error: null,
            usedByCount: 0,
            titleId: `ingredient-usage-title-${Math.random().toString(36).slice(2)}`,
        };
    },
    watch: {
        isOpen: {
            immediate: true,
            handler(value) {
                if (value) {
                    this.$nextTick(() => {
                        this.$refs.dialog?.focus();
                    });
                    this.loadUsage();
                }
            },
        },
        globalId() {
            if (this.isOpen) this.loadUsage();
        },
    },
    methods: {
        async loadUsage() {
            if (!this.globalId) return;

            this.loading = true;
            this.error = null;
            try {
                const response = await showIngredient(this.globalId);
                this.usedByCount = Number(response?.data?.data?.used_by_count || 0);
            } catch (error) {
                this.error = error;
            } finally {
                this.loading = false;
            }
        },
        close() {
            this.$emit('close');
        },
        trapFocus(event) {
            const focusable = this.$refs.dialog?.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (!focusable || focusable.length === 0) return;

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                last.focus();
                return;
            }
            if (!event.shiftKey && document.activeElement === last) {
                first.focus();
                return;
            }
            if (!this.$refs.dialog.contains(document.activeElement)) {
                first.focus();
            }
        },
    },
};
</script>
