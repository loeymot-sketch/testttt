<template>
    <!--
      [POS-V4-ORDERS-TRACKER 2026-05-02]
      Écran caisse plein écran : kanban des commandes actives (POS + borne + online)
      pour le caissier, avec live update Echo. Aucune logique de pricing — affichage
      des totaux renvoyés par le backend (invariant FoodKing : pricing SSOT).
      L'écran client (OSS) reste séparé : route admin.order-status-screen.
    -->
    <section class="pos-tracker-shell" data-pos-tracker-shell>
        <ConnectionStatusBanner suppress-transient suppress-session-invalid />

        <header class="pos-tracker-bar">
            <div class="pos-tracker-bar-left min-w-0">
                <p class="pos-tracker-eyebrow">Caisse FoodKing</p>
                <h1 class="pos-tracker-title">{{ $t('pos.tracker.title') }}</h1>
                <div class="pos-tracker-status-row">
                    <span>
                        <strong>{{ stats.active }}</strong>
                        {{ $t('pos.tracker.active_orders') }}
                    </span>
                    <span class="pos-tracker-status-pill pos-tracker-status-pill--ready" v-if="stats.ready > 0">
                        <i class="fa-solid fa-bell-concierge" aria-hidden="true"></i>
                        {{ stats.ready }} {{ $t('pos.tracker.ready_short') }}
                    </span>
                    <span>{{ stats.todayCount }} {{ $t('pos.tracker.today_total') }}</span>
                </div>
            </div>
            <div class="pos-tracker-bar-right">
                <div class="pos-tracker-search">
                    <i class="lab lab-search-normal" aria-hidden="true"></i>
                    <input
                        type="search"
                        v-model.trim="filters.query"
                        :placeholder="$t('pos.tracker.search_placeholder')"
                        :aria-label="$t('pos.tracker.search_placeholder')"
                    />
                    <button
                        v-if="filters.query"
                        type="button"
                        class="pos-tracker-search-clear"
                        @click="filters.query = ''"
                        :aria-label="$t('button.clear')"
                    >✕</button>
                </div>
                <div class="pos-tracker-source-tabs" role="tablist" :aria-label="$t('pos.tracker.source_filter')">
                    <button
                        v-for="src in sourceTabs"
                        :key="src.id"
                        type="button"
                        role="tab"
                        :aria-selected="filters.source === src.id"
                        :class="['pos-tracker-source-tab', filters.source === src.id ? 'is-active' : '']"
                        @click="filters.source = src.id"
                    >
                        <span class="pos-tracker-source-tab-icon" aria-hidden="true">{{ src.icon }}</span>
                        <span>{{ src.label }}</span>
                    </button>
                </div>
                <router-link
                    :to="{ name: 'admin.pos-orders.list' }"
                    class="pos-tracker-history-link"
                    :title="$t('pos.tracker.history_hint')"
                    data-testid="pos-tracker-history"
                >
                    <i class="fa-solid fa-list-ul" aria-hidden="true"></i>
                    <span>{{ $t('pos.tracker.history') }}</span>
                </router-link>
                <router-link
                    :to="{ name: 'admin.order-status-screen' }"
                    target="_blank"
                    rel="noopener"
                    class="pos-tracker-customer-link"
                    :title="$t('pos.tracker.customer_screen_hint')"
                >
                    <i class="fa-solid fa-display" aria-hidden="true"></i>
                    {{ $t('pos.tracker.customer_screen') }}
                </router-link>
                <router-link
                    :to="{ name: 'admin.pos' }"
                    class="pos-tracker-back-link"
                >
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    {{ $t('pos.tracker.back_to_pos') }}
                </router-link>
            </div>
        </header>

        <div v-if="!realtimeConnected" class="pos-tracker-rt-warn" role="status">
            {{ $t('pos.tracker.realtime_lost') }}
        </div>

        <div class="pos-tracker-grid" :aria-busy="loading ? 'true' : 'false'">
            <article
                v-for="col in columns"
                :key="col.id"
                :class="['pos-tracker-col', `pos-tracker-col--${col.tone}`, col.highlight && col.orders.length > 0 ? 'is-pulse' : '']"
            >
                <header class="pos-tracker-col-head">
                    <h2>
                        <span class="pos-tracker-col-icon" aria-hidden="true">{{ col.icon }}</span>
                        {{ col.label }}
                    </h2>
                    <span class="pos-tracker-col-count" :aria-label="`${col.orders.length} ${col.label}`">
                        {{ col.orders.length }}
                    </span>
                </header>

                <div class="pos-tracker-col-body" v-if="col.orders.length > 0">
                    <transition-group name="pos-tracker-card" tag="div" class="pos-tracker-cards">
                        <article
                            v-for="order in col.orders"
                            :key="order.id"
                            :class="['pos-tracker-card', `pos-tracker-card--${col.tone}`, newReadyIds.has(order.id) ? 'is-fresh' : '']"
                            :data-testid="`tracker-order-${order.id}`"
                        >
                            <header class="pos-tracker-card-head">
                                <span class="pos-tracker-card-num">
                                    {{ order.queue_number ? 'N°' + order.queue_number : ('#' + (order.order_serial_no || order.id)) }}
                                </span>
                                <span :class="['pos-tracker-card-source', `pos-tracker-card-source--${sourceOf(order)}`]"
                                      :title="$t('pos.tracker.source_' + sourceOf(order))">
                                    {{ sourceIcon(order) }}
                                </span>
                                <span class="pos-tracker-card-time" :title="formatTime(order.created_at)">
                                    {{ elapsedShort(order.created_at) }}
                                </span>
                            </header>
                            <div class="pos-tracker-card-customer" v-if="customerLabel(order)">
                                <i class="fa-solid fa-user" aria-hidden="true"></i>
                                <span>{{ customerLabel(order) }}</span>
                            </div>
                            <ul class="pos-tracker-card-items">
                                <li
                                    v-for="(item, idx) in itemsPreview(order)"
                                    :key="idx"
                                >
                                    <span class="pos-tracker-card-qty">{{ item.quantity || 1 }}×</span>
                                    <span class="pos-tracker-card-name">{{ item.item_name || item.name }}</span>
                                </li>
                                <li v-if="extraItemsCount(order) > 0" class="pos-tracker-card-more">
                                    + {{ extraItemsCount(order) }} {{ $t('pos.tracker.more_items') }}
                                </li>
                            </ul>
                            <footer class="pos-tracker-card-foot">
                                <span class="pos-tracker-card-total">{{ formatPrice(order.total ?? order.order_amount) }}</span>
                                <div class="pos-tracker-card-actions">
                                    <router-link
                                        :to="{ name: 'admin.pos-orders.show', params: { id: order.id } }"
                                        class="pos-tracker-card-btn"
                                        :title="$t('pos.tracker.view_details')"
                                    >
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </router-link>
                                    <!--
                                      [POS-V4-CASHIER-OPS 2026-05-02] One-click reprint.
                                      Loads full order then mounts ReceiptComponent inside this view.
                                    -->
                                    <button
                                        type="button"
                                        class="pos-tracker-card-btn"
                                        :disabled="reprintBusyId === order.id"
                                        :title="$t('pos.reprint_ticket_hint')"
                                        :data-testid="`tracker-reprint-${order.id}`"
                                        @click="requestReprint(order)"
                                    >
                                        <i class="fa-solid fa-print" aria-hidden="true"></i>
                                    </button>
                                    <!--
                                      [POS-V4-CASHIER-OPS 2026-05-02] Cancel with reason.
                                      Visible only for non-final statuses (ACCEPT/PREPARING/PREPARED).
                                      Backend OrderService L1546-1551 already enforces the reason
                                      (required, max 700) — we duplicate the rule client-side
                                      to short-circuit the round-trip + give immediate UX feedback.
                                    -->
                                    <button
                                        v-if="col.id !== 'delivered'"
                                        type="button"
                                        class="pos-tracker-card-btn pos-tracker-card-btn--danger"
                                        :title="$t('pos.cancel_order_hint')"
                                        :data-testid="`tracker-cancel-${order.id}`"
                                        @click="openCancelDialog(order)"
                                    >
                                        <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                    </button>
                                    <button
                                        v-if="col.id === 'prepared'"
                                        type="button"
                                        class="pos-tracker-card-btn pos-tracker-card-btn--primary"
                                        :disabled="!!order._delivering"
                                        :title="$t('pos.tracker.mark_delivered')"
                                        @click="markDelivered(order)"
                                    >
                                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                                        <span class="hidden xl:inline">{{ $t('pos.tracker.delivered_short') }}</span>
                                    </button>
                                </div>
                            </footer>
                        </article>
                    </transition-group>
                </div>
                <div v-else class="pos-tracker-col-empty">
                    <span class="pos-tracker-col-empty-icon" aria-hidden="true">{{ col.emptyIcon }}</span>
                    <p>{{ col.emptyLabel || $t('pos.tracker.empty_column') }}</p>
                </div>
            </article>
        </div>

        <div v-if="loading && columns.every(c => c.orders.length === 0)" class="pos-tracker-loading">
            <div class="pos-tracker-spinner" aria-hidden="true"></div>
            <p>{{ $t('pos.tracker.loading') }}</p>
        </div>

        <!--
          [POS-V4-CASHIER-OPS 2026-05-02] Cancel-order dialog.
          Custom inline dialog (not using bootstrap modal) so we keep full
          control of the textarea focus/validation lifecycle. Click on
          backdrop dismisses; Esc dismisses (handled at element level).
        -->
        <div
            v-if="cancelDialog.open"
            class="pos-tracker-cancel-overlay"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="'pos-tracker-cancel-title'"
            @click.self="closeCancelDialog"
            @keydown.esc="closeCancelDialog"
        >
            <div class="pos-tracker-cancel-card">
                <header class="pos-tracker-cancel-head">
                    <h3 id="pos-tracker-cancel-title">{{ $t('pos.cancel_order_title') }}</h3>
                    <button
                        type="button"
                        class="pos-tracker-cancel-close"
                        :aria-label="$t('button.close')"
                        @click="closeCancelDialog"
                    >
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>
                <div class="pos-tracker-cancel-body">
                    <p class="pos-tracker-cancel-target" v-if="cancelDialog.order">
                        <strong>{{ cancelDialog.order.queue_number ? 'N°' + cancelDialog.order.queue_number : '#' + (cancelDialog.order.order_serial_no || cancelDialog.order.id) }}</strong>
                        <span v-if="customerLabel(cancelDialog.order)"> — {{ customerLabel(cancelDialog.order) }}</span>
                        <span> — {{ formatPrice(cancelDialog.order.total ?? cancelDialog.order.order_amount) }}</span>
                    </p>
                    <label for="pos-tracker-cancel-reason" class="pos-tracker-cancel-label">
                        {{ $t('pos.cancel_order_reason_label') }}
                    </label>
                    <textarea
                        id="pos-tracker-cancel-reason"
                        ref="cancelReasonInput"
                        v-model="cancelDialog.reason"
                        rows="3"
                        maxlength="700"
                        :placeholder="$t('pos.cancel_order_reason_placeholder')"
                        class="pos-tracker-cancel-textarea"
                        data-testid="tracker-cancel-reason"
                    ></textarea>
                    <p v-if="cancelDialog.error" class="pos-tracker-cancel-error">{{ cancelDialog.error }}</p>
                </div>
                <footer class="pos-tracker-cancel-foot">
                    <button
                        type="button"
                        class="pos-tracker-cancel-btn pos-tracker-cancel-btn--ghost"
                        @click="closeCancelDialog"
                        :disabled="cancelDialog.busy"
                    >
                        {{ $t('pos.cancel_order_cancel') }}
                    </button>
                    <button
                        type="button"
                        class="pos-tracker-cancel-btn pos-tracker-cancel-btn--danger"
                        :disabled="cancelDialog.busy"
                        :aria-busy="cancelDialog.busy"
                        data-testid="tracker-cancel-confirm"
                        @click="confirmCancelOrder"
                    >
                        <i v-if="cancelDialog.busy" class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                        {{ $t('pos.cancel_order_confirm') }}
                    </button>
                </footer>
            </div>
        </div>

        <!--
          [POS-V4-CASHIER-OPS 2026-05-02] Hidden ReceiptComponent for one-click
          reprints from the tracker. Uses the same existing #receiptModal so
          the existing print buttons (kitchen + client) remain authoritative
          for the actual paper output.
        -->
        <ReceiptComponent v-if="reprintOrder && reprintOrder.id" :order="reprintOrder" />
    </section>
</template>

<script>
import axios from 'axios';
import orderStatusEnum from '../../../enums/modules/orderStatusEnum';
import { onEvents } from '../../../services/eventContract';
import alertService from '../../../services/alertService';
import appService from '../../../services/appService';
import ConnectionStatusBanner from '../../common/ConnectionStatusBanner.vue';
import ReceiptComponent from './ReceiptComponent.vue';

const POLL_WS_MS = 60000;
const POLL_NO_WS_MS = 8000;
const FRESH_HIGHLIGHT_MS = 6000;

/**
 * [POS-V4-ORDERS-TRACKER 2026-05-02]
 * Écran caisse plein écran. Kanban 4 colonnes : ACCEPT, PREPARING, PREPARED, DELIVERED.
 * Données : `admin/pos-order` (store posOrder/lists). Live: Echo `branch.{branchId}` —
 * mêmes events que PosComponent (OrderCreated, OrderStatusChanged, OrderPaidAtCounter)
 * pour cohérence cross-surface. Stock/availability sont gérés ailleurs (PosComponent).
 */
export default {
    name: 'PosOrdersTrackerComponent',
    components: { ConnectionStatusBanner, ReceiptComponent },
    data() {
        return {
            loading: false,
            orders: [],
            filters: {
                query: '',
                source: 'all',
            },
            enums: { orderStatusEnum },
            newReadyIds: new Set(),
            _eventSub: null,
            _pollTimer: null,
            _onWsConnected: null,
            _onWsDisconnected: null,
            realtimeConnected: !!(window._wsService?.isConnected()),
            _freshTimers: Object.create(null),
            // [POS-V4-CASHIER-OPS 2026-05-02] One-click reprint state. Holds
            // the full hydrated order to feed ReceiptComponent. We keep a
            // single instance — only the most recent reprint is rendered.
            reprintOrder: {},
            reprintBusyId: null,
            // [POS-V4-CASHIER-OPS 2026-05-02] Cancel-with-reason dialog state.
            cancelDialog: {
                open: false,
                order: null,
                reason: '',
                error: '',
                busy: false,
            },
        };
    },
    computed: {
        sourceTabs() {
            return [
                { id: 'all', icon: '🧾', label: this.$t('pos.tracker.source_all') },
                { id: 'pos', icon: '🛒', label: this.$t('pos.tracker.source_pos') },
                { id: 'kiosk', icon: '🖥️', label: this.$t('pos.tracker.source_kiosk') },
                { id: 'online', icon: '🌐', label: this.$t('pos.tracker.source_online') },
            ];
        },
        filteredOrders() {
            const q = this.filters.query.toLowerCase();
            const src = this.filters.source;
            return this.orders.filter((o) => {
                if (src !== 'all' && this.sourceOf(o) !== src) return false;
                if (q) {
                    const hay = String(o.queue_number || '') + ' ' + String(o.order_serial_no || '') + ' ' + String(o.user?.name || '') + ' ' + String(o.user?.first_name || '');
                    if (!hay.toLowerCase().includes(q)) return false;
                }
                return true;
            });
        },
        ordersByStatus() {
            const buckets = { accept: [], preparing: [], prepared: [], delivered: [] };
            for (const o of this.filteredOrders) {
                const s = parseInt(o.order_status ?? o.status ?? 0, 10);
                if (s === orderStatusEnum.ACCEPT) buckets.accept.push(o);
                else if (s === orderStatusEnum.PREPARING) buckets.preparing.push(o);
                else if (s === orderStatusEnum.PREPARED) buckets.prepared.push(o);
                else if (s === orderStatusEnum.DELIVERED) buckets.delivered.push(o);
            }
            // Sort each bucket: oldest first for active queues, newest first for delivered.
            buckets.accept.sort((a, b) => this._tsOf(a) - this._tsOf(b));
            buckets.preparing.sort((a, b) => this._tsOf(a) - this._tsOf(b));
            buckets.prepared.sort((a, b) => this._tsOf(a) - this._tsOf(b));
            buckets.delivered.sort((a, b) => this._tsOf(b) - this._tsOf(a));
            return buckets;
        },
        columns() {
            const b = this.ordersByStatus;
            return [
                {
                    id: 'accept',
                    label: this.$t('pos.tracker.col_accept'),
                    icon: '🧾',
                    tone: 'amber',
                    orders: b.accept,
                    emptyIcon: '✓',
                    emptyLabel: this.$t('pos.tracker.empty_accept'),
                },
                {
                    id: 'preparing',
                    label: this.$t('pos.tracker.col_preparing'),
                    icon: '🍳',
                    tone: 'primary',
                    orders: b.preparing,
                    emptyIcon: '⏳',
                    emptyLabel: this.$t('pos.tracker.empty_preparing'),
                },
                {
                    id: 'prepared',
                    label: this.$t('pos.tracker.col_prepared'),
                    icon: '🛎️',
                    tone: 'green',
                    highlight: true,
                    orders: b.prepared,
                    emptyIcon: '—',
                    emptyLabel: this.$t('pos.tracker.empty_prepared'),
                },
                {
                    id: 'delivered',
                    label: this.$t('pos.tracker.col_delivered'),
                    icon: '✅',
                    tone: 'muted',
                    orders: b.delivered,
                    emptyIcon: '—',
                    emptyLabel: this.$t('pos.tracker.empty_delivered'),
                },
            ];
        },
        stats() {
            const b = this.ordersByStatus;
            return {
                active: b.accept.length + b.preparing.length + b.prepared.length,
                ready: b.prepared.length,
                todayCount: this.orders.length,
            };
        },
    },
    mounted() {
        this.fetchOrders();
        this._subscribeEcho();
        this._bindWsService();
        this._startPolling();
    },
    beforeUnmount() {
        this._unsubscribeEcho();
        this._unbindWsService();
        this._stopPolling();
        Object.values(this._freshTimers).forEach((t) => clearTimeout(t));
    },
    methods: {
        authBranchId() {
            const candidates = [
                this.$store.getters['auth/authBranchId'],
                this.$store.getters.authBranchId,
                this.$store.state?.auth?.authBranchId,
            ];
            for (const c of candidates) {
                if (c === '' || c == null) continue;
                const v = parseInt(c, 10);
                if (Number.isFinite(v)) return v;
            }
            return 0;
        },
        _bindWsService() {
            const ws = window._wsService;
            if (!ws) return;
            this._onWsConnected = () => { this.realtimeConnected = true; this._restartPolling(); this.fetchOrders(); };
            this._onWsDisconnected = () => { this.realtimeConnected = false; this._restartPolling(); };
            ws.on('connected', this._onWsConnected);
            ws.on('disconnected', this._onWsDisconnected);
        },
        _unbindWsService() {
            const ws = window._wsService;
            if (!ws) return;
            if (this._onWsConnected) ws.off('connected', this._onWsConnected);
            if (this._onWsDisconnected) ws.off('disconnected', this._onWsDisconnected);
        },
        _subscribeEcho() {
            if (!window.Echo) return;
            const branchId = this.authBranchId();
            if (branchId <= 0) return;
            try {
                this._eventSub = onEvents(branchId, [
                    { broadcastAs: 'OrderCreated', handler: () => this.fetchOrders() },
                    {
                        broadcastAs: 'OrderStatusChanged',
                        handler: (event) => {
                            const data = event?.payload || {};
                            const newStatus = parseInt(data.new_status, 10);
                            const oid = parseInt(data.order_id, 10);
                            if (newStatus === orderStatusEnum.PREPARED && oid) {
                                this._markFresh(oid);
                            }
                            this.fetchOrders();
                        },
                    },
                    { broadcastAs: 'OrderPaidAtCounter', handler: () => this.fetchOrders() },
                ]);
            } catch (e) {
                /* echo auth failed — polling fallback */
            }
        },
        _unsubscribeEcho() {
            try { this._eventSub?.unsubscribe(); } catch (e) { /* defensive */ }
            this._eventSub = null;
        },
        _pollInterval() {
            return this.realtimeConnected ? POLL_WS_MS : POLL_NO_WS_MS;
        },
        _startPolling() {
            this._stopPolling();
            this._pollTimer = setInterval(() => this.fetchOrders(), this._pollInterval());
        },
        _stopPolling() {
            if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; }
        },
        _restartPolling() {
            this._startPolling();
        },
        _markFresh(orderId) {
            const id = parseInt(orderId, 10);
            if (!id) return;
            this.newReadyIds = new Set([...this.newReadyIds, id]);
            if (this._freshTimers[id]) clearTimeout(this._freshTimers[id]);
            this._freshTimers[id] = setTimeout(() => {
                const next = new Set(this.newReadyIds);
                next.delete(id);
                this.newReadyIds = next;
                delete this._freshTimers[id];
            }, FRESH_HIGHLIGHT_MS);
        },
        _tsOf(o) {
            if (!o) return 0;
            const t = o.created_at || o.updated_at;
            if (!t) return 0;
            const v = new Date(t).getTime();
            return Number.isFinite(v) ? v : 0;
        },
        async fetchOrders() {
            this.loading = this.orders.length === 0;
            try {
                const today = this._todayRange();
                const res = await this.$store.dispatch('posOrder/lists', {
                    per_page: 100,
                    from_date: today.from,
                    to_date: today.to,
                    vuex: false,
                });
                const data = res?.data?.data || [];
                this.orders = Array.isArray(data) ? data : [];
            } catch (e) {
                /* surface error sparingly to avoid alert fatigue */
            } finally {
                this.loading = false;
            }
        },
        _todayRange() {
            const d = new Date();
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return { from: `${y}-${m}-${day}`, to: `${y}-${m}-${day}` };
        },
        async markDelivered(order) {
            if (!order || order._delivering) return;
            order._delivering = true;
            try {
                // [POS-V4-CASHIER-OPS 2026-05-02 FIX] Backend OrderStatusRequest
                // validates `status`, not `order_status` — previous cycle shipped
                // the wrong field name which silently no-op'd validation. Aligned
                // with PosOrderShowComponent canonical usage.
                await this.$store.dispatch('posOrder/changeStatus', {
                    id: order.id,
                    status: orderStatusEnum.DELIVERED,
                });
                await this.fetchOrders();
            } catch (e) {
                const msg = e?.response?.data?.message || this.$t('message.something_wrong');
                alertService.error(msg);
                order._delivering = false;
            }
        },
        // [POS-V4-CASHIER-OPS 2026-05-02] One-click reprint.
        // Fetches the full order (we only hold the lightweight list payload)
        // and opens the existing ReceiptComponent modal, which carries its own
        // print buttons (kitchen + client) — fiscal compteur is updated by the
        // existing `pos.print` endpoint, NOT here. We're a pure UI shortcut.
        async requestReprint(order) {
            if (!order || !order.id) return;
            if (this.reprintBusyId === order.id) return;
            this.reprintBusyId = order.id;
            try {
                const res = await this.$store.dispatch('posOrder/show', order.id);
                const fullOrder = res?.data?.data;
                if (!fullOrder || !fullOrder.id) {
                    throw new Error('empty');
                }
                this.reprintOrder = fullOrder;
                this.$nextTick(() => {
                    appService.modalShow('#receiptModal');
                });
            } catch (e) {
                alertService.error(this.$t('pos.reprint_error'));
            } finally {
                this.reprintBusyId = null;
            }
        },
        // [POS-V4-CASHIER-OPS 2026-05-02] Cancel-with-reason flow.
        openCancelDialog(order) {
            this.cancelDialog = {
                open: true,
                order,
                reason: '',
                error: '',
                busy: false,
            };
            this.$nextTick(() => {
                try { this.$refs.cancelReasonInput?.focus(); } catch (e) { /* defensive */ }
            });
        },
        closeCancelDialog() {
            if (this.cancelDialog.busy) return;
            this.cancelDialog = {
                open: false,
                order: null,
                reason: '',
                error: '',
                busy: false,
            };
        },
        async confirmCancelOrder() {
            const dlg = this.cancelDialog;
            if (!dlg.open || !dlg.order || dlg.busy) return;
            const reason = String(dlg.reason || '').trim();
            if (reason.length < 3) {
                this.cancelDialog.error = this.$t('pos.cancel_order_reason_required');
                return;
            }
            this.cancelDialog.busy = true;
            this.cancelDialog.error = '';
            try {
                // Backend OrderService::changeStatus accepts `reason` for the
                // CANCELED transition (validates required|max:700, persists on
                // order, dispatches OrderStatusChanged with reason). We rely on
                // that single endpoint — no schema change here.
                await this.$store.dispatch('posOrder/changeStatus', {
                    id: dlg.order.id,
                    status: orderStatusEnum.CANCELED,
                    reason,
                });
                this.cancelDialog.busy = false;
                this.closeCancelDialog();
                alertService.success(this.$t('pos.cancel_order_done'));
                await this.fetchOrders();
            } catch (e) {
                this.cancelDialog.busy = false;
                const msg = e?.response?.data?.message
                    || e?.response?.data?.errors?.reason?.[0]
                    || this.$t('pos.cancel_order_error');
                this.cancelDialog.error = msg;
            }
        },
        sourceOf(o) {
            const surface = String(o.source_surface || o._origin || '').toLowerCase();
            if (surface === 'kiosk') return 'kiosk';
            if (surface === 'pos') return 'pos';
            if (surface === 'online') return 'online';
            const ot = parseInt(o.order_type, 10);
            // Heuristics fallback when source_surface is missing
            if (Number.isFinite(ot)) {
                if (ot === 17 || ot === 18) return 'kiosk';
                if (ot === 15 || ot === 20) return 'pos';
            }
            return 'pos';
        },
        sourceIcon(o) {
            const s = this.sourceOf(o);
            if (s === 'kiosk') return '🖥️';
            if (s === 'online') return '🌐';
            return '🛒';
        },
        customerLabel(o) {
            const u = o.user || {};
            const n = u.name || [u.first_name, u.last_name].filter(Boolean).join(' ');
            return n || o.customer_name || '';
        },
        itemsPreview(o) {
            const items = Array.isArray(o.order_items) ? o.order_items : [];
            return items.slice(0, 3);
        },
        extraItemsCount(o) {
            const items = Array.isArray(o.order_items) ? o.order_items : [];
            return Math.max(0, items.length - 3);
        },
        formatPrice(v) {
            const n = Number(v) || 0;
            try {
                return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(n);
            } catch (e) {
                return n.toFixed(2) + ' €';
            }
        },
        formatTime(iso) {
            if (!iso) return '';
            try {
                return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            } catch (e) { return ''; }
        },
        elapsedShort(iso) {
            if (!iso) return '';
            const t = new Date(iso).getTime();
            if (!Number.isFinite(t)) return '';
            const diff = Math.max(0, Date.now() - t);
            const mins = Math.floor(diff / 60000);
            if (mins < 1) return this.$t('pos.tracker.now');
            if (mins < 60) return mins + ' min';
            const h = Math.floor(mins / 60);
            const m = mins % 60;
            return h + 'h' + (m < 10 ? '0' + m : m);
        },
    },
};
</script>

<style scoped>
/* =============================================================================
   PosOrdersTrackerComponent — POS V5 Design Convergence (refonte 2026-05-02)
   -----------------------------------------------------------------------------
   Mission : CV1-POS-DESIGN-CONVERGENCE-001
   Doc plan : §3.6
   - Approche chirurgicale : on remappe les tokens scoped --pos-tracker-*
     aux tokens V5 globaux. Tous les styles existants (excellents) prennent
     automatiquement la palette warm V5 sans toucher la structure DOM.
   - Bordure left colorée par status (Q4 plan : signal scan visuel rapide)
   ============================================================================= */
.pos-tracker-shell {
    /* Remap scoped tokens → V5 globals */
    --pos-tracker-bg: var(--pos-v5-bg-app);
    --pos-tracker-card-bg: var(--pos-v5-bg-panel);
    --pos-tracker-border: var(--pos-v5-border);
    --pos-tracker-text: var(--pos-v5-ink);
    --pos-tracker-muted: var(--pos-v5-ink-soft);
    --pos-tracker-primary: var(--pos-v5-brand-red);
    --pos-tracker-primary-soft: var(--pos-v5-brand-red-soft);
    --pos-tracker-amber: var(--pos-v5-warning);
    --pos-tracker-amber-soft: var(--pos-v5-warning-soft);
    --pos-tracker-green: var(--pos-v5-success);
    --pos-tracker-green-soft: var(--pos-v5-success-soft);
    --pos-tracker-muted-soft: var(--pos-v5-bg-subtle);

    min-height: 100dvh;
    background: var(--pos-tracker-bg);
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5) var(--pos-v5-space-6);
    color: var(--pos-tracker-text);
    font-family: var(--pos-v5-font-sans);
}

.pos-tracker-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--pos-v5-space-4) var(--pos-v5-space-6);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-5);
    border-radius: var(--pos-v5-radius-lg);
    background: var(--pos-tracker-card-bg);
    border: 1px solid var(--pos-tracker-border);
    border-left: 4px solid var(--pos-v5-brand-red);
    box-shadow: var(--pos-v5-shadow-md);
    margin-bottom: var(--pos-v5-space-4);
}

.pos-tracker-eyebrow {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.08em;
    color: var(--pos-tracker-muted);
    text-transform: uppercase;
    margin: 0 0 4px;
}

.pos-tracker-title {
    font-size: 22px;
    font-weight: 700;
    color: var(--pos-tracker-text);
    margin: 0 0 6px;
    line-height: 1.1;
}

.pos-tracker-status-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px 16px;
    font-size: 13px;
    color: var(--pos-tracker-muted);
}

.pos-tracker-status-row strong {
    color: var(--pos-tracker-text);
    font-weight: 700;
}

.pos-tracker-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: var(--pos-tracker-green-soft);
    color: #166534;
    border: 1px solid rgba(26, 183, 89, 0.25);
}

.pos-tracker-status-pill--ready {
    animation: pos-tracker-soft-pulse 2.4s ease-in-out infinite;
}

.pos-tracker-bar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.pos-tracker-search {
    position: relative;
    display: flex;
    align-items: center;
    background: #F7F7FC;
    border: 1px solid var(--pos-tracker-border);
    border-radius: 10px;
    padding: 0 10px;
    min-width: 200px;
}

.pos-tracker-search i {
    color: var(--pos-tracker-muted);
    font-size: 14px;
}

.pos-tracker-search input {
    flex: 1;
    border: 0;
    background: transparent;
    padding: 8px 6px;
    font-size: 13px;
    color: var(--pos-tracker-text);
    outline: none;
    min-width: 0;
}

.pos-tracker-search input::placeholder {
    color: var(--pos-tracker-muted);
}

.pos-tracker-search-clear {
    background: transparent;
    border: 0;
    color: var(--pos-tracker-muted);
    cursor: pointer;
    font-size: 14px;
    padding: 0 4px;
}

.pos-tracker-source-tabs {
    display: inline-flex;
    background: #F7F7FC;
    border: 1px solid var(--pos-tracker-border);
    border-radius: 10px;
    padding: 3px;
    gap: 2px;
}

.pos-tracker-source-tab {
    background: transparent;
    border: 0;
    padding: 6px 10px;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 600;
    color: var(--pos-tracker-muted);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: background 0.15s ease, color 0.15s ease;
}

.pos-tracker-source-tab:hover {
    background: rgba(176, 0, 77, 0.06);
    color: var(--pos-tracker-text);
}

.pos-tracker-source-tab.is-active {
    background: var(--pos-tracker-primary);
    color: #fff;
}

.pos-tracker-source-tab-icon {
    font-size: 14px;
}

.pos-tracker-history-link,
.pos-tracker-customer-link,
.pos-tracker-back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid var(--pos-tracker-border);
    background: #fff;
    color: var(--pos-tracker-text);
    text-decoration: none;
    transition: background 0.15s ease, border-color 0.15s ease;
}

.pos-tracker-history-link:hover,
.pos-tracker-customer-link:hover,
.pos-tracker-back-link:hover {
    background: var(--pos-tracker-primary-soft);
    border-color: var(--pos-tracker-primary);
    color: var(--pos-tracker-primary);
}

.pos-tracker-rt-warn {
    margin-bottom: 12px;
    padding: 8px 14px;
    border-radius: 10px;
    background: #FEF3C7;
    color: #92400E;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid rgba(217, 119, 6, 0.2);
}

.pos-tracker-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    align-items: start;
}

@media (max-width: 1280px) {
    .pos-tracker-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 720px) {
    .pos-tracker-grid { grid-template-columns: 1fr; }
}

.pos-tracker-col {
    background: var(--pos-tracker-card-bg);
    border: 1px solid var(--pos-tracker-border);
    border-radius: 14px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 280px;
    max-height: calc(100dvh - 160px);
}

.pos-tracker-col-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border-bottom: 1px solid var(--pos-tracker-border);
    flex-shrink: 0;
}

.pos-tracker-col-head h2 {
    font-size: 14px;
    font-weight: 700;
    color: var(--pos-tracker-text);
    margin: 0;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.pos-tracker-col-icon { font-size: 18px; }

.pos-tracker-col-count {
    background: var(--pos-tracker-muted-soft);
    color: var(--pos-tracker-text);
    border-radius: 999px;
    padding: 2px 10px;
    font-size: 12px;
    font-weight: 700;
    min-width: 26px;
    text-align: center;
}

.pos-tracker-col--amber .pos-tracker-col-count { background: var(--pos-tracker-amber-soft); color: var(--pos-tracker-amber); }
.pos-tracker-col--primary .pos-tracker-col-count { background: var(--pos-tracker-primary-soft); color: var(--pos-tracker-primary); }
.pos-tracker-col--green .pos-tracker-col-count { background: var(--pos-tracker-green-soft); color: #166534; }
.pos-tracker-col--muted .pos-tracker-col-count { background: var(--pos-tracker-muted-soft); color: var(--pos-tracker-muted); }

.pos-tracker-col--green {
    border-color: rgba(26, 183, 89, 0.4);
    box-shadow: 0 0 0 1px rgba(26, 183, 89, 0.12) inset;
}

.pos-tracker-col--green.is-pulse {
    animation: pos-tracker-col-glow 2.6s ease-in-out infinite;
}

@keyframes pos-tracker-col-glow {
    0%, 100% { box-shadow: 0 0 0 1px rgba(26, 183, 89, 0.12) inset; }
    50%      { box-shadow: 0 0 0 2px rgba(26, 183, 89, 0.32) inset, 0 0 18px rgba(26, 183, 89, 0.18); }
}

.pos-tracker-col-body {
    padding: 10px;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.pos-tracker-cards {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.pos-tracker-col-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px 16px;
    color: var(--pos-tracker-muted);
    font-size: 13px;
    text-align: center;
    gap: 8px;
}

.pos-tracker-col-empty-icon {
    font-size: 28px;
    opacity: 0.55;
}

.pos-tracker-card {
    border: 1px solid var(--pos-tracker-border);
    border-radius: 12px;
    background: #fff;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
}

.pos-tracker-card:hover {
    box-shadow: 0 6px 16px rgba(31, 31, 57, 0.08);
    transform: translateY(-1px);
}

/* Q4 plan : bordure left 4px par status — scan visuel rapide pour caissier */
.pos-tracker-card--amber { border-left: 4px solid var(--pos-tracker-amber); }
.pos-tracker-card--primary { border-left: 4px solid var(--pos-tracker-primary); }
.pos-tracker-card--green { border-left: 4px solid var(--pos-tracker-green); }
.pos-tracker-card--muted { border-left: 4px solid var(--pos-v5-border-strong); opacity: 0.85; }

.pos-tracker-card.is-fresh { animation: pos-tracker-card-pop 1.2s ease-out 1; border-color: var(--pos-tracker-green); }

@keyframes pos-tracker-card-pop {
    0%   { transform: scale(0.96); box-shadow: 0 0 0 0 rgba(26, 183, 89, 0.45); }
    40%  { transform: scale(1.02); box-shadow: 0 0 0 8px rgba(26, 183, 89, 0.18); }
    100% { transform: scale(1);    box-shadow: 0 0 0 0 rgba(26, 183, 89, 0.0); }
}

.pos-tracker-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.pos-tracker-card-num {
    font-size: 17px;
    font-weight: 800;
    color: var(--pos-tracker-text);
    letter-spacing: -0.01em;
}

.pos-tracker-card-source {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: var(--pos-tracker-muted-soft);
    font-size: 14px;
}

.pos-tracker-card-source--kiosk { background: #EEF2FF; }
.pos-tracker-card-source--online { background: #ECFEFF; }

.pos-tracker-card-time {
    margin-left: auto;
    font-size: 12px;
    font-weight: 600;
    color: var(--pos-tracker-muted);
}

.pos-tracker-card-customer {
    font-size: 12px;
    color: var(--pos-tracker-muted);
    display: flex;
    align-items: center;
    gap: 6px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-tracker-card-customer span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-tracker-card-items {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.pos-tracker-card-items li {
    display: flex;
    gap: 6px;
    font-size: 12px;
    color: var(--pos-tracker-text);
    line-height: 1.35;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-tracker-card-qty {
    font-weight: 700;
    color: var(--pos-tracker-primary);
    flex-shrink: 0;
    min-width: 22px;
}

.pos-tracker-card-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-tracker-card-more {
    color: var(--pos-tracker-muted);
    font-style: italic;
    font-size: 11px;
}

.pos-tracker-card-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 6px;
    border-top: 1px dashed var(--pos-tracker-border);
}

.pos-tracker-card-total {
    font-size: 14px;
    font-weight: 700;
    color: var(--pos-tracker-text);
}

.pos-tracker-card-actions {
    display: inline-flex;
    gap: 6px;
}

.pos-tracker-card-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    border-radius: 8px;
    border: 1px solid var(--pos-tracker-border);
    background: #fff;
    color: var(--pos-tracker-text);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.pos-tracker-card-btn:hover {
    background: var(--pos-tracker-primary-soft);
    border-color: var(--pos-tracker-primary);
    color: var(--pos-tracker-primary);
}

.pos-tracker-card-btn--primary {
    background: var(--pos-tracker-green);
    border-color: var(--pos-tracker-green);
    color: #fff;
}

.pos-tracker-card-btn--primary:hover {
    background: #15a151;
    border-color: #15a151;
    color: #fff;
}

.pos-tracker-card-btn--primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* [POS-V4-CASHIER-OPS 2026-05-02] danger variant for cancel-order */
.pos-tracker-card-btn--danger {
    border-color: rgba(239, 68, 68, 0.4);
    color: #b91c1c;
    background: #fff;
}
.pos-tracker-card-btn--danger:hover {
    background: #fee2e2;
    border-color: #ef4444;
    color: #991b1b;
}
.pos-tracker-card-btn--danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* [POS-V4-CASHIER-OPS 2026-05-02] cancel-with-reason inline dialog */
.pos-tracker-cancel-overlay {
    position: fixed;
    inset: 0;
    z-index: 2400;
    background: rgba(15, 23, 42, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.pos-tracker-cancel-card {
    width: min(480px, 100%);
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.24);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.pos-tracker-cancel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--pos-tracker-border);
}
.pos-tracker-cancel-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: var(--pos-tracker-text);
}
.pos-tracker-cancel-close {
    width: 32px;
    height: 32px;
    border-radius: 999px;
    border: 1px solid var(--pos-tracker-border);
    background: #fff;
    color: var(--pos-tracker-muted);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}
.pos-tracker-cancel-close:hover {
    background: #fee2e2;
    color: #b91c1c;
}
.pos-tracker-cancel-body {
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.pos-tracker-cancel-target {
    margin: 0 0 4px;
    font-size: 13px;
    color: var(--pos-tracker-muted);
}
.pos-tracker-cancel-target strong {
    color: var(--pos-tracker-text);
    font-weight: 700;
}
.pos-tracker-cancel-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--pos-tracker-text);
}
.pos-tracker-cancel-textarea {
    width: 100%;
    min-height: 84px;
    padding: 10px 12px;
    border: 1px solid var(--pos-tracker-border);
    border-radius: 10px;
    font-size: 14px;
    color: var(--pos-tracker-text);
    background: #f9fafb;
    resize: vertical;
    transition: border-color 0.15s ease, background 0.15s ease;
}
.pos-tracker-cancel-textarea:focus {
    outline: none;
    border-color: #ef4444;
    background: #fff;
}
.pos-tracker-cancel-error {
    margin: 0;
    padding: 8px 10px;
    background: #fee2e2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    color: #991b1b;
    font-size: 12px;
    font-weight: 600;
}
.pos-tracker-cancel-foot {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 20px;
    border-top: 1px solid var(--pos-tracker-border);
    background: #f9fafb;
}
.pos-tracker-cancel-btn {
    height: 38px;
    padding: 0 18px;
    border-radius: 10px;
    border: 1px solid var(--pos-tracker-border);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.pos-tracker-cancel-btn--ghost {
    background: #fff;
    color: var(--pos-tracker-text);
}
.pos-tracker-cancel-btn--ghost:hover {
    background: var(--pos-tracker-muted-soft);
}
.pos-tracker-cancel-btn--ghost:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.pos-tracker-cancel-btn--danger {
    background: #ef4444;
    border-color: #ef4444;
    color: #fff;
}
.pos-tracker-cancel-btn--danger:hover {
    background: #dc2626;
    border-color: #dc2626;
}
.pos-tracker-cancel-btn--danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pos-tracker-loading {
    margin-top: 24px;
    text-align: center;
    color: var(--pos-tracker-muted);
}

.pos-tracker-spinner {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 3px solid var(--pos-tracker-border);
    border-top-color: var(--pos-tracker-primary);
    margin: 0 auto 10px;
    animation: pos-tracker-spin 0.9s linear infinite;
}

@keyframes pos-tracker-spin {
    to { transform: rotate(360deg); }
}

@keyframes pos-tracker-soft-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(26, 183, 89, 0); }
    50%      { box-shadow: 0 0 0 6px rgba(26, 183, 89, 0.18); }
}

/* Transition group animations for cards moving between columns */
.pos-tracker-card-enter-active,
.pos-tracker-card-leave-active {
    transition: opacity 0.25s ease, transform 0.25s ease;
}

.pos-tracker-card-enter-from { opacity: 0; transform: translateY(-6px); }
.pos-tracker-card-leave-to { opacity: 0; transform: translateY(6px); }

@media (prefers-reduced-motion: reduce) {
    .pos-tracker-status-pill--ready,
    .pos-tracker-col--green.is-pulse,
    .pos-tracker-card.is-fresh { animation: none; }
}
</style>
