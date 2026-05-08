<template>
    <tr class="db-table-body-tr">
        <td class="db-table-body-td sm-row-name">
            <span class="sm-row-primary">{{ row.name }}</span>
            <span v-if="secondary" class="sm-row-secondary">{{ secondary }}</span>
        </td>
        <td class="db-table-body-td">
            <span :class="['sm-status-pill', row.is_available ? 'sm-status-on' : 'sm-status-off']">
                {{
                    row.is_available
                        ? $t('stock.toggle_available')
                        : $t('stock.toggle_unavailable')
                }}
            </span>
            <span
                v-if="!row.is_available && row.unavailable_reason"
                class="sm-row-reason-display"
            >
                {{ row.unavailable_reason }}
            </span>
        </td>
        <td class="db-table-body-td">
            <input
                v-if="!row.is_available"
                type="text"
                class="db-field-control sm-reason-input"
                :placeholder="$t('stock.reason_placeholder')"
                :value="row.unavailable_reason || ''"
                :disabled="!toggleSupported"
                maxlength="32"
                @change="onReasonChange"
            />
        </td>
        <td class="db-table-body-td sm-toggle-cell">
            <label
                class="sm-switch"
                :class="{ 'sm-switch-disabled': !toggleSupported }"
                :title="!toggleSupported ? $t('stock.toggle_disabled_hint') : ''"
            >
                <input
                    type="checkbox"
                    :checked="row.is_available"
                    :disabled="!toggleSupported || saving"
                    @change="onToggleChange"
                />
                <span class="sm-switch-slider"></span>
            </label>
        </td>
    </tr>
</template>

<script>
/**
 * [F-016b minimal]
 *
 * One row in the stock manager table. The parent decides what
 * "secondary" line to show under the name (parent item name for
 * extras / variations) and whether toggling is supported on this
 * tab (items always supported; extras + variations gated until
 * F-016a-BIS).
 *
 * Emits two events upward:
 *   - "toggle"   { entityType, entityId, isAvailable, reason }
 *   - "reason"   { entityType, entityId, reason }
 */
export default {
    name: "StockToggleRow",
    props: {
        row: {
            type: Object,
            required: true,
        },
        entityType: {
            // "item" | "extra" | "variation"
            type: String,
            required: true,
        },
        secondary: {
            type: String,
            default: "",
        },
        toggleSupported: {
            type: Boolean,
            default: true,
        },
        saving: {
            type: Boolean,
            default: false,
        },
    },
    methods: {
        onToggleChange(event) {
            const isAvailable = !!event.target.checked;
            this.$emit("toggle", {
                entityType: this.entityType,
                entityId: this.row.id,
                isAvailable,
                reason: this.row.unavailable_reason || null,
            });
        },
        onReasonChange(event) {
            const reason = String(event.target.value || "").slice(0, 32);
            this.$emit("reason", {
                entityType: this.entityType,
                entityId: this.row.id,
                reason,
            });
        },
    },
};
</script>

<style scoped>
.sm-row-name {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.sm-row-primary {
    font-weight: 600;
    color: #1f2937;
}
.sm-row-secondary {
    font-size: 0.78rem;
    color: #64748b;
}
.sm-row-reason-display {
    margin-left: 8px;
    font-size: 0.78rem;
    color: #6b7280;
    font-style: italic;
}
.sm-status-pill {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 9999px;
    font-size: 0.78rem;
    font-weight: 600;
}
.sm-status-on {
    background: #dcfce7;
    color: #166534;
}
.sm-status-off {
    background: #fee2e2;
    color: #991b1b;
}
.sm-reason-input {
    max-width: 220px;
}
.sm-toggle-cell {
    text-align: right;
}
.sm-switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
}
.sm-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.sm-switch-slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background-color: #cbd5e1;
    border-radius: 9999px;
    transition: 0.18s;
}
.sm-switch-slider::before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: #ffffff;
    border-radius: 50%;
    transition: 0.18s;
}
.sm-switch input:checked + .sm-switch-slider {
    background-color: #16a34a;
}
.sm-switch input:checked + .sm-switch-slider::before {
    transform: translateX(20px);
}
.sm-switch-disabled .sm-switch-slider {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
