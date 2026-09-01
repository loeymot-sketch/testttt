<template>
    <div class="grid grid-cols-1 gap-3 rounded border border-gray-200 bg-[#F7F7FC] p-3 md:grid-cols-6" :data-testid="`admin-composer-step-${index}`">
        <input v-model="draft.step_key" class="db-field-control md:col-span-1" :data-testid="`admin-composer-step-${index}-key`" placeholder="step_key" @input="emitChange" />
        <input v-model="draft.label" class="db-field-control md:col-span-1" :data-testid="`admin-composer-step-${index}-label`" placeholder="Label" @input="emitChange" />
        <select v-model="draft.source_type" class="db-field-control md:col-span-1" :data-testid="`admin-composer-step-${index}-source-type`" @change="onSourceTypeChange">
            <option value="item_attribute">item_attribute</option>
            <option value="extra_group">extra_group</option>
            <option value="addon">addon</option>
        </select>
        <select
            v-if="hasPickerOptions"
            v-model="draft.source_ref"
            class="db-field-control md:col-span-1"
            :data-testid="`admin-composer-step-${index}-source-ref`"
            :data-picker="`admin-composer-step-${index}-source-picker`"
            @change="emitChange"
        >
            <option value="">{{ allOptionsLabel }}</option>
            <option v-for="opt in optionsForType" :key="`${opt.source_type}-${opt.id}`" :value="String(opt.id)">
                {{ opt.name }}
            </option>
        </select>
        <input
            v-else
            v-model="draft.source_ref"
            class="db-field-control md:col-span-1"
            :data-testid="`admin-composer-step-${index}-source-ref`"
            placeholder="source_ref"
            @input="emitChange"
        />
        <input v-model.number="draft.min_select" type="number" min="0" class="db-field-control" :data-testid="`admin-composer-step-${index}-min`" @input="emitChange" />
        <input v-model.number="draft.max_select" type="number" min="0" class="db-field-control" :data-testid="`admin-composer-step-${index}-max`" @input="emitChange" />
        <select v-model="draft.addon_role" class="db-field-control md:col-span-2" :data-testid="`admin-composer-step-${index}-addon-role`" @change="emitChange">
            <option :value="null">No addon role</option>
            <option value="drink">drink</option>
            <option value="side">side</option>
            <option value="dessert">dessert</option>
            <option value="menu_component">menu_component</option>
            <option value="upsell">upsell</option>
        </select>
    </div>
</template>

<script>
export default {
    name: 'StepEditorComponent',
    props: {
        modelValue: {
            type: Object,
            required: true,
        },
        index: {
            type: Number,
            default: 0,
        },
        // [CV1-WIZARD-COMPOSABLE-001 T-WC-SOURCE-PICKER-01] Optional shape:
        // { item_attribute: [{id,name,source_type}], extra_group: [...], addon: [...] }
        // Provided by ProductComposerEditorComponent after fetching
        // /admin/composer/items/{id}/available-sources. When absent or empty for
        // the current source_type, the component degrades gracefully to a
        // free-form text input (backward compatible — legacy data may exist).
        availableSources: {
            type: Object,
            default: () => ({}),
        },
    },
    emits: ['update:modelValue'],
    data() {
        return {
            draft: { ...this.modelValue },
        };
    },
    computed: {
        optionsForType() {
            const type = this.draft?.source_type || 'item_attribute';
            const list = this.availableSources?.[type];
            return Array.isArray(list) ? list : [];
        },
        hasPickerOptions() {
            return this.optionsForType.length > 0;
        },
        allOptionsLabel() {
            // i18n optional: when $t is not available (e.g. unit tests), fall back to a literal label.
            return typeof this.$t === 'function' ? this.$t('label.composer.all_source_options') : 'Aucune source — page vide en caisse';
        },
    },
    watch: {
        modelValue: {
            deep: true,
            handler(value) {
                this.draft = { ...value };
            },
        },
    },
    methods: {
        emitChange() {
            this.$emit('update:modelValue', { ...this.draft });
        },
        onSourceTypeChange() {
            // Reset source_ref when source_type changes — IDs from one source bucket
            // are not interchangeable with another (e.g. an item_attribute id has no
            // meaning when source_type=addon).
            this.draft.source_ref = '';
            this.emitChange();
        },
    },
};
</script>
