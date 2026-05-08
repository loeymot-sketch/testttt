<template>
    <ErrorBoundary>
        <LoadingComponent :props="loadingProps" />

        <div class="db-card db-tab-div active">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t("delivery_platforms.title") }}</h3>
                <div class="db-card-filter dp-filters">
                    <label class="dp-filter-label" for="dp-branch-filter">
                        {{ $t("label.branch") }}
                    </label>
                    <select
                        id="dp-branch-filter"
                        v-model="branchFilter"
                        class="db-field-control dp-branch-select"
                        @change="reload"
                    >
                        <option :value="''">{{ $t('delivery_platforms.all_branches') }}</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">
                            {{ b.name }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="db-table-responsive">
                <table class="db-table stripe">
                    <thead class="db-table-head">
                        <tr class="db-table-head-tr">
                            <th class="db-table-head-th">
                                {{ $t("delivery_platforms.platform") }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t("delivery_platforms.enabled") }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t("delivery_platforms.store_id") }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t("delivery_platforms.health") }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t("delivery_platforms.last_received") }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t("delivery_platforms.actions") }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="db-table-body" v-if="filteredPlatforms.length > 0">
                        <DeliveryPlatformRow
                            v-for="platform in filteredPlatforms"
                            :key="platform.id"
                            :platform="platform"
                            @toggle="onToggle"
                            @edit="onEdit"
                        />
                    </tbody>
                    <tbody v-else>
                        <tr>
                            <td class="db-table-body-td dp-empty-row" colspan="6">
                                {{ $t("delivery_platforms.empty") }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DeliveryPlatformConfigModal
            :visible="modalVisible"
            :platform="selectedPlatform"
            @close="closeModal"
            @saved="onSaved"
        />
    </ErrorBoundary>
</template>

<script>
import axios from "axios";
import LoadingComponent from "../components/LoadingComponent";
import ErrorBoundary from "../components/ErrorBoundary.vue";
import DeliveryPlatformRow from "./DeliveryPlatformRow.vue";
import DeliveryPlatformConfigModal from "./DeliveryPlatformConfigModal.vue";
import alertService from "../../../services/alertService";

/**
 * [PARALLEL-TRACK-1.4 / Phase 4]
 *
 * Admin page that lists every (branch × platform) row, with a
 * branch filter dropdown and a single shared modal for editing.
 *
 * The page uses the dedicated Vuex module (`deliveryPlatform`) for
 * the list-level state to align with the kioskMachine pattern, but
 * one-shot mutations (toggle / save) talk directly to axios because
 * a global cache invalidation is not worth a Vuex action for those
 * paths.
 */
export default {
    name: "DeliveryPlatformsPage",
    components: {
        LoadingComponent,
        ErrorBoundary,
        DeliveryPlatformRow,
        DeliveryPlatformConfigModal,
    },
    data() {
        return {
            loadingProps: { isActive: false },
            platforms: [],
            branchFilter: "",
            modalVisible: false,
            selectedPlatform: null,
        };
    },
    computed: {
        branches() {
            // The branch store exposes the standard `lists` getter
            // used everywhere else in the admin UI.
            return this.$store.getters["branch/lists"] || [];
        },
        filteredPlatforms() {
            if (!this.branchFilter) return this.platforms;
            return this.platforms.filter(
                (p) => String(p.branch_id) === String(this.branchFilter)
            );
        },
    },
    mounted() {
        this.loadBranches();
        this.reload();
    },
    methods: {
        loadBranches() {
            // If the store already has branches loaded (other admin
            // pages typically pre-load them) we skip the request.
            try {
                if (this.branches.length === 0) {
                    this.$store.dispatch("branch/lists", { paginate: 0 }).catch(() => {});
                }
            } catch (e) {
                // Non-fatal: page still works without the filter.
            }
        },
        async reload() {
            this.loadingProps.isActive = true;
            try {
                const params = {};
                if (this.branchFilter) params.branch_id = this.branchFilter;
                const res = await axios.get("admin/delivery-platforms", { params });
                this.platforms = res.data?.data || [];
            } catch (err) {
                alertService.error(err?.response?.data?.message || this.$t("delivery_platforms.load_failed"));
                this.platforms = [];
            } finally {
                this.loadingProps.isActive = false;
            }
        },
        onEdit(platform) {
            this.selectedPlatform = platform;
            this.modalVisible = true;
        },
        async onToggle(platform) {
            this.loadingProps.isActive = true;
            try {
                const res = await axios.post(`admin/delivery-platforms/${platform.id}/toggle`);
                const updated = res.data?.data;
                if (updated) {
                    const idx = this.platforms.findIndex((p) => p.id === updated.id);
                    if (idx !== -1) this.platforms.splice(idx, 1, updated);
                }
                alertService.success(this.$t("delivery_platforms.toggle_success"));
            } catch (err) {
                alertService.error(err?.response?.data?.message || this.$t("delivery_platforms.toggle_failed"));
                // Re-fetch to recover the canonical state
                await this.reload();
            } finally {
                this.loadingProps.isActive = false;
            }
        },
        onSaved(updated) {
            if (updated && updated.id) {
                const idx = this.platforms.findIndex((p) => p.id === updated.id);
                if (idx !== -1) this.platforms.splice(idx, 1, updated);
            } else {
                this.reload();
            }
        },
        closeModal() {
            this.modalVisible = false;
            this.selectedPlatform = null;
        },
    },
};
</script>

<style scoped>
.dp-filters {
    display: flex;
    align-items: center;
    gap: 10px;
}
.dp-filter-label {
    font-size: 0.85rem;
    color: #475569;
}
.dp-branch-select {
    min-width: 180px;
}
.dp-empty-row {
    text-align: center;
    color: #6b7280;
    padding: 24px;
    font-style: italic;
}
</style>
