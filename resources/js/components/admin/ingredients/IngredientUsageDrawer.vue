<template>
    <teleport to="body">
        <div v-if="isOpen" class="fixed inset-0 z-50" @keydown.esc="close" @keydown.tab.prevent="trapFocus">
            <button
                ref="backdrop"
                type="button"
                class="absolute inset-0 h-full w-full bg-slate-900/40"
                :aria-label="$t('label.ingredient.usage_drawer_close')"
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
                        <p v-if="typeLabel" class="mt-1 text-xs text-slate-500">{{ typeLabel }}</p>
                    </div>
                    <button
                        ref="closeButton"
                        type="button"
                        class="rounded px-2 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-100"
                        :aria-label="$t('label.ingredient.usage_drawer_close')"
                        @click="close"
                    >
                        {{ $t('label.ingredient.usage_drawer_close') }}
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto px-5 py-4">
                    <p v-if="loading" class="text-sm text-slate-500" aria-live="polite" data-testid="ingredient-usage-loading">
                        {{ $t('label.ingredient.loading') }}
                    </p>
                    <p v-else-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" aria-live="polite" data-testid="ingredient-usage-error">
                        {{ $t('label.ingredient.error') }}
                    </p>
                    <template v-else>
                        <!-- En-tête : nom + statut + count -->
                        <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2">
                            <p class="text-sm font-semibold text-slate-700" data-testid="ingredient-usage-name">
                                {{ ingredientName }}
                                <span v-if="!isAvailable" class="ml-2 inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">
                                    {{ $t('label.ingredient.status_unavailable') }}
                                </span>
                            </p>
                            <p class="mt-1 text-xs text-slate-500" data-testid="ingredient-usage-count">
                                {{ usedByCount > 0
                                    ? $t('label.ingredient.usage_count', { count: usedByCount })
                                    : $t('label.ingredient.usage_none') }}
                            </p>
                        </div>

                        <!-- Liste used_by -->
                        <p v-if="usedBy.length === 0" class="mt-4 text-sm text-slate-500" data-testid="ingredient-usage-empty">
                            {{ $t('label.ingredient.usage_empty') }}
                        </p>
                        <ul v-else class="mt-4 space-y-2" role="list" data-testid="ingredient-usage-list">
                            <li
                                v-for="entry in usedBy"
                                :key="`${entry.owner_type}:${entry.owner_id}:${entry.wizard_profile_id}`"
                                class="rounded border border-slate-200 bg-white px-3 py-2"
                                :data-testid="`ingredient-usage-entry-${entry.owner_type}-${entry.owner_id}`"
                            >
                                <a
                                    :href="entry.admin_url"
                                    class="text-sm font-semibold text-blue-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                                >
                                    {{ entry.owner_name }}
                                </a>
                                <p class="mt-1 text-xs text-slate-500">
                                    <span class="inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 text-xxs font-semibold uppercase tracking-wide">
                                        {{ entry.owner_type === 'category' ? $t('label.ingredient.owner_category') : $t('label.ingredient.owner_item') }}
                                    </span>
                                    <span class="ml-2">{{ entry.step_label }}</span>
                                </p>
                            </li>
                        </ul>
                    </template>
                </div>
            </aside>
        </div>
    </teleport>
</template>

<script>
import { getIngredientUsage } from '../../../services/ingredientService';

export default {
    name: 'IngredientUsageDrawer',
    props: {
        globalId: { type: String, default: null },
        isOpen: { type: Boolean, default: false },
    },
    emits: ['close'],
    computed: {
        // Render a human, localized ingredient-type label instead of the raw
        // internal globalId token (`type:id`, e.g. "attribute:8"). The type
        // prefix maps to the same labels as the list tabs.
        typeLabel() {
            const type = String(this.globalId || '').split(':')[0];
            const map = {
                attribute: 'label.ingredient.tab_attribute',
                extra: 'label.ingredient.tab_extra',
                addon: 'label.ingredient.tab_addon',
            };
            return map[type] ? this.$t(map[type]) : '';
        },
    },
    data() {
        return {
            loading: false,
            error: null,
            usedBy: [],
            usedByCount: 0,
            ingredientName: '',
            isAvailable: true,
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
            this.usedBy = [];
            this.usedByCount = 0;
            this.ingredientName = '';
            this.isAvailable = true;
            try {
                const response = await getIngredientUsage(this.globalId);
                const data = response?.data?.data || {};
                this.usedBy = Array.isArray(data.used_by) ? data.used_by : [];
                this.usedByCount = Number(data.used_by_count || 0);
                this.ingredientName = String(data.name || '');
                this.isAvailable = Boolean(data.is_available);
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
