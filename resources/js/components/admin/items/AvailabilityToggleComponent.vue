<template>
    <div class="flex flex-col gap-1 items-start max-w-[200px]">
        <div v-if="!displayIsAvailable" class="text-xs">
            <span class="db-badge db-table-badge text-red-600 bg-red-100">{{ $t('label.out_of_stock') }}</span>
            <span v-if="unavailableReason" class="block text-gray-600 mt-0.5">{{ unavailableReason }}</span>
        </div>
        <button type="button" class="db-btn py-1 px-2 text-sm whitespace-normal text-left"
            :class="displayIsAvailable ? 'text-white bg-gray-600' : 'text-white bg-primary'" :disabled="isPending"
            @click.prevent="toggle">
            {{ displayIsAvailable ? $t('label.toggle_unavailable') : $t('label.toggle_available') }}
        </button>
    </div>
</template>

<script>
import { mapState } from 'vuex';
import appService from '../../../services/appService';

export default {
    name: 'AvailabilityToggleComponent',
    props: {
        itemId: { type: Number, required: true },
        branchId: { default: null },
        isAvailable: { type: Boolean, default: true },
        unavailableReason: { type: String, default: null },
    },
    emits: ['availability-changed', 'toggle-error'],
    data() {
        return {
            displayIsAvailable: this.isAvailable,
            lastErrorMessage: null,
        };
    },
    computed: {
        ...mapState('itemAvailability', ['pending']),
        isPending() {
            return !!this.pending[this.itemId];
        },
    },
    watch: {
        isAvailable(value) {
            this.displayIsAvailable = value;
        },
    },
    methods: {
        async toggle() {
            const previousAvailable = this.displayIsAvailable;
            const nextAvailable = !previousAvailable;
            this.displayIsAvailable = nextAvailable;
            this.lastErrorMessage = null;

            try {
                await this.$store.dispatch('itemAvailability/toggle', {
                    itemId: this.itemId,
                    branchId: this.branchId,
                    isAvailable: nextAvailable,
                    unavailableReason: nextAvailable ? null : (this.unavailableReason || null),
                });
                this.$emit('availability-changed', { itemId: this.itemId, isAvailable: nextAvailable });
            } catch (e) {
                this.displayIsAvailable = previousAvailable;
                const message = this.errorMessage(e);
                this.lastErrorMessage = message;
                if (typeof appService?.alertError === 'function') {
                    appService.alertError(message);
                }
                this.$emit('toggle-error', { itemId: this.itemId, message, error: e });
            }
        },
        errorMessage(error) {
            return error?.response?.data?.message
                || error?.message
                || this.$t?.('message.something_went_wrong')
                || 'Unable to update availability.';
        },
    },
};
</script>
