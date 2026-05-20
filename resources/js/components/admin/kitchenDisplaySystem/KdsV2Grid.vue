<!--
  [kds/sprint-2 V-5 wrapper] Single FIFO 4×2 grid wrapper. Renders 8
  KdsOrderCard tiles sorted oldest top-left → newest bottom-right.

  Responsibilities (kept thin — the orchestrator passes orders + emits PATCH):
    - FIFO sort using created_at_iso/created_at (helpers/kdsDisplay::parseOrderCreatedMs)
    - Slot assignment for bump-bar shortcut [A]–[H]
    - Auto-transition watcher (single-chef ACCEPT → PREPARING) via shouldAutoTransition
    - 3s pending bump queue (chef taps Prêt → toast 3s → PATCH; undo cancels)
    - Keyboard [A]–[H] to bump corresponding slot
    - aria-live region for new card insertions / state changes

  Feature flag: kds.v2_enabled (URL ?v2=1, localStorage, or owner settings).
  Old 4-column layout stays in KitchenDisplaySystemComponent.vue v-else branch
  for instant rollback.
-->
<template>
  <div class="kds-v2" :dir="dir">
    <!-- Single banner zone -->
    <KdsStatusBanner
      :offline-since="offlineSince"
      :list-at-cap="listAtCap"
      :near-cap="visibleOrders.length"
      :fallback-mode="fallbackMode"
      :admin-polling-hint="adminPollingHint"
      :bump-local-only-notice="bumpLocalOnlyNotice"
    />

    <!-- Empty state -->
    <div v-if="visibleOrders.length === 0" class="kds-v2__empty">
      <div class="kds-v2__empty-illustration" aria-hidden="true">
        <div class="kds-v2__empty-glow"></div>
        <svg width="120" height="120" viewBox="0 0 64 64" fill="none" stroke="#9CA3AF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <ellipse cx="32" cy="42" rx="22" ry="6" fill="#F3F4F6" stroke="#D1D5DB" />
          <path d="M10 38a22 8 0 0 1 44 0" fill="white" />
          <path d="M14 30c0-4 6-8 18-8s18 4 18 8" />
          <circle cx="22" cy="24" r="1.5" fill="#9CA3AF" />
          <circle cx="32" cy="22" r="1.5" fill="#9CA3AF" />
          <circle cx="42" cy="24" r="1.5" fill="#9CA3AF" />
        </svg>
      </div>
      <div class="kds-v2__empty-title">{{ $t('label.kds_empty_state') }}</div>
      <div class="kds-v2__empty-sub">{{ $t('label.kds_empty_state_sub') }}</div>
    </div>

    <!-- FIFO Grid 4×2 -->
    <div v-else class="kds-v2__grid">
      <KdsOrderCard
        v-for="(o, idx) in visibleOrders.slice(0, 8)"
        :key="o.id"
        :order="o"
        :now="now"
        :shortcut="SHORTCUTS[idx]"
        @ready="onCtaTap(o.id, o.queue_number)"
      />
      <!-- placeholders to keep grid stable when <8 -->
      <div
        v-for="i in Math.max(0, 8 - visibleOrders.length)"
        :key="`ph-${i}`"
        class="kds-v2__placeholder"
      ></div>
    </div>

    <!-- Undo Toast (single at a time) -->
    <KdsUndoToast :toast="activeToast" @undo="onUndo" />

    <!-- aria-live region for screen readers -->
    <div class="sr-only" aria-live="polite" aria-atomic="true">{{ liveMessage }}</div>
  </div>
</template>

<script>
import KdsOrderCard from './KdsOrderCard.vue';
import KdsStatusBanner from './KdsStatusBanner.vue';
import KdsUndoToast from './KdsUndoToast.vue';
import {
    parseOrderCreatedMs,
} from '../../../helpers/kdsDisplay.js';
import {
    shouldAutoTransition,
    pickOldestAutoPromoteCandidate,
} from '../../../helpers/kdsAutoTransition.js';
import {
    ORDER_STATUS,
} from '../../../helpers/kdsState.js';

const SHORTCUTS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

export default {
    name: 'KdsV2Grid',
    components: { KdsOrderCard, KdsStatusBanner, KdsUndoToast },
    props: {
        orders: { type: Array, default: () => [] },
        dir: { type: String, default: 'ltr' },
        offlineSince: { type: Number, default: null },
        listAtCap: { type: Boolean, default: false },
        fallbackMode: { type: Boolean, default: false },
        adminPollingHint: { type: Boolean, default: false },
        bumpLocalOnlyNotice: { type: Boolean, default: false },
        // [Wave Q-2 2026-05-20] Default OFF. Owner override of the RESEARCH §4.3
        // single-chef auto-promote heuristic: cashier needs a consistent
        // CONFIRMÉE → EN PRÉPARATION → PRÊT flow across all tickets so the POS
        // suivi screen stays predictable. The parent KitchenDisplaySystemComponent
        // also pins `v2AutoTransitionEnabled = false` so this stays off
        // independently of the prop default for any caller that omits the binding.
        autoTransitionEnabled: { type: Boolean, default: false },
    },
    emits: ['change-status', 'auto-promote'],
    data() {
        return {
            now: Date.now(),
            tickerId: null,
            activeToast: null,
            pendingTimeoutId: null,
            liveMessage: '',
        };
    },
    computed: {
        SHORTCUTS() {
            return SHORTCUTS;
        },
        // FIFO: oldest first, then by id for stable ties.
        visibleOrders() {
            const arr = Array.isArray(this.orders) ? [...this.orders] : [];
            arr.sort((a, b) => {
                const ta = parseOrderCreatedMs(a);
                const tb = parseOrderCreatedMs(b);
                if (ta !== tb) {
                    return ta - tb;
                }
                return (parseInt(a?.id, 10) || 0) - (parseInt(b?.id, 10) || 0);
            });
            return arr;
        },
    },
    watch: {
        // Auto-transition watcher: when the queue updates AND no order is in
        // PREPARING AND there's a NEW order at the head, promote it.
        visibleOrders: {
            handler(newQ) {
                if (!this.autoTransitionEnabled) {
                    return;
                }
                const candidate = pickOldestAutoPromoteCandidate(newQ);
                if (candidate && shouldAutoTransition(candidate, newQ, true)) {
                    // Emit so the orchestrator can dispatch the PATCH through
                    // the existing store action — no duplicate axios pathway.
                    this.$emit('auto-promote', candidate.id);
                    this.liveMessage = this.$t('label.kds_aria_live_preparing', { id: candidate.queue_number || candidate.id });
                }
            },
            deep: false,
        },
    },
    mounted() {
        // Single global ticker — all cards read `this.now` reactively, no
        // per-card setInterval.
        this.tickerId = window.setInterval(() => {
            this.now = Date.now();
        }, 1000);
        window.addEventListener('keydown', this.onKey);
    },
    beforeUnmount() {
        if (this.tickerId) {
            window.clearInterval(this.tickerId);
            this.tickerId = null;
        }
        if (this.pendingTimeoutId) {
            window.clearTimeout(this.pendingTimeoutId);
            this.pendingTimeoutId = null;
        }
        window.removeEventListener('keydown', this.onKey);
    },
    methods: {
        onKey(e) {
            // [A]–[H] bumps the nth slot. Enter/Esc handled by KdsOrderCard.
            const idx = SHORTCUTS.indexOf(String(e.key || '').toUpperCase());
            if (idx >= 0 && idx < this.visibleOrders.length) {
                const o = this.visibleOrders[idx];
                if (o) {
                    // [Wave S-2 P-OWNER 2026-05-20] Cash-pending orders MUST NOT
                    // be bumped by keyboard shortcut. Mirror the UI gate that
                    // replaces the CTA with a passive badge for cash-at-counter
                    // orders awaiting cashier encaissement (Wave S-5). Without
                    // this, [A]–[H] would silently contradict the badge.
                    if (o.payment_pending_counter === true) {
                        return;
                    }
                    e.preventDefault();
                    this.onCtaTap(o.id, o.queue_number);
                }
            }
        },
        // [Wave Q-2 2026-05-20] Chef taps Prêt → optimistic toast 3s → PATCH single
        // step transition. Owner-reported bug: emitting PREPARED unconditionally
        // made an ACCEPT-state order skip PREPARING (server rejected with 422,
        // but the optimistic toast hid the failure). Mirror the legacy
        // `kdsBump` step ladder in `KitchenDisplaySystemComponent.vue:1716-1728`
        // and the server `OrderStateMachine::allows` rule (ACCEPT→PREPARING |
        // PREPARING→PREPARED). Single tap = one step; chef taps twice on a
        // CONFIRMÉE ticket to reach PRÊT.
        onCtaTap(orderId, queueNo) {
            // Cancel any previous pending bump (single-slot toast)
            if (this.pendingTimeoutId) {
                window.clearTimeout(this.pendingTimeoutId);
            }
            const order = this.visibleOrders.find((o) => o.id === orderId);
            const currentStatus = parseInt(order?.status ?? order?.rawStatus, 10);
            const nextStatus = currentStatus === ORDER_STATUS.ACCEPT
                ? ORDER_STATUS.PREPARING
                : ORDER_STATUS.PREPARED;
            const isFinalStep = nextStatus === ORDER_STATUS.PREPARED;
            const toastId = `bump-${orderId}-${Date.now()}`;
            this.activeToast = { id: toastId, orderId, queueNo, expiresAt: Date.now() + 3000 };
            this.pendingTimeoutId = window.setTimeout(() => {
                // Window expired — fire the PATCH for real.
                this.$emit('change-status', {
                    orderId,
                    status: nextStatus,
                });
                this.activeToast = null;
                this.pendingTimeoutId = null;
                this.liveMessage = isFinalStep
                    ? this.$t('label.kds_aria_live_ready', { id: queueNo || orderId })
                    : this.$t('label.kds_aria_live_preparing', { id: queueNo || orderId });
            }, 3000);
        },
        onUndo(toastId) {
            if (this.activeToast && this.activeToast.id === toastId) {
                if (this.pendingTimeoutId) {
                    window.clearTimeout(this.pendingTimeoutId);
                    this.pendingTimeoutId = null;
                }
                this.activeToast = null;
                this.liveMessage = this.$t('label.kds_undo_done');
            }
        },
    },
};
</script>

<style scoped>
.kds-v2 {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: #F9FAFB;
    position: relative;
    font-family: 'Inter', system-ui, sans-serif;
    min-height: 0;
}
[dir="rtl"] .kds-v2 {
    font-family: 'Noto Naskh Arabic', 'Inter', system-ui, sans-serif;
}

.kds-v2__grid {
    flex: 1;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    grid-template-rows: repeat(2, minmax(0, 1fr));
    gap: 16px;
    padding: 16px;
    min-height: 0;
}

/* 4K: 5 cols × 2 rows, more breathing room */
@media (min-width: 2560px) {
    .kds-v2__grid {
        grid-template-columns: repeat(5, minmax(0, 1fr));
        grid-template-rows: repeat(2, minmax(0, 1fr));
    }
}

.kds-v2__placeholder {
    border: 2px dashed #E5E7EB;
    border-radius: 12px;
    min-height: 200px;
}

.kds-v2__empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #9CA3AF;
    padding: 32px;
}
.kds-v2__empty-illustration {
    position: relative;
    width: 200px;
    height: 200px;
    margin-bottom: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.kds-v2__empty-glow {
    position: absolute;
    inset: 0;
    border-radius: 9999px;
    background: radial-gradient(closest-side, #F3F4F6, transparent 70%);
}
.kds-v2__empty-title {
    font-size: 32px;
    font-weight: 700;
    color: #374151;
}
.kds-v2__empty-sub {
    margin-top: 8px;
    font-size: 16px;
    color: #9CA3AF;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
