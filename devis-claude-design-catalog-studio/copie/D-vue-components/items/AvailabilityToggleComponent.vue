<template>
    <div class="flex flex-col gap-1 items-start max-w-[200px]">
        <div v-if="!isAvailable" class="text-xs">
            <span class="db-badge db-table-badge text-red-600 bg-red-100">{{ $t('label.out_of_stock') }}</span>
            <span v-if="unavailableReason" class="block text-gray-600 mt-0.5">{{ unavailableReason }}</span>
        </div>
        <button type="button" class="db-btn py-1 px-2 text-sm whitespace-normal text-left"
            :class="isAvailable ? 'text-white bg-gray-600' : 'text-white bg-primary'" :disabled="isPending"
            @click.prevent="toggle">
            {{ isAvailable ? $t('label.toggle_unavailable') : $t('label.toggle_available') }}
        </button>
    </div>
</template>

<script>
import { mapState } from 'vuex';

export default {
    name: 'AvailabilityToggleComponent',
    props: {
        itemId: { type: Number, required: true },
        branchId: { default: null },
        isAvailable: { type: Boolean, default: true },
        unavailableReason: { type: String, default: null },
    },
    emits: ['availability-changed'],
    computed: {
        ...mapState('itemAvailability', ['pending']),
        isPending() {
            return !!this.pending[this.itemId];
        },
    },
    methods: {
        async toggle() {
            const nextAvailable = !this.isAvailable;
            try {
                await this.$store.dispatch('itemAvailability/toggle', {
                    itemId: this.itemId,
                    branchId: this.branchId,
                    isAvailable: nextAvailable,
                    unavailableReason: nextAvailable ? null : (this.unavailableReason || null),
                });
                this.$emit('availability-changed', { itemId: this.itemId, isAvailable: nextAvailable });
            } catch (e) {
                /* surfaced via store.lastError */
            }
        },
    },
};
</script>
