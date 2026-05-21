<!--
  [Wave X3 2026-05-21] KDS Historique du jour — read-only V1.

  Slide-in right drawer listing today's PREPARED + OUT_FOR_DELIVERY + DELIVERED
  orders for the chef's branch. Used to verify content when a customer reports
  an error on an already-bumped order.

  Read-only V1: revert PREPARED → PREPARING is intentionally NOT exposed here.
  OrderStateMachine (frozen §7) forbids reverse transitions, and the owner has
  classified this as "secondaire" — revert is V1.0.2 backlog pending a LOCK
  plan + owner countersign.

  Wave U "Récemment servies" strip in `KdsV2Grid.vue` remains independent —
  this drawer is a separate sibling surface accessible from a header button.

  Endpoint: GET /api/admin/kds-order/history-today
    - Permission-gated by `kitchen-display-system` (Chef role)
    - Branch-scoped (admin branch_id=0 sees all branches)
    - Throttled 60/min
    - TZ-aware Paris-local bounds (Wave T R5 discipline)
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
            V1.0.2 backlog: revert button (PREPARED → PREPARING).
            Blocked in V1 by OrderStateMachine §7 frozen-zone (forward-only).
            Requires LOCK plan + owner countersign before implementation.
          -->
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

  emits: ['close'],

  data() {
    return {
      orders: [],
      loading: false,
      error: false,
    };
  },

  watch: {
    open(newVal) {
      if (newVal) {
        this.fetch();
      }
    },
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

/* RTL */
.kds-history-drawer[dir="rtl"] .kds-history-drawer__panel {
  box-shadow: 8px 0 32px rgba(0, 0, 0, 0.25);
}
</style>
