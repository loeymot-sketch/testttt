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
                                            <!--
                                                [P0 CLÔTURE-BLOQUÉE 2026-08-15 · GOAL_CONFORT_MAX] Une session
                                                CLOSED-non-réconciliée (2e appel /reconcile échoué — écart >
                                                seuil sans la permission cash.reconcile.variance.override)
                                                n'avait AUCUN chemin de reprise : reconcile() existait côté JS
                                                mais 0 écran ne l'appelait. Session bloquée à vie, invisible de
                                                l'écran de caisse (qui ne relit QUE status=OPEN).
                                            -->
                                            <button
                                                v-if="s.status === 'closed'"
                                                type="button"
                                                data-test="cash-reconcile-start"
                                                class="db-btn py-1 px-2 mt-1 text-xs text-white bg-amber-600"
                                                :disabled="reconcilingId === s.id"
                                                @click="startReconcile(s)"
                                            >
                                                {{ reconcilingId === s.id ? $t('label.loading') + '…' : $t('button.cash_reconcile_now') }}
                                            </button>
                                            <div v-if="reconcileTarget && reconcileTarget.id === s.id" class="mt-2 p-2 bg-amber-50 border border-amber-200 rounded">
                                                <p v-if="reconcileError" class="text-xs text-red-700 mb-1">{{ reconcileError }}</p>
                                                <template v-if="reconcileNeedsReason">
                                                    <label :for="'reconcileReason' + s.id" class="text-xs text-gray-700 block mb-1">
                                                        {{ $t('label.cash_variance_reason') }}
                                                    </label>
                                                    <textarea
                                                        :id="'reconcileReason' + s.id"
                                                        data-test="cash-reconcile-reason"
                                                        v-model="reconcileReason"
                                                        rows="2"
                                                        maxlength="255"
                                                        class="db-field-control text-xs w-full mb-2"
                                                    ></textarea>
                                                    <div class="flex gap-2">
                                                        <button type="button" data-test="cash-reconcile-confirm" class="db-btn py-1 px-2 text-xs text-white bg-primary" @click="confirmReconcile(s)">
                                                            {{ $t('button.confirm') }}
                                                        </button>
                                                        <button type="button" data-test="cash-reconcile-cancel" class="db-btn py-1 px-2 text-xs bg-gray-200" @click="cancelReconcile">
                                                            {{ $t('button.cancel') }}
                                                        </button>
                                                    </div>
                                                </template>
                                            </div>
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
import { formatPrice } from '../../../helpers/formatPrice';
import { reconcile as reconcileCashSession } from '../../../services/CashDrawerService';
import alertService from '../../../services/alertService';

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
            // [P0 CLÔTURE-BLOQUÉE 2026-08-15] État de la reprise de réconciliation.
            reconcilingId: null,
            reconcileTarget: null,
            reconcileNeedsReason: false,
            reconcileReason: '',
            reconcileError: '',
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
            // [HEAL-money-fr 2026-06-26] FR (ADR-007): render "7,90 €" (comma
            // decimal, NBSP U+00A0 before €) via the canonical admin renderer.
            // Was "7.90" (US point, no symbol) on the cash reconciliation report.
            return formatPrice(v);
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
        // [P0 CLÔTURE-BLOQUÉE 2026-08-15 · GOAL_CONFORT_MAX] Reprise d'une session
        // CLOSED-non-réconciliée. Tente d'abord SANS raison (cas courant : écart sous
        // le seuil, `reconcileSession()` est idempotent) ; si le backend répond
        // CASH_VARIANCE_REASON_REQUIRED, révèle le champ de saisie plutôt que
        // d'échouer en silence.
        async startReconcile(session) {
            this.reconcileError = '';
            this.reconcileNeedsReason = false;
            this.reconcileReason = '';
            this.reconcileTarget = session;
            await this.attemptReconcile(session, null);
        },
        async confirmReconcile(session) {
            await this.attemptReconcile(session, this.reconcileReason);
        },
        cancelReconcile() {
            this.reconcileTarget = null;
            this.reconcileNeedsReason = false;
            this.reconcileError = '';
            this.reconcileReason = '';
        },
        async attemptReconcile(session, reason) {
            this.reconcilingId = session.id;
            this.reconcileError = '';
            try {
                const result = await reconcileCashSession(session.id, reason);
                session.status = result?.data?.status || 'reconciled';
                session.variance = result?.data?.variance ?? session.variance;
                session.expected_closing_amount = result?.data?.expected ?? session.expected_closing_amount;
                this.cancelReconcile();
                alertService.success(this.$t('message.cash_session_reconciled'));
            } catch (err) {
                const data = err?.response?.data;
                if (data?.code === 'CASH_VARIANCE_REASON_REQUIRED') {
                    this.reconcileNeedsReason = true;
                    this.reconcileError = this.$t('message.cash_variance_reason_required', { threshold: this.formatMoney(data.threshold) });
                } else if (data?.code === 'CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED') {
                    this.reconcileNeedsReason = false;
                    this.reconcileError = this.$t('message.cash_variance_manager_required', { threshold: this.formatMoney(data.threshold) });
                } else {
                    this.reconcileError = data?.message || this.$t('message.something_wrong');
                }
                // eslint-disable-next-line no-console
                console.error('[CashSessionReport] reconcile failed', err);
            } finally {
                this.reconcilingId = null;
            }
        },
    },
};
</script>
