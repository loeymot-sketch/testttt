<!--
  [kds/sprint-2 V-5 wrapper] Single FIFO 4×2 grid wrapper. Renders 8
  KdsOrderCard tiles sorted oldest top-left → newest bottom-right.

  Responsibilities (kept thin — the orchestrator passes orders + emits PATCH):
    - FIFO sort using created_at_iso/created_at (helpers/kdsDisplay::parseOrderCreatedMs)
    - Slot assignment for bump-bar shortcut [A]–[H]
    - Auto-transition watcher (single-chef ACCEPT → PREPARING) via shouldAutoTransition
    - Immediate PATCH on Prêt tap (Wave V 2026-05-21 — was 3s pending+undo,
      but single-slot serialization killed the previous order's PATCH when chef
      chained 3+ orders back-to-back; owner mandate "enlève cette sécurité").
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
      :near-cap="activeOrders.length"
      :fallback-mode="fallbackMode"
      :admin-polling-hint="adminPollingHint"
      :bump-local-only-notice="bumpLocalOnlyNotice"
      :reserve-right-gutter="overflowActiveCount > 0"
    />

    <!-- Empty state (only when NO active orders — served strip below renders independently) -->
    <div v-if="activeOrders.length === 0" class="kds-v2__empty">
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

    <!-- FIFO Grid 4×2 — ACTIVE only (ACCEPT + PREPARING). PREPARED → served strip below.
         [Wave U 2026-05-21] Owner-reported bug: PREPARED orders stayed greyed in grid
         (kds-card--ready opacity:0.7) with the elapsed timer still ticking, occupying
         a slot. Now they leave the active FIFO entirely and surface in a compact
         strip at the bottom for ~last 4 served, with elapsed-since-served. -->
    <div v-else class="kds-v2__grid">
      <KdsOrderCard
        v-for="(o, idx) in activeOrders.slice(0, 8)"
        :key="o.id"
        :order="o"
        :now="now"
        :shortcut="SHORTCUTS[idx]"
        :recall-active="isRecallActive(o)"
        @ready="onCtaTap(o.id, o.queue_number)"
      />
      <!-- placeholders to keep grid stable when <8 -->
      <div
        v-for="i in Math.max(0, 8 - activeOrders.length)"
        :key="`ph-${i}`"
        class="kds-v2__placeholder"
      ></div>
    </div>

    <!-- [Wave N M-KDS-6 F1 P0 2026-05-24] Overflow chip — chef visibility safety net.
         Wave M empirical finding: KdsV2Grid:55 slice(0,8) silently dropped orders 9+
         from the rendered FIFO grid. No chip, no count, no [I]–… keyboard shortcut
         beyond [H]. Owner verbatim mandate « chef qui pourrait sortir une commande
         incomplète » = operational safety risk: if chef thinks the board fully shows
         the queue, orders 9+ silently age past SLA. This chip is the independent
         operational safety net BEFORE the full S3 PROPOSAL Option A/B/C layout
         redesign (owner-gate). Trigger = activeOrders.length > 8 (the partition the
         grid actually slices — NOT total feed length, which would falsely count
         recently-served PREPARED orders in the bottom strip). -->
    <div
      v-if="overflowActiveCount > 0"
      class="kds-overflow-chip"
      role="status"
      aria-live="polite"
    >
      <span class="kds-overflow-chip__icon" aria-hidden="true">!</span>
      <span class="kds-overflow-chip__text">+{{ overflowActiveCount }} {{ $t('label.kds_orders_waiting_more') }}</span>
    </div>

    <!-- [Wave U 2026-05-21] Récemment servies — compact archive strip.
         Renders the 4 most recently PREPARED orders with elapsed-since-served.
         Small footprint (60px row) so it never steals space from the active grid. -->
    <div v-if="recentlyServed.length > 0" class="kds-v2__served" role="region" :aria-label="$t('label.kds_recently_served')">
      <div class="kds-v2__served-label">{{ $t('label.kds_recently_served') }}</div>
      <div class="kds-v2__served-list">
        <div
          v-for="o in recentlyServed"
          :key="`served-${o.id}`"
          class="kds-v2__served-pill keep-latin"
          :title="$t('label.kds_served_pill_title', { queue: o.queue_number || o.id })"
        >
          <span class="kds-v2__served-pill-num">N°{{ o.queue_number || o.id }}</span>
          <span class="kds-v2__served-pill-ago">{{ servedAgoLabel(o) }}</span>
        </div>
      </div>
    </div>

    <!-- [Wave V 2026-05-21] KdsUndoToast removed — chef Prêt tap now PATCHes
         immediately. The 3s undo window + single-slot serialization
         (clearTimeout(pendingTimeoutId)) caused a cross-order race: when chef
         chained "Prêt" on 3+ orders within 3s, the previous order's PATCH
         was cancelled by the next click → only the LAST order transitioned,
         the rest stayed EN COURS until chef re-clicked (perceived as a 30s
         retry-after toast). Per owner mandate "enlève cette sécurité".
         Component file kept for instant rollback. -->

    <!-- aria-live region for screen readers -->
    <div class="sr-only" aria-live="polite" aria-atomic="true">{{ liveMessage }}</div>
  </div>
</template>

<script>
import KdsOrderCard from './KdsOrderCard.vue';
import KdsStatusBanner from './KdsStatusBanner.vue';
// [Wave V 2026-05-21] KdsUndoToast import removed — see template comment.
// File kept on disk for instant rollback (git revert single commit restores).
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
    components: { KdsOrderCard, KdsStatusBanner },
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
        // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
        // Ids of orders that are currently in the RAPPELÉ window (60s after a
        // chef "Annuler bump" click). Populated by the orchestrator from
        // `kdsRecalledMap` + the 60s TTL. Each card cross-references this list
        // to decide whether to render the RAPPELÉ badge overlay.
        recallActiveIds: { type: Array, default: () => [] },
    },
    emits: ['change-status', 'auto-promote'],
    data() {
        return {
            now: Date.now(),
            tickerId: null,
            // [Wave V 2026-05-21] activeToast + pendingTimeoutId removed — the
            // pending bump queue is gone (immediate PATCH). aria-live message
            // is still emitted via `liveMessage` so screen-reader announcements
            // remain functional.
            liveMessage: '',
        };
    },
    computed: {
        SHORTCUTS() {
            return SHORTCUTS;
        },
        // FIFO: oldest first, then by id for stable ties. Includes ALL statuses
        // the backend feed exposes (ACCEPT + PREPARING + PREPARED) so derived
        // computeds (activeOrders, recentlyServed) can partition without
        // re-fetching. Kept as the single sort surface for parity with
        // pre-Wave-U behavior. Auto-transition watcher and keyboard shortcut
        // now read activeOrders only — PREPARED orders no longer occupy a
        // grid slot.
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
        // [Wave U 2026-05-21] Active grid orders = ACCEPT (4) + PREPARING (7) only.
        // PREPARED (8) leaves the FIFO grid (was lingering greyed via
        // kds-card--ready opacity:0.7 with timer still ticking — owner-reported bug).
        //
        // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
        // PREPARED orders whose id appears in `recallActiveIds` (i.e. inside the
        // 60s post-recall window) are RE-INJECTED into the grid so the chef
        // sees the card with the RAPPELÉ badge alongside the live work. After
        // 60s the orchestrator drops the id from the prop and the card slides
        // back to the "Récemment servies" strip via the existing partitioning.
        activeOrders() {
            const recallIds = new Set(Array.isArray(this.recallActiveIds) ? this.recallActiveIds : []);
            return this.visibleOrders.filter((o) => {
                const s = parseInt(o?.status ?? o?.rawStatus, 10);
                if (s === ORDER_STATUS.ACCEPT || s === ORDER_STATUS.PREPARING) {
                    return true;
                }
                if (s === ORDER_STATUS.PREPARED && recallIds.has(o?.id)) {
                    return true;
                }
                return false;
            });
        },
        // [Wave U 2026-05-21] Récemment servies — last 4 PREPARED orders by
        // updated_at desc (updated_at = moment of the PREPARING→PREPARED PATCH
        // applied server-side, matches "il y a X min depuis Prêt" semantics).
        // Backend feed still returns PREPARED orders until OSS/POS flips them
        // to DELIVERED, so this list naturally compacts as orders are picked up.
        recentlyServed() {
            const prepared = this.visibleOrders.filter((o) => {
                const s = parseInt(o?.status ?? o?.rawStatus, 10);
                return s === ORDER_STATUS.PREPARED;
            });
            prepared.sort((a, b) => {
                const ta = Date.parse(a?.updated_at || '') || 0;
                const tb = Date.parse(b?.updated_at || '') || 0;
                return tb - ta;
            });
            return prepared.slice(0, 4);
        },
        // [Wave N M-KDS-6 F1 P0 2026-05-24] Overflow count for the chef-visibility
        // safety chip. Counts ACTIVE orders (ACCEPT|PREPARING) beyond the 8 slots
        // the FIFO grid actually renders — recentlyServed (PREPARED) orders are
        // NOT counted (they live in the bottom strip and are not "waiting"). Stays
        // 0 when the queue fits the grid (overflow chip stays hidden via v-if).
        overflowActiveCount() {
            return Math.max(0, this.activeOrders.length - 8);
        },
    },
    watch: {
        // Auto-transition watcher: when the queue updates AND no order is in
        // PREPARING AND there's a NEW order at the head, promote it.
        // [Wave U 2026-05-21] Switched from visibleOrders → activeOrders so the
        // candidate picker never sees PREPARED tickets (which are excluded from
        // the rendered grid).
        activeOrders: {
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
        // [Wave V 2026-05-21] No pendingTimeoutId to clean — onCtaTap fires
        // synchronously, no in-flight setTimeout owned by this component.
        window.removeEventListener('keydown', this.onKey);
    },
    methods: {
        onKey(e) {
            // [A]–[H] bumps the nth slot. Enter/Esc handled by KdsOrderCard.
            // [Wave U 2026-05-21] Index against activeOrders (the rendered list)
            // so the shortcut letter matches the on-card [A]–[H] badge after
            // PREPARED orders are partitioned out of the grid.
            const idx = SHORTCUTS.indexOf(String(e.key || '').toUpperCase());
            if (idx >= 0 && idx < this.activeOrders.length) {
                const o = this.activeOrders[idx];
                if (o) {
                    // [GOAL-2026-05-30 D1 — OWNER REVERSAL of Wave S-2] Cash-pending orders
                    // MAY now be bumped (kitchen prepares before encashment); the [A]–[H]
                    // shortcut no longer skips them — it matches the now-always-present CTA.
                    // The "non encaissé" note stays visible on the card (KdsOrderCard).
                    e.preventDefault();
                    this.onCtaTap(o.id, o.queue_number);
                }
            }
        },
        // [Wave V 2026-05-21 P-OWNER] Chef taps Prêt → IMMEDIATE PATCH dispatch.
        //
        // Previous design (Wave Q-2 2026-05-20): optimistic toast 3s window
        // → PATCH after timer expired. The single-slot serialization (a
        // shared `pendingTimeoutId`) cancelled any in-flight pending bump
        // whenever the chef clicked Prêt on a SECOND order within 3s. Net
        // effect when chef chained 3 tickets back-to-back:
        //   t=0    click A → pending(A), timer A
        //   t=500  click B → clearTimeout(A), pending(B), timer B
        //   t=1000 click C → clearTimeout(B), pending(C), timer C
        //   t=3000+timer C fires → only C transitions; A & B never PATCHed.
        // Chef saw A & B still in queue, re-clicked → server pipeline kept
        // up with the natural cadence (no race when individual clicks),
        // but UX read "trop de requêtes, réessayer dans 30s" toast because
        // bootstrap.js maps any incidental 429 from upstream paths to the
        // generic rate-limited copy.
        //
        // Owner mandate: "enlève cette sécurité — je veux valider 3 commandes
        // en même temps, puis 3 commandes livrées." So we remove the 3s
        // undo window entirely. Each tap fires a PATCH immediately with
        // its own X-Idempotency-Key (UUID v4 generated by
        // buildIdempotencyHeaders), and the backend OrderStateMachine
        // serialises per-order via lockForUpdate — concurrent PATCHes on
        // DIFFERENT orders are fully independent. Duplicate PATCH on the
        // SAME order returns 409 (idempotency conflict OR state machine
        // InvalidTransition) and is silently swallowed by
        // KitchenDisplaySystemComponent::onV2ChangeStatus → refresh.
        //
        // Step-ladder logic preserved from Wave Q-2: a single tap on a
        // CONFIRMÉE (ACCEPT=4) ticket advances to EN PRÉPARATION (PREPARING=7);
        // a second tap advances to PRÊT (PREPARED=8). Matches the server
        // `OrderStateMachine::allows` rule and the legacy `kdsBump` step
        // ladder in KitchenDisplaySystemComponent.vue:1716-1728.
        onCtaTap(orderId, queueNo) {
            const order = this.activeOrders.find((o) => o.id === orderId);
            if (!order) {
                return;
            }
            const currentStatus = parseInt(order?.status ?? order?.rawStatus, 10);
            const nextStatus = currentStatus === ORDER_STATUS.ACCEPT
                ? ORDER_STATUS.PREPARING
                : ORDER_STATUS.PREPARED;
            const isFinalStep = nextStatus === ORDER_STATUS.PREPARED;

            // Fire PATCH immediately — no 3s wait, no single-slot serialization.
            this.$emit('change-status', {
                orderId,
                status: nextStatus,
            });

            // a11y: announce the transition for screen readers via the
            // existing sr-only aria-live="polite" region. No visual toast.
            this.liveMessage = isFinalStep
                ? this.$t('label.kds_aria_live_ready', { id: queueNo || orderId })
                : this.$t('label.kds_aria_live_preparing', { id: queueNo || orderId });
        },
        // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
        // True if the order is in the RAPPELÉ window (passed down from the
        // orchestrator via `recallActiveIds`). KdsOrderCard renders the badge
        // overlay accordingly.
        isRecallActive(order) {
            if (!order || !Array.isArray(this.recallActiveIds)) {
                return false;
            }
            return this.recallActiveIds.includes(order.id);
        },
        // [Wave U 2026-05-21] Compact "il y a Xm" relative label for the
        // recently-served strip. Reads `now` reactively so each pill updates
        // every second alongside the active card timers (no per-pill setInterval).
        servedAgoLabel(o) {
            const stamp = Date.parse(o?.updated_at || '') || 0;
            if (!stamp) {
                return '';
            }
            const diffSec = Math.max(0, Math.floor((this.now - stamp) / 1000));
            if (diffSec < 60) {
                return this.$t('label.kds_served_just_now');
            }
            // [KDS-UI-02 FIX] Roll the relative label up past minutes so a
            // multi-day-old served order no longer renders "il y a 5907 min".
            // < 60 min  → "il y a {mins} min" (existing path, unchanged)
            // < 24 h    → "il y a {hours} h"  (floor of whole hours)
            // ≥ 24 h    → "il y a {days} j"    (floor of whole days)
            const mins = Math.floor(diffSec / 60);
            if (mins < 60) {
                return this.$t('label.kds_served_ago', { mins });
            }
            const hours = Math.floor(mins / 60);
            if (hours < 24) {
                return this.$t('label.kds_served_ago_hours', { hours });
            }
            const days = Math.floor(hours / 24);
            return this.$t('label.kds_served_ago_days', { days });
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

/* [Wave N M-KDS-6 F1 P0 2026-05-24] Overflow chip — chef visibility safety net.
   Cayenne red (#F4501E) high-contrast pill, absolute top-right of .kds-v2
   (which is position:relative). z-index:100 keeps it above grid cards but the
   parent .kds-v2 stacking context contains the chip below any modal overlay.
   Pulse keyframe pulls peripheral attention; the `prefers-reduced-motion`
   media query disables animation for vestibular-sensitive operators. */
.kds-overflow-chip {
    position: absolute;
    top: 16px;
    right: 16px;
    padding: 8px 16px;
    background: #F4501E;
    color: #1A1A1A;
    border-radius: 8px;
    font-weight: 700;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    animation: kds-overflow-pulse 2s ease-in-out infinite;
    z-index: 100;
}
.kds-overflow-chip__icon {
    font-size: 18px;
    line-height: 1;
    font-weight: 900;
}
@keyframes kds-overflow-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}
@media (prefers-reduced-motion: reduce) {
    .kds-overflow-chip {
        animation: none;
    }
}

/* [Wave U 2026-05-21] Récemment servies — compact archive strip.
   Lives below the 4x2 active grid. Single row, small footprint
   (~60px total) so it never steals vertical budget from the active
   cards. Pills are read-only (no CTA, no keyboard, no timer-pulse). */
.kds-v2__served {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 16px 12px;
    border-top: 1px solid #E5E7EB;
    background: #F9FAFB;
}
.kds-v2__served-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #6B7280;
    flex-shrink: 0;
}
.kds-v2__served-list {
    display: flex;
    flex-wrap: nowrap;
    gap: 8px;
    overflow-x: auto;
    overscroll-behavior: contain;
    min-width: 0;
}
.kds-v2__served-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 9999px;
    background: #ECFDF5;
    color: #065F46;
    border: 1px solid #A7F3D0;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 13px;
    font-weight: 700;
    line-height: 1;
    flex-shrink: 0;
}
.kds-v2__served-pill-num {
    font-weight: 800;
    letter-spacing: -0.02em;
}
.kds-v2__served-pill-ago {
    font-family: 'Inter', system-ui, sans-serif;
    font-size: 11px;
    font-weight: 600;
    color: #047857;
    opacity: 0.85;
    letter-spacing: 0.02em;
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
