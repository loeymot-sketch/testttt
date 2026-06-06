<template>
    <LoadingComponent :props="loading" />
    <div class="col-12 xl:col-6">
        <div class="db-card">
            <div class="db-card-header flex items-center justify-between flex-wrap gap-2">
                <h3 class="db-card-title mb-0">{{ $t('label.stock_low_alerts') }}</h3>
                <router-link to="/admin/stock/rupture" class="text-sm font-medium text-orange-700" data-testid="stock-low-alerts-view-all">
                    {{ $t('label.view_all_alerts') }}
                </router-link>
            </div>
            <div class="db-card-body">
                <!-- [DASH-04 FIX] Distinct neutral error state on API failure —
                     the widget previously swallowed the error into alerts=[] and
                     showed the reassuring "Aucune alerte stock bas" (false-negative).
                     Raw FR literal (no new i18n JSON key — out of this lane). -->
                <div v-if="!loading.isActive && hasError" data-testid="stock-low-alerts-error" class="text-sm text-gray-500 flex items-center gap-2">
                    <i class="lab lab-warning text-gray-400" aria-hidden="true"></i>
                    <span>Données indisponibles</span>
                </div>
                <p v-else-if="!loading.isActive && alerts.length === 0" data-testid="stock-low-alerts-empty" class="text-sm text-gray-500">
                    {{ $t('label.no_low_alerts') }}
                </p>
                <div v-else-if="loading.isActive" class="py-6" />
                <table v-else class="db-table db-table-mini w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100">
                            <th class="py-2 text-left font-medium text-gray-600">{{ $t('label.branch') }}</th>
                            <th class="py-2 text-left font-medium text-gray-600">{{ $t('label.item') }}</th>
                            <th class="py-2 text-right font-medium text-gray-600 whitespace-nowrap">{{ $t('label.quantity') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="alert in alerts.slice(0, 5)"
                            :key="`${alert.branch_id}-${alert.stockable_type}-${alert.stockable_id}`"
                            class="border-b border-gray-50"
                            :data-testid="`stock-low-alert-${alert.stockable_id}`">
                            <td class="py-2 pr-2 text-gray-700">{{ alert.branch_name || alert.branch_id }}</td>
                            <td class="py-2 pr-2 text-gray-800">{{ alert.label || alert.stockable_name || ('#' + alert.stockable_id) }}</td>
                            <td class="py-2 text-right text-orange-600 font-semibold whitespace-nowrap">
                                {{ alert.on_hand }} / {{ alert.threshold_low }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import LoadingComponent from "../components/LoadingComponent";

export default {
    name: 'StockLowAlertsWidget',
    components: { LoadingComponent },
    data() {
        return {
            loading: { isActive: false },
            alerts: [],
            // [DASH-04 FIX] Distinguish "fetched OK, genuinely empty" from
            // "fetch failed" so the template never shows a false 'all good'.
            hasError: false,
        };
    },
    mounted() {
        // [iter15-mega-fix D-005 2026-05-10] Cashier (POS role) used to land
        // briefly on /admin/dashboard after login (loginAsPosOperator helper)
        // which mounted this widget and fired GET /api/admin/stock/low-alerts
        // → 403 Forbidden silently. Gate the call behind the same permission
        // shape used by DashboardComponent.quickAccessLinks (`items` perm with
        // explicit access:false skips). When the perm entry is absent we keep
        // the historic default-allow so admins keep working unchanged.
        if (!this.canFetchAlerts()) {
            this.alerts = [];
            return;
        }
        this.fetchAlerts();
    },
    methods: {
        // [iter15-mega-fix D-005 2026-05-10] Permission gate aligned with
        // /admin/stock/rupture route meta (permissionUrl:'items') and the
        // existing DashboardComponent.normalizedPermissions() helper.
        canFetchAlerts() {
            const raw = this.$store.getters.authPermission;
            const perms = Array.isArray(raw) ? raw : (raw && Array.isArray(raw.data) ? raw.data : []);
            if (!perms.length) {
                // Empty/initial state — keep historic default-allow so admin
                // dashboards never silently degrade if perms haven't hydrated.
                return true;
            }
            const entry = perms.find((p) => p && p.url === 'items');
            if (!entry) {
                return true;
            }
            return entry.access === true;
        },
        async fetchAlerts() {
            this.loading.isActive = true;
            try {
                const res = await axios.get('admin/stock/low-alerts');
                this.alerts = res.data?.alerts ?? [];
                // [DASH-04 FIX] Success → clear any prior error (self-heal).
                this.hasError = false;
            } catch (_e) {
                // [DASH-04 FIX] Flag the error instead of silently rendering an
                // empty 'no low alerts' reassurance.
                this.alerts = [];
                this.hasError = true;
            } finally {
                this.loading.isActive = false;
            }
        },
    },
};
</script>
