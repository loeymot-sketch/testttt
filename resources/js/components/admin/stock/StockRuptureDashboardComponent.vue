<template>
    <!--
        StockRuptureDashboardComponent — Mission #2 Vague 2 action 2.1 + 2.7.

        Admin dashboard that surfaces:
          - Items currently auto-86'd (is_available=false from preventive scan)
          - Last `php artisan stock:scan-rupture` run timestamp + summary
          - Stock-low alerts (preventive: on_hand <= threshold_low) per branch
          - Manual "Run scan now" button (dev/staging utility)

        The dashboard does NOT mutate stock directly. It consumes:
          - GET /api/admin/stock/scan-rupture/last-summary  (TODO Codex endpoint)
          - GET /api/admin/stock/low-alerts                  (TODO Codex endpoint)
          - POST /api/admin/stock/scan-rupture/run           (manual trigger)

        Audit  : reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md §A.1 #7 + §A.2 #14
        Plan   : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md tasks 2.1 + 2.7
        Status : SKELETON — implementation TODO Codex.
    -->
    <section
        class="space-y-4"
        data-testid="stock-rupture-dashboard"
        :aria-busy="loading"
    >
        <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">
                    {{ $t('admin.stock_rupture.title') }}
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $t('admin.stock_rupture.subtitle') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="rounded px-2 py-1 text-xs font-semibold"
                    :class="cronStatusBadgeClass"
                    :data-testid="`stock-rupture-cron-${cronEnabled ? 'on' : 'off'}`"
                >
                    {{ cronEnabled ? $t('admin.stock_rupture.cron_enabled') : $t('admin.stock_rupture.cron_disabled') }}
                </span>
                <button
                    type="button"
                    class="db-btn db-btn-secondary text-sm !text-slate-800"
                    :disabled="loading || runningManually"
                    data-testid="stock-rupture-run-now"
                    @click="runScanNow"
                >
                    <i class="lab lab-refresh" aria-hidden="true"></i>
                    {{ $t('admin.stock_rupture.run_now') }}
                </button>
            </div>
        </header>

        <article
            v-if="lastRunSummary"
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="stock-rupture-last-run"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.stock_rupture.last_run') }}
            </h3>
            <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.stock_rupture.last_run_at') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ lastRunSummary.ran_at_human }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.stock_rupture.items_flipped') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ lastRunSummary.items_flipped }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.stock_rupture.items_skipped') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ lastRunSummary.items_skipped }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.stock_rupture.duration_ms') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ lastRunSummary.duration_ms }} ms</dd>
                </div>
            </dl>
        </article>

        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="stock-rupture-currently-86"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.stock_rupture.currently_86') }} ({{ currentlyUnavailable.length }})
            </h3>
            <p v-if="currentlyUnavailable.length === 0" class="mt-3 text-sm text-slate-600">
                {{ $t('admin.stock_rupture.none_unavailable') }}
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="row in currentlyUnavailable"
                    :key="`${row.branch_id}-${row.item_id}`"
                    class="flex items-center justify-between rounded border border-slate-100 p-3"
                    :data-testid="`stock-rupture-row-${row.item_id}`"
                >
                    <div>
                        <p class="text-sm font-semibold text-slate-800">{{ row.item_name }}</p>
                        <p class="text-xs text-slate-600">
                            {{ row.branch_name }} · {{ $t('admin.stock_rupture.flipped_at') }} {{ row.flipped_at_human }}
                        </p>
                    </div>
                    <span class="rounded bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                        {{ row.reason }}
                    </span>
                </li>
            </ul>
        </article>

        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="stock-rupture-low-alerts"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.stock_rupture.low_alerts') }} ({{ lowAlerts.length }})
            </h3>
            <p v-if="lowAlerts.length === 0" class="mt-3 text-sm text-slate-600">
                {{ $t('admin.stock_rupture.no_low_alerts') }}
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="alert in lowAlerts"
                    :key="`${alert.branch_id}-${alert.stockable_id}-${alert.stockable_type}`"
                    class="flex items-center justify-between rounded border border-amber-200 bg-amber-50 p-3"
                    :data-testid="`stock-low-alert-${alert.stockable_id}`"
                >
                    <div>
                        <p class="text-sm font-semibold text-amber-900">{{ alert.stockable_name }}</p>
                        <p class="text-xs text-amber-800">
                            {{ alert.branch_name }} · {{ alert.on_hand }} / {{ alert.threshold_low }}
                        </p>
                    </div>
                    <span class="rounded bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900">
                        {{ $t('admin.stock_rupture.below_threshold') }}
                    </span>
                </li>
            </ul>
        </article>
    </section>
</template>

<script>
/**
 * StockRuptureDashboardComponent — admin observability surface for the
 * preventive auto-86 cron and the stock-low alert listener.
 *
 * Props:
 *   pollIntervalMs: Number — polling cadence for refreshing data.
 *                            Default 60_000.
 *
 * TODO Codex (plan task 2.1 + 2.7):
 *   1. Implement loadAll() calling in parallel:
 *        GET /api/admin/stock/scan-rupture/last-summary
 *        GET /api/admin/stock/scan-rupture/currently-unavailable
 *        GET /api/admin/stock/low-alerts
 *      Backend endpoints to be added under app/Http/Controllers/Admin/Stock/
 *      (stub controllers + routes registered in routes/api.php).
 *   2. Implement runScanNow() POST /api/admin/stock/scan-rupture/run
 *      with optimistic UX: disable the button + show toast on response.
 *   3. Wire poll lifecycle: setInterval(loadAll, pollIntervalMs) in
 *      mounted(), clearInterval in beforeUnmount(). Pause polling when
 *      document.hidden === true (visibility API) to avoid burning quota.
 *   4. A11y:
 *        - aria-busy on the section root while loading.
 *        - Sort lists chronologically (most recent flip first).
 *        - Each row should be keyboard-focusable to inspect details.
 *   5. i18n keys (fr/en/ar):
 *        admin.stock_rupture.{title,subtitle,cron_enabled,cron_disabled,
 *           run_now,last_run,last_run_at,items_flipped,items_skipped,
 *           duration_ms,currently_86,none_unavailable,flipped_at,
 *           low_alerts,no_low_alerts,below_threshold}
 *   6. Sentinel: tests/js/stockRuptureDashboard.spec.js +
 *      tests/Feature/Stock/StockRuptureDashboardEndpointsTest.php
 */
export default {
    name: 'StockRuptureDashboardComponent',
    props: {
        pollIntervalMs: { type: Number, default: 60_000 },
    },
    data() {
        return {
            loading: false,
            cronEnabled: false,
            summaries: [],
            lastRunSummary: null,
            currentlyUnavailable: [],
            lowAlerts: [],
            runningManually: false,
            _timer: null,
            _visibilityHandler: null,
        };
    },
    computed: {
        cronStatusBadgeClass() {
            return this.cronEnabled
                ? 'bg-emerald-50 text-emerald-700'
                : 'bg-slate-100 text-slate-600';
        },
    },
    mounted() {
        this.loadAll();
        this._timer = setInterval(this.loadAll, this.pollIntervalMs);
        this._visibilityHandler = () => {
            if (!document.hidden) this.loadAll();
        };
        document.addEventListener('visibilitychange', this._visibilityHandler);
    },
    beforeUnmount() {
        if (this._timer) clearInterval(this._timer);
        if (this._visibilityHandler) {
            document.removeEventListener('visibilitychange', this._visibilityHandler);
        }
    },
    methods: {
        async loadAll() {
            if (document.hidden) return;

            this.loading = true;
            try {
                const [summaryResponse, alertsResponse] = await Promise.all([
                    axios.get('/api/admin/stock/scan-rupture/last-summary'),
                    axios.get('/api/admin/stock/low-alerts'),
                ]);

                const summaryData = summaryResponse.data || {};
                this.cronEnabled = Boolean(summaryData.cron_enabled);
                this.summaries = Array.isArray(summaryData.branches) ? summaryData.branches : [];
                this.currentlyUnavailable = Array.isArray(summaryData.currently_unavailable)
                    ? summaryData.currently_unavailable
                    : [];
                this.lowAlerts = Array.isArray(alertsResponse.data?.alerts) ? alertsResponse.data.alerts : [];
                this.lastRunSummary = this.normalizeLastRunSummary(this.summaries);
            } finally {
                this.loading = false;
            }
        },
        async runScanNow() {
            this.runningManually = true;
            try {
                const branchId = this.summaries[0]?.branch_id
                    || this.currentlyUnavailable[0]?.branch_id
                    || this.lowAlerts[0]?.branch_id
                    || null;
                await axios.post('/api/admin/stock/scan-rupture/run', branchId ? { branch_id: branchId } : {});
                await this.loadAll();
            } finally {
                this.runningManually = false;
            }
        },
        normalizeLastRunSummary(branches) {
            const summaries = (branches || [])
                .map((branch) => branch.summary)
                .filter(Boolean);

            if (summaries.length === 0) {
                return null;
            }

            const latest = summaries.reduce((selected, summary) => {
                if (!selected) return summary;
                return new Date(summary.ran_at || 0) > new Date(selected.ran_at || 0) ? summary : selected;
            }, null);

            return {
                ...latest,
                ran_at_human: latest.ran_at ? new Date(latest.ran_at).toLocaleString() : '-',
                items_flipped: Number(latest.items_flipped || 0),
                items_skipped: Number(latest.items_skipped_partial_stock || 0)
                    + Number(latest.items_already_unavailable || 0),
                duration_ms: Number(latest.duration_ms || 0),
            };
        },
    },
};
</script>
