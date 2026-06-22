<template>
    <div class="rounded border border-gray-200 bg-white p-3" :data-testid="`admin-composer-step-${index}-preview`">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-heading">{{ step.label || step.step_key }}</p>
                <p class="text-xs text-gray-500">{{ step.source_type }} · {{ step.source_ref || 'manual' }}</p>
            </div>
            <span class="text-xs font-semibold text-primary">{{ step.min_select || 0 }}-{{ step.max_select || 1 }}</span>
        </div>
        <div v-if="step.addon_role" class="mt-2 text-xs font-semibold text-[#00749B]">
            {{ step.addon_role }}
        </div>
    </div>
</template>

<script>
export default {
    name: 'StepPreviewComponent',
    props: {
        step: {
            type: Object,
            required: true,
        },
        index: {
            type: Number,
            default: 0,
        },
    },
};
</script>
