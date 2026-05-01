<template>
    <div class="grid grid-cols-1 gap-3 rounded border border-gray-200 bg-[#F7F7FC] p-3 md:grid-cols-6" :data-testid="`admin-composer-step-${index}`">
        <input v-model="draft.step_key" class="db-field-control md:col-span-1" :data-testid="`admin-composer-step-${index}-key`" placeholder="step_key" @input="emitChange" />
        <input v-model="draft.label" class="db-field-control md:col-span-1" :data-testid="`admin-composer-step-${index}-label`" placeholder="Label" @input="emitChange" />
        <select v-model="draft.source_type" class="db-field-control md:col-span-1" :data-testid="`admin-composer-step-${index}-source-type`" @change="emitChange">
            <option value="item_attribute">item_attribute</option>
            <option value="extra_group">extra_group</option>
            <option value="addon">addon</option>
        </select>
        <input v-model="draft.source_ref" class="db-field-control md:col-span-1" :data-testid="`admin-composer-step-${index}-source-ref`" placeholder="source_ref" @input="emitChange" />
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
    },
    emits: ['update:modelValue'],
    data() {
        return {
            draft: { ...this.modelValue },
        };
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
    },
};
</script>
