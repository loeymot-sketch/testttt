<template>
    <LoadingComponent :props="loading" />

    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('label.delivery_cash_sessions') }}</h3>

                <div class="db-card-filter">
                    <div class="db-card-filter-item">
                        <label for="filterStatus" class="db-field-title after:hidden">
                            {{ $t('label.status') }}
                        </label>
                        <select
                            id="filterStatus"
                            class="db-field-control"
                            v-model="filters.status"
                            @change="list(1)"
                            data-testid="delivery-cash-filter-status"
                        >
                            <option value="">{{ $t('label.all') }}</option>
                            <option value="open">{{ $t('label.delivery_cash_status_open') }}</option>
                            <option value="closed">{{ $t('label.delivery_cash_status_closed') }}</option>
                            <option value="reconciled">{{ $t('label.delivery_cash_status_reconciled') }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="db-table-responsive">
                <table class="db-table stripe">
                    <thead class="db-table-head">
                        <tr class="db-table-head-tr">
                            <th class="db-table-head-th">{{ $t('label.id') }}</th>
                            <th class="db-table-head-th">{{ $t('label.delivery_boy') }}</th>
                            <th class="db-table-head-th">{{ $t('label.branch') }}</th>
                            <th class="db-table-head-th">{{ $t('label.cash_session_opening_amount') }}</th>
                            <th class="db-table-head-th">{{ $t('label.cash_session_closing_amount') }}</th>
                            <th class="db-table-head-th">{{ $t('label.cash_session_variance') }}</th>
                            <th class="db-table-head-th">{{ $t('label.status') }}</th>
                            <th class="db-table-head-th">{{ $t('label.cash_session_opened_at') }}</th>
                            <th class="db-table-head-th hidden-print">{{ $t('label.action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="db-table-body" v-if="sessions.length > 0">
                        <tr
                            class="db-table-body-tr"
                            v-for="session in sessions"
                            :key="session.id"
                            :data-testid="`delivery-cash-session-row-${session.id}`"
                        >
                            <td class="db-table-body-td">#{{ session.id }}</td>
                            <td class="db-table-body-td">{{ session.delivery_boy_id }}</td>
                            <td class="db-table-body-td">{{ session.branch_id }}</td>
                            <td class="db-table-body-td">{{ formatMoney(session.opening_amount) }}</td>
                            <td class="db-table-body-td">
                                {{ session.closing_amount === null ? '—' : formatMoney(session.closing_amount) }}
                            </td>
                            <td class="db-table-body-td">
                                <span
                                    v-if="session.variance !== null"
                                    :class="varianceClass(session.variance)"
                                >
                                    {{ formatVariance(session.variance) }}
                                </span>
                                <span v-else>—</span>
                            </td>
                            <td class="db-table-body-td">
                                <span :class="statusBadgeClass(session.status)">
                                    {{ $t(`label.delivery_cash_status_${session.status}`) }}
                                </span>
                            </td>
                            <td class="db-table-body-td">{{ formatTimestamp(session.opened_at) }}</td>
                            <td class="db-table-body-td hidden-print">
                                <button
                                    type="button"
                                    class="db-btn-outline-sm"
                                    :data-testid="`delivery-cash-session-view-${session.id}`"
                                    @click="$emit('view', session.id)"
                                >
                                    <i class="lab lab-eye-line"></i>
                                    <span>{{ $t('button.view') }}</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tbody class="db-table-body" v-else>
                        <tr class="db-table-body-tr">
                            <td class="db-table-body-td text-center" colspan="9">
                                <div class="p-4" data-testid="delivery-cash-empty-state">
                                    <span class="d-block mt-3 text-lg">{{ $t('message.no_data_available') }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6"
                v-if="pagination.total > pagination.per_page"
            >
                <button
                    type="button"
                    class="db-btn-outline-sm"
                    :disabled="pagination.current_page <= 1"
                    @click="list(pagination.current_page - 1)"
                >
                    {{ $t('button.previous') }}
                </button>
                <span>
                    {{ pagination.current_page }} / {{ pagination.last_page }} ({{ pagination.total }})
                </span>
                <button
                    type="button"
                    class="db-btn-outline-sm"
                    :disabled="pagination.current_page >= pagination.last_page"
                    @click="list(pagination.current_page + 1)"
                >
                    {{ $t('button.next') }}
                </button>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [V1.0.2 Sub-6.3 BUILD-1 — 2026-05-18] Delivery boy cash session — admin list view.
 *
 * Scope-minimal : reads /api/admin/delivery-boy/cash-sessions and renders
 * a table with status badges + variance highlight. Filter by status only
 * (delivery_boy_id filter added by Wave 6b-1.7 enrichment).
 *
 * Emits `view` with session ID when a row's view button is clicked — the
 * parent route should swap to the detail component.
 */
import axios from 'axios';
import LoadingComponent from '../components/LoadingComponent';

export default {
    name: 'DeliveryBoyCashSessionListComponent',
    components: { LoadingComponent },
    emits: ['view'],
    data() {
        return {
            loading: { isActive: false },
            sessions: [],
            pagination: {
                total: 0,
                per_page: 20,
                current_page: 1,
                last_page: 1,
            },
            filters: {
                status: '',
                delivery_boy_id: null,
                per_page: 20,
            },
        };
    },
    mounted() {
        this.list();
    },
    methods: {
        list(page = 1) {
            this.loading.isActive = true;
            const params = {
                per_page: this.filters.per_page,
                page,
            };
            if (this.filters.status) {
                params.status = this.filters.status;
            }
            if (this.filters.delivery_boy_id) {
                params.delivery_boy_id = this.filters.delivery_boy_id;
            }

            return axios
                .get('/admin/delivery-boy/cash-sessions', { params })
                .then((res) => {
                    this.sessions = res.data.data || [];
                    this.pagination = res.data.pagination || this.pagination;
                    this.loading.isActive = false;
                })
                .catch(() => {
                    this.loading.isActive = false;
                });
        },
        formatMoney(value) {
            const n = Number(value) || 0;
            try {
                return n.toLocaleString('fr-FR', {
                    style: 'currency',
                    currency: 'EUR',
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            } catch (_e) {
                return `${n.toFixed(2)} €`;
            }
        },
        formatVariance(value) {
            const n = Number(value) || 0;
            const formatted = this.formatMoney(Math.abs(n));
            if (n > 0) return `+${formatted}`;
            if (n < 0) return `-${formatted}`;
            return formatted;
        },
        varianceClass(value) {
            const n = Number(value) || 0;
            if (n > 0.01) return 'text-green-600 font-semibold';
            if (n < -0.01) return 'text-red-600 font-semibold';
            return 'text-gray-700';
        },
        statusBadgeClass(status) {
            switch (status) {
                case 'open':
                    return 'badge badge-warning';
                case 'closed':
                    return 'badge badge-info';
                case 'reconciled':
                    return 'badge badge-success';
                default:
                    return 'badge badge-secondary';
            }
        },
        formatTimestamp(iso) {
            if (!iso) return '—';
            try {
                const d = new Date(iso);
                if (Number.isNaN(d.getTime())) return iso;
                return d.toLocaleString('fr-FR', {
                    day: '2-digit',
                    month: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                });
            } catch (_e) {
                return iso;
            }
        },
    },
};
</script>
