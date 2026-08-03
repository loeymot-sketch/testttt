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
                <span class="mb-1 block text-sm font-semibold text-[#405149]">
                    {{ t('label.composer.source_type', 'Source') }}
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
                <span class="mb-1 block text-sm font-semibold text-[#405149]">
                    {{ t('label.composer.source_ref', 'Choix disponibles') }}
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

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <label class="rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-4">
                <span class="flex items-center justify-between text-sm font-semibold text-[#405149]">
                    {{ t('label.composer.min_select', 'Minimum') }}
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
                <span class="flex items-center justify-between text-sm font-semibold text-[#405149]">
                    {{ t('label.composer.max_select', 'Maximum') }}
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

        <fieldset class="rounded-lg border border-[#d9dfdc] bg-[#fbfcfb] p-4">
            <legend class="px-1 text-sm font-semibold text-[#405149]">
                {{ t('label.composer.visible_on', 'Visible sur') }}
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
                <span class="block text-sm font-semibold text-[#405149]">
                    {{ t('label.composer.is_active', 'Active') }}
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
        t(key, fallback) {
            return typeof this.$t === 'function' ? this.$t(key) : fallback;
        },
        clone(value) {
            return {
                ...value,
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
