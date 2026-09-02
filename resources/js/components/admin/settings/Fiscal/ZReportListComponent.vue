<template>
    <LoadingComponent :props="loading" />

    <div class="db-card db-tab-div active">
        <div class="db-card-header border-none">
            <h3 class="db-card-title">{{ $t("menu.z_reports") }}</h3>
            <div class="db-card-filter">
                <!-- [AUDIT-A P1-1 2026-08-06] Rapport X (photographie de la journée EN COURS,
                     sans clôturer) — l'API existait sans aucune UI. -->
                <button type="button" class="db-btn py-2 text-white bg-primary" @click="openXReport"
                        data-testid="fiscal-x-report-btn">
                    <i class="lab lab-view"></i>
                    <span>{{ $t("label.x_report") }}</span>
                </button>
            </div>
        </div>

        <div class="db-table-responsive">
            <table class="db-table stripe">
                <thead class="db-table-head">
                    <tr class="db-table-head-tr">
                        <th class="db-table-head-th">#</th>
                        <th class="db-table-head-th">{{ $t("label.date") }}</th>
                        <th class="db-table-head-th">{{ $t("label.status") }}</th>
                        <th class="db-table-head-th">{{ $t("label.total_amount") }}</th>
                        <th class="db-table-head-th">{{ $t("label.action") }}</th>
                    </tr>
                </thead>
                <tbody class="db-table-body" v-if="reports.length > 0">
                    <tr class="db-table-body-tr" v-for="report in reports" :key="report.id">
                        <td class="db-table-body-td">Z-{{ report.sequence_no }}</td>
                        <td class="db-table-body-td">{{ formatDate(report.closed_at || report.opened_at) }}</td>
                        <td class="db-table-body-td">
                            <span :class="String(report.status) === 'closed' ? 'text-green-600' : 'text-orange-500'"
                                  class="capitalize font-medium">
                                {{ String(report.status) === 'closed' ? $t('label.closed') : $t('label.open') }}
                            </span>
                        </td>
                        <td class="db-table-body-td">{{ formatMoney(report.total_ttc ?? report.total) }}</td>
                        <td class="db-table-body-td">
                            <button type="button" class="db-btn-outline sm primary m-0.5"
                                    :data-testid="`z-report-pdf-${report.id}`"
                                    :disabled="downloadingId === report.id"
                                    @click="downloadPdf(report)">
                                <i class="lab lab-file-export"></i>
                                PDF
                            </button>
                        </td>
                    </tr>
                </tbody>
                <tbody v-else>
                    <tr>
                        <td colspan="5" class="db-table-body-td text-center text-gray-400 py-6">
                            {{ $t("label.no_data") }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modale rapport X (lecture seule, journée en cours) -->
    <div class="modal" :class="{ active: xModalActive }">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">{{ $t("label.x_report") }}</h3>
                <button class="modal-close fa-solid fa-xmark text-xl text-slate-400 hover:text-red-500"
                        :aria-label="$t('button.close')" @click="xModalActive = false" type="button"></button>
            </div>
            <div class="modal-body">
                <pre class="text-xs whitespace-pre-wrap break-words max-h-96 overflow-y-auto"
                     data-testid="fiscal-x-report-body">{{ xReportText }}</pre>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";

/**
 * [AUDIT-A P1-1 2026-08-06] Rapports Z NF525 — l'API (liste / détail / PDF /
 * rapport X) existait au complet (routes/api.php fiscal.*) mais AUCUNE page ne
 * la servait : les PDF légaux étaient inatteignables sans curl. Page liste
 * lecture seule + téléchargement PDF + rapport X. L'ouverture/clôture de
 * journée reste pilotée par le flux caisse existant — volontairement AUCUN
 * bouton open/close ici (éviter une clôture accidentelle depuis les réglages).
 */
export default {
    name: 'ZReportListComponent',
    components: { LoadingComponent },
    data() {
        return {
            loading: { isActive: false },
            reports: [],
            downloadingId: null,
            xModalActive: false,
            xReportText: '',
        };
    },
    mounted() {
        this.fetch();
    },
    methods: {
        async fetch() {
            this.loading.isActive = true;
            try {
                const res = await axios.get('admin/fiscal/z-report');
                this.reports = Array.isArray(res.data?.data) ? res.data.data : [];
            } catch (e) {
                alertService.error(e.response?.data?.message || e.message);
            } finally {
                this.loading.isActive = false;
            }
        },
        async downloadPdf(report) {
            this.downloadingId = report.id;
            try {
                const res = await axios.get(`admin/fiscal/z-report/${report.id}/pdf`, { responseType: 'blob' });
                const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
                const a = document.createElement('a');
                a.href = url;
                a.download = `rapport-z-${report.sequence_no}.pdf`;
                document.body.appendChild(a);
                a.click();
                a.remove();
                window.URL.revokeObjectURL(url);
            } catch (e) {
                alertService.error(this.$t('label.download_failed'));
            } finally {
                this.downloadingId = null;
            }
        },
        async openXReport() {
            this.loading.isActive = true;
            try {
                const res = await axios.get('admin/fiscal/x-report');
                this.xReportText = JSON.stringify(res.data?.data ?? res.data, null, 2);
                this.xModalActive = true;
            } catch (e) {
                alertService.error(e.response?.data?.message || e.message);
            } finally {
                this.loading.isActive = false;
            }
        },
        formatDate(iso) {
            if (!iso) return '—';
            const d = new Date(iso);
            return Number.isNaN(d.getTime()) ? String(iso) : d.toLocaleString('fr-FR');
        },
        formatMoney(v) {
            const n = Number(v);
            return Number.isFinite(n) ? n.toFixed(2).replace('.', ',') + ' €' : '—';
        },
    },
};
</script>
