<!--
  [Wave X3 2026-05-21] KDS Historique du jour — read-only V1.
  [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]

  Slide-in right drawer listing today's PREPARED + OUT_FOR_DELIVERY + DELIVERED
  orders for the chef's branch. Used to verify content when a customer reports
  an error on an already-bumped order.

  Heal-5: chef "↶ Annuler bump" surfaced on each PREPARED order whose
  `updated_at` is within the last 60 seconds. Click POSTs to
  `/api/admin/kds-order/recall/{order}` which appends a `kitchen_recall` row
  to `order_status_transitions` (NF525-safe append; `orders.status` stays
  PREPARED). Backend broadcasts `KdsOrderRecalled` on `private-branch.{id}`
  so KdsV2Grid re-injects the card with the RAPPELÉ badge.

  Read-only revert (PREPARED → PREPARING) remains intentionally NOT exposed
  here — V1.0.2 backlog Path C. Path B is the compensating action that does
  NOT violate OrderStateMachine §7 (uses the identity transition).

  Wave U "Récemment servies" strip in `KdsV2Grid.vue` remains independent —
  this drawer is a separate sibling surface accessible from a header button.

  Endpoint: GET /api/admin/kds-order/history-today
    - Permission-gated by `kitchen-display-system` (Chef role)
    - Branch-scoped (admin branch_id=0 sees all branches)
    - Throttled 60/min
    - TZ-aware Paris-local bounds (Wave T R5 discipline)

  Endpoint: POST /api/admin/kds-order/recall/{order}
    - Same permission gate, idempotency + throttle:kds-bump
    - 422 if order is not PREPARED OR window > 60s
    - 409 if already recalled (cap N=1)
-->
<template>
  <div
    v-if="open"
    class="kds-history-drawer"
    role="dialog"
    aria-modal="true"
    :aria-label="$t('label.kds_history_button_aria')"
    data-testid="kds-history-drawer"
  >
    <div
      class="kds-history-drawer__backdrop"
      @click="$emit('close')"
    ></div>

    <div class="kds-history-drawer__panel" :dir="dir">
      <header class="kds-history-drawer__header">
        <h2 class="kds-history-drawer__title">
          <span aria-hidden="true">📚</span>
          {{ $t('label.kds_history_title') }}
          <span v-if="!loading && !error" class="kds-history-drawer__count">
            ({{ orders.length }})
          </span>
        </h2>
        <button
          type="button"
          class="kds-history-drawer__close"
          :aria-label="$t('label.kds_history_close_aria')"
          data-testid="kds-history-close"
          @click="$emit('close')"
        >
          <span aria-hidden="true">✕</span>
        </button>
      </header>

      <div
        v-if="loading"
        class="kds-history-drawer__loading"
        data-testid="kds-history-loading"
        role="status"
        aria-live="polite"
      >
        {{ $t('label.kds_history_loading') }}
      </div>

      <div
        v-else-if="error"
        class="kds-history-drawer__error"
        data-testid="kds-history-error"
        role="alert"
      >
        <span>{{ $t('label.kds_history_error') }}</span>
        <button
          type="button"
          class="kds-history-drawer__retry"
          @click="fetch"
        >
          {{ $t('label.kds_history_retry') }}
        </button>
      </div>

      <div
        v-else-if="orders.length === 0"
        class="kds-history-drawer__empty"
        data-testid="kds-history-empty"
      >
        {{ $t('label.kds_history_empty') }}
      </div>

      <ul
        v-else
        class="kds-history-drawer__list"
        data-testid="kds-history-list"
      >
        <li
          v-for="order in orders"
          :key="order.id"
          :class="['kds-history-drawer__item', statusClass(order.status)]"
          data-testid="kds-history-item"
        >
          <div class="kds-history-drawer__head">
            <span class="kds-history-drawer__queue">
              N°{{ order.queue_number || order.order_serial_no || order.id }}
            </span>
            <span
              class="kds-history-drawer__status"
              :data-status="order.status"
            >
              {{ statusLabel(order.status) }}
            </span>
            <time
              class="kds-history-drawer__time"
              :datetime="order.updated_at"
            >
              {{ formatTime(order.updated_at) }}
            </time>
          </div>

          <ul class="kds-history-drawer__items">
            <li
              v-for="(item, idx) in (order.order_items || [])"
              :key="(item.id || idx) + '-' + idx"
              class="kds-history-drawer__line"
            >
              <span class="kds-history-drawer__qty">{{ item.quantity }}×</span>
              <span class="kds-history-drawer__name">{{ itemName(item) }}</span>
              <span
                v-if="Array.isArray(item.item_variations) && item.item_variations.length"
                class="kds-history-drawer__variations"
              >
                <em
                  v-for="(variation, vIdx) in item.item_variations"
                  :key="vIdx"
                >— {{ variation.name }}<span v-if="vIdx + 1 < item.item_variations.length">, </span></em>
              </span>
            </li>
          </ul>
          <!--
            [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
            Chef "Annuler bump" surfaced ONLY when:
              - order.status === PREPARED (statuses OUT/DELIVERED can no longer be recalled)
              - order.updated_at within the last 60s (TTL window matches backend guard)
              - order.recalled_by_id NOT already set in the local cache (cap N=1 — best-effort,
                the backend remains the source of truth and returns 409 otherwise)

            V1.0.2 backlog Path C (reverse transition PREPARED → PREPARING) remains
            deferred — Path B is the V1 ship per owner mandate 2026-05-26.
          -->
          <div
            v-if="canRecall(order)"
            class="kds-history-drawer__recall-row"
          >
            <button
              type="button"
              class="kds-history-drawer__recall-btn"
              :aria-label="$t('label.kds_recall_button_aria', { queue: order.queue_number || order.id })"
              :disabled="recallingIds.includes(order.id)"
              :data-testid="`kds-recall-${order.id}`"
              @click="recall(order)"
            >
              {{ $t('label.kds_recall_button') }}
            </button>
            <span class="kds-history-drawer__recall-hint">
              {{ recallSecondsLeft(order) }}s
            </span>
          </div>
          <div
            v-else-if="order.status === STATUS_PREPARED && wasRecentlyRecalled(order)"
            class="kds-history-drawer__recall-row kds-history-drawer__recall-row--done"
          >
            <span
              class="kds-history-drawer__recall-badge"
              :aria-label="$t('label.kds_recall_badge_aria', { queue: order.queue_number || order.id })"
            >
              {{ $t('label.kds_recall_badge') }}
            </span>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

const STATUS_PREPARED          = 8;
const STATUS_OUT_FOR_DELIVERY  = 10;
const STATUS_DELIVERED         = 13;

export default {
  name: 'KdsHistoryDrawer',

  props: {
    open: {
      type: Boolean,
      default: false,
    },
    dir: {
      type: String,
      default: 'ltr',
    },
  },

  // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B] emits `recalled`
  // so the parent KitchenDisplaySystemComponent can re-inject the card into
  // KdsV2Grid with the RAPPELÉ badge for 60s without waiting for the next
  // polling tick (websocket fan-out covers other stations; the click origin
  // gets the instant local mirror via this emit).
  emits: ['close', 'recalled'],

  data() {
    return {
      orders: [],
      loading: false,
      error: false,
      // [Heal-5] Reactive clock — drives the 60s countdown badge so the
      // "↶ Annuler bump" button auto-hides when the TTL expires without
      // requiring a fetch round-trip. Single setInterval owned by this
      // component (cleared on beforeUnmount).
      now: Date.now(),
      tickerId: null,
      // [Heal-5] Ids of orders for which a recall POST is in-flight — used
      // to disable the button + show a spinner without re-rendering the list.
      recallingIds: [],
      // [Heal-5] Local cache of orders the chef has recalled in this session
      // (orderId → recalledAtMs). Backend remains the source of truth (returns
      // 409 if a row already exists), but this lets us flip the UI to the
      // RAPPELÉ badge synchronously on success without waiting for a refetch.
      recalledMap: {},
      // [Heal-5] Constant — recall TTL window in seconds. Mirrors the backend
      // 60s window in KitchenDisplaySystemOrderService::recall.
      RECALL_TTL_SECONDS: 60,
      // [Heal-5] Status constant kept on the instance so the template can
      // reference it via `STATUS_PREPARED` without importing.
      STATUS_PREPARED,
    };
  },

  watch: {
    open(newVal) {
      if (newVal) {
        this.fetch();
        // Reset the local recall cache when the drawer (re-)opens so a
        // chef who closed + re-opened sees the canonical backend state.
        this.recalledMap = {};
      }
    },
  },

  // [W2 audit 2026-05-21] a11y: WAI-ARIA dialog pattern (WCAG 2.1.2) requires
  // Escape to close. Backdrop click works but keyboard-only operators (chef
  // with gloves / stylus) were stuck. Handler is gated by `this.open` so it
  // is a no-op when the drawer is closed; teardown in beforeUnmount avoids
  // listener leaks if a parent re-mounts during a Vue-router transition.
  mounted() {
    this._onEsc = (e) => {
      if (this.open && (e.key === 'Escape' || e.key === 'Esc')) {
        this.$emit('close');
      }
    };
    document.addEventListener('keydown', this._onEsc);

    // [Heal-5] Single 1s ticker for the recall TTL countdown. Cheaper than
    // a per-button setInterval; the template just reads `this.now` reactively.
    this.tickerId = window.setInterval(() => {
      this.now = Date.now();
    }, 1000);
  },

  beforeUnmount() {
    if (this._onEsc) {
      document.removeEventListener('keydown', this._onEsc);
      this._onEsc = null;
    }
    if (this.tickerId) {
      window.clearInterval(this.tickerId);
      this.tickerId = null;
    }
  },

  methods: {
    async fetch() {
      this.loading = true;
      this.error = false;
      try {
        // Matches existing KDS endpoint shape: `admin/kds-order/...` (no leading
        // slash, no `/api/` — axios baseURL is configured project-wide).
        const response = await axios.get('admin/kds-order/history-today');
        const payload = response.data;
        // Laravel ResourceCollection always nests rows under `.data`, but be
        // defensive in case any middleware unwraps it.
        this.orders = Array.isArray(payload)
          ? payload
          : Array.isArray(payload && payload.data) ? payload.data : [];
      } catch (e) {
        this.error = true;
        this.orders = [];
      } finally {
        this.loading = false;
      }
    },

    itemName(item) {
      // KDSOrderDetailsResource exposes `item_name` directly; defensive fall-backs.
      return item.item_name
        || (item.item && item.item.name)
        || item.name
        || '';
    },

    statusClass(status) {
      return {
        'is-prepared': status === STATUS_PREPARED,
        'is-out':       status === STATUS_OUT_FOR_DELIVERY,
        'is-delivered': status === STATUS_DELIVERED,
      };
    },

    statusLabel(status) {
      const map = {
        [STATUS_PREPARED]:         this.$t('label.kds_state_prepared'),
        [STATUS_OUT_FOR_DELIVERY]: this.$t('label.kds_state_out'),
        [STATUS_DELIVERED]:        this.$t('label.kds_state_delivered'),
      };
      return map[status] || String(status);
    },

    formatTime(value) {
      if (!value) return '';
      try {
        const d = new Date(value);
        const hh = String(d.getHours()).padStart(2, '0');
        const mm = String(d.getMinutes()).padStart(2, '0');
        return `${hh}:${mm}`;
      } catch (_e) {
        return '';
      }
    },

    // [Heal-5] Returns ms since the order was last bumped (PREPARED). Returns
    // -1 if the timestamp is unparseable so the gates below safely fail-closed.
    _msSinceUpdated(order) {
      const stamp = Date.parse(order?.updated_at || '');
      if (Number.isNaN(stamp)) {
        return -1;
      }
      return Math.max(0, this.now - stamp);
    },

    // [Heal-5] Eligibility gate for the "↶ Annuler bump" button. Three rules:
    //   1. Order must currently be PREPARED (post-bump). OUT/DELIVERED orders
    //      are out-of-scope — once the customer has picked up the food, the
    //      compensating action no longer makes sense (the customer can't be
    //      asked to give it back).
    //   2. updated_at must be within the last RECALL_TTL_SECONDS. Backend
    //      enforces the same 60s guard; this mirror keeps the button hidden
    //      after expiry so chef never sees a clickable button that would 422.
    //   3. The order has NOT been recalled yet (cap N=1). Local cache short-
    //      circuits the second click; backend remains source of truth via 409.
    canRecall(order) {
      if (!order || order.status !== STATUS_PREPARED) {
        return false;
      }
      if (this.wasRecentlyRecalled(order)) {
        return false;
      }
      const ms = this._msSinceUpdated(order);
      if (ms < 0) {
        return false;
      }
      return ms < this.RECALL_TTL_SECONDS * 1000;
    },

    // [Heal-5] Countdown in seconds shown next to the button — gives the chef
    // peripheral awareness ("5s left to undo"). Clamped to 0 so a transiently
    // out-of-window read never displays a negative.
    recallSecondsLeft(order) {
      const ms = this._msSinceUpdated(order);
      if (ms < 0) {
        return 0;
      }
      return Math.max(0, this.RECALL_TTL_SECONDS - Math.floor(ms / 1000));
    },

    // [Heal-5] Has the chef recalled this order in the current drawer session?
    // The badge renders when recently recalled even though the button is gone.
    wasRecentlyRecalled(order) {
      if (!order) {
        return false;
      }
      const recalledAt = this.recalledMap[order.id];
      if (!recalledAt) {
        return false;
      }
      // Badge persists 60s post-recall (same window as the button) so the chef
      // sees the confirmation even if the drawer scrolls or another order is
      // added. After 60s the row reverts to the default styling.
      return (this.now - recalledAt) < this.RECALL_TTL_SECONDS * 1000;
    },

    // [Heal-5] POST to /api/admin/kds-order/recall/{order}. The endpoint
    // mirrors `change-status` middleware (idempotency + throttle:kds-bump)
    // so a double-click is absorbed (1 row, not 2). On success we emit
    // `recalled` so the parent KitchenDisplaySystemComponent can re-inject
    // the card into KdsV2Grid with the RAPPELÉ badge immediately, without
    // waiting for the websocket fan-out (which still fires for other
    // stations of the same branch).
    async recall(order) {
      if (!order || !this.canRecall(order)) {
        return;
      }
      if (this.recallingIds.includes(order.id)) {
        return; // double-click guard
      }
      this.recallingIds = [...this.recallingIds, order.id];
      try {
        // [GOAL-2026-05-29 F6] The /recall route carries the `idempotency`
        // middleware (routes/api.php:1160) which REQUIRES X-Idempotency-Key —
        // the POST omitted it, so it ALWAYS 422'd and the catch faked a RAPPELÉ
        // badge while the order was never recalled. Send a stable per-minute key
        // (mirrors POS counter-collect): a network retry within the minute
        // dedupes server-side; the 60s recall window aligns with the bucket.
        const idempotencyKey = `kds-recall-${order.id}-${Math.floor(Date.now() / 60000)}`;
        const response = await axios.post(`admin/kds-order/recall/${order.id}`, null, {
          headers: { 'X-Idempotency-Key': idempotencyKey },
        });
        const recalledAt = Date.now();
        this.recalledMap = { ...this.recalledMap, [order.id]: recalledAt };
        this.$emit('recalled', {
          orderId: order.id,
          queueNumber: order.queue_number || null,
          recalledAt,
          payload: response?.data || null,
        });
      } catch (e) {
        // 409 → already recalled by another chef on the same branch. Surface
        // the localized message and refetch so the chef sees the canonical
        // state. 422 (window expired) likewise triggers a refetch so stale
        // entries are pruned.
        const status = e?.response?.status;
        const message = e?.response?.data?.message;
        if (status === 409) {
          // 409 = already recalled by another chef on this branch → it genuinely
          // IS recalled, so mark locally (button hides until the next fetch).
          this.recalledMap = { ...this.recalledMap, [order.id]: Date.now() };
        }
        // [GOAL-2026-05-29 F6] 422 = recall window expired → the recall did NOT
        // happen, so do NOT fake a RAPPELÉ badge (the old code did, masking the
        // failure). Just surface the message below; the next fetch prunes the row.
        // Defensive surfacing via the existing toast helper if available.
        // Falls back to console.warn so the failure is observable in dev.
        if (window?.toastr?.error && message) {
          window.toastr.error(message);
        } else {
          console.warn('[KDS recall] failed:', status, message || e?.message);
        }
      } finally {
        this.recallingIds = this.recallingIds.filter((id) => id !== order.id);
      }
    },
  },
};
</script>

<style scoped>
.kds-history-drawer {
  position: fixed;
  inset: 0;
  z-index: 9000;
  display: flex;
  justify-content: flex-end;
  pointer-events: none;
}

.kds-history-drawer__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  pointer-events: auto;
}

.kds-history-drawer__panel {
  position: relative;
  width: min(440px, 90vw);
  height: 100%;
  background: #ffffff;
  box-shadow: -8px 0 32px rgba(0, 0, 0, 0.25);
  pointer-events: auto;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.kds-history-drawer__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-bottom: 1px solid #eaeaea;
  background: #111111;
  color: #ffffff;
  flex-shrink: 0;
}

.kds-history-drawer__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
  display: flex;
  gap: 8px;
  align-items: baseline;
}

.kds-history-drawer__count {
  font-size: 0.85rem;
  opacity: 0.85;
  font-weight: 400;
}

.kds-history-drawer__close {
  background: transparent;
  border: none;
  color: inherit;
  font-size: 1.25rem;
  cursor: pointer;
  padding: 6px 10px;
  border-radius: 4px;
}

.kds-history-drawer__close:hover,
.kds-history-drawer__close:focus {
  background: rgba(255, 255, 255, 0.12);
  outline: 2px solid #ffd400;
  outline-offset: 1px;
}

.kds-history-drawer__loading,
.kds-history-drawer__empty,
.kds-history-drawer__error {
  padding: 22px 18px;
  color: #555;
  font-size: 0.95rem;
}

.kds-history-drawer__error {
  color: #b00020;
  display: flex;
  flex-direction: column;
  gap: 10px;
  align-items: flex-start;
}

.kds-history-drawer__retry {
  background: #111;
  color: #fff;
  border: none;
  padding: 6px 14px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.85rem;
}

.kds-history-drawer__list {
  list-style: none;
  margin: 0;
  padding: 12px;
  overflow-y: auto;
  flex: 1;
}

.kds-history-drawer__item {
  border: 1px solid #e2e2e2;
  border-left: 4px solid #888;
  border-radius: 6px;
  padding: 10px 12px;
  margin-bottom: 10px;
  background: #fafafa;
}

.kds-history-drawer__item.is-prepared  { border-left-color: #1e88e5; }
.kds-history-drawer__item.is-out       { border-left-color: #fb8c00; }
.kds-history-drawer__item.is-delivered { border-left-color: #2e7d32; }

.kds-history-drawer__head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.kds-history-drawer__queue {
  font-weight: 700;
  font-size: 1rem;
}

.kds-history-drawer__status {
  background: #111;
  color: #fff;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.kds-history-drawer__time {
  margin-left: auto;
  font-variant-numeric: tabular-nums;
  font-size: 0.85rem;
  color: #444;
}

.kds-history-drawer__items {
  list-style: none;
  margin: 8px 0 0;
  padding: 0;
}

.kds-history-drawer__line {
  font-size: 0.9rem;
  padding: 2px 0;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.kds-history-drawer__qty {
  font-weight: 700;
  min-width: 28px;
}

.kds-history-drawer__name {
  flex: 1;
}

.kds-history-drawer__variations {
  color: #555;
  font-style: italic;
  font-size: 0.85rem;
  width: 100%;
  margin-left: 32px;
}

/* [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B] recall row.
   - Visually grounded under the items list, separated by a thin top border
     so the chef's eye lands on the orange CTA after reading the order content.
   - The button uses Cayenne orange #F4501E (matches `.kds-overflow-chip` in
     KdsV2Grid for brand cohesion) with high contrast white text.
   - The TTL hint (e.g. "12s") is monospace so the countdown ticks visually
     without layout shift.
   - The RAPPELÉ badge variant inverts to a quiet success treatment after a
     successful recall — the chef has the confirmation but the row no longer
     screams for action. */
.kds-history-drawer__recall-row {
  margin-top: 10px;
  padding-top: 8px;
  border-top: 1px dashed #e2e2e2;
  display: flex;
  align-items: center;
  gap: 10px;
}

.kds-history-drawer__recall-btn {
  background: #F4501E;
  color: #ffffff;
  border: none;
  padding: 8px 14px;
  border-radius: 6px;
  font-weight: 700;
  font-size: 0.9rem;
  cursor: pointer;
  letter-spacing: 0.02em;
  box-shadow: 0 2px 4px rgba(244, 80, 30, 0.18);
}

.kds-history-drawer__recall-btn:hover:not(:disabled),
.kds-history-drawer__recall-btn:focus:not(:disabled) {
  background: #DC4615;
  outline: 2px solid #ffd400;
  outline-offset: 1px;
}

.kds-history-drawer__recall-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.kds-history-drawer__recall-hint {
  font-family: 'JetBrains Mono', ui-monospace, monospace;
  font-size: 0.8rem;
  font-weight: 600;
  color: #6B7280;
  font-variant-numeric: tabular-nums;
}

.kds-history-drawer__recall-row--done {
  border-top-style: solid;
  border-top-color: #F4501E;
}

.kds-history-drawer__recall-badge {
  display: inline-flex;
  align-items: center;
  background: #F4501E;
  color: #ffffff;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

/* RTL */
.kds-history-drawer[dir="rtl"] .kds-history-drawer__panel {
  box-shadow: 8px 0 32px rgba(0, 0, 0, 0.25);
}
</style>
