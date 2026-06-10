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

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
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
                            :aria-label="t('label.composer.source_ref_help', 'Par défaut toutes les options de la source sont proposées.')"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                        <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 rounded bg-neutral-900 p-2 text-xs font-normal text-white opacity-0 shadow-lg transition group-hover:opacity-100">
                            {{ t('label.composer.source_ref_help', 'Par défaut toutes les options de la source sont proposées.') }}
                        </span>
                    </span>
                </span>
                <select
                    v-model="draft.source_ref"
                    class="db-field-control"
                    data-testid="composer-step-source-ref"
                    @change="commit"
                >
                    <option value="">{{ t('label.composer.all_source_options', 'Toutes les options') }}</option>
                    <option v-for="source in optionsForType" :key="`${draft.source_type}-${source.id}`" :value="String(source.id)">
                        {{ source.name }}
                    </option>
                </select>
                <span v-if="!optionsForType.length" class="mt-1 block text-xs text-[#8a6812]" data-testid="composer-step-source-empty">
                    {{ t('message.composer.no_sources', 'Aucune source disponible pour ce type.') }}
                </span>
            </label>
        </div>

        <label v-if="draft.source_type === 'addon'" class="block">
            <span class="mb-1 flex items-center gap-2 text-sm font-semibold text-[#405149]">
                {{ t('label.composer.addon_role', "Rôle de l'add-on") }}
                <span class="group relative inline-flex">
                    <button
                        type="button"
                        class="text-neutral-400 hover:text-neutral-700"
                        :aria-label="t('label.composer.addon_role_help', 'Catégorise cette page d’add-ons (boisson, accompagnement, dessert…).')"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </button>
                    <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 rounded bg-neutral-900 p-2 text-xs font-normal text-white opacity-0 shadow-lg transition group-hover:opacity-100">
                        {{ t('label.composer.addon_role_help', 'Catégorise cette page d’add-ons (boisson, accompagnement, dessert…).') }}
                    </span>
                </span>
            </span>
            <select
                v-model="draft.addon_role"
                class="db-field-control"
                data-testid="composer-step-addon-role"
                @change="commit"
            >
                <option :value="null">{{ t('label.composer.addon_role_none', 'Aucun rôle') }}</option>
                <option value="drink">{{ t('label.composer.addon_role_drink', 'Boisson') }}</option>
                <option value="side">{{ t('label.composer.addon_role_side', 'Accompagnement') }}</option>
                <option value="dessert">{{ t('label.composer.addon_role_dessert', 'Dessert') }}</option>
                <option value="menu_component">{{ t('label.composer.addon_role_menu_component', 'Composant menu') }}</option>
                <option value="upsell">{{ t('label.composer.addon_role_upsell', 'Vente additionnelle') }}</option>
            </select>
        </label>

        <!-- [GOAL CMS heal P1-3 2026-06-10] Presets lisibles owner : pilotent
             min/max/allow_repeat sans raisonner en chiffres. -->
        <fieldset class="rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-4">
            <legend class="px-1 text-sm font-semibold text-[#405149]">
                {{ t('label.composer.choice_logic', 'Logique des choix') }}
            </legend>
            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        value="single"
                        :checked="choicePreset === 'single'"
                        data-testid="composer-step-preset-single"
                        @change="applyPreset('single')"
                    >
                    {{ t('label.composer.preset_single', 'Choix unique (1 obligatoire)') }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        value="multiple"
                        :checked="choicePreset === 'multiple'"
                        data-testid="composer-step-preset-multiple"
                        @change="applyPreset('multiple')"
                    >
                    {{ t('label.composer.preset_multiple', 'Choix multiples (libre)') }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        value="custom"
                        :checked="choicePreset === 'custom'"
                        data-testid="composer-step-preset-custom"
                        disabled
                    >
                    {{ t('label.composer.preset_custom', 'Personnalisé (réglages ci-dessous)') }}
                </label>
            </div>
        </fieldset>

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
                    type="range"
                    min="0"
                    max="10"
                    class="mt-3 w-full"
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
                    type="range"
                    min="0"
                    max="10"
                    class="mt-3 w-full"
                    data-testid="composer-step-max-range"
                    @input="onMaxChange"
                />
            </label>
        </div>
        <div class="text-xs text-neutral-600" data-testid="composer-step-min-max-summary">
            <span v-if="Number(draft.min_select) === 0 && Number(draft.max_select) === 1">
                {{ t('label.composer.min_max_summary_optional_one', '= Optionnel, le client peut choisir 1 article maximum.') }}
            </span>
            <span v-else-if="Number(draft.min_select) === Number(draft.max_select)">
                {{ t('label.composer.min_max_summary_required_n', '= Obligatoire, le client doit choisir exactement {n} articles.', { n: Number(draft.min_select) }) }}
            </span>
            <span v-else>
                {{ t('label.composer.min_max_summary_range', '= Le client peut choisir entre {min} et {max} articles.', { min: Number(draft.min_select), max: Number(draft.max_select) }) }}
            </span>
            <span v-if="draft.allow_repeat" class="mt-1 block text-[#405149]" data-testid="composer-step-repeat-summary">
                {{ t('label.composer.allow_repeat_summary', 'Le même choix peut être pris en plusieurs exemplaires.') }}
            </span>
        </div>

        <label class="flex items-center justify-between rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-4">
            <span>
                <span class="flex items-center gap-2 text-sm font-semibold text-[#405149]">
                    {{ t('label.composer.allow_repeat', 'Quantité par choix') }}
                    <span class="group relative inline-flex">
                        <button
                            type="button"
                            class="text-neutral-400 hover:text-neutral-700"
                            :aria-label="t('label.composer.allow_repeat_help', 'Le client peut prendre plusieurs fois le même choix.')"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                        <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 rounded bg-neutral-900 p-2 text-xs font-normal text-white opacity-0 shadow-lg transition group-hover:opacity-100">
                            {{ t('label.composer.allow_repeat_help', 'Le client peut prendre plusieurs fois le même choix.') }}
                        </span>
                    </span>
                </span>
                <span class="block text-xs text-[#66756e]">
                    {{ draft.allow_repeat ? t('label.composer.allow_repeat_state_on', 'Activé — le même choix peut être répété') : t('label.composer.allow_repeat_state_off', 'Désactivé — chaque choix une seule fois') }}
                </span>
            </span>
            <input
                v-model="draft.allow_repeat"
                type="checkbox"
                class="h-5 w-5"
                data-testid="composer-step-allow-repeat"
                @change="commit"
            />
        </label>

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
    },
    emits: ['update:modelValue', 'change'],
    data() {
        return {
            draft: this.clone(this.modelValue),
        };
    },
    computed: {
        optionsForType() {
            const list = this.availableSources?.[this.draft.source_type];
            return Array.isArray(list) ? list : [];
        },
        // [heal P1-3] reverse mapping: which preset matches current settings.
        choicePreset() {
            const min = Number(this.draft.min_select);
            const max = Number(this.draft.max_select);
            const repeat = Boolean(this.draft.allow_repeat);
            if (min === 1 && max === 1 && !repeat) return 'single';
            if (min === 0 && max === 10 && repeat) return 'multiple';
            return 'custom';
        },
    },
    watch: {
        modelValue: {
            deep: true,
            handler(value) {
                this.draft = this.clone(value);
            },
        },
    },
    methods: {
        t(key, fallback, params) {
            if (typeof this.$t === 'function') {
                return params ? this.$t(key, params) : this.$t(key);
            }
            return fallback;
        },
        clone(value) {
            const minSelect = value?.min_select ?? value?.min ?? 0;
            const maxSelect = value?.max_select ?? value?.max ?? Math.max(1, Number(minSelect));

            return {
                ...value,
                min_select: Number(minSelect),
                max_select: Number(maxSelect),
                allow_repeat: Boolean(value?.allow_repeat),
                addon_role: value?.addon_role ?? null,
                visible_on: Array.isArray(value?.visible_on) ? [...value.visible_on] : ['pos', 'kiosk'],
            };
        },
        commit() {
            this.$emit('update:modelValue', this.clone(this.draft));
            this.$emit('change', this.clone(this.draft));
        },
        // [heal P1-3] preset → min/max/allow_repeat mapping.
        applyPreset(preset) {
            if (preset === 'single') {
                this.draft.min_select = 1;
                this.draft.max_select = 1;
                this.draft.allow_repeat = false;
            } else if (preset === 'multiple') {
                this.draft.min_select = 0;
                this.draft.max_select = 10;
                this.draft.allow_repeat = true;
            }
            this.commit();
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
