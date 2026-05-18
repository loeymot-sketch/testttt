<template>
    <!--
        StockRuptureDashboardComponent — V1.0.2 BUILD-4 wireup (Round 4).
        Plan: reports/test-e2e/goal-2026-05-18/round-1/agent-6-stock-ui-plan.md

        Surface that exposes:
          - Cron status + manual scan trigger.
          - List of items currently auto-86 / manually-86 with per-row Restaurer.
          - Low-stock alerts list with per-row Mettre en rupture (item only).
          - Bulk multi-select (Restaurer) on rupture list.
          - Search filter (client-side, item name).
          - Branch filter dropdown (admin branch_id=0 sees Toutes les branches).
          - Real-time refresh via Echo (private-branch.{branchId}) on
            ItemAvailabilityChanged / ItemVariationAvailabilityChanged /
            ItemExtraAvailabilityChanged. Falls back to 60s polling for admin
            (branch_id=0) where Echo subscribes to N branches is not supported.

        Consumes (no new routes — REUSE existing endpoints):
          - GET  /api/admin/stock/scan-rupture/last-summary
          - GET  /api/admin/stock/low-alerts
          - POST /api/admin/stock/scan-rupture/run
          - POST /api/admin/availability/toggle              (item)
          - POST /api/admin/availability/toggle-extra
          - POST /api/admin/availability/toggle-variation
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
                <label
                    v-if="canFilterByBranch"
                    class="flex items-center gap-2 text-xs text-slate-700"
                    data-testid="stock-rupture-branch-filter"
                >
                    <span>{{ $t('admin.stock_rupture.branch_filter_label') }}</span>
                    <select
                        v-model.number="branchFilter"
                        class="rounded border border-slate-300 px-2 py-1 text-sm text-slate-800"
                        data-testid="stock-rupture-branch-select"
                    >
                        <option :value="0">{{ $t('admin.stock_rupture.branch_filter_all') }}</option>
                        <option
                            v-for="branch in summaries"
                            :key="branch.branch_id"
                            :value="branch.branch_id"
                        >
                            {{ branch.branch_name }}
                        </option>
                    </select>
                </label>
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

        <div
            v-if="errorMessage"
            class="rounded border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"
            role="alert"
            data-testid="stock-rupture-error"
        >
            {{ errorMessage }}
        </div>

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
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-sm font-semibold text-slate-800">
                    {{ $t('admin.stock_rupture.currently_86') }} ({{ filteredCurrentlyUnavailable.length }})
                </h3>
                <input
                    v-model="searchQuery"
                    type="search"
                    :placeholder="$t('admin.stock_rupture.search_placeholder')"
                    class="rounded border border-slate-300 px-3 py-1 text-sm text-slate-800"
                    data-testid="stock-rupture-search"
                />
            </div>

            <p v-if="filteredCurrentlyUnavailable.length === 0" class="mt-3 text-sm text-slate-600">
                {{ $t('admin.stock_rupture.none_unavailable') }}
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="row in filteredCurrentlyUnavailable"
                    :key="`${row.branch_id}-${row.item_id}`"
                    class="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-100 p-3"
                    :data-testid="`stock-rupture-row-${row.item_id}`"
                >
                    <div class="flex items-center gap-3">
                        <input
                            v-if="canEditAvailability"
                            type="checkbox"
                            :value="rowSelectionKey(row)"
                            v-model="selectedRupture"
                            class="h-4 w-4 rounded border-slate-300"
                            :aria-label="$t('admin.stock_rupture.select_all')"
                            :data-testid="`stock-rupture-select-${row.item_id}`"
                        />
                        <div>
                            <p class="text-sm font-semibold text-slate-800">{{ row.item_name }}</p>
                            <p class="text-xs text-slate-600">
                                {{ row.branch_name }} · {{ $t('admin.stock_rupture.flipped_at') }} {{ row.flipped_at_human }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="rounded bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                            {{ translateReason(row.reason) }}
                        </span>
                        <button
                            v-if="canEditAvailability"
                            type="button"
                            class="db-btn db-btn-secondary text-xs !text-slate-800"
                            :disabled="isRowBusy(rowSelectionKey(row))"
                            :data-testid="`stock-rupture-restore-${row.item_id}`"
                            @click="restoreItem(row)"
                        >
                            {{ $t('admin.stock_rupture.restore') }}
                        </button>
                    </div>
                </li>
            </ul>
        </article>

        <article
            class="rounded border border-slate-200 bg-white p-4"
            data-testid="stock-rupture-low-alerts"
        >
            <h3 class="text-sm font-semibold text-slate-800">
                {{ $t('admin.stock_rupture.low_alerts') }} ({{ filteredLowAlerts.length }})
            </h3>
            <p v-if="filteredLowAlerts.length === 0" class="mt-3 text-sm text-slate-600">
                {{ $t('admin.stock_rupture.no_low_alerts') }}
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="alert in filteredLowAlerts"
                    :key="`${alert.branch_id}-${alert.stockable_id}-${alert.stockable_type}`"
                    class="flex flex-wrap items-center justify-between gap-2 rounded border border-amber-200 bg-amber-50 p-3"
                    :data-testid="`stock-low-alert-${alert.stockable_id}`"
                >
                    <div>
                        <p class="text-sm font-semibold text-amber-900">{{ alert.stockable_name }}</p>
                        <p class="text-xs text-amber-800">
                            {{ alert.branch_name }} · {{ alert.on_hand }} / {{ alert.threshold_low }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="rounded bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900">
                            {{ $t('admin.stock_rupture.below_threshold') }}
                        </span>
                        <button
                            v-if="canEditAvailability && isItemAlert(alert)"
                            type="button"
                            class="db-btn db-btn-secondary text-xs !text-slate-800"
                            :disabled="isAlertBusy(alert)"
                            :data-testid="`stock-low-alert-mark-${alert.stockable_id}`"
                            @click="markAlertRupture(alert)"
                        >
                            {{ $t('admin.stock_rupture.mark_rupture') }}
                        </button>
                    </div>
                </li>
            </ul>
        </article>

        <!--
            Sticky bulk action bar — only visible when N>=1 selected on the
            rupture list.
        -->
        <div
            v-if="canEditAvailability && selectedRupture.length > 0"
            class="sticky bottom-0 z-10 flex flex-wrap items-center gap-2 rounded border border-slate-200 bg-white px-4 py-3 shadow-sm"
            data-testid="stock-rupture-bulk-bar"
        >
            <span class="text-sm font-semibold text-slate-700">
                {{ $t('admin.stock_rupture.selected_count', { count: selectedRupture.length }) }}
            </span>
            <button
                type="button"
                class="db-btn db-btn-primary text-sm"
                :disabled="bulkBusy"
                data-testid="stock-rupture-bulk-restore"
                @click="bulkRestore"
            >
                {{ $t('admin.stock_rupture.restore_selected') }}
            </button>
            <button
                type="button"
                class="db-btn db-btn-secondary text-sm !text-slate-800"
                :disabled="bulkBusy"
                data-testid="stock-rupture-bulk-clear"
                @click="clearSelection"
            >
                {{ $t('admin.stock_rupture.cancel') }}
            </button>
        </div>

        <!--
            Reason modal — opens when marking an item rupture from the low-alerts
            list. Single-step pick from MANUAL_UNAVAILABLE_REASONS whitelist.
        -->
        <div
            v-if="reasonModal.open"
            class="fixed inset-0 z-30 flex items-center justify-center bg-slate-900/40"
            role="dialog"
            aria-modal="true"
            data-testid="stock-rupture-reason-modal"
        >
            <div class="w-full max-w-md rounded border border-slate-200 bg-white p-4 shadow-md">
                <h4 class="text-base font-semibold text-slate-800">
                    {{ reasonModal.title }}
                </h4>
                <fieldset class="mt-3 space-y-2">
                    <legend class="sr-only">{{ $t('admin.stock_rupture.reason_label') }}</legend>
                    <label
                        v-for="reason in availableReasons"
                        :key="reason.value"
                        class="flex cursor-pointer items-center gap-2 rounded border border-slate-200 p-2 text-sm text-slate-800 hover:bg-slate-50"
                    >
                        <input
                            type="radio"
                            :value="reason.value"
                            v-model="reasonModal.selectedReason"
                            :data-testid="`stock-rupture-reason-${reason.value}`"
                        />
                        <span>{{ reason.label }}</span>
                    </label>
                </fieldset>
                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="db-btn db-btn-secondary text-sm !text-slate-800"
                        data-testid="stock-rupture-reason-cancel"
                        @click="closeReasonModal"
                    >
                        {{ $t('admin.stock_rupture.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="db-btn db-btn-primary text-sm"
                        :disabled="!reasonModal.selectedReason"
                        data-testid="stock-rupture-reason-confirm"
                        @click="confirmReasonModal"
                    >
                        {{ $t('admin.stock_rupture.confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>

<script>
import appService from "../../../services/appService";
import { onEvents } from "../../../services/eventContract";

/**
 * StockRuptureDashboardComponent — V1.0.2 admin observability + manual override.
 *
 * Permissions (Spatie):
 *   - items_show  : view dashboard data (read endpoints)
 *   - items_edit  : toggle availability (write endpoints — gated UI elements)
 *   - items_create: trigger manual scan
 *
 * Echo channel: `branch.{branchId}` (helper prepends `private-` internally).
 *   - branch_id=0 admins: polling fallback (cannot subscribe to N branches).
 *   - branch_id>0 staff:  Echo subscribe → debounced loadAll on event.
 */

const MANUAL_UNAVAILABLE_REASONS = [
    'out_of_stock_manual',
    'seasonal',
    'recipe_change',
    'supplier_issue',
    'quality_issue',
];

const DEBOUNCE_MS = 500;

function debounce(fn, ms) {
    let t = null;
    return function (...args) {
        if (t) clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), ms);
    };
}

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
            errorMessage: '',
            // Filters
            searchQuery: '',
            branchFilter: 0,
            // Selection state for bulk
            selectedRupture: [],
            busyRows: {},
            bulkBusy: false,
            // Reason modal
            reasonModal: {
                open: false,
                target: null,
                title: '',
                selectedReason: '',
            },
            // Echo lifecycle
            _timer: null,
            _visibilityHandler: null,
            _echoSubscription: null,
            _debouncedReload: null,
        };
    },
    computed: {
        cronStatusBadgeClass() {
            return this.cronEnabled
                ? 'bg-emerald-50 text-emerald-700'
                : 'bg-slate-100 text-slate-600';
        },
        canEditAvailability() {
            return appService.permissionChecker('items_edit');
        },
        canFilterByBranch() {
            return this.authBranchId() === 0 && this.summaries.length > 1;
        },
        availableReasons() {
            return MANUAL_UNAVAILABLE_REASONS.map((value) => ({
                value,
                label: this.$t(`admin.stock_rupture.reason.${value}`),
            }));
        },
        filteredCurrentlyUnavailable() {
            const q = (this.searchQuery || '').trim().toLowerCase();
            const branchId = Number(this.branchFilter) || 0;
            return (this.currentlyUnavailable || []).filter((row) => {
                if (branchId > 0 && Number(row.branch_id) !== branchId) return false;
                if (!q) return true;
                return String(row.item_name || '').toLowerCase().includes(q);
            });
        },
        filteredLowAlerts() {
            const q = (this.searchQuery || '').trim().toLowerCase();
            const branchId = Number(this.branchFilter) || 0;
            return (this.lowAlerts || []).filter((alert) => {
                if (branchId > 0 && Number(alert.branch_id) !== branchId) return false;
                if (!q) return true;
                return String(alert.stockable_name || '').toLowerCase().includes(q);
            });
        },
    },
    mounted() {
        this._debouncedReload = debounce(() => { this.loadAll(); }, DEBOUNCE_MS);
        this.loadAll();
        this._timer = setInterval(this.loadAll, this.pollIntervalMs);
        this._visibilityHandler = () => {
            if (!document.hidden) this.loadAll();
        };
        document.addEventListener('visibilitychange', this._visibilityHandler);
        this.subscribeEcho();
    },
    beforeUnmount() {
        if (this._timer) clearInterval(this._timer);
        if (this._visibilityHandler) {
            document.removeEventListener('visibilitychange', this._visibilityHandler);
        }
        this.unsubscribeEcho();
    },
    methods: {
        // ---------- Auth helpers ----------
        authBranchId() {
            try {
                const fromGetter = this.$store?.getters?.['auth/authBranchId'];
                if (fromGetter !== undefined && fromGetter !== null && fromGetter !== '') {
                    return Number(fromGetter) || 0;
                }
                const fromState = this.$store?.state?.auth?.authBranchId;
                if (fromState !== undefined && fromState !== null && fromState !== '') {
                    return Number(fromState) || 0;
                }
            } catch (_) {}
            return 0;
        },

        // ---------- Echo lifecycle ----------
        subscribeEcho() {
            if (!window.Echo) return;
            const branchId = this.authBranchId();
            // Admin (branch_id=0) cannot subscribe to N branches — polling 60s is the fallback.
            if (branchId <= 0) return;
            this.unsubscribeEcho();
            try {
                this._echoSubscription = onEvents(branchId, [
                    { broadcastAs: 'ItemAvailabilityChanged', handler: () => { this._debouncedReload(); } },
                    { broadcastAs: 'ItemVariationAvailabilityChanged', handler: () => { this._debouncedReload(); } },
                    { broadcastAs: 'ItemExtraAvailabilityChanged', handler: () => { this._debouncedReload(); } },
                ]);
            } catch (e) {
                console.warn('[StockRuptureDashboard] Echo subscription failed:', e?.message);
            }
        },
        unsubscribeEcho() {
            try {
                this._echoSubscription?.unsubscribe?.();
            } catch (_) {}
            this._echoSubscription = null;
        },

        // ---------- Data load ----------
        async loadAll() {
            if (document.hidden) return;

            this.loading = true;
            this.errorMessage = '';
            try {
                const [summaryResponse, alertsResponse] = await Promise.all([
                    axios.get('admin/stock/scan-rupture/last-summary'),
                    axios.get('admin/stock/low-alerts'),
                ]);

                const summaryData = summaryResponse.data || {};
                this.cronEnabled = Boolean(summaryData.cron_enabled);
                this.summaries = Array.isArray(summaryData.branches) ? summaryData.branches : [];
                this.currentlyUnavailable = Array.isArray(summaryData.currently_unavailable)
                    ? summaryData.currently_unavailable
                    : [];
                this.lowAlerts = Array.isArray(alertsResponse.data?.alerts) ? alertsResponse.data.alerts : [];
                this.lastRunSummary = this.normalizeLastRunSummary(this.summaries);
                this.pruneStaleSelection();
            } catch (err) {
                this.errorMessage = this.$t('admin.stock_rupture.loading_error');
            } finally {
                this.loading = false;
            }
        },

        async runScanNow() {
            this.runningManually = true;
            this.errorMessage = '';
            try {
                const branchId = (Number(this.branchFilter) || 0) > 0
                    ? Number(this.branchFilter)
                    : (this.summaries[0]?.branch_id
                        || this.currentlyUnavailable[0]?.branch_id
                        || this.lowAlerts[0]?.branch_id
                        || null);
                await axios.post('admin/stock/scan-rupture/run', branchId ? { branch_id: branchId } : {});
                await this.loadAll();
            } catch (err) {
                this.errorMessage = this.$t('admin.stock_rupture.toggle_error');
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

        // ---------- Translation helper ----------
        translateReason(reason) {
            if (!reason) return '';
            const key = `admin.stock_rupture.reason.${reason}`;
            const translated = this.$t(key);
            return translated === key ? reason : translated;
        },

        // ---------- Selection helpers ----------
        rowSelectionKey(row) {
            return `${row.branch_id}:${row.item_id}`;
        },
        isRowBusy(key) {
            return Boolean(this.busyRows[key]);
        },
        isAlertBusy(alert) {
            const k = `${alert.branch_id}:${alert.stockable_type}:${alert.stockable_id}`;
            return Boolean(this.busyRows[k]);
        },
        isItemAlert(alert) {
            // Mark-rupture button currently exposed for Item type only.
            const t = String(alert.stockable_type || '').toLowerCase();
            if (t === 'item') return true;
            if (t.endsWith('\\item')) return true;
            // Heuristic for FQCNs ending in '\\Item' but not extra/variation.
            if (t.includes('item') && !t.includes('extra') && !t.includes('variation')) return true;
            return false;
        },
        pruneStaleSelection() {
            const validKeys = new Set(
                (this.currentlyUnavailable || []).map((r) => this.rowSelectionKey(r))
            );
            this.selectedRupture = (this.selectedRupture || []).filter((k) => validKeys.has(k));
        },
        clearSelection() {
            this.selectedRupture = [];
        },

        // ---------- Optimistic toggle (item — restore from rupture list) ----------
        async restoreItem(row) {
            const key = this.rowSelectionKey(row);
            if (this.busyRows[key]) return;
            this.busyRows = { ...this.busyRows, [key]: true };
            const snapshot = [...this.currentlyUnavailable];
            this.currentlyUnavailable = snapshot.filter((r) => this.rowSelectionKey(r) !== key);
            try {
                await axios.post('admin/availability/toggle', {
                    item_id: Number(row.item_id),
                    branch_id: Number(row.branch_id),
                    is_available: true,
                });
            } catch (err) {
                this.currentlyUnavailable = snapshot;
                this.errorMessage = this.$t('admin.stock_rupture.toggle_error');
            } finally {
                const next = { ...this.busyRows };
                delete next[key];
                this.busyRows = next;
            }
        },

        // ---------- Reason modal flow (mark item rupture from low-alerts) ----------
        markAlertRupture(alert) {
            if (!this.isItemAlert(alert)) return;
            this.reasonModal = {
                open: true,
                target: {
                    kind: 'item',
                    id: Number(alert.stockable_id),
                    branchId: Number(alert.branch_id),
                    name: alert.stockable_name,
                },
                title: alert.stockable_name || this.$t('admin.stock_rupture.mark_rupture'),
                selectedReason: '',
            };
        },
        closeReasonModal() {
            this.reasonModal = {
                open: false,
                target: null,
                title: '',
                selectedReason: '',
            };
        },
        async confirmReasonModal() {
            const { target, selectedReason } = this.reasonModal;
            if (!target || !selectedReason) return;
            const key = `${target.branchId}:item:${target.id}`;
            if (this.busyRows[key]) return;
            this.busyRows = { ...this.busyRows, [key]: true };
            this.closeReasonModal();
            try {
                await axios.post('admin/availability/toggle', {
                    item_id: Number(target.id),
                    branch_id: Number(target.branchId),
                    is_available: false,
                    unavailable_reason: selectedReason,
                });
                await this.loadAll();
            } catch (err) {
                this.errorMessage = this.$t('admin.stock_rupture.toggle_error');
            } finally {
                const next = { ...this.busyRows };
                delete next[key];
                this.busyRows = next;
            }
        },

        // ---------- Bulk actions (sequential to avoid new backend route) ----------
        async bulkRestore() {
            if (this.selectedRupture.length === 0) return;
            this.bulkBusy = true;
            this.errorMessage = '';
            const keys = [...this.selectedRupture];
            const rowsByKey = new Map(
                (this.currentlyUnavailable || []).map((r) => [this.rowSelectionKey(r), r])
            );
            let ok = 0;
            let fail = 0;
            const total = keys.length;
            const concurrency = 5;
            let cursor = 0;
            const runOne = async () => {
                while (cursor < keys.length) {
                    const i = cursor++;
                    const k = keys[i];
                    const row = rowsByKey.get(k);
                    if (!row) {
                        fail += 1;
                        continue;
                    }
                    try {
                        await axios.post('admin/availability/toggle', {
                            item_id: Number(row.item_id),
                            branch_id: Number(row.branch_id),
                            is_available: true,
                        });
                        ok += 1;
                    } catch (_) {
                        fail += 1;
                    }
                }
            };
            try {
                const workers = Array.from({ length: Math.min(concurrency, keys.length) }, () => runOne());
                await Promise.all(workers);
                if (fail > 0) {
                    this.errorMessage = this.$t(
                        'admin.stock_rupture.bulk_partial_error',
                        { ok, fail, total }
                    );
                }
                await this.loadAll();
                if (fail === 0) {
                    this.clearSelection();
                }
            } finally {
                this.bulkBusy = false;
            }
        },
    },
};
</script>
