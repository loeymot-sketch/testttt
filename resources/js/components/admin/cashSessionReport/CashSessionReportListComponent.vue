<template>
    <!--
        [Wave O — O4 2026-05-20] Admin daily cash sessions report — UI minimale.
        Owner request : « voir les caisses chaque jour, début + fin, transactions ».

        Source de données: GET /api/admin/cash-sessions-report
        Permissions backend: pos-manage-fiscal (Admin + Branch Manager).
        Branch isolation gérée backend via BranchScope — l'UI ne filtre rien.
    -->
    <div class="col-12">
        <div class="db-card db-tab-div active">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('menu.cash_sessions_report') }}</h3>
            </div>

            <div class="db-card-body">
                <!-- Date range filter -->
                <form class="p-4 sm:p-5 mb-3 flex flex-wrap items-end gap-3" @submit.prevent="loadSessions">
                    <div>
                        <label for="cashFrom" class="db-field-title after:hidden">{{ $t('label.from_date') }}</label>
                        <input id="cashFrom" v-model="filters.from" type="date" class="db-field-control" />
                    </div>
                    <div>
                        <label for="cashTo" class="db-field-title after:hidden">{{ $t('label.to_date') }}</label>
                        <input id="cashTo" v-model="filters.to" type="date" class="db-field-control" />
                    </div>
                    <button type="submit" class="db-btn py-2 text-white bg-primary">
                        <i class="lab lab-search-line lab-font-size-16"></i>
                        <span>{{ $t('button.search') }}</span>
                    </button>
                    <button type="button" class="db-btn py-2 text-white bg-gray-600" @click="clearFilters">
                        <i class="lab lab-cross-line-2 lab-font-size-22"></i>
                        <span>{{ $t('button.clear') }}</span>
                    </button>
                </form>

                <!-- Loading state -->
                <div v-if="loading" class="p-6 text-center text-gray-500">
                    {{ $t('label.loading') }}…
                </div>

                <!-- Empty state -->
                <div v-else-if="!groupedByDay.length" class="p-6 text-center text-gray-500">
                    {{ $t('label.no_data_available') }}
                </div>

                <!-- Daily groupings -->
                <div v-else class="px-4 sm:px-5 pb-5">
                    <div
                        v-for="day in groupedByDay"
                        :key="day.date"
                        class="mb-6 border rounded-lg overflow-hidden"
                    >
                        <header class="bg-gray-50 px-4 py-3 border-b flex flex-wrap justify-between items-center">
                            <h4 class="font-semibold text-lg text-primary">
                                {{ formatDate(day.date) }}
                            </h4>
                            <div class="text-sm text-gray-600 flex flex-wrap gap-4">
                                <span>{{ $t('label.sessions') }}: <strong>{{ day.sessions.length }}</strong></span>
                                <span>{{ $t('label.transactions') }}: <strong>{{ day.totalTransactions }}</strong></span>
                                <span>
                                    {{ $t('label.opening_total') }}: <strong>{{ formatMoney(day.totalOpening) }}</strong>
                                </span>
                                <span>
                                    {{ $t('label.closing_total') }}: <strong>{{ formatMoney(day.totalClosing) }}</strong>
                                </span>
                            </div>
                        </header>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-100 text-gray-700">
                                    <tr>
                                        <th class="px-3 py-2 text-left">{{ $t('label.session_id') }}</th>
                                        <th class="px-3 py-2 text-left">{{ $t('label.branch') }}</th>
                                        <th class="px-3 py-2 text-left">{{ $t('label.cashier') }}</th>
                                        <th class="px-3 py-2 text-left">{{ $t('label.opened_at') }}</th>
                                        <th class="px-3 py-2 text-left">{{ $t('label.closed_at') }}</th>
                                        <th class="px-3 py-2 text-right">{{ $t('label.opening_amount') }}</th>
                                        <th class="px-3 py-2 text-right">{{ $t('label.closing_amount') }}</th>
                                        <th class="px-3 py-2 text-right">{{ $t('label.variance') }}</th>
                                        <th class="px-3 py-2 text-right">{{ $t('label.transactions') }}</th>
                                        <th class="px-3 py-2 text-left">{{ $t('label.status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="s in day.sessions"
                                        :key="s.id"
                                        class="border-t hover:bg-gray-50"
                                    >
                                        <td class="px-3 py-2">#{{ s.id }}</td>
                                        <td class="px-3 py-2">{{ s.branch_id }}</td>
                                        <td class="px-3 py-2">{{ s.opened_by_name || '—' }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ formatTime(s.opened_at) }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ s.closed_at ? formatTime(s.closed_at) : '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ formatMoney(s.opening_amount) }}</td>
                                        <td class="px-3 py-2 text-right">
                                            {{ s.closing_amount === null ? '—' : formatMoney(s.closing_amount) }}
                                        </td>
                                        <td class="px-3 py-2 text-right" :class="varianceClass(s.variance)">
                                            {{ s.variance === null ? '—' : formatMoney(s.variance) }}
                                        </td>
                                        <td class="px-3 py-2 text-right">{{ s.transactions_count }}</td>
                                        <td class="px-3 py-2">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs" :class="statusClass(s.status)">
                                                {{ $t('label.cash_status_' + s.status) }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="meta.last_page > 1" class="flex justify-center items-center gap-3 mt-4">
                        <button
                            type="button"
                            class="db-btn py-2 px-4 bg-gray-200"
                            :disabled="meta.current_page <= 1"
                            @click="changePage(meta.current_page - 1)"
                        >
                            {{ $t('button.previous') }}
                        </button>
                        <span class="text-gray-700">
                            {{ meta.current_page }} / {{ meta.last_page }}
                        </span>
                        <button
                            type="button"
                            class="db-btn py-2 px-4 bg-gray-200"
                            :disabled="meta.current_page >= meta.last_page"
                            @click="changePage(meta.current_page + 1)"
                        >
                            {{ $t('button.next') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'CashSessionReportListComponent',
    data() {
        return {
            loading: false,
            sessions: [],
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: 50,
                total: 0,
            },
            filters: {
                from: '',
                to: '',
            },
        };
    },
    computed: {
        groupedByDay() {
            const map = new Map();
            for (const s of this.sessions) {
                const key = s.business_date || 'unknown';
                if (!map.has(key)) {
                    map.set(key, {
                        date: key,
                        sessions: [],
                        totalOpening: 0,
                        totalClosing: 0,
                        totalTransactions: 0,
                    });
                }
                const bucket = map.get(key);
                bucket.sessions.push(s);
                bucket.totalOpening += Number(s.opening_amount || 0);
                bucket.totalClosing += Number(s.closing_amount || 0);
                bucket.totalTransactions += Number(s.transactions_count || 0);
            }
            // Map iteration preserves insertion order; sessions arrive
            // sorted opened_at desc so days come out desc as expected.
            return Array.from(map.values());
        },
    },
    mounted() {
        this.loadSessions();
    },
    methods: {
        async loadSessions(page = 1) {
            this.loading = true;
            try {
                const params = { page, per_page: this.meta.per_page };
                if (this.filters.from) params.from = this.filters.from;
                if (this.filters.to) params.to = this.filters.to;
                const res = await (window.axios || axios).get('admin/cash-sessions-report', { params });
                this.sessions = res.data?.data || [];
                this.meta = res.data?.meta || this.meta;
            } catch (e) {
                // eslint-disable-next-line no-console
                console.error('[CashSessionReport] load failed', e);
                this.sessions = [];
            } finally {
                this.loading = false;
            }
        },
        clearFilters() {
            this.filters.from = '';
            this.filters.to = '';
            this.loadSessions(1);
        },
        changePage(p) {
            if (p < 1 || p > this.meta.last_page) return;
            this.loadSessions(p);
        },
        formatDate(d) {
            if (!d) return '';
            try {
                // [Wave P-5 2026-05-20] Pin locale to active i18n (FR-fr by default in
                // FoodKing) so Playwright/Chromium-headless ne renvoie pas "Wednesday, May 20"
                // alors que l'UI complète est en français.
                const locale = this.$i18n?.locale || 'fr-FR';
                return new Date(d).toLocaleDateString(locale, {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                });
            } catch (e) {
                return d;
            }
        },
        formatTime(iso) {
            if (!iso) return '';
            try {
                const d = new Date(iso);
                const locale = this.$i18n?.locale || 'fr-FR';
                return d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
            } catch (e) {
                return iso;
            }
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
            } catch (_e) {
                return n.toFixed(2).replace('.', ',') + ' €';
            }
        },
        varianceClass(v) {
            if (v === null || v === undefined) return '';
            const n = Number(v);
            if (Math.abs(n) < 0.01) return 'text-green-700';
            if (n < 0) return 'text-red-700 font-semibold';
            return 'text-amber-700';
        },
        statusClass(s) {
            switch (s) {
                case 'open': return 'bg-blue-100 text-blue-800';
                case 'closed': return 'bg-amber-100 text-amber-800';
                case 'reconciled': return 'bg-green-100 text-green-800';
                default: return 'bg-gray-100 text-gray-800';
            }
        },
    },
};
</script>
