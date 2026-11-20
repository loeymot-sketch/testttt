<template>
    <section class="space-y-4" data-testid="ingredient-list" :aria-busy="loading ? 'true' : 'false'">
        <header class="rounded border border-neutral-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-rose-700">
                        FoodKing V1
                    </p>
                    <h1 class="mt-1 text-xl font-semibold text-slate-900">
                        {{ $t('label.ingredient.title') }}
                    </h1>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $t('label.ingredient.subtitle') }}
                    </p>
                </div>
                <span class="rounded bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">
                    {{ totalCount }}
                </span>
            </div>
        </header>

        <nav
            class="flex flex-wrap gap-2"
            role="tablist"
            :aria-label="$t('label.ingredient.tablist_label')"
        >
            <button
                v-for="(tab, index) in tabs"
                :key="tab.value"
                ref="tabButtons"
                type="button"
                role="tab"
                :id="`tab-${tab.value}`"
                class="rounded border px-3 py-2 text-sm font-semibold transition"
                :class="activeTab === tab.value ? 'border-rose-700 bg-rose-700 text-white' : 'border-neutral-200 bg-white text-slate-600 hover:bg-slate-50'"
                :aria-selected="activeTab === tab.value ? 'true' : 'false'"
                :aria-controls="`panel-${tab.value}`"
                :tabindex="activeTab === tab.value ? 0 : -1"
                @click="selectTab(tab.value)"
                @keydown.right.prevent="focusNextTab(index)"
                @keydown.left.prevent="focusPrevTab(index)"
                @keydown.home.prevent="focusFirstTab"
                @keydown.end.prevent="focusLastTab"
            >
                {{ $t(tab.label) }}
            </button>
        </nav>

        <div v-if="error" class="rounded border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700" role="alert">
            <p>{{ $t('label.ingredient.error') }}</p>
            <button type="button" class="mt-2 rounded bg-rose-700 px-3 py-1.5 text-white" @click="fetchIngredients">
                {{ $t('label.ingredient.retry') }}
            </button>
        </div>

        <article
            :id="`panel-${activeTab}`"
            class="overflow-hidden rounded border border-neutral-200 bg-white shadow-sm"
            role="tabpanel"
            :aria-labelledby="`tab-${activeTab}`"
            tabindex="0"
        >
            <div v-if="loading" class="p-6 text-sm text-slate-500">
                {{ $t('label.ingredient.loading') }}
            </div>

            <!--
                [ONB 2026-08-28] L'onglet par defaut est « Tous », et
                `fetchIngredients()` n'envoie alors AUCUN parametre. Afficher
                « trouve pour ce filtre » envoyait le commercant chercher un
                filtre qui n'existe pas. On distingue les deux cas.
            -->
            <div v-else-if="ingredients.length === 0" class="p-6 text-center text-sm text-slate-500" data-testid="ingredient-empty">
                {{ activeTab === 'all' ? $t('label.ingredient.empty_all') : $t('label.ingredient.empty_filtered') }}
            </div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200" aria-live="polite">
                    <caption class="sr-only">
                        {{ $t('label.ingredient.table_caption') }}
                    </caption>
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $t('label.ingredient.column_name') }}
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $t('label.ingredient.column_type') }}
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $t('label.ingredient.column_usage') }}
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $t('label.ingredient.column_actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 bg-white">
                        <tr
                            v-for="ingredient in ingredients"
                            :key="ingredient.global_id"
                            :data-global-id="ingredient.global_id"
                        >
                            <th scope="row" class="px-4 py-3 text-left">
                                <p class="text-sm font-semibold text-slate-900">{{ ingredient.name }}</p>
                                <p v-if="ingredient.group_label" class="text-xs text-slate-500">{{ ingredient.group_label }}</p>
                            </th>
                            <td class="px-4 py-3">
                                <span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                    {{ typeLabel(ingredient.type) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600">
                                {{ $t('label.ingredient.usage_count', { count: ingredient.used_by_count || 0 }) }}
                            </td>
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    class="rounded border border-neutral-200 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                    @click="openUsage(ingredient.global_id)"
                                >
                                    {{ $t('label.ingredient.view_details') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>

        <p class="sr-only" aria-live="polite">{{ notification }}</p>

        <IngredientUsageDrawer
            :global-id="selectedGlobalId"
            :is-open="drawerOpen"
            @close="drawerOpen = false"
        />
    </section>
</template>

<script>
import { mapState } from 'vuex';
import IngredientUsageDrawer from './IngredientUsageDrawer.vue';

const TYPE_LABELS = {
    attribute: 'label.ingredient.tab_attribute',
    extra: 'label.ingredient.tab_extra',
    addon: 'label.ingredient.tab_addon',
};

export default {
    name: 'IngredientListComponent',
    components: {
        IngredientUsageDrawer,
    },
    props: {
        type: { type: String, default: null },
    },
    data() {
        return {
            activeTab: this.normalizedType(this.type || this.$route?.params?.type || 'all'),
            drawerOpen: false,
            selectedGlobalId: null,
            notification: '',
            tabs: [
                { value: 'all', label: 'label.ingredient.tab_all' },
                { value: 'attribute', label: 'label.ingredient.tab_attribute' },
                { value: 'extra', label: 'label.ingredient.tab_extra' },
                { value: 'addon', label: 'label.ingredient.tab_addon' },
            ],
        };
    },
    computed: {
        ...mapState('ingredients', ['list', 'loading', 'error']),
        ingredients() {
            return this.list;
        },
        totalCount() {
            return this.list.length;
        },
    },
    watch: {
        type(value) {
            this.activeTab = this.normalizedType(value || 'all');
            this.fetchIngredients();
        },
        '$route.params.type'(value) {
            this.activeTab = this.normalizedType(value || 'all');
            this.fetchIngredients();
        },
    },
    mounted() {
        this.fetchIngredients();
    },
    methods: {
        normalizedType(value) {
            return ['attribute', 'extra', 'addon'].includes(value) ? value : 'all';
        },
        fetchIngredients() {
            const params = this.activeTab === 'all' ? {} : { type: this.activeTab };
            return this.$store.dispatch('ingredients/fetch', params);
        },
        selectTab(tab) {
            this.activeTab = tab;
            this.fetchIngredients();
        },
        focusTab(index) {
            const tab = this.tabs[index];
            if (!tab) return;

            this.selectTab(tab.value);
            this.$nextTick(() => {
                const buttons = Array.isArray(this.$refs.tabButtons)
                    ? this.$refs.tabButtons
                    : [this.$refs.tabButtons].filter(Boolean);
                buttons[index]?.focus();
            });
        },
        focusNextTab(index) {
            this.focusTab((index + 1) % this.tabs.length);
        },
        focusPrevTab(index) {
            this.focusTab((index - 1 + this.tabs.length) % this.tabs.length);
        },
        focusFirstTab() {
            this.focusTab(0);
        },
        focusLastTab() {
            this.focusTab(this.tabs.length - 1);
        },
        typeLabel(type) {
            return this.$t(TYPE_LABELS[type] || 'label.ingredient.column_type');
        },
        openUsage(globalId) {
            this.selectedGlobalId = globalId;
            this.drawerOpen = true;
        },
        handleToggled() {
            this.notification = this.$t('message.ingredient.toggle_success');
        },
        handleToggleError() {
            this.notification = this.$t('message.ingredient.toggle_error');
        },
    },
};
</script>
