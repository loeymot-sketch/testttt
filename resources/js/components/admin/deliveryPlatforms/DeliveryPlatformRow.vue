<template>
    <tr class="db-table-body-tr">
        <td class="db-table-body-td">
            <div class="dp-platform-cell">
                <span class="dp-platform-name">{{ platformLabel }}</span>
                <span class="dp-platform-branch" v-if="platform.branch_id">
                    #{{ platform.branch_id }}
                </span>
            </div>
        </td>
        <td class="db-table-body-td">
            <div class="relative inline-block w-11 h-5">
                <input
                    :id="`dp-toggle-${platform.id}`"
                    type="checkbox"
                    class="peer appearance-none w-11 h-5 bg-slate-100 rounded-full checked:bg-primary cursor-pointer transition-colors duration-300"
                    :checked="!!platform.enabled"
                    :aria-label="$t(platform.enabled ? 'delivery_platforms.enabled' : 'delivery_platforms.disabled')"
                    @change="$emit('toggle', platform)"
                />
                <label
                    :for="`dp-toggle-${platform.id}`"
                    class="absolute top-0 left-0 w-5 h-5 bg-white rounded-full border border-slate-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 cursor-pointer pointer-events-none"
                ></label>
            </div>
        </td>
        <td class="db-table-body-td">
            <code class="dp-store-id" v-if="platform.external_store_id">
                {{ platform.external_store_id }}
            </code>
            <span v-else class="dp-empty">—</span>
        </td>
        <td class="db-table-body-td">
            <DeliveryPlatformHealthBadge :last-received-at="platform.last_webhook_received_at" />
        </td>
        <td class="db-table-body-td">
            <span class="dp-timestamp" :title="platform.last_webhook_received_at || ''">
                {{ formattedReceivedAt }}
            </span>
        </td>
        <td class="db-table-body-td">
            <button
                type="button"
                class="db-btn-outline sm primary modal-btn m-0.5"
                @click="$emit('edit', platform)"
            >
                <i class="lab lab-edit"></i>
                <span>{{ $t("button.edit") }}</span>
            </button>
        </td>
    </tr>
</template>

<script>
import DeliveryPlatformHealthBadge from "./DeliveryPlatformHealthBadge.vue";

/**
 * [PARALLEL-TRACK-1.4 / Phase 4]
 *
 * One row in the delivery platform admin table. Stays presentational —
 * all axios calls + Vuex dispatches happen on the parent (Page) so
 * the row can be unit-tested with a mock platform object.
 */
export default {
    name: "DeliveryPlatformRow",
    components: { DeliveryPlatformHealthBadge },
    props: {
        platform: {
            type: Object,
            required: true,
        },
    },
    emits: ["toggle", "edit"],
    computed: {
        platformLabel() {
            const key = `delivery_platforms.platform_${this.platform.platform}`;
            const translated = this.$t(key);
            // If translation missing, fallback to the raw platform code
            // so the operator never sees a key like "delivery_platforms.platform_xxx".
            return translated && translated !== key ? translated : this.platform.platform;
        },
        formattedReceivedAt() {
            if (!this.platform.last_webhook_received_at) {
                return this.$t("delivery_platforms.never_received");
            }
            try {
                const d = new Date(this.platform.last_webhook_received_at);
                return d.toLocaleString();
            } catch (e) {
                return this.platform.last_webhook_received_at;
            }
        },
    },
};
</script>

<style scoped>
.dp-platform-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.dp-platform-name {
    font-weight: 600;
    color: #111827;
}
.dp-platform-branch {
    font-size: 0.75rem;
    color: #6b7280;
}
.dp-store-id {
    background: #f3f4f6;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 0.8rem;
    color: #374151;
}
.dp-empty {
    color: #9ca3af;
}
.dp-timestamp {
    font-size: 0.85rem;
    color: #4b5563;
}
</style>
