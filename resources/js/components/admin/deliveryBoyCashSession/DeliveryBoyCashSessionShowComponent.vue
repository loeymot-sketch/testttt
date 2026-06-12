<template>
    <LoadingComponent :props="loading" />

    <div class="col-12" v-if="session">
        <div class="db-card mb-6">
            <div class="db-card-header">
                <h3 class="db-card-title" data-testid="delivery-cash-session-title">
                    {{ $t('label.delivery_cash_session') }} #{{ session.id }}
                </h3>
                <span :class="statusBadgeClass(session.status)" data-testid="delivery-cash-session-status">
                    {{ $t(`label.delivery_cash_status_${session.status}`) }}
                </span>
            </div>

            <div class="db-card-body">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <dt class="text-sm text-gray-500">{{ $t('label.delivery_boy') }}</dt>
                        <!-- [W-REM T-R2.3 Q-1] D-B3-01 : nom au lieu de l'ID brut. -->
                        <dd class="text-base font-semibold" data-testid="delivery-cash-livreur-id">
                            {{ session.delivery_boy_name || `#${session.delivery_boy_id}` }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">{{ $t('label.branch') }}</dt>
                        <dd class="text-base font-semibold">{{ session.branch_name || `#${session.branch_id}` }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">{{ $t('label.cash_session_opening_amount') }}</dt>
                        <dd class="text-base font-semibold" data-testid="delivery-cash-opening-amount">
                            {{ formatMoney(session.opening_amount) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">{{ $t('label.cash_session_closing_amount') }}</dt>
                        <dd class="text-base font-semibold">
                            {{ session.closing_amount === null ? '—' : formatMoney(session.closing_amount) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">{{ $t('label.cash_session_expected_amount') }}</dt>
                        <dd class="text-base font-semibold">
                            {{ session.expected_closing_amount === null ? '—' : formatMoney(session.expected_closing_amount) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">{{ $t('label.cash_session_variance') }}</dt>
                        <dd
                            v-if="session.variance !== null"
                            :class="varianceClass(session.variance)"
                            data-testid="delivery-cash-variance"
                        >
                            {{ formatVariance(session.variance) }}
                        </dd>
                        <dd v-else>—</dd>
                    </div>
                    <div v-if="session.variance_reason" class="sm:col-span-2">
                        <dt class="text-sm text-gray-500">{{ $t('label.cash_session_variance_reason') }}</dt>
                        <dd class="text-base" data-testid="delivery-cash-variance-reason">
                            {{ session.variance_reason }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">{{ $t('label.cash_session_opened_at') }}</dt>
                        <dd>{{ formatTimestamp(session.opened_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">{{ $t('label.cash_session_closed_at') }}</dt>
                        <dd>{{ formatTimestamp(session.closed_at) }}</dd>
                    </div>
                </dl>

                <div class="mt-6 flex gap-3" v-if="canMutate && !activeFormMode">
                    <button
                        v-if="session.status === 'open'"
                        type="button"
                        class="db-btn bg-warning text-white"
                        data-testid="delivery-cash-action-close"
                        @click="activeFormMode = 'close'"
                    >
                        {{ $t('label.cash_session_close') }}
                    </button>
                    <button
                        v-if="session.status === 'closed'"
                        type="button"
                        class="db-btn bg-success text-white"
                        data-testid="delivery-cash-action-reconcile"
                        @click="activeFormMode = 'reconcile'"
                    >
                        {{ $t('label.cash_session_reconcile') }}
                    </button>
                </div>

                <!-- [GOAL-2026-05-29 BTN-P1] Close/Reconcile buttons previously $emit'd to a
                     non-existent parent (dead). Now they mount the (self-contained) Form
                     inline; on success the session is refreshed in place. -->
                <div v-if="canMutate && activeFormMode" class="mt-6" data-testid="delivery-cash-action-form-wrap">
                    <DeliveryBoyCashSessionFormComponent
                        :mode="activeFormMode"
                        :session-id="session.id"
                        @submitted="onActionSubmitted"
                        @cancel="activeFormMode = null"
                    />
                </div>
            </div>
        </div>

        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t('label.cash_session_movements') }}</h3>
            </div>
            <div class="db-card-body">
                <table v-if="movements.length" class="db-table stripe">
                    <thead class="db-table-head">
                        <tr class="db-table-head-tr">
                            <th class="db-table-head-th">{{ $t('label.date') }}</th>
                            <th class="db-table-head-th">{{ $t('label.type') }}</th>
                            <th class="db-table-head-th text-right">{{ $t('label.amount') }}</th>
                            <th class="db-table-head-th text-right">{{ $t('label.direction') }}</th>
                            <th class="db-table-head-th">{{ $t('label.notes') }}</th>
                        </tr>
                    </thead>
                    <tbody class="db-table-body">
                        <tr
                            v-for="mvt in movements"
                            :key="mvt.id"
                            class="db-table-body-tr"
                            :data-testid="`delivery-cash-mvt-row-${mvt.id}`"
                        >
                            <td class="db-table-body-td">{{ formatTimestamp(mvt.created_at) }}</td>
                            <td class="db-table-body-td">
                                {{ $t(`label.delivery_cash_mvt_${mvt.type}`) }}
                            </td>
                            <td class="db-table-body-td text-right">{{ formatMoney(mvt.amount) }}</td>
                            <td class="db-table-body-td text-right">
                                {{ mvt.direction === 'in' ? '↑ IN' : '↓ OUT' }}
                            </td>
                            <td class="db-table-body-td">{{ mvt.notes || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else data-testid="delivery-cash-no-movements" class="text-center text-gray-500 py-4">
                    {{ $t('label.cash_session_no_movements') }}
                </p>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [V1.0.2 Sub-6.3 BUILD-1 — 2026-05-18] Delivery boy cash session — admin detail view.
 *
 * Loads /api/admin/delivery-boy/cash-sessions/{id} with movements eager-loaded.
 *
 * [GOAL-2026-05-29 BTN-P1] This is a TOP-LEVEL route component — the old
 * $emit('close-session'/'reconcile-session') reached no parent (dead buttons).
 * It now mounts the self-contained Form inline (close/reconcile modes) and
 * refreshes the session in place on success. Backend enforces the
 * `delivery-boys` permission on the mutation regardless of canMutate.
 */
import axios from 'axios';
import LoadingComponent from '../components/LoadingComponent';
import DeliveryBoyCashSessionFormComponent from './DeliveryBoyCashSessionFormComponent.vue';

export default {
    name: 'DeliveryBoyCashSessionShowComponent',
    components: { LoadingComponent, DeliveryBoyCashSessionFormComponent },
    props: {
        sessionId: { type: [Number, String], required: true },
        canMutate: { type: Boolean, default: true },
    },
    data() {
        return {
            loading: { isActive: false },
            session: null,
            activeFormMode: null,
        };
    },
    computed: {
        movements() {
            return this.session?.movements || [];
        },
    },
    watch: {
        sessionId: {
            immediate: true,
            handler() {
                this.fetch();
            },
        },
    },
    methods: {
        // [GOAL-2026-05-29 BTN-P1] Form(close|reconcile) success → close form + re-fetch
        // the full session (the show endpoint eager-loads movements, which the
        // close/reconcile response may not carry) so status + variance refresh in place.
        onActionSubmitted() {
            this.activeFormMode = null;
            this.fetch();
        },
        fetch() {
            if (!this.sessionId) return;
            this.loading.isActive = true;
            return axios
                .get(`/admin/delivery-boy/cash-sessions/${this.sessionId}`)
                .then((res) => {
                    this.session = res.data.data || null;
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
                    second: '2-digit',
                });
            } catch (_e) {
                return iso;
            }
        },
    },
};
</script>
