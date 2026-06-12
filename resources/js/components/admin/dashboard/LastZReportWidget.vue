<template>
    <LoadingComponent :props="loading" />
    <div class="col-12 xl:col-6">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title mb-0">{{ $t('label.last_z_report') }}</h3>
            </div>
            <div class="db-card-body">
                <p v-if="!loading.isActive && zReportUnavailable" class="text-sm text-gray-600 mb-3">
                    {{ $t('label.no_data_available') }}
                </p>
                <p v-else-if="loading.isActive" class="py-6" />
                <template v-else-if="resolvedReport">
                    <p class="text-sm text-gray-800 font-medium mb-1">
                        {{ $t('label.last_z_report') }} #{{ resolvedReport.sequence_no }}
                    </p>
                    <p class="text-xs text-gray-600 mb-1 capitalize">
                        {{ localizedStatus }}
                    </p>
                    <p class="text-sm text-gray-600 mb-3">
                        {{ formattedClosedAt }}
                    </p>
                </template>
                <!-- [W3.5 COCKPIT 2026-06-08] Fiscal-correct copy: the generic
                     "Voir toutes" (view_all_alerts) belonged to the stock-alerts
                     widget. A "Dernier Z" widget links to the fiscal closures.
                     [dispute-r3 B-R1-16 2026-06-12] Router target FIXED: the
                     link landed on /admin/transactions (0 Z displayed). It now
                     targets the real read-only Z closures page /admin/z-reports
                     (zReportRoutes — created in the same heal, arbitrage with
                     B-R1-18 which had left the admin with NO Z UI at all). -->
                <router-link :to="{ name: 'admin.zReports.list' }" class="text-sm font-medium text-orange-700"
                    data-testid="last-z-report-link">
                    {{ $t('label.view_z_reports') }}
                </router-link>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import LoadingComponent from "../components/LoadingComponent";

/** @typedef {{ sequence_no?: number, status?: string, closed_at?: string|null, opened_at?: string|null }} ZReportRow */

export default {
    name: 'LastZReportWidget',
    components: { LoadingComponent },
    data() {
        return {
            loading: { isActive: false },
            /** @type {ZReportRow | null} */
            resolvedReport: null,
            zReportUnavailable: false,
        };
    },
    computed: {
        // [W3.5 COCKPIT 2026-06-08] Render the Z status in FR. Reuse the existing
        // cash_status_* keys (open/closed/reconciled). Use an explicit whitelist
        // map rather than `$t('label.cash_status_' + status)` so an unexpected
        // status value falls back to the raw string instead of leaking the raw
        // i18n key (no-raw-label rule). Status is lowercase (see fetchReports).
        localizedStatus() {
            const row = this.resolvedReport;
            if (! row || ! row.status) return '';
            const map = {
                open: 'label.cash_status_open',
                closed: 'label.cash_status_closed',
                reconciled: 'label.cash_status_reconciled',
            };
            const key = map[String(row.status).toLowerCase()];
            return key ? this.$t(key) : String(row.status);
        },
        formattedClosedAt() {
            const row = this.resolvedReport;
            if (! row) return '';
            const iso = row.closed_at || row.opened_at || null;
            if (! iso) return '';
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return String(iso);
            // [DASH-UIUX-2026-06-08 P2] Force fr-FR — a bare toLocaleString() rendered the
            // "Dernier Z" datetime in the browser locale (e.g. en-US "6/8/2026, 12:58:19 AM")
            // on the FR admin dashboard. fr-FR gives 24h "08/06/2026 00:58:19".
            return d.toLocaleString('fr-FR');
        },
    },
    mounted() {
        // [iter15-mega-fix D-005 2026-05-10] Cashier (POS role) used to land
        // briefly on /admin/dashboard after login (loginAsPosOperator helper)
        // which mounted this widget and fired GET /api/admin/fiscal/z-report
        // → 403 Forbidden silently. Gate the call behind the `transactions`
        // permission (matches transactionRoutes.js permissionUrl). Keeps the
        // historic default-allow when perms haven't hydrated yet.
        if (!this.canFetchReports()) {
            this.zReportUnavailable = true;
            this.resolvedReport = null;
            return;
        }
        this.fetchReports();
    },
    methods: {
        // [iter15-mega-fix D-005 2026-05-10] Permission gate aligned with
        // transactionRoutes.js (permissionUrl:'transactions') and mirrors the
        // existing DashboardComponent.normalizedPermissions() helper.
        canFetchReports() {
            const raw = this.$store.getters.authPermission;
            const perms = Array.isArray(raw) ? raw : (raw && Array.isArray(raw.data) ? raw.data : []);
            if (!perms.length) {
                return true;
            }
            const entry = perms.find((p) => p && p.url === 'transactions');
            if (!entry) {
                return true;
            }
            return entry.access === true;
        },
        async fetchReports() {
            this.loading.isActive = true;
            this.zReportUnavailable = false;
            this.resolvedReport = null;
            try {
                const res = await axios.get('admin/fiscal/z-report');
                const rows = Array.isArray(res.data?.data) ? res.data.data : [];
                if (rows.length === 0) {
                    this.zReportUnavailable = true;
                    return;
                }
                const closed = rows.find(r => String(r.status) === 'closed');
                this.resolvedReport = closed || rows[0] || null;
            } catch (_e) {
                this.zReportUnavailable = true;
                this.resolvedReport = null;
            } finally {
                this.loading.isActive = false;
            }
        },
    },
};
</script>
