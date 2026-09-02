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
                    <!-- [GOAL-2026-05-29 BTN-P1] Open-session entry: the Form was orphaned
                         (no UI path to open). Mounts the existing self-contained Form (open mode). -->
                    <div class="db-card-filter-item flex items-end">
                        <button
                            type="button"
                            class="db-btn bg-primary text-white"
                            data-testid="delivery-cash-open-session-btn"
                            @click="showOpenForm = !showOpenForm"
                        >
                            <i class="lab lab-plus"></i>
                            <span>{{ $t('label.cash_session_open') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- [GOAL-2026-05-29 BTN-P1] Inline open form (was orphaned component). -->
            <div v-if="showOpenForm" class="px-4 pb-4" data-testid="delivery-cash-open-form-wrap">
                <DeliveryBoyCashSessionFormComponent
                    mode="open"
                    @submitted="onSessionOpened"
                    @cancel="showOpenForm = false"
                />
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
                            <td class="db-table-body-td">{{ deliveryBoyName(session.delivery_boy_id) }}</td>
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
                                    @click="viewSession(session.id)"
                                >
                                    <i class="lab lab-view"></i>
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
 * [GOAL-2026-05-29 BTN-P1] The View button previously did $emit('view') but this
 * is a TOP-LEVEL route component — no parent listened, so it was a dead button.
 * Now it $router.push to the .show route. Also mounts the (previously orphaned)
 * Form in open mode so a session can actually be opened from this surface.
 */
import axios from 'axios';
import LoadingComponent from '../components/LoadingComponent';
import DeliveryBoyCashSessionFormComponent from './DeliveryBoyCashSessionFormComponent.vue';

export default {
    name: 'DeliveryBoyCashSessionListComponent',
    components: { LoadingComponent, DeliveryBoyCashSessionFormComponent },
    data() {
        return {
            loading: { isActive: false },
            showOpenForm: false,
            sessions: [],
            // [visual-round-1 P3 fix 2026-07-07] id -> livreur name lookup so the
            // LIVREUR column shows the person's name, not the raw user id. The
            // cash-session resource only exposes delivery_boy_id, so we resolve
            // names client-side from the delivery-boy directory (frontend scope).
            deliveryBoyMap: {},
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
        this.loadDeliveryBoys();
        this.list();
    },
    methods: {
        // [visual-round-1 P3 fix 2026-07-07] Build the id -> name lookup from the
        // delivery-boy directory (same endpoint the Sales Report filter uses).
        loadDeliveryBoys() {
            return axios
                .get('admin/delivery-boy', { params: { order_column: 'id', order_type: 'asc' } })
                .then((res) => {
                    const map = {};
                    (res.data.data || []).forEach((boy) => {
                        if (boy && boy.id != null) {
                            map[boy.id] = boy.name;
                        }
                    });
                    this.deliveryBoyMap = map;
                })
                .catch(() => { /* directory unavailable — fall back to #id below */ });
        },
        deliveryBoyName(id) {
            if (id === null || id === undefined) {
                return '—';
            }
            return this.deliveryBoyMap[id] || `#${id}`;
        },
        // [GOAL-2026-05-29 BTN-P1] Was $emit('view') to a non-existent parent (dead button).
        viewSession(id) {
            this.$router.push({ name: 'admin.delivery-boy-cash-sessions.show', params: { id } });
        },
        // [GOAL-2026-05-29 BTN-P1] Form(open) success → close form + refresh + jump to the new session.
        onSessionOpened(session) {
            this.showOpenForm = false;
            if (session && session.id) {
                this.viewSession(session.id);
            } else {
                this.list(1);
            }
        },
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
