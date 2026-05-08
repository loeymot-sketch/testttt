<template>
    <ErrorBoundary>
        <LoadingComponent :props="loadingProps" />

        <div class="db-card db-tab-div active">
            <div class="db-card-header border-none sm-header">
                <div>
                    <h3 class="db-card-title">{{ $t('stock.title') }}</h3>
                    <p v-if="generatedAt" class="sm-last-updated">
                        {{ $t('stock.last_updated') }}: {{ formattedGeneratedAt }}
                    </p>
                </div>
                <div class="db-card-filter sm-filters">
                    <label class="sm-filter-label" for="sm-branch-filter">
                        {{ $t('label.branch') }}
                    </label>
                    <select
                        id="sm-branch-filter"
                        v-model="selectedBranchId"
                        class="db-field-control sm-branch-select"
                        @change="reload"
                    >
                        <option v-for="b in branches" :key="b.id" :value="b.id">
                            {{ b.name }}
                        </option>
                    </select>
                    <button
                        type="button"
                        class="db-btn db-btn-outline sm-refresh"
                        :disabled="loadingProps.isActive"
                        @click="reload"
                    >
                        {{ $t('common.refresh') || 'Refresh' }}
                    </button>
                </div>
            </div>

            <div class="sm-tabs" role="tablist">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === tab.key"
                    :class="['sm-tab', { active: activeTab === tab.key }]"
                    @click="activeTab = tab.key"
                >
                    {{ $t('stock.' + tab.key) }}
                    <span class="sm-tab-count">{{ collections[tab.key].length }}</span>
                </button>
            </div>

            <div
                v-if="!toggleSupportedFor(activeTab)"
                class="sm-banner sm-banner-warning"
                role="status"
            >
                {{ $t('stock.toggle_disabled_banner') }}
            </div>

            <div class="db-table-responsive sm-table-wrap">
                <table class="db-table stripe">
                    <thead class="db-table-head">
                        <tr class="db-table-head-tr">
                            <th class="db-table-head-th">{{ $t('stock.col_name') }}</th>
                            <th class="db-table-head-th">{{ $t('stock.col_status') }}</th>
                            <th class="db-table-head-th">{{ $t('stock.col_reason') }}</th>
                            <th class="db-table-head-th sm-th-toggle">{{ $t('stock.col_toggle') }}</th>
                        </tr>
                    </thead>
                    <tbody class="db-table-body" v-if="visibleRows.length > 0">
                        <StockToggleRow
                            v-for="row in visibleRows"
                            :key="rowKey(row)"
                            :row="row"
                            :entity-type="entityTypeFor(activeTab)"
                            :secondary="secondaryFor(row)"
                            :toggle-supported="toggleSupportedFor(activeTab)"
                            :saving="savingId === row.id"
                            @toggle="onToggle"
                            @reason="onReasonChange"
                        />
                    </tbody>
                    <tbody v-else>
                        <tr>
                            <td class="db-table-body-td sm-empty" colspan="4">
                                {{ $t('stock.empty') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <details class="sm-audit">
                <summary @click="onAuditOpen">
                    {{ $t('stock.audit_log') }}
                </summary>
                <ul v-if="auditLogs.length > 0" class="sm-audit-list">
                    <li v-for="log in auditLogs" :key="log.id">
                        <span class="sm-audit-time">{{ formatAuditTime(log.created_at) }}</span>
                        <span class="sm-audit-action">{{ log.action }}</span>
                        <span class="sm-audit-resource">{{ log.resource }}</span>
                    </li>
                </ul>
                <p v-else-if="auditLoaded" class="sm-audit-empty">
                    {{ $t('stock.audit_empty') }}
                </p>
            </details>
        </div>
    </ErrorBoundary>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import ErrorBoundary from "../components/ErrorBoundary.vue";
import StockToggleRow from "./StockToggleRow.vue";
import alertService from "../../../services/alertService";

/**
 * [F-016b minimal]
 *
 * Minimal owner-facing stock manager. Three tabs (items / extras /
 * variations) backed by `/api/admin/stock`. The toggle for items
 * persists through {@see \App\Services\Menu\AvailabilityService};
 * extras and variations render but their toggles are disabled-with-banner
 * until F-016a-BIS lands.
 *
 * No client-side filters or pagination by design — owner uses Ctrl+F
 * in the browser. Lists are capped at 500 rows server-side.
 */
export default {
    name: "StockManagerMinimalPage",
    components: {
        LoadingComponent,
        ErrorBoundary,
        StockToggleRow,
    },
    data() {
        return {
            loadingProps: { isActive: false },
            selectedBranchId: null,
            activeTab: "items",
            tabs: [
                { key: "items" },
                { key: "extras" },
                { key: "variations" },
            ],
            savingId: null,
            auditLoaded: false,
        };
    },
    computed: {
        branches() {
            return this.$store.getters["branch/lists"] || [];
        },
        items() {
            return this.$store.getters["stockManager/items"];
        },
        extras() {
            return this.$store.getters["stockManager/extras"];
        },
        variations() {
            return this.$store.getters["stockManager/variations"];
        },
        auditLogs() {
            return this.$store.getters["stockManager/audit"];
        },
        generatedAt() {
            return this.$store.getters["stockManager/generatedAt"];
        },
        formattedGeneratedAt() {
            const raw = this.generatedAt;
            if (!raw) return "";
            try {
                return new Date(raw).toLocaleString();
            } catch (e) {
                return raw;
            }
        },
        collections() {
            return {
                items: this.items,
                extras: this.extras,
                variations: this.variations,
            };
        },
        visibleRows() {
            return this.collections[this.activeTab] || [];
        },
    },
    mounted() {
        this.loadBranches();
        this.reload();
    },
    methods: {
        loadBranches() {
            try {
                if (this.branches.length === 0) {
                    this.$store
                        .dispatch("branch/lists", { paginate: 0 })
                        .catch(() => {});
                }
            } catch (e) {
                // non-fatal
            }
        },
        async reload() {
            this.loadingProps.isActive = true;
            try {
                await this.$store.dispatch("stockManager/load", {
                    branchId: this.selectedBranchId || undefined,
                });
                // Pin the dropdown to whichever branch the backend resolved
                // (head-office users default to the first active branch).
                const resolved = this.$store.getters["stockManager/branchId"];
                if (resolved && resolved !== this.selectedBranchId) {
                    this.selectedBranchId = resolved;
                }
            } catch (err) {
                alertService.error(
                    err?.response?.data?.message || this.$t("stock.load_failed")
                );
            } finally {
                this.loadingProps.isActive = false;
            }
        },
        toggleSupportedFor(tabKey) {
            if (tabKey === "items") return true;
            if (tabKey === "extras") {
                return !!this.$store.getters["stockManager/extrasToggleSupported"];
            }
            if (tabKey === "variations") {
                return !!this.$store.getters[
                    "stockManager/variationsToggleSupported"
                ];
            }
            return false;
        },
        entityTypeFor(tabKey) {
            if (tabKey === "items") return "item";
            if (tabKey === "extras") return "extra";
            if (tabKey === "variations") return "variation";
            return "item";
        },
        secondaryFor(row) {
            if (this.activeTab === "extras") {
                return row.item_name || "";
            }
            if (this.activeTab === "variations") {
                const parts = [];
                if (row.attribute_name) parts.push(row.attribute_name);
                if (row.item_name) parts.push(row.item_name);
                return parts.join(" – ");
            }
            return "";
        },
        rowKey(row) {
            return `${this.activeTab}-${row.id}`;
        },
        async onToggle(payload) {
            if (!this.toggleSupportedFor(this.activeTab)) {
                return;
            }
            const branchId = this.selectedBranchId;
            if (!branchId) {
                alertService.error(this.$t("stock.no_branch_selected"));
                return;
            }

            // Optimistic in-place update; rollback on failure.
            const previous = this.snapshotRow(payload.entityType, payload.entityId);
            this.$store.commit("stockManager/applyLocalToggle", payload);
            this.savingId = payload.entityId;

            try {
                await this.$store.dispatch("stockManager/toggle", {
                    entityType: payload.entityType,
                    entityId: payload.entityId,
                    branchId,
                    isAvailable: payload.isAvailable,
                    reason: payload.reason,
                });
                alertService.success(this.$t("stock.toggle_success"));
            } catch (err) {
                // Rollback to the previous availability state.
                if (previous) {
                    this.$store.commit("stockManager/applyLocalToggle", {
                        entityType: payload.entityType,
                        entityId: payload.entityId,
                        isAvailable: previous.is_available,
                        reason: previous.unavailable_reason,
                    });
                }
                alertService.error(
                    err?.response?.data?.message || this.$t("stock.toggle_failed")
                );
            } finally {
                this.savingId = null;
            }
        },
        async onReasonChange(payload) {
            // Update reason WITHOUT flipping availability: simply re-call
            // toggle with the existing is_available=false state and the
            // new reason. We intentionally only run this on items because
            // extras / variations are gated.
            if (this.activeTab !== "items") return;

            const branchId = this.selectedBranchId;
            if (!branchId) return;

            try {
                await this.$store.dispatch("stockManager/toggle", {
                    entityType: payload.entityType,
                    entityId: payload.entityId,
                    branchId,
                    isAvailable: false,
                    reason: payload.reason,
                });
                this.$store.commit("stockManager/applyLocalToggle", {
                    entityType: payload.entityType,
                    entityId: payload.entityId,
                    isAvailable: false,
                    reason: payload.reason,
                });
            } catch (err) {
                alertService.error(
                    err?.response?.data?.message || this.$t("stock.toggle_failed")
                );
            }
        },
        snapshotRow(entityType, entityId) {
            const collection =
                entityType === "item"
                    ? this.items
                    : entityType === "extra"
                    ? this.extras
                    : this.variations;
            const row = (collection || []).find((r) => r.id === entityId);
            return row ? { ...row } : null;
        },
        async onAuditOpen() {
            // Lazy-load the audit list the first time the user expands it.
            if (this.auditLoaded || !this.selectedBranchId) return;
            try {
                await this.$store.dispatch("stockManager/loadAudit", {
                    branchId: this.selectedBranchId,
                    limit: 50,
                });
                this.auditLoaded = true;
            } catch (e) {
                // Non-fatal: surface a toast but leave the list collapsed.
                alertService.error(this.$t("stock.audit_failed"));
            }
        },
        formatAuditTime(raw) {
            if (!raw) return "";
            try {
                return new Date(raw).toLocaleString();
            } catch (e) {
                return raw;
            }
        },
    },
};
</script>

<style scoped>
.sm-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.sm-last-updated {
    margin: 4px 0 0;
    font-size: 0.78rem;
    color: #6b7280;
}
.sm-filters {
    display: flex;
    align-items: center;
    gap: 10px;
}
.sm-filter-label {
    font-size: 0.85rem;
    color: #475569;
}
.sm-branch-select {
    min-width: 200px;
}
.sm-tabs {
    display: flex;
    gap: 4px;
    margin: 12px 0 8px;
    border-bottom: 1px solid #e5e7eb;
}
.sm-tab {
    background: transparent;
    border: 0;
    padding: 8px 16px;
    cursor: pointer;
    color: #475569;
    font-weight: 500;
    border-bottom: 2px solid transparent;
}
.sm-tab.active {
    color: #1f2937;
    border-bottom-color: #16a34a;
}
.sm-tab-count {
    margin-left: 6px;
    background: #f1f5f9;
    color: #475569;
    font-size: 0.72rem;
    padding: 1px 6px;
    border-radius: 9999px;
}
.sm-banner {
    margin: 8px 0;
    padding: 10px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
}
.sm-banner-warning {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
}
.sm-th-toggle {
    text-align: right;
}
.sm-empty {
    text-align: center;
    color: #6b7280;
    padding: 24px;
    font-style: italic;
}
.sm-audit {
    margin-top: 16px;
    padding: 8px 0;
    border-top: 1px solid #e5e7eb;
}
.sm-audit summary {
    cursor: pointer;
    color: #475569;
    font-size: 0.85rem;
    padding: 6px 0;
}
.sm-audit-list {
    list-style: none;
    padding: 0;
    margin: 6px 0 0;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.78rem;
    color: #374151;
}
.sm-audit-list li {
    display: flex;
    gap: 12px;
    padding: 4px 0;
    border-bottom: 1px dashed #f1f5f9;
}
.sm-audit-time {
    color: #94a3b8;
    flex-shrink: 0;
}
.sm-audit-action {
    color: #1f2937;
    flex-shrink: 0;
    min-width: 180px;
}
.sm-audit-resource {
    color: #475569;
}
.sm-audit-empty {
    color: #6b7280;
    font-style: italic;
    margin: 8px 0 0;
}
</style>
