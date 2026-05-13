<template>
    <!--
        OutboxOverviewComponent — CV1-OBSERVABILITY-OUTBOX-001.

        Ops dashboard that surfaces the domain_events outbox pipeline health
        so a crashed laravel-websockets / queue:work worker stops being a
        SILENT failure. Five sections, all data-driven by a single backend
        endpoint:

            GET /api/admin/observability/outbox

        1. domain_events pending      (count + last 50 rows)
        2. domain_events dispatched   (24h count + p50/p95/p99 latency)
        3. jobs queue=high            (count + oldest job age)
        4. failed_jobs                (count + last 20 with truncated error)
        5. health probes              (queue:work / websockets:serve UP|DOWN)

        Two admin-gated actions:
            POST /api/admin/observability/outbox/retry-failed
            POST /api/admin/observability/outbox/drain-failed

        Audit  : reports/red-r5/SYNTHESIS_FINAL.md §3
        Mission: missions/CV1-OBSERVABILITY-OUTBOX-001.md
    -->
    <section
        class="space-y-4"
        data-testid="outbox-overview-dashboard"
        :aria-busy="loading"
    >
        <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">
                    {{ $t('admin.observability_outbox.title') }}
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $t('admin.observability_outbox.subtitle') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span
                    v-if="generatedAtHuman"
                    class="text-xs text-slate-500"
                    data-testid="outbox-generated-at"
                >
                    {{ $t('admin.observability_outbox.generated_at') }} {{ generatedAtHuman }}
                </span>
                <button
                    type="button"
                    class="db-btn db-btn-secondary text-sm !text-slate-800"
                    :disabled="loading"
                    data-testid="outbox-refresh"
                    @click="loadAll"
                >
                    <i class="lab lab-refresh" aria-hidden="true"></i>
                    {{ $t('admin.observability_outbox.refresh') }}
                </button>
                <button
                    type="button"
                    class="db-btn db-btn-secondary text-sm !text-slate-800"
                    :disabled="retrying || (failedJobs.count === 0 && pending.count === 0)"
                    data-testid="outbox-retry-failed"
                    @click="retryFailed"
                >
                    <i class="lab lab-redo" aria-hidden="true"></i>
                    {{ $t('admin.observability_outbox.retry_failed') }}
                </button>
                <button
                    type="button"
                    class="db-btn db-btn-secondary text-sm !text-slate-800"
                    :disabled="draining || failedJobs.count === 0"
                    data-testid="outbox-drain-failed"
                    @click="drainFailed"
                >
                    <i class="lab lab-trash" aria-hidden="true"></i>
                    {{ $t('admin.observability_outbox.drain_failed') }}
                </button>
            </div>
        </header>

        <!-- Section 5: Health probes (rendered first so ops see status at a glance) -->
        <article
            class="grid grid-cols-1 gap-3 sm:grid-cols-2"
            data-testid="outbox-health"
        >
            <div
                class="rounded border p-4"
                :class="health.queue_work.status === 'up'
                    ? 'border-emerald-200 bg-emerald-50'
                    : 'border-rose-200 bg-rose-50'"
                :data-testid="`outbox-health-queue-${health.queue_work.status}`"
            >
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-800">
                        queue:work
                    </h3>
                    <span
                        class="rounded px-2 py-1 text-xs font-semibold"
                        :class="health.queue_work.status === 'up'
                            ? 'bg-emerald-100 text-emerald-800'
                            : 'bg-rose-100 text-rose-800'"
                    >
                        {{ health.queue_work.status === 'up'
                            ? $t('admin.observability_outbox.up')
                            : $t('admin.observability_outbox.down') }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-slate-600">
                    {{ formatHealthAge(health.queue_work.last_signal_age_seconds) }}
                </p>
            </div>
            <div
                class="rounded border p-4"
                :class="health.websockets_serve.status === 'up'
                    ? 'border-emerald-200 bg-emerald-50'
                    : 'border-rose-200 bg-rose-50'"
                :data-testid="`outbox-health-ws-${health.websockets_serve.status}`"
            >
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-800">
                        websockets:serve
                    </h3>
                    <span
                        class="rounded px-2 py-1 text-xs font-semibold"
                        :class="health.websockets_serve.status === 'up'
                            ? 'bg-emerald-100 text-emerald-800'
                            : 'bg-rose-100 text-rose-800'"
                    >
                        {{ health.websockets_serve.status === 'up'
                            ? $t('admin.observability_outbox.up')
                            : $t('admin.observability_outbox.down') }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-slate-600">
                    {{ formatHealthAge(health.websockets_serve.last_signal_age_seconds) }}
                </p>
            </div>
        </article>

        <!-- Section 1: Pending events -->
        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="outbox-pending"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.observability_outbox.pending') }}
                ({{ pending.count }})
            </h3>
            <p v-if="pending.rows.length === 0" class="mt-3 text-sm text-slate-600">
                {{ $t('admin.observability_outbox.no_pending') }}
            </p>
            <div v-else class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase text-slate-500">
                            <th class="px-2 py-1 text-left">id</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.event_type') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.branch_id') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.attempts') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.created_at') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.last_error') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in pending.rows"
                            :key="`pending-${row.id}`"
                            class="border-t border-slate-100"
                            :data-testid="`outbox-pending-row-${row.id}`"
                        >
                            <td class="px-2 py-1 font-mono text-xs">{{ row.id }}</td>
                            <td class="px-2 py-1">{{ row.event_type }}</td>
                            <td class="px-2 py-1">{{ row.branch_id ?? '—' }}</td>
                            <td class="px-2 py-1">
                                <span
                                    class="rounded px-2 py-0.5 text-xs font-semibold"
                                    :class="row.attempts > 3
                                        ? 'bg-rose-100 text-rose-800'
                                        : 'bg-amber-100 text-amber-800'"
                                >
                                    {{ row.attempts }}
                                </span>
                            </td>
                            <td class="px-2 py-1 text-xs text-slate-600">{{ formatTimestamp(row.created_at) }}</td>
                            <td class="px-2 py-1 text-xs text-rose-700">{{ row.last_error ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>

        <!-- Section 2: Dispatched events (24h) -->
        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="outbox-dispatched"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.observability_outbox.dispatched_24h') }}
            </h3>
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.observability_outbox.count') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ dispatched.count }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">p50</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">
                        {{ dispatched.latency_p50_ms !== null ? `${dispatched.latency_p50_ms} ms` : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">p95</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">
                        {{ dispatched.latency_p95_ms !== null ? `${dispatched.latency_p95_ms} ms` : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">p99</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">
                        {{ dispatched.latency_p99_ms !== null ? `${dispatched.latency_p99_ms} ms` : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.observability_outbox.samples') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ dispatched.samples }}</dd>
                </div>
            </dl>
        </article>

        <!-- Section 3: Queue high lane -->
        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="outbox-queue-high"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.observability_outbox.queue_high') }}
            </h3>
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.observability_outbox.count') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ queueHigh.count }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.observability_outbox.oldest_age') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">
                        {{ queueHigh.oldest_age_seconds !== null ? formatAgeSeconds(queueHigh.oldest_age_seconds) : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ $t('admin.observability_outbox.available') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ queueHigh.available ? '✓' : '—' }}</dd>
                </div>
            </dl>
        </article>

        <!-- Section 4: Failed jobs -->
        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="outbox-failed-jobs"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.observability_outbox.failed_jobs') }}
                ({{ failedJobs.count }})
            </h3>
            <p v-if="failedJobs.rows.length === 0" class="mt-3 text-sm text-slate-600">
                {{ $t('admin.observability_outbox.no_failed_jobs') }}
            </p>
            <div v-else class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase text-slate-500">
                            <th class="px-2 py-1 text-left">id</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.queue') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.failed_at') }}</th>
                            <th class="px-2 py-1 text-left">{{ $t('admin.observability_outbox.exception') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in failedJobs.rows"
                            :key="`failed-${row.id}`"
                            class="border-t border-slate-100"
                            :data-testid="`outbox-failed-row-${row.id}`"
                        >
                            <td class="px-2 py-1 font-mono text-xs">{{ row.id }}</td>
                            <td class="px-2 py-1">{{ row.queue }}</td>
                            <td class="px-2 py-1 text-xs text-slate-600">{{ formatTimestamp(row.failed_at) }}</td>
                            <td class="px-2 py-1 text-xs text-rose-700">{{ row.exception_first_line }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</template>

<script>
/**
 * OutboxOverviewComponent
 *
 * Polling: every `pollIntervalMs` (default 10s) and on visibility-change.
 * Refresh is paused while the document is hidden to avoid burning quota
 * during long sessions.
 *
 * Admin-gated mutations:
 *   - retryFailed() POST /retry-failed → re-queues failed events
 *   - drainFailed() POST /drain-failed { older_than_hours: 24 } → safe purge
 */
export default {
    name: 'OutboxOverviewComponent',
    props: {
        pollIntervalMs: { type: Number, default: 10_000 },
    },
    data() {
        return {
            loading: false,
            retrying: false,
            draining: false,
            generatedAt: null,
            pending: { count: 0, rows: [] },
            dispatched: {
                count: 0,
                latency_p50_ms: null,
                latency_p95_ms: null,
                latency_p99_ms: null,
                samples: 0,
            },
            queueHigh: { available: true, count: 0, oldest_age_seconds: null },
            failedJobs: { available: true, count: 0, rows: [] },
            health: {
                queue_work: { status: 'down', last_signal_age_seconds: null, method: '' },
                websockets_serve: { status: 'down', last_signal_age_seconds: null, method: '' },
            },
            _timer: null,
            _visibilityHandler: null,
        };
    },
    computed: {
        generatedAtHuman() {
            return this.generatedAt ? new Date(this.generatedAt).toLocaleString() : null;
        },
    },
    mounted() {
        this.loadAll();
        // [Polling] auto refresh every pollIntervalMs to surface a freshly-stuck pipeline.
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
                const { data } = await axios.get('/api/admin/observability/outbox');
                this.generatedAt = data.generated_at || null;
                this.pending = data.pending || { count: 0, rows: [] };
                this.dispatched = data.dispatched_24h || this.dispatched;
                this.queueHigh = data.queue_high || this.queueHigh;
                this.failedJobs = data.failed_jobs || this.failedJobs;
                this.health = data.health || this.health;
            } finally {
                this.loading = false;
            }
        },
        async retryFailed() {
            this.retrying = true;
            try {
                await axios.post('/api/admin/observability/outbox/retry-failed');
                await this.loadAll();
            } finally {
                this.retrying = false;
            }
        },
        async drainFailed() {
            this.draining = true;
            try {
                await axios.post('/api/admin/observability/outbox/drain-failed', {
                    older_than_hours: 24,
                });
                await this.loadAll();
            } finally {
                this.draining = false;
            }
        },
        formatTimestamp(value) {
            if (!value) return '—';
            try {
                return new Date(value).toLocaleString();
            } catch (e) {
                return String(value);
            }
        },
        formatAgeSeconds(seconds) {
            if (seconds == null) return '—';
            if (seconds < 60) return `${seconds}s`;
            if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
            return `${Math.floor(seconds / 3600)}h`;
        },
        formatHealthAge(seconds) {
            if (seconds == null) return this.$t('admin.observability_outbox.no_signal');
            return this.$t('admin.observability_outbox.last_signal') + ' ' + this.formatAgeSeconds(seconds);
        },
    },
};
</script>
