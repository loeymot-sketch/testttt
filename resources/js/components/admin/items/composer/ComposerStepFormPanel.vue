<template>
    <form class="space-y-5" data-testid="composer-step-form-panel" @submit.prevent>
        <label class="block">
            <span class="mb-1 block text-sm font-semibold text-[#405149]">
                {{ t('label.composer.step_label', 'Nom de la page') }}
            </span>
            <input
                v-model="draft.label"
                class="db-field-control"
                data-testid="composer-step-label-input"
                @input="commit"
            />
        </label>

        <section
            v-if="page"
            class="rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-4"
            data-testid="composer-step-page-block"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-[#405149]">
                        Choix de la page « {{ page.label }} »
                        <span
                            v-if="!page.is_library"
                            class="ml-2 rounded-full bg-[#fff7ed] px-2 py-0.5 text-[11px] font-semibold text-[#9a3412]"
                        >personnalisée</span>
                    </p>
                    <p class="text-xs text-[#66756e]">
                        {{ page.is_library
                            ? 'Page partagée : la modifier met à jour toutes les catégories qui l\'utilisent.'
                            : 'Page propre à cette catégorie : vous pouvez la modifier librement.' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="page.is_library"
                        type="button"
                        class="db-btn-outline"
                        data-testid="composer-step-customize-page"
                        @click="$emit('customize-page', page)"
                    >
                        Personnaliser pour cette catégorie
                    </button>
                    <button
                        type="button"
                        class="db-btn-outline"
                        data-testid="composer-step-edit-page"
                        @click="$emit('edit-page', page)"
                    >
                        Modifier les choix et les prix
                    </button>
                </div>
            </div>

            <ul v-if="pageChoices.length" class="mt-3 flex flex-wrap gap-2" data-testid="composer-step-page-choices">
                <li
                    v-for="choice in pageChoices"
                    :key="choice.id"
                    class="rounded-full border border-[#d9dfdc] bg-white px-3 py-1 text-xs text-[#405149]"
                >
                    {{ choice.name }}
                    <span v-if="Number(choice.price) > 0" class="ml-1 font-semibold text-[#14743a]">
                        +{{ euros(choice.price) }}
                    </span>
                </li>
            </ul>
            <p v-else class="mt-3 text-xs font-semibold text-[#8a6812]" data-testid="composer-step-page-empty">
                Cette page n'a aucun choix : elle serait vide en caisse. Ajoutez-en dans « Pages de wizard ».
            </p>

            <p
                v-if="!draft.is_active"
                class="mt-3 rounded border border-[#e4d8b5] bg-[#fff8df] px-3 py-2 text-xs font-semibold text-[#8a6812]"
                data-testid="composer-step-page-off"
            >
                Page éteinte : elle n'apparaît ni en caisse ni sur la borne. Activez-la ci-dessous, puis publiez.
            </p>
        </section>

        <div v-if="!page" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <label class="block">
                <span class="mb-1 flex items-center gap-2 text-sm font-semibold text-[#405149]">
                    {{ t('label.composer.source_type_human', "D'où viennent les choix ?") }}
                    <span class="group relative inline-flex">
                        <button
                            type="button"
                            class="text-neutral-400 hover:text-neutral-700"
                            :aria-label="t('label.composer.source_type_help', 'Détermine la base : attributs, extras ou add-ons catalogue.')"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                        <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 rounded bg-neutral-900 p-2 text-xs font-normal text-white opacity-0 shadow-lg transition group-hover:opacity-100">
                            {{ t('label.composer.source_type_help', 'Détermine la base : attributs, extras ou add-ons catalogue.') }}
                        </span>
                    </span>
                </span>
                <select
                    v-model="draft.source_type"
                    class="db-field-control"
                    data-testid="composer-step-source-type"
                    @change="onSourceTypeChange"
                >
                    <option v-for="(label, value) in sourceTypeLabels" :key="value" :value="value">
                        {{ label }}
                    </option>
                </select>
            </label>

            <label class="block">
                <span class="mb-1 flex items-center gap-2 text-sm font-semibold text-[#405149]">
                    {{ t('label.composer.source_ref_human', 'Limiter à un groupe précis (optionnel)') }}
                    <span class="group relative inline-flex">
                        <button
                            type="button"
                            class="text-neutral-400 hover:text-neutral-700"
                            :aria-label="t('label.composer.source_ref_help', 'Vide = aucun choix en caisse, sauf extras liés au nom de la page.')"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                        <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 rounded bg-neutral-900 p-2 text-xs font-normal text-white opacity-0 shadow-lg transition group-hover:opacity-100">
                            {{ t('label.composer.source_ref_help', 'Vide = aucun choix en caisse, sauf extras liés au nom de la page.') }}
                        </span>
                    </span>
                </span>
                <select
                    v-model="draft.source_ref"
                    class="db-field-control"
                    data-testid="composer-step-source-ref"
                    @change="commit"
                >
                    <option value="">{{ t('label.composer.all_source_options', 'Aucune source — page vide en caisse') }}</option>
                    <option v-for="source in optionsForType" :key="`${draft.source_type}-${source.id}`" :value="String(source.id)">
                        {{ source.name }}
                    </option>
                </select>
                <span v-if="!optionsForType.length" class="mt-1 block text-xs text-[#8a6812]" data-testid="composer-step-source-empty">
                    {{ t('message.composer.no_sources', 'Aucune source disponible pour ce type.') }}
                </span>
            </label>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <label class="rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-4">
                <span class="flex items-center justify-between gap-3 text-sm font-semibold text-[#405149]">
                    <span class="flex items-center gap-2">
                        {{ t('label.composer.min_select', 'Minimum') }}
                        <span class="group relative inline-flex">
                            <button
                                type="button"
                                class="text-neutral-400 hover:text-neutral-700"
                                :aria-label="t('label.composer.min_select_help', 'Combien d’articles le client doit minimum choisir.')"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </button>
                            <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 rounded bg-neutral-900 p-2 text-xs font-normal text-white opacity-0 shadow-lg transition group-hover:opacity-100">
                                {{ t('label.composer.min_select_help', 'Combien d’articles le client doit minimum choisir.') }}
                            </span>
                        </span>
                    </span>
                    <strong>{{ draft.min_select }}</strong>
                </span>
                <input
                    v-model.number="draft.min_select"
                    type="number"
                    min="0"
                    max="20"
                    class="db-field-control mt-3"
                    data-testid="composer-step-min-range"
                    @input="onMinChange"
                />
            </label>

            <label class="rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-4">
                <span class="flex items-center justify-between gap-3 text-sm font-semibold text-[#405149]">
                    <span class="flex items-center gap-2">
                        {{ t('label.composer.max_select', 'Maximum') }}
                        <span class="group relative inline-flex">
                            <button
                                type="button"
                                class="text-neutral-400 hover:text-neutral-700"
                                :aria-label="t('label.composer.max_select_help', 'Combien d’articles le client peut maximum choisir.')"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </button>
                            <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 rounded bg-neutral-900 p-2 text-xs font-normal text-white opacity-0 shadow-lg transition group-hover:opacity-100">
                                {{ t('label.composer.max_select_help', 'Combien d’articles le client peut maximum choisir.') }}
                            </span>
                        </span>
                    </span>
                    <strong>{{ draft.max_select }}</strong>
                </span>
                <input
                    v-model.number="draft.max_select"
                    type="number"
                    min="0"
                    max="20"
                    class="db-field-control mt-3"
                    data-testid="composer-step-max-range"
                    @input="onMaxChange"
                />
            </label>
        </div>
        <div class="text-xs text-neutral-600" data-testid="composer-step-min-max-summary">
            {{ minMaxSummary }}
        </div>

        <fieldset class="rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-4">
            <legend class="px-1 text-sm font-semibold text-[#405149]">
                {{ t('label.composer.visible_on', 'Visible sur') }}
                <span class="group relative ml-2 inline-flex">
                    <button
                        type="button"
                        class="text-neutral-400 hover:text-neutral-700"
                        :aria-label="t('label.composer.visible_on_help', 'Sur quels canaux cette étape apparaît.')"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </button>
                    <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 rounded bg-neutral-900 p-2 text-xs font-normal text-white opacity-0 shadow-lg transition group-hover:opacity-100">
                        {{ t('label.composer.visible_on_help', 'Sur quels canaux cette étape apparaît.') }}
                    </span>
                </span>
            </legend>
            <div class="mt-3 flex flex-wrap gap-3">
                <label class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-[#405149]">
                    <input
                        type="checkbox"
                        :checked="isVisible('pos')"
                        data-testid="composer-step-visible-pos"
                        @change="toggleSurface('pos')"
                    />
                    POS
                </label>
                <label class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-[#405149]">
                    <input
                        type="checkbox"
                        :checked="isVisible('kiosk')"
                        data-testid="composer-step-visible-kiosk"
                        @change="toggleSurface('kiosk')"
                    />
                    Kiosk
                </label>
            </div>
        </fieldset>

        <label class="flex items-center justify-between rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-4">
            <span>
                <span class="flex items-center gap-2 text-sm font-semibold text-[#405149]">
                    {{ t('label.composer.is_active', 'Active') }}
                    <span class="group relative inline-flex">
                        <button
                            type="button"
                            class="text-neutral-400 hover:text-neutral-700"
                            :aria-label="t('label.composer.is_active_help', 'Désactiver une étape la cache aux clients sans la supprimer.')"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                        <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 rounded bg-neutral-900 p-2 text-xs font-normal text-white opacity-0 shadow-lg transition group-hover:opacity-100">
                            {{ t('label.composer.is_active_help', 'Désactiver une étape la cache aux clients sans la supprimer.') }}
                        </span>
                    </span>
                </span>
                <span class="block text-xs text-[#66756e]">
                    {{ draft.is_active ? t('label.active', 'Actif') : t('label.inactive', 'Inactif') }}
                </span>
            </span>
            <input
                v-model="draft.is_active"
                type="checkbox"
                class="h-5 w-5"
                data-testid="composer-step-active-toggle"
                @change="commit"
            />
        </label>
    </form>
</template>

<script>
export default {
    name: 'ComposerStepFormPanel',
    props: {
        modelValue: {
            type: Object,
            required: true,
        },
        availableSources: {
            type: Object,
            default: () => ({}),
        },
        sourceTypeLabels: {
            type: Object,
            default: () => ({
                item_attribute: 'Attribut produit',
                extra_group: 'Groupe extras',
                addon: 'Addon catalogue',
            }),
        },
        /** Page de la bibliothèque reliée à cette étape (null = étape libre, ancien mode). */
        page: {
            type: Object,
            default: null,
        },
    },
    emits: ['update:modelValue', 'change', 'edit-page', 'customize-page'],
    data() {
        return {
            draft: this.clone(this.modelValue),
        };
    },
    computed: {
        /**
         * Avant : « le client doit choisir exactement 1 articles. » — le pluriel était figé et le
         * `{n}` de la clé i18n avait déjà été interpolé à vide par `$t` avant le `.replace` appelant.
         */
        minMaxSummary() {
            const min = Number(this.draft.min_select) || 0;
            const max = Number(this.draft.max_select) || 0;
            const noun = (n) => (n > 1 ? 'articles' : 'article');
            if (min === 0 && max === 1) {
                return '= Optionnel, le client peut choisir 1 article maximum.';
            }
            if (min === 0) {
                return `= Optionnel, le client peut choisir jusqu'à ${max} ${noun(max)}.`;
            }
            if (min === max) {
                return `= Obligatoire, le client doit choisir exactement ${min} ${noun(min)}.`;
            }
            return `= Le client peut choisir entre ${min} et ${max} ${noun(max)}.`;
        },
        pageChoices() {
            const choices = Array.isArray(this.page?.choices) ? this.page.choices : [];
            return choices.filter((choice) => Number(choice.status) !== 10);
        },
        optionsForType() {
            const list = this.availableSources?.[this.draft.source_type];
            return Array.isArray(list) ? list : [];
        },
    },
    watch: {
        modelValue: {
            deep: true,
            handler(value) {
                // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] commit() emits
                // the cloned draft up to the parent, which round-trips it right
                // back down through this same watcher (the parent's
                // selectedStepDraft getter always returns a fresh `{...step}`
                // copy, so the incoming reference never matches, only the
                // content). Replacing `draft` wholesale on that echo forces
                // Vue to rebuild the <select>'s bound option list mid-tick,
                // which a real Playwright run caught as the element detaching
                // from the DOM during its own selectOption() call. Skipping the
                // replacement when the incoming value is content-identical to
                // the current draft leaves genuine external changes (switching
                // to a different step, same watcher, real different data)
                // fully unaffected.
                const next = this.clone(value);
                if (JSON.stringify(next) === JSON.stringify(this.draft)) {
                    return;
                }
                this.draft = next;
            },
        },
    },
    methods: {
        /** Prix au format français : 0.9 → « 0,90 € ». */
        euros(value) {
            return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(value) || 0);
        },
        t(key, fallback) {
            return typeof this.$t === 'function' ? this.$t(key) : fallback;
        },
        clone(value) {
            const minSelect = value?.min_select ?? value?.min ?? 0;
            const maxSelect = value?.max_select ?? value?.max ?? Math.max(1, Number(minSelect));

            return {
                ...value,
                min_select: Number(minSelect),
                max_select: Number(maxSelect),
                visible_on: Array.isArray(value?.visible_on) ? [...value.visible_on] : ['pos', 'kiosk'],
            };
        },
        commit() {
            this.$emit('update:modelValue', this.clone(this.draft));
            this.$emit('change', this.clone(this.draft));
        },
        onSourceTypeChange() {
            this.draft.source_ref = '';
            this.commit();
        },
        onMinChange() {
            if (Number(this.draft.max_select) < Number(this.draft.min_select)) {
                this.draft.max_select = Number(this.draft.min_select);
            }
            this.commit();
        },
        onMaxChange() {
            if (Number(this.draft.max_select) < Number(this.draft.min_select)) {
                this.draft.min_select = Number(this.draft.max_select);
            }
            this.commit();
        },
        isVisible(surface) {
            return Array.isArray(this.draft.visible_on) && this.draft.visible_on.includes(surface);
        },
        toggleSurface(surface) {
            const current = Array.isArray(this.draft.visible_on) ? [...this.draft.visible_on] : [];
            if (current.includes(surface)) {
                this.draft.visible_on = current.filter((item) => item !== surface);
            } else {
                this.draft.visible_on = [...current, surface];
            }
            this.commit();
        },
    },
};
</script>
