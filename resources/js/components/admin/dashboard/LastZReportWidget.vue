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
                    <p class="text-xs text-gray-600 mb-1" data-testid="last-z-report-status">
                        {{ statutLisible }}
                    </p>
                    <p class="text-sm text-gray-600 mb-3">
                        {{ formattedClosedAt }}
                        <span
                            v-if="ageTexte"
                            :class="ageInquietant ? 'text-amber-700 font-semibold' : 'text-gray-500'"
                            data-testid="last-z-report-age"
                        >— {{ ageTexte }}</span>
                    </p>
                </template>
                <router-link :to="{ name: 'admin.transactions.list' }" class="text-sm font-medium text-orange-700"
                    data-testid="last-z-report-link">
                    {{ $t('label.view_all_alerts') }}
                </router-link>
            </div>
        </div>
    </div>
</template>

<script>
import appService from '../../../services/appService';
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
        /**
         * [2026-09-03] Le statut sortait en anglais brut (« Closed ») dans une interface
         * entièrement française. `capitalize` en CSS ne traduit rien : il met une majuscule.
         */
        statutLisible() {
            const brut = String(this.resolvedReport?.status ?? '').toLowerCase();
            const table = {
                closed: 'Clôturé',
                open: 'Ouvert',
                pending: 'En attente',
            };
            // Repli sur la valeur brute : un statut inconnu doit rester lisible, pas disparaître.
            return table[brut] || (brut ? brut.charAt(0).toUpperCase() + brut.slice(1) : '');
        },
        /**
         * [2026-09-03] Un Z d'aujourd'hui et un Z de sept semaines s'affichaient à l'identique.
         * Mesuré en production : le dernier datait de 47 jours, avec 81 commandes encaissées
         * depuis. Le chiffre était juste ; c'est la seule information qui le rendrait
         * actionnable qui manquait.
         */
        ageJours() {
            const iso = this.resolvedReport?.closed_at || this.resolvedReport?.opened_at || null;
            if (! iso) return null;
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return null;
            return Math.floor((Date.now() - d.getTime()) / 86400000);
        },
        ageTexte() {
            const j = this.ageJours;
            if (j === null || j < 0) return '';
            if (j === 0) return "aujourd'hui";
            if (j === 1) return 'hier';
            return `il y a ${j} jours`;
        },
        /** Au-delà de deux jours sans clôture, le gérant doit le voir sans le chercher. */
        ageInquietant() {
            return this.ageJours !== null && this.ageJours > 2;
        },
        formattedClosedAt() {
            const row = this.resolvedReport;
            if (! row) return '';
            const iso = row.closed_at || row.opened_at || null;
            if (! iso) return '';
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return String(iso);
            // [2026-09-02] Locale du produit, pas du navigateur : « 7/16/2026 » sur une
            // date de clôture Z se lit 7 juillet pour un lecteur français.
            return appService.dateHeureFr(d);
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
                return false;
            }
            const entry = perms.find((p) => p && p.url === 'transactions');
            if (!entry) {
                return false;
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
