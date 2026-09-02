<template>
    <LoadingComponent :props="loading" />
    <div class="col-12 xl:col-6">
        <div class="db-card">
            <div class="db-card-header flex items-center justify-between flex-wrap gap-2">
                <h3 class="db-card-title mb-0 flex items-center gap-2">
                    {{ $t('label.stock_low_alerts') }}
                    <!-- [T-D STOCK-IA 2026-08-16 · GOAL owner] "trois articles stock faible" —
                         un compte visible, pas seulement les 5 premières lignes du tableau. -->
                    <span
                        v-if="!loading.isActive && alerts.length"
                        class="db-table-badge text-orange-900 bg-orange-100 rounded-full px-2 py-0.5 text-xs font-semibold"
                        data-testid="stock-low-alerts-count"
                    >{{ alerts.length }}</span>
                </h3>
                <router-link to="/admin/stock/rupture" class="text-sm font-medium text-orange-700" data-testid="stock-low-alerts-view-all">
                    {{ $t('label.view_all_alerts') }}
                </router-link>
            </div>
            <div class="db-card-body">
                <!-- [T-5.2 PANNE-MASQUEE 2026-08-15 · GOAL_CONFORT_MAX] fetchAlerts()
                     avalait toute erreur (403/500/réseau) en la traitant EXACTEMENT
                     comme "aucune alerte" — une panne était donc invisible, jamais
                     distinguable d'un vrai stock sain. -->
                <p v-if="!loading.isActive && fetchError" class="text-sm text-red-600" data-testid="stock-low-alerts-error">
                    {{ $t('label.stock_low_alerts_error') }}
                </p>
                <!--
                    [2026-09-02] « Aucune alerte de stock bas » se lit « votre stock va bien ».
                    Mesuré : 55 lignes de stock sur 55 ont `threshold_low` NULL, donc la requête
                    de l'API ne peut JAMAIS rien remonter — feu vert permanent, pendant que
                    Coca-Cola 33cl était à 0. Un panneau qui ne surveille rien doit le dire.
                -->
                <p
                    v-else-if="!loading.isActive && alerts.length === 0 && aucunSeuilConfigure"
                    class="text-sm text-amber-700"
                    data-testid="stock-low-alerts-no-threshold"
                >
                    {{ $t('label.stock_low_alerts_no_threshold') }}
                </p>
                <p v-else-if="!loading.isActive && alerts.length === 0" class="text-sm text-gray-500">
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
            fetchError: false,
            // [2026-09-02] Distinguer « rien à signaler » de « rien n'est surveillé ».
            trackedRows: 0,
            thresholdsConfigured: 0,
        };
    },
    computed: {
        /**
         * Vrai quand des lignes de stock existent mais qu'AUCUNE ne porte de seuil bas :
         * la requête d'alerte ne peut alors rien remonter, quel que soit le stock réel.
         * On ne l'affirme que si `tracked_rows > 0` — sur une base sans aucun stock suivi,
         * « aucune alerte » reste la bonne phrase.
         */
        aucunSeuilConfigure() {
            return this.trackedRows > 0 && this.thresholdsConfigured === 0;
        },
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
        // /admin/stock/rupture route meta and the existing
        // DashboardComponent.normalizedPermissions() helper.
        // [test-e2e fix A-002/E-001/E-002/E-006 round-1 2026-08-16] Le gate
        // comparait p.url === 'items' alors que le VRAI gate backend sur
        // GET admin/stock/low-alerts est `permission:items_show`
        // (StockRuptureDashboardController::__construct — url seedée
        // 'items/show', PermissionTableSeeder.php:63), pas `items` (url
        // 'items'). Ça ne changeait rien pour le rôle de test POS Operator
        // (qui n'a ni l'un ni l'autre) mais un rôle réel avec `items_show`
        // seul (pas `items`) aurait un faux-négatif (widget masqué à tort),
        // et l'inverse un faux-positif (widget affiché puis 403 au fetch).
        canFetchAlerts() {
            const raw = this.$store.getters.authPermission;
            const perms = Array.isArray(raw) ? raw : (raw && Array.isArray(raw.data) ? raw.data : []);
            if (!perms.length) {
                return false;
            }
            const entry = perms.find((p) => p && (p.url === 'items/show' || p.name === 'items_show'));
            if (!entry) {
                return false;
            }
            return entry.access === true;
        },
        async fetchAlerts() {
            this.loading.isActive = true;
            try {
                const res = await axios.get('admin/stock/low-alerts');
                this.alerts = res.data?.alerts ?? [];
                this.trackedRows = Number(res.data?.tracked_rows ?? 0);
                this.thresholdsConfigured = Number(res.data?.thresholds_configured ?? 0);
                this.fetchError = false;
            } catch (_e) {
                // [T-5.2 PANNE-MASQUEE] Une panne (403/500/réseau) ne doit JAMAIS
                // ressembler à "aucune alerte" — cf. bannière d'erreur dans le template.
                this.alerts = [];
                this.trackedRows = 0;
                this.thresholdsConfigured = 0;
                this.fetchError = true;
            } finally {
                this.loading.isActive = false;
            }
        },
    },
};
</script>
