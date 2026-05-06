<template>
    <div class="flex flex-col gap-1">
        <button
            type="button"
            role="switch"
            class="inline-flex items-center justify-between gap-2 rounded border px-3 py-1.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
            :class="displayIsAvailable ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'"
            :aria-checked="displayIsAvailable ? 'true' : 'false'"
            :aria-label="labelText"
            :aria-busy="isPending ? 'true' : 'false'"
            :aria-describedby="displayReason ? reasonId : null"
            :disabled="isPending"
            @click="toggle"
            @keydown.space.prevent="toggle"
            @keydown.enter.prevent="toggle"
        >
            <span
                class="h-2.5 w-2.5 rounded-full"
                :class="displayIsAvailable ? 'bg-emerald-500' : 'bg-rose-500'"
                aria-hidden="true"
            ></span>
            <span>{{ displayIsAvailable ? $t('label.ingredient.toggle_available') : $t('label.ingredient.toggle_unavailable') }}</span>
        </button>
        <span v-if="!displayIsAvailable && displayReason" :id="reasonId" class="text-xs text-slate-500">
            {{ displayReason }}
        </span>
    </div>
</template>

<script>
export default {
    name: 'IngredientAvailabilityToggleComponent',
    props: {
        globalId: { type: String, required: true },
        isAvailable: { type: Boolean, default: true },
        reason: { type: String, default: null },
    },
    emits: ['toggled', 'error'],
    data() {
        return {
            displayIsAvailable: this.isAvailable,
            displayReason: this.reason,
        };
    },
    computed: {
        isPending() {
            return this.$store?.state?.ingredients?.lastTogglingGlobalId === this.globalId;
        },
        labelText() {
            return this.displayIsAvailable
                ? this.$t('label.ingredient.toggle_available')
                : this.$t('label.ingredient.toggle_unavailable');
        },
        reasonId() {
            return `ingredient-toggle-reason-${this.globalId.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
        },
    },
    watch: {
        isAvailable(value) {
            this.displayIsAvailable = value;
        },
        reason(value) {
            this.displayReason = value;
        },
    },
    methods: {
        async toggle() {
            const previousAvailable = this.displayIsAvailable;
            const previousReason = this.displayReason;
            const nextAvailable = !previousAvailable;
            let nextReason = nextAvailable ? null : previousReason;

            if (!nextAvailable) {
                nextReason = window.prompt(this.$t('label.ingredient.toggle_prompt_reason'), previousReason || '');
                if (nextReason === null) return;
                nextReason = String(nextReason).trim() || null;
            }

            this.displayIsAvailable = nextAvailable;
            this.displayReason = nextReason;

            try {
                const response = await this.$store.dispatch('ingredients/toggleAvailability', {
                    globalId: this.globalId,
                    isAvailable: nextAvailable,
                    reason: nextReason,
                });
                this.$emit('toggled', {
                    globalId: this.globalId,
                    isAvailable: nextAvailable,
                    reason: nextReason,
                    ingredient: response?.data?.data || null,
                });
            } catch (error) {
                this.displayIsAvailable = previousAvailable;
                this.displayReason = previousReason;
                this.$emit('error', { globalId: this.globalId, error });
            }
        },
    },
};
</script>
