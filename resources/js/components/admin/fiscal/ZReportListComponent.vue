<template>
    <!--
        [HEAL dispute-r3 B-R1-16 2026-06-12] Page MINIMALE lecture-seule des
        clôtures Z — la cible honnête du widget « Voir les clôtures Z » du
        dashboard, qui atterrissait sur /admin/transactions (0 Z affiché).

        Arbitrage B-R1-16 × B-R1-18 : l'API existe depuis POS-9.4.9
        (GET /api/admin/fiscal/z-report — latest 100, permission
        pos-manage-fiscal, branch-scoped pour les staff) mais AUCUNE page ne
        la consommait. Cette page est volontairement minimale : consultation
        (séquence, statut, ouverture/clôture, total TTC, commandes) — le
        téléchargement PDF / l'archivage UI restent le périmètre produit
        B-R1-18 (gate owner).

        NF525 : READ-ONLY. Aucun write, aucune interaction fiscale.
    -->
    <div class="col-12">
        <div class="db-card db-tab-div active">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('menu.z_reports') }}</h3>
            </div>

            <div class="db-card-body">
                <!-- Loading state -->
                <div v-if="loading" class="p-6 text-center text-gray-500">
                    {{ $t('label.loading') }}…
                </div>

                <!-- Unavailable (403 / API down) -->
                <div
                    v-else-if="unavailable"
                    class="p-6 text-center text-gray-500"
                    data-testid="z-reports-unavailable"
                >
                    {{ $t('label.no_data_available') }}
                </div>

                <!-- Empty state -->
                <div
                    v-else-if="!reports.length"
                    class="p-8 text-center text-gray-500"
                    data-testid="z-reports-empty"
                >
                    {{ $t('label.no_data_available') }}
                </div>

                <!-- Z closures table -->
                <div v-else class="px-4 sm:px-5 pb-5">
                    <div class="overflow-x-auto border rounded-lg">
                        <table class="w-full text-sm" data-testid="z-reports-table">
                            <thead class="bg-gray-100 text-gray-700">
                                <tr>
                                    <th class="px-3 py-2 text-left">{{ $t('label.z_sequence') }}</th>
                                    <th class="px-3 py-2 text-left">{{ $t('label.status') }}</th>
                                    <th class="px-3 py-2 text-left">{{ $t('label.z_opened_at') }}</th>
                                    <th class="px-3 py-2 text-left">{{ $t('label.z_closed_at') }}</th>
                                    <th class="px-3 py-2 text-right">{{ $t('label.z_total_ttc') }}</th>
                                    <th class="px-3 py-2 text-right">{{ $t('label.z_order_count') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in reports"
                                    :key="row.id"
                                    class="border-t hover:bg-gray-50"
                                    :data-testid="`z-report-row-${row.id}`"
                                >
                                    <td class="px-3 py-2 font-semibold">#{{ row.sequence_no }}</td>
                                    <td class="px-3 py-2">
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded text-xs"
                                            :class="statusClass(row.status)"
                                        >
                                            {{ localizedStatus(row.status) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(row.opened_at) }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(row.closed_at) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ formatMoney(row.total_ttc) }}</td>
                                    <td class="px-3 py-2 text-right">{{ row.order_count ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

/** @typedef {{ id: number, sequence_no?: number, status?: string, opened_at?: string|null, closed_at?: string|null, total_ttc?: string|number, order_count?: number }} ZReportRow */

export default {
    name: 'ZReportListComponent',
    data() {
        return {
            loading: false,
            unavailable: false,
            /** @type {ZReportRow[]} */
            reports: [],
        };
    },
    mounted() {
        this.fetchReports();
    },
    methods: {
        async fetchReports() {
            this.loading = true;
            this.unavailable = false;
            try {
                const res = await axios.get('admin/fiscal/z-report');
                this.reports = Array.isArray(res.data?.data) ? res.data.data : [];
            } catch (_e) {
                // 403 (pas pos-manage-fiscal) / API down → état indisponible
                // propre, jamais de page cassée.
                this.reports = [];
                this.unavailable = true;
            } finally {
                this.loading = false;
            }
        },
        // Même whitelist FR que LastZReportWidget (no-raw-label rule : un
        // statut inattendu rend la valeur brute, jamais une clef i18n).
        localizedStatus(status) {
            const map = {
                open: 'label.cash_status_open',
                closed: 'label.cash_status_closed',
                reconciled: 'label.cash_status_reconciled',
            };
            const key = map[String(status || '').toLowerCase()];
            return key ? this.$t(key) : String(status || '—');
        },
        statusClass(status) {
            switch (String(status || '').toLowerCase()) {
                case 'open':       return 'bg-emerald-100 text-emerald-800';
                case 'closed':     return 'bg-gray-100 text-gray-800';
                case 'reconciled': return 'bg-blue-100 text-blue-800';
                default:           return 'bg-gray-100 text-gray-800';
            }
        },
        formatDateTime(iso) {
            if (!iso) return '—';
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return String(iso);
            // fr-FR forcé (leçon DASH-UIUX P2 : locale navigateur ≠ FR).
            return d.toLocaleString('fr-FR');
        },
        formatMoney(v) {
            const n = Number(v || 0);
            try {
                return new Intl.NumberFormat('fr-FR', {
                    style: 'currency',
                    currency: 'EUR',
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                }).format(n);
            } catch (e) {
                return `${n.toFixed(2)} €`;
            }
        },
    },
};
</script>
