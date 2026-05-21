<template>
    <!--
        [Wave X — X4 2026-05-21] Admin unified cash & transactions overview.
        Owner mandate (verbatim translated):
          « Toutes les commandes encaissées (POS direct, borne cash-collected,
            livreur) au MÊME ENDROIT en base. Admin part + caisse voient tout.
            Pour chaque transaction : source (borne/caisse/livreur) + mode
            paiement + total. Totaux par source + grand total. Permet de
            détecter écarts (cash manquant). »

        Source: GET /api/admin/cash-overview (CashOverviewController).
        Branch isolation: backend-driven (admin sees all; staff sees own).
        Sibling: /admin/cash-sessions-report (Wave O O4) stays as detail view.
    -->
    <div class="col-12">
        <div class="db-card db-tab-div active">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('menu.cash_overview') }}</h3>
            </div>

            <div class="db-card-body">
                <!-- Filters -->
                <form
                    class="p-4 sm:p-5 mb-3 flex flex-wrap items-end gap-3"
                    data-testid="cash-overview-filters"
                    @submit.prevent="fetch"
                >
                    <div>
                        <label for="cashOverviewFrom" class="db-field-title after:hidden">{{ $t('label.from_date') }}</label>
                        <input id="cashOverviewFrom" v-model="filters.from" type="date" class="db-field-control" />
                    </div>
                    <div>
                        <label for="cashOverviewTo" class="db-field-title after:hidden">{{ $t('label.to_date') }}</label>
                        <input id="cashOverviewTo" v-model="filters.to" type="date" class="db-field-control" />
                    </div>
                    <div>
                        <label for="cashOverviewSource" class="db-field-title after:hidden">{{ $t('label.source') }}</label>
                        <select id="cashOverviewSource" v-model="filters.source" class="db-field-control">
                            <option value="">{{ $t('label.all_sources') }}</option>
                            <option value="caisse">{{ $t('label.source_caisse') }}</option>
                            <option value="borne">{{ $t('label.source_borne') }}</option>
                            <option value="livreur">{{ $t('label.source_livreur') }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="cashOverviewMode" class="db-field-title after:hidden">{{ $t('label.payment_method') }}</label>
                        <select id="cashOverviewMode" v-model="filters.mode" class="db-field-control">
                            <option value="">{{ $t('label.all_methods') }}</option>
                            <option value="cash">{{ $t('label.mode_cash') }}</option>
                            <option value="card">{{ $t('label.mode_card') }}</option>
                            <option value="mobile">{{ $t('label.mode_mobile') }}</option>
                            <option value="ticket">{{ $t('label.mode_ticket') }}</option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        class="db-btn py-2 text-white bg-primary"
                        data-testid="cash-overview-search"
                    >
                        <i class="lab lab-search-line lab-font-size-16"></i>
                        <span>{{ $t('button.search') }}</span>
                    </button>
                    <button
                        type="button"
                        class="db-btn py-2 text-white bg-gray-600"
                        data-testid="cash-overview-clear"
                        @click="clearFilters"
                    >
                        <i class="lab lab-cross-line-2 lab-font-size-22"></i>
                        <span>{{ $t('button.clear') }}</span>
                    </button>
                </form>

                <!-- Summary cards -->
                <section
                    v-if="!loading && summary"
                    class="px-4 sm:px-5 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3"
                    data-testid="cash-overview-summary"
                >
                    <div class="border rounded-lg p-3 bg-white">
                        <div class="text-xs uppercase text-gray-500">{{ $t('label.grand_total') }}</div>
                        <div class="text-2xl font-bold text-primary mt-1">{{ formatMoney(summary.total) }}</div>
                        <div class="text-xs text-gray-500 mt-1">
                            {{ summary.count }} {{ $t('label.transactions_short') }}
                        </div>
                    </div>
                    <div
                        v-for="src in displayedSources"
                        :key="`src-${src.key}`"
                        class="border rounded-lg p-3 bg-white"
                        :data-testid="`cash-overview-source-${src.key}`"
                    >
                        <div class="text-xs uppercase text-gray-500">{{ src.label }}</div>
                        <div class="text-xl font-semibold mt-1">{{ formatMoney(src.stat.total) }}</div>
                        <div class="text-xs text-gray-500 mt-1">
                            {{ src.stat.count }} {{ $t('label.transactions_short') }}
                        </div>
                    </div>
                </section>

                <!-- Cash drawer reconciliation card -->
                <!--
                    [Wave X-C round-2 2026-05-21] Fix C-013 — the previous
                    diff cell was algebraically locked to `-opening_amount`
                    (cashDiff = collected - (opening + collected) = -opening),
                    so it always displayed `-100 € (manquant)` even when the
                    drawer was perfectly healthy. A real écart requires a
                    `counted_physical_cash` input compared to `expected_cash`
                    (= opening + collected). The cashier-count input is V1.0.2
                    backlog; until then we show 3 HONEST values + an info
                    note. No misleading diff cell.
                    Source: GET /api/admin/cash-overview cash_session payload.
                -->
                <section
                    v-if="!loading && cashSession"
                    class="px-4 sm:px-5 mb-4"
                    data-testid="cash-overview-reconciliation"
                >
                    <div class="border-l-4 border-amber-500 bg-amber-50 rounded p-3">
                        <div class="text-sm font-semibold text-amber-900">
                            {{ $t('label.cash_drawer_reconciliation') }}
                        </div>
                        <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                            <div>
                                <span class="text-gray-600">{{ $t('label.drawer_opened_at') }}:</span>
                                <strong class="ml-1">{{ formatTime(cashSession.opened_at) }}</strong>
                            </div>
                            <div>
                                <span class="text-gray-600">{{ $t('label.opening_amount') }}:</span>
                                <strong
                                    class="ml-1"
                                    data-testid="cash-overview-reconciliation-opening"
                                >{{ formatMoneyEuro(cashSession.opening_amount) }}</strong>
                            </div>
                            <div>
                                <span class="text-gray-600">{{ $t('label.cash_collected_today') }}:</span>
                                <strong
                                    class="ml-1"
                                    data-testid="cash-overview-reconciliation-collected"
                                >{{ formatMoneyEuro(cashSession.cash_collected) }}</strong>
                            </div>
                            <div>
                                <span class="text-gray-600">{{ $t('label.expected_in_drawer') }}:</span>
                                <strong
                                    class="ml-1 text-amber-900"
                                    data-testid="cash-overview-reconciliation-expected"
                                >{{ formatMoneyEuro(cashSession.expected_cash) }}</strong>
                            </div>
                        </div>
                        <div
                            class="mt-2 text-xs text-amber-800"
                            data-testid="cash-overview-reconciliation-note"
                        >
                            {{ $t('label.cash_drawer_count_pending_note') }}
                        </div>
                    </div>
                </section>

                <!-- Mode breakdown -->
                <section
                    v-if="!loading && summary && Object.keys(summary.by_mode || {}).length"
                    class="px-4 sm:px-5 mb-4"
                    data-testid="cash-overview-mode-breakdown"
                >
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">{{ $t('label.breakdown_by_method') }}</h4>
                    <div class="flex flex-wrap gap-2">
                        <div
                            v-for="(stat, mode) in summary.by_mode"
                            :key="`mode-${mode}`"
                            class="border rounded-full px-3 py-1 text-xs bg-gray-50"
                            :data-testid="`cash-overview-mode-${mode}`"
                        >
                            <strong>{{ modeLabel(mode) }}</strong>
                            <span class="text-gray-500 ml-1">{{ stat.count }} · {{ formatMoney(stat.total) }}</span>
                        </div>
                    </div>
                </section>

                <!-- Loading state -->
                <div v-if="loading" class="p-6 text-center text-gray-500">
                    {{ $t('label.loading') }}…
                </div>

                <!-- Empty state -->
                <div
                    v-else-if="!transactions.length"
                    class="p-6 text-center text-gray-500"
                    data-testid="cash-overview-empty"
                >
                    {{ $t('label.no_data_available') }}
                </div>

                <!-- Transactions table -->
                <div v-else class="px-4 sm:px-5 pb-5">
                    <div class="overflow-x-auto border rounded-lg" data-testid="cash-overview-table-wrapper">
                        <table class="w-full text-sm" data-testid="cash-overview-table">
                            <thead class="bg-gray-100 text-gray-700">
                                <tr>
                                    <th class="px-3 py-2 text-left">{{ $t('label.time') }}</th>
                                    <th class="px-3 py-2 text-left">{{ $t('label.order_number') }}</th>
                                    <th class="px-3 py-2 text-left">{{ $t('label.source') }}</th>
                                    <th class="px-3 py-2 text-left">{{ $t('label.payment_method') }}</th>
                                    <th class="px-3 py-2 text-left">{{ $t('label.delivery_boy') }}</th>
                                    <th class="px-3 py-2 text-right">{{ $t('label.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="tx in transactions"
                                    :key="tx.id"
                                    class="border-t hover:bg-gray-50"
                                    :data-testid="`cash-overview-row-${tx.id}`"
                                >
                                    <td class="px-3 py-2 whitespace-nowrap">{{ formatTime(tx.created_at) }}</td>
                                    <td class="px-3 py-2">
                                        <span v-if="tx.queue_number">N°{{ tx.queue_number }}</span>
                                        <span v-else class="text-gray-400">#{{ tx.order_id }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded text-xs"
                                            :class="sourceClass(tx.source)"
                                        >
                                            {{ sourceLabel(tx.source) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2">{{ modeLabel(tx.mode_bucket) }}</td>
                                    <td class="px-3 py-2">{{ tx.delivery_boy_name || '—' }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ formatMoney(tx.amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p
                        v-if="meta.capped"
                        class="text-xs text-amber-700 mt-2"
                        data-testid="cash-overview-cap-notice"
                    >
                        {{ $t('label.cash_overview_capped_notice') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'CashOverviewComponent',
    data() {
        const today = new Date().toISOString().slice(0, 10);
        return {
            loading: false,
            transactions: [],
            summary: null,
            cashSession: null,
            meta: { capped: false, row_count: 0 },
            filters: {
                from: today,
                to: today,
                source: '',
                mode: '',
            },
        };
    },
    computed: {
        // Render fixed source order : caisse / borne / livreur, populating
        // zero-count buckets so the dashboard layout stays stable.
        displayedSources() {
            const known = ['caisse', 'borne', 'livreur'];
            const stats = (this.summary && this.summary.by_source) || {};
            return known.map((key) => ({
                key,
                label: this.sourceLabel(key),
                stat: stats[key] || { count: 0, total: 0 },
            }));
        },
        // [Wave X-C round-2 2026-05-21] Fix C-013 — dropped `cashDiff`,
        // `diffLabel`, `diffClass` computed props (and the diff cell that
        // used them). The previous diff was algebraically `-opening_amount`
        // and never reflected a real écart. Until V1.0.2 ships a cashier
        // count-input feature the page displays 3 honest values
        // (opening / collected_today / expected_in_drawer) without a
        // misleading diff.
    },
    mounted() {
        this.fetch();
    },
    watch: {
        // [Wave X-C round-1 2026-05-21] Re-fetch when the URL query changes
        // (e.g. ?branch_id=1 → ?branch_id=2 stays on the same route component
        // so `mounted()` doesn't fire again). Without this watcher the admin
        // would have to hard-reload to switch branch context.
        '$route.query': {
            handler() { this.fetch(); },
            deep: true,
        },
    },
    methods: {
        async fetch() {
            this.loading = true;
            try {
                const params = {};
                if (this.filters.from) params.from = this.filters.from;
                if (this.filters.to) params.to = this.filters.to;
                if (this.filters.source) params.source = this.filters.source;
                if (this.filters.mode) params.mode = this.filters.mode;

                // [Wave X-C round-1 2026-05-21] Honour ?branch_id= URL query
                // so admin can pin reconciliation to a specific branch via
                // a shareable link. Filter form has no branch_id field — the
                // top-bar branch selector / direct URL is the SSOT for that
                // dimension. Backend silently ignores branch_id for staff
                // (they're forced to their own branch).
                const routeBranch = this.$route && this.$route.query
                    ? Number(this.$route.query.branch_id || 0)
                    : 0;
                if (routeBranch > 0) {
                    params.branch_id = routeBranch;
                }

                const res = await (window.axios || axios).get('admin/cash-overview', { params });
                const payload = res.data || {};
                this.transactions = Array.isArray(payload.data) ? payload.data : [];
                this.summary = payload.summary || null;
                this.cashSession = payload.cash_session || null;
                this.meta = payload.meta || { capped: false, row_count: 0 };
            } catch (e) {
                // eslint-disable-next-line no-console
                console.error('[CashOverview] load failed', e);
                this.transactions = [];
                this.summary = null;
                this.cashSession = null;
            } finally {
                this.loading = false;
            }
        },
        clearFilters() {
            const today = new Date().toISOString().slice(0, 10);
            this.filters = { from: today, to: today, source: '', mode: '' };
            this.fetch();
        },
        sourceLabel(s) {
            switch (s) {
                case 'caisse':  return this.$t('label.source_caisse');
                case 'borne':   return this.$t('label.source_borne');
                case 'livreur': return this.$t('label.source_livreur');
                default:        return s || '—';
            }
        },
        sourceClass(s) {
            switch (s) {
                case 'caisse':  return 'bg-blue-100 text-blue-800';
                case 'borne':   return 'bg-purple-100 text-purple-800';
                case 'livreur': return 'bg-emerald-100 text-emerald-800';
                default:        return 'bg-gray-100 text-gray-800';
            }
        },
        modeLabel(m) {
            switch (m) {
                case 'cash':   return this.$t('label.mode_cash');
                case 'card':   return this.$t('label.mode_card');
                case 'mobile': return this.$t('label.mode_mobile');
                case 'ticket': return this.$t('label.mode_ticket');
                case 'other':  return this.$t('label.mode_other');
                default:       return m || '—';
            }
        },
        formatMoney(v) {
            const n = Number(v || 0);
            return n.toFixed(2);
        },
        // [Wave X-C round-1 2026-05-21] Currency-formatted helper used by the
        // reconciliation card so the écart number is unambiguously EUR. Plain
        // `formatMoney` kept untouched for the summary cards / table cells to
        // minimise visual diff outside the reconciliation strip.
        formatMoneyEuro(v) {
            const n = Number(v || 0);
            try {
                const locale = (this.$i18n && this.$i18n.locale) || 'fr-FR';
                return new Intl.NumberFormat(locale, {
                    style: 'currency',
                    currency: 'EUR',
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                }).format(n);
            } catch (e) {
                return `${n.toFixed(2)} €`;
            }
        },
        formatTime(iso) {
            if (!iso) return '—';
            try {
                const d = new Date(iso);
                const locale = (this.$i18n && this.$i18n.locale) || 'fr-FR';
                return d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
            } catch (e) {
                return iso;
            }
        },
    },
};
</script>
