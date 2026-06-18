<template>
    <LoadingComponent :props="loading" />
    <div class="row">
        <div class="col-12">
            <BreadcrumbComponent />
        </div>
        <div class="col-12">
            <div class="db-card enc-card">
                <div class="db-card-header border-none enc-header">
                    <div>
                        <h3 class="db-card-title">{{ $t('menu.encaissement') }}</h3>
                        <p class="enc-subtitle">{{ $t('label.encaisser_queue_subtitle') }}</p>
                    </div>
                    <div class="enc-header-actions">
                        <!-- [micro-ux 2026-06-18] count chip gets an accessible name so a
                             screen reader announces "N order(s) to collect" not a bare number. -->
                        <span class="enc-count-chip" :aria-label="$t('label.encaisser_queue_count', { count: orders.length })">{{ orders.length }}</span>
                        <button class="db-btn py-2 text-white bg-primary" @click.prevent="fetchPending">
                            <i class="lab lab-refresh-line lab-font-size-16"></i>
                            <span>{{ $t('button.refresh') }}</span>
                        </button>
                    </div>
                </div>

                <!-- [micro-ux 2026-06-18] polite announcer — fires only when the queue GROWS
                     so a cashier on this screen hears a newly-arrived order without watching it. -->
                <div class="sr-only" aria-live="polite">{{ queueAnnouncement }}</div>

                <div class="enc-body">
                    <div v-if="orders.length === 0 && !fetchError" class="enc-empty">
                        <div class="enc-empty-icon">✅</div>
                        <p class="enc-empty-title">{{ $t('label.encaisser_queue_empty') }}</p>
                    </div>

                    <!-- [micro-ux 2026-06-18] surface a failed fetch instead of masquerading
                         as the ✅ empty-state; offer an explicit retry. Gated on empty so a
                         background-poll failure never clobbers the last-good queue. -->
                    <div v-else-if="fetchError && orders.length === 0" class="enc-empty">
                        <div class="enc-empty-icon">⚠️</div>
                        <p class="enc-empty-title">{{ $t('message.something_wrong') }}</p>
                        <button class="enc-collect-btn mt-3" @click.prevent="fetchPending">
                            {{ $t('button.retry') }}
                        </button>
                    </div>

                    <div v-else class="enc-grid">
                        <div v-for="order in orders" :key="order.id" class="enc-ticket">
                            <div class="enc-ticket-top">
                                <span class="enc-origin-badge" :class="originBadge(order).cls">
                                    {{ originBadge(order).label }}
                                </span>
                                <span class="enc-queue" v-if="order.queue_number">N°{{ order.queue_number }}</span>
                            </div>
                            <div class="enc-ticket-customer">{{ customerName(order) }}</div>
                            <ul class="enc-ticket-items" v-if="order.order_items && order.order_items.length">
                                <li v-for="(it, idx) in order.order_items.slice(0, 4)" :key="idx">
                                    {{ it.quantity }}× {{ itemName(it) }}
                                </li>
                                <li v-if="order.order_items.length > 4" class="enc-more">
                                    +{{ order.order_items.length - 4 }}…
                                </li>
                            </ul>
                            <div class="enc-ticket-bottom">
                                <span class="enc-amount">{{ formatPrice(orderAmount(order)) }}</span>
                                <!-- [micro-ux 2026-06-18] per-button accessible name disambiguates the
                                     repeated "Encaisser" buttons by queue/order number for screen readers. -->
                                <button class="enc-collect-btn" :aria-label="$t('label.encaisser') + ' N°' + (order.queue_number || order.id)" @click.prevent="openEncaissement(order)">
                                    {{ $t('label.encaisser') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Shared counter-collect modal (cash / card / mobile / ticket).
             It POSTs admin/pos/counter-collect/{id}/confirm itself; on @confirmed
             we refresh the queue so the now-paid order leaves the list. -->
        <PosCounterCollectModal
            :order="encaisseOrder"
            @confirmed="onEncaisseConfirmed"
            @cancel="encaisseOrder = null" />
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import BreadcrumbComponent from "../components/BreadcrumbComponent";
import PosCounterCollectModal from "../pos/PosCounterCollectModal.vue";
import appService from "../../../services/appService";
import alertService from "../../../services/alertService";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import { adminPriceMixin } from "../../../helpers/formatPrice";
import { onEvents } from "../../../services/eventContract";

export default {
    name: "EncaissementComponent",
    mixins: [adminPriceMixin],
    components: {
        LoadingComponent,
        BreadcrumbComponent,
        PosCounterCollectModal,
    },
    data() {
        return {
            loading: { isActive: false },
            orders: [],
            encaisseOrder: null,
            pollTimer: null,
            enums: { orderTypeEnum },
            // [micro-ux 2026-06-18] aria-live text (set only when the queue grows)
            // + error flag for the initial/manual fetch empty-state.
            queueAnnouncement: '',
            fetchError: false,
        };
    },
    mounted() {
        this.fetchPending();
        // Light poll so a cashier on this screen sees newly-arrived Borne
        // orders without a manual refresh. Cleared on unmount.
        this.pollTimer = setInterval(() => this.fetchPending(true), 20000);
        // [F-W5-01 sync heal 2026-06-03] Real-time push so newly-arrived Borne
        // orders + counter-collected ones reflect sub-second; the 20s poll above
        // stays as the WS-down fallback (mirrors KDS/OSS/tracker pattern).
        this.subscribeEcho();
    },
    beforeUnmount() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
        this.unsubscribeEcho();
    },
    methods: {
        fetchPending(silent = false) {
            if (!silent) this.loading.isActive = true;
            const prevCount = this.orders.length;
            axios.get('admin/pos/counter-collect/pending').then((res) => {
                const next = res.data?.data || [];
                // [micro-ux 2026-06-18] Announce ONLY on growth (a new order arrived),
                // not on every poll, so the screen reader isn't chatty.
                if (next.length > prevCount) {
                    this.queueAnnouncement = this.$t('label.encaisser_queue_new_arrival', { count: next.length });
                }
                this.orders = next;
                this.fetchError = false;
                this.loading.isActive = false;
            }).catch(() => {
                // [micro-ux 2026-06-18] Only flip the visible error flag on the
                // initial/manual fetch — a background poll failure stays silent and
                // keeps the last-good queue on screen.
                if (!silent) this.fetchError = true;
                this.loading.isActive = false;
            });
        },
        // [F-W5-01 sync heal 2026-06-03] Echo subscription mirrors KDS/OSS/tracker:
        // branch staff (branch_id>0) get sub-second updates; admin (branch 0) keeps
        // the 20s poll fallback. Re-fetch on OrderCreated (new Borne arrival),
        // OrderPaidAtCounter (collected → drops off), OrderStatusChanged (cancel/refund).
        // [F-W5-01] Robust branch-id resolution mirrors PreparingAndReadyComponent:
        // the auth store module is NOT namespaced, so the bare `authBranchId` getter is
        // the canonical path; the namespaced + state paths are belt-and-suspenders.
        authBranchId() {
            const candidates = [
                this.$store.getters['auth/authBranchId'],
                this.$store.getters.authBranchId,
                this.$store.state?.auth?.authBranchId,
            ];
            for (const c of candidates) {
                if (c === '' || c === null || typeof c === 'undefined') continue;
                const v = parseInt(c, 10);
                if (Number.isFinite(v)) return v;
            }
            return 0;
        },
        subscribeEcho() {
            if (!window.Echo) return;
            const branchId = this.authBranchId();
            if (branchId <= 0) return;
            this.unsubscribeEcho();
            try {
                this._eventSub = onEvents(branchId, [
                    { broadcastAs: 'OrderCreated', handler: () => this.fetchPending(true) },
                    { broadcastAs: 'OrderPaidAtCounter', handler: () => this.fetchPending(true) },
                    { broadcastAs: 'OrderStatusChanged', handler: () => this.fetchPending(true) },
                ]);
            } catch (e) {
                console.warn('[Encaissement] Echo subscription failed:', e.message);
            }
        },
        unsubscribeEcho() {
            try { this._eventSub?.unsubscribe(); } catch (_) { /* noop */ }
            this._eventSub = null;
        },
        // Origin resolver — source_surface is the reliable signal. Today the
        // pending endpoint returns Borne (kiosk) orders; once delta-(B) routes
        // POS walk-in through PENDING_COUNTER, Caisse rows appear here too.
        originBadge(order) {
            const surface = String(order.source_surface || '').toLowerCase();
            if (surface === 'kiosk') return { label: this.$t('label.kiosk'), cls: 'origin-borne' };
            if (surface === 'pos') return { label: this.$t('label.caisse'), cls: 'origin-caisse' };
            if (surface === 'web' || surface === 'app' || surface === 'mobile') {
                return { label: this.$t('label.online'), cls: 'origin-online' };
            }
            return { label: this.$t('label.kiosk'), cls: 'origin-borne' };
        },
        customerName(order) {
            return order.user?.name || order.customer_name || this.$t('label.guest');
        },
        itemName(it) {
            return it.item_name || it.name || it.orderItem?.name || it.order_item?.name || '';
        },
        orderAmount(order) {
            return order.cash_pending_amount ?? order.total ?? order.order_amount ?? 0;
        },
        openEncaissement(order) {
            if (!order || !order.id) return;
            const amount = this.orderAmount(order);
            // PosCounterCollectModal reads order.total — map the amount due onto it.
            this.encaisseOrder = { ...order, total: amount };
        },
        onEncaisseConfirmed() {
            this.encaisseOrder = null;
            alertService.success(this.$t('label.encaisser_success', { order: '' }));
            this.fetchPending();
        },
    },
};
</script>

<style scoped>
.enc-card {
    border-radius: var(--pos-v5-radius-lg);
    box-shadow: var(--pos-v5-shadow-md);
    border: 1px solid var(--pos-v5-border);
    background: var(--pos-v5-bg-panel);
    overflow: hidden;
}
.enc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: linear-gradient(180deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel) 80%);
    border-bottom: 1px solid var(--pos-v5-border);
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5);
}
.db-card-title {
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h5);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-ink);
}
.enc-subtitle { color: var(--pos-v5-ink-soft); font-size: 0.85rem; margin-top: 0.15rem; }
.enc-header-actions { display: flex; align-items: center; gap: 0.75rem; }
.enc-count-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2.2rem;
    height: 2.2rem;
    border-radius: 9999px;
    background: var(--pos-v5-brand-red);
    color: #fff;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.enc-body { padding: var(--pos-v5-space-5); }
.enc-empty { text-align: center; padding: 3rem 1rem; color: var(--pos-v5-ink-soft); }
.enc-empty-icon { font-size: 2.5rem; }
.enc-empty-title { margin-top: 0.75rem; font-size: 1.05rem; font-weight: 600; }

.enc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 1rem;
}
.enc-ticket {
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-md);
    background: #fff;
    padding: 0.9rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    box-shadow: var(--pos-v5-shadow-sm);
}
.enc-ticket-top { display: flex; align-items: center; justify-content: space-between; }
.enc-origin-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 9999px;
    padding: 0.12rem 0.55rem;
    font-size: 0.74rem;
    font-weight: 700;
    border: 1px solid transparent;
}
.origin-borne { background: #fff7ed; color: #9a3412; border-color: #fed7aa; }
.origin-caisse { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
.origin-online { background: #f5f3ff; color: #5b21b6; border-color: #ddd6fe; }
.enc-queue {
    font-weight: 800;
    color: #9a3412;
    font-variant-numeric: tabular-nums;
}
/* [micro-ux 2026-06-18] self-contained sr-only so the aria-live announcer is
   visually hidden but still read, regardless of global utility availability. */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
/* [micro-ux 2026-06-18] long customer name truncates instead of pushing the card. */
.enc-ticket-customer { font-weight: 600; color: var(--pos-v5-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.enc-ticket-items { list-style: none; padding: 0; margin: 0; font-size: 0.82rem; color: var(--pos-v5-ink-soft); }
.enc-ticket-items li { line-height: 1.4; }
.enc-more { font-style: italic; }
.enc-ticket-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0.4rem;
    padding-top: 0.6rem;
    border-top: 1px dashed var(--pos-v5-border);
}
.enc-amount { font-weight: 800; font-size: 1.1rem; color: var(--pos-v5-ink); font-variant-numeric: tabular-nums; }
.enc-collect-btn {
    background: var(--pos-v5-brand-red);
    color: #fff;
    border: none;
    border-radius: var(--pos-v5-radius-md);
    /* [ux-perfection 2026-06-18 caisse-16"] the primary cash-collection action — raised to the 44px touch
       floor (was ~40px) for the seated cashier on the 16" touchscreen. */
    min-height: 44px;
    padding: 0.5rem 1.1rem;
    font-weight: 800;
    cursor: pointer;
    /* [micro-ux 2026-06-18] include transform so the :active press reads as a
       physical tap on the touchscreen. */
    transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                transform var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.enc-collect-btn:hover { background: var(--pos-v5-brand-red-dark); }
.enc-collect-btn:active { transform: translateY(1px); }
:deep(.db-btn.bg-primary) {
    background: var(--pos-v5-brand-red) !important;
    border-radius: var(--pos-v5-radius-md);
    font-weight: var(--pos-v5-weight-bold);
}
:deep(.db-btn.bg-primary:hover) { background: var(--pos-v5-brand-red-dark) !important; }
</style>
