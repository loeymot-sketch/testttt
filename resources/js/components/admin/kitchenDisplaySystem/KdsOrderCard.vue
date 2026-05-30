<!--
  [kds/sprint-2 V-1] Single canonical KDS card. Replaces the per-source
  column-specific card markup that was duplicated 4× in the legacy
  KitchenDisplaySystemComponent.vue template (lines ~228-940).

  Structure (DESIGN_SPEC_KDS_V2_2026-05-11.md §3.2 ASCII):
    [TOP ACCENT STRIPE 6px — age or brand]
    [HEADER]
      meta row: [shortcut]  STATE pill + SOURCE chip   [ALLERGIE pill]
      main row: N°XX queue number (LEFT)  ATTENTE mm:ss (RIGHT)
    [BODY]
      KdsOrderLine × N (scroll if >5 items)
    [FOOTER]
      Prêt CTA — full width, 52px, brand #111827, with check icon
-->
<template>
  <div
    class="kds-card"
    :class="cardClass"
    :style="cardStyle"
    tabindex="0"
    role="region"
    :aria-label="ariaLabel"
    :data-order-id="order.id"
    @keydown.enter="onCardKeydownEnter"
  >
    <!-- TOP ACCENT STRIPE — dominant 2m signal -->
    <div class="kds-card__stripe" :style="{ background: stripeColor }"></div>

    <!-- [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
         RAPPELÉ badge — surfaces when the orchestrator marks this order as
         recalled (chef "Annuler bump" on same station, or websocket fan-out
         from another station of the same branch). Lives ABOVE the header so
         it's the first thing the chef sees on re-injection. aria-live=polite
         announces the recall to screen-reader users. -->
    <div
      v-if="recallActive"
      class="kds-card__recall-badge"
      role="status"
      aria-live="polite"
      :aria-label="$t('label.kds_recall_badge_aria', { queue: order.queue_number || order.id })"
      :data-testid="`kds-card-recall-badge-${order.id}`"
    >
      {{ $t('label.kds_recall_badge') }}
    </div>

    <!-- HEADER -->
    <div class="kds-card__header" :style="{ background: headerBg }">
      <!-- meta row: slot + state/source + allergen -->
      <div class="kds-card__meta">
        <span v-if="shortcut" class="kds-card__shortcut" :title="$t('label.kds_bump_local_only_notice')">[{{ shortcut }}]</span>
        <span v-else class="kds-card__shortcut-spacer"></span>

        <div class="kds-card__state-source">
          <span class="kds-card__state-pill" :style="statePillStyle">
            <span class="kds-card__state-dot" :style="{ background: stateTheme.dot }"></span>
            {{ stateLabel }}
          </span>
          <span class="kds-card__source-chip" :style="sourceChipStyle">
            <span class="kds-card__source-label">{{ sourceLabel }}</span>
          </span>
        </div>

        <span
          v-if="hasAllergen"
          class="kds-card__allergen-pill"
          role="alert"
          aria-live="assertive"
        >
          ⚠ {{ $t('label.kds_allergie') }}
        </span>
        <span v-else class="kds-card__allergen-spacer"></span>
      </div>

      <!-- main row: queue number + elapsed -->
      <div class="kds-card__main">
        <div class="kds-card__queue keep-latin">
          <span class="kds-card__queue-prefix">N°</span>{{ order.queue_number || order.id }}
        </div>
        <div class="kds-card__elapsed-wrap">
          <span class="kds-card__elapsed-label" :style="{ color: elapsedLabelColor }">
            {{ $t('label.kds_attente') }}
          </span>
          <div
            class="kds-card__elapsed keep-latin"
            :class="bucket === 'critical' ? 'kds-pulse-digit' : ''"
            :style="{ color: elapsedColor }"
          >
            {{ elapsedFormatted }}
          </div>
        </div>
      </div>
    </div>

    <!-- BODY -->
    <!-- KDS-R1-05 heal: tabindex=0 + role=region + aria-label = Safari keyboard accessibility
         on scrollable overflow:auto region (axe-core 'scrollable-region-focusable' serious). -->
    <div
      class="kds-card__body"
      tabindex="0"
      role="region"
      :aria-label="$t('label.kds_card_body_aria', { queue: order.queue_number || order.id })"
    >
      <!-- [Sprint 2A DEL-3 2026-05-16] Delivery block — only renders when
           the order is destined for delivery AND the backend has populated
           `order_address` (i.e. the eager-load was applied). Without this,
           the livreur sees a token + delivery_time and literally cannot
           deliver. Kept visually quiet (border-left accent, monospace
           latin digits for the phone) so it never out-screams the queue
           number or the elapsed timer. -->
      <div v-if="isDeliveryOrder" class="kds-card__delivery" data-testid="kds-card-delivery">
        <div v-if="deliveryAddressLine" class="kds-card__delivery-row">
          <span class="kds-card__delivery-icon" aria-hidden="true">&#128205;</span>
          <span class="kds-card__delivery-text">{{ deliveryAddressLine }}</span>
        </div>
        <div v-if="customerName" class="kds-card__delivery-row kds-card__delivery-row--muted">
          <span class="kds-card__delivery-icon" aria-hidden="true">&#128100;</span>
          <span class="kds-card__delivery-text">{{ customerName }}</span>
        </div>
        <a
          v-if="customerPhone"
          class="kds-card__delivery-row kds-card__delivery-phone keep-latin"
          :href="`tel:${customerPhone}`"
          :aria-label="$t('label.kds_call_customer_aria', { name: customerName || '', phone: customerPhone })"
        >
          <span class="kds-card__delivery-icon" aria-hidden="true">&#128241;</span>
          <span class="kds-card__delivery-text">{{ customerPhone }}</span>
        </a>
      </div>
      <template v-for="(item, idx) in order.order_items" :key="item.id || idx">
        <div class="kds-card__item-block">
          <KdsOrderLine
            v-for="(line, li) in renderItemLines(item)"
            :key="li"
            :line="line"
          />
        </div>
      </template>
      <div class="kds-card__body-fade"></div>
    </div>

    <!-- FOOTER — bump CTA + (when awaiting counter encashment) a NON-blocking note -->
    <!-- [GOAL-2026-05-30 D1 — OWNER REVERSAL of Wave S-2 2026-05-20] Owner now wants the
         kitchen to PREPARE an order BEFORE encashment. So for a cash-at-counter order still
         awaiting collection (payment_pending_counter=true) we show a NON-blocking
         "Non encaissé / paiement en attente" note AND keep the bump CTA enabled — the chef can
         advance the order; the cashier collects later in /admin/encaissement. (The earlier
         "MUST NOT bump" gate was UI-only — the server never blocked it; comment was inaccurate.)
         For paid orders the note is hidden and only the CTA shows (unchanged). -->
    <div
      v-if="isCashPending"
      class="kds-card__cash-pending kds-card__cash-pending--note"
      role="status"
      :aria-label="$t('label.kds_card_cash_pending_aria')"
      data-testid="kds-card-cash-pending"
    >
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="10" />
        <polyline points="12 6 12 12 16 14" />
      </svg>
      <span>{{ $t('label.kds_card_cash_pending') }}</span>
    </div>
    <button
      type="button"
      class="kds-card__cta"
      @click.prevent="onCta"
      :aria-label="$t('label.kds_card_cta_ready')"
      data-testid="kds-card-cta-ready"
    >
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M5 12l5 5L20 7" />
      </svg>
      <span>{{ $t('label.kds_card_cta_ready') }}</span>
    </button>
  </div>
</template>

<script>
import KdsOrderLine from './KdsOrderLine.vue';
import { renderItem, orderHasAnyAllergen } from '../../../helpers/kdsCustomization.js';
import {
    getKdsAgeBucket,
    parseOrderCreatedMs,
} from '../../../helpers/kdsDisplay.js';
import {
    KDS_STATE_THEME,
    KDS_STATE_I18N_KEYS,
    kdsStateFromStatus,
} from '../../../helpers/kdsState.js';
import {
    KDS_SOURCE_THEME,
    KDS_SOURCE_I18N_KEYS,
    kdsSourceFromSurface,
} from '../../../helpers/kdsSource.js';
// [UR1-002 V1.0.2 Wave B1] phoneDisplay SSOT — mirrors App\Support\PhoneDisplay::safe
import { safePhone } from '../../../helpers/phoneDisplay.js';

const AGE_HEADER_BG = {
    fresh: '#FFFFFF',
    warning: '#FFEDD5',
    critical: '#FEE2E2',
};
const AGE_ELAPSED_COLOR = {
    fresh: '#111827',
    warning: '#9A3412',
    critical: '#991B1B',
};
const AGE_BORDER = {
    fresh: '#E5E7EB',
    warning: '#EA580C',
    critical: '#DC2626',
};

export default {
    name: 'KdsOrderCard',
    components: { KdsOrderLine },
    props: {
        order: {
            type: Object,
            required: true,
        },
        now: {
            type: Number,
            default: () => Date.now(),
        },
        shortcut: {
            type: String,
            default: null,
        },
        // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
        // True when this order is in the 60s RAPPELÉ window after a chef
        // recall. Drives the orange overlay badge at the top of the card.
        recallActive: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['ready'],
    computed: {
        kdsState() {
            return kdsStateFromStatus(this.order.status) || 'NEW';
        },
        kdsSource() {
            return kdsSourceFromSurface(this.order.source_surface);
        },
        stateTheme() {
            return KDS_STATE_THEME[this.kdsState] || KDS_STATE_THEME.NEW;
        },
        sourceTheme() {
            return KDS_SOURCE_THEME[this.kdsSource] || KDS_SOURCE_THEME.POS;
        },
        stateLabel() {
            const k = KDS_STATE_I18N_KEYS[this.kdsState];
            const t = this.$t(k);
            return t === k ? this.kdsState : t;
        },
        sourceLabel() {
            const k = KDS_SOURCE_I18N_KEYS[this.kdsSource];
            const t = this.$t(k);
            return t === k ? this.kdsSource : t;
        },
        statePillStyle() {
            return {
                background: this.stateTheme.bg,
                color: this.stateTheme.text,
                border: this.kdsState === 'NEW' ? `1px solid ${this.stateTheme.border}` : 'none',
            };
        },
        sourceChipStyle() {
            return {
                background: this.sourceTheme.bg,
                color: this.sourceTheme.text,
            };
        },
        hasAllergen() {
            return orderHasAnyAllergen(this.order.order_items || []);
        },
        createdMs() {
            return parseOrderCreatedMs(this.order);
        },
        elapsedSeconds() {
            return Math.max(0, Math.floor((this.now - this.createdMs) / 1000));
        },
        bucket() {
            return getKdsAgeBucket(this.createdMs, this.now);
        },
        headerBg() {
            return AGE_HEADER_BG[this.bucket] || AGE_HEADER_BG.fresh;
        },
        elapsedColor() {
            return AGE_ELAPSED_COLOR[this.bucket] || AGE_ELAPSED_COLOR.fresh;
        },
        elapsedLabelColor() {
            // KDS-R1-02 heal: fixed dark color always (9.5:1 on white, WCAG AA-large) —
            // bucket color stays on the BIG elapsed number for visual hierarchy.
            // Previous bucket-tinted label hit 1.94:1 (axe-confirmed FAIL).
            return '#374151';
        },
        borderColor() {
            // Allergen overrides age — orange regardless of how old.
            if (this.hasAllergen) {
                return '#F97316';
            }
            return AGE_BORDER[this.bucket] || AGE_BORDER.fresh;
        },
        stripeColor() {
            if (this.hasAllergen) {
                return '#F97316';
            }
            if (this.bucket === 'fresh') {
                return this.kdsState === 'PREPARING' ? '#111827' : '#E5E7EB';
            }
            return AGE_BORDER[this.bucket];
        },
        cardStyle() {
            return {
                border: `3px solid ${this.borderColor}`,
                height: '462px',
            };
        },
        cardClass() {
            return {
                'kds-card--critical': this.bucket === 'critical',
                'kds-card--ready': this.kdsState === 'READY',
                'kds-card--cancelled': this.kdsState === 'CANCELLED',
            };
        },
        elapsedFormatted() {
            const s = this.elapsedSeconds;
            const m = Math.floor(s / 60);
            const r = s % 60;
            return `${String(m).padStart(2, '0')}:${String(r).padStart(2, '0')}`;
        },
        ariaLabel() {
            const m = Math.floor(this.elapsedSeconds / 60);
            const r = this.elapsedSeconds % 60;
            return this.$t('label.kds_card_aria', { queue: this.order.queue_number || this.order.id, source: this.sourceLabel, state: this.stateLabel, minutes: m, seconds: r });
        },
        // [Sprint 2A DEL-3 2026-05-16] Delivery-context computeds.
        // Source-truth precedence: explicit source_surface === 'delivery'
        // (set by Order.php boot creating hook) OR legacy order_type === 5
        // (OrderType::DELIVERY) — backwards-compatible with rows created
        // before the source_surface column was added.
        isDeliveryOrder() {
            const surface = String(this.order?.source_surface || '').toLowerCase();
            if (surface === 'delivery') {
                return true;
            }
            // OrderType::DELIVERY === 5 (app/Enums/OrderType.php).
            return Number(this.order?.order_type) === 5;
        },
        deliveryAddress() {
            return this.order?.order_address || null;
        },
        deliveryAddressLine() {
            const a = this.deliveryAddress;
            if (!a) {
                return '';
            }
            const parts = [];
            if (a.apartment) {
                parts.push(a.apartment);
            }
            if (a.address) {
                parts.push(a.address);
            }
            return parts.join(' — ');
        },
        customerName() {
            return this.order?.customer?.name || '';
        },
        customerPhone() {
            // [Sprint 5A Z9-P1-03 + UR1-002 V1.0.2 Wave B1] Hide Sprint 2B
            // legacy sentinels from the tel: link / on-card display.
            // `PENDING_<id>` / `PENDING_CREATE_*` are placeholders injected
            // by User::creating for legacy paths and produce a broken
            // `tel:PENDING_*` href otherwise. Centralized via phoneDisplay
            // SSOT (mirrors App\Support\PhoneDisplay::safe).
            return safePhone(this.order?.customer?.phone);
        },
        // [GOAL-2026-05-30 D1] `payment_pending_counter` (KDSOrderDetailsResource:44 =
        // payment_status === PENDING_COUNTER) now drives only a NON-blocking "non encaissé"
        // NOTE — NOT a bump gate. Owner reversed the Wave S-2 "must wait for cash" rule: the
        // kitchen prepares before encashment; the cashier collects later in /admin/encaissement.
        isCashPending() {
            return this.order?.payment_pending_counter === true;
        },
    },
    methods: {
        renderItemLines(item) {
            return renderItem(item).lines;
        },
        onCta() {
            // [GOAL-2026-05-30 D1] No payment gate — the chef may bump an unpaid (PENDING_COUNTER)
            // order; the "non encaissé" note stays visible and the cashier collects it later.
            this.$emit('ready', this.order.id);
        },
        onCardKeydownEnter() {
            this.onCta();
        },
    },
};
</script>

<style scoped>
.kds-card {
    position: relative;
    display: flex;
    flex-direction: column;
    background: #FFFFFF;
    border-radius: 12px;
    overflow: hidden;
    transition: transform 250ms ease-out, box-shadow 150ms ease, opacity 200ms ease;
    box-shadow: 0 1px 2px rgba(17, 24, 39, 0.04);
}
.kds-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.kds-card:focus-visible {
    outline: 4px solid #EA580C;
    outline-offset: 4px;
}

.kds-card__stripe {
    height: 6px;
    flex-shrink: 0;
}

/* [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
   RAPPELÉ badge — Cayenne orange #F4501E pill positioned over the top stripe
   so it's the dominant 2-3m signal when a card is re-injected post-recall.
   Uses absolute positioning + z-index:5 so it sits over the stripe (z-index:1)
   without disturbing the rest of the card layout. The pulse animation pulls
   peripheral attention; respects `prefers-reduced-motion`. */
.kds-card__recall-badge {
    position: absolute;
    top: 12px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 5;
    background: #F4501E;
    color: #FFFFFF;
    padding: 4px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    box-shadow: 0 2px 8px rgba(244, 80, 30, 0.35);
    animation: kds-recall-pulse 2s ease-in-out infinite;
}
@keyframes kds-recall-pulse {
    0%, 100% { transform: translateX(-50%) scale(1); }
    50% { transform: translateX(-50%) scale(1.05); }
}
@media (prefers-reduced-motion: reduce) {
    .kds-card__recall-badge {
        animation: none;
    }
}

.kds-card__header {
    display: flex;
    flex-direction: column;
    border-bottom: 1px solid #F3F4F6;
}

.kds-card__meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 26px;
    padding: 6px 12px 0;
}
.kds-card__shortcut {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    /* KDS-R1-03 heal: stable WCAG AA contrast regardless of bucket-tinted header.
       Previously #6B7280 on rgba(0,0,0,0.04) gave 3.63:1 on critical bucket (red header)
       and 4.43:1 on fresh — both FAIL AA for normal text. Now opaque gray-100 bg + gray-800
       text = ~12.6:1 stable on every bucket. */
    color: #1F2937;
    background: #F3F4F6;
    min-width: 22px;
    height: 18px;
    border-radius: 4px;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
}
.kds-card__shortcut-spacer,
.kds-card__allergen-spacer {
    width: 22px;
}

.kds-card__state-source {
    display: flex;
    align-items: center;
    gap: 6px;
}
.kds-card__state-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 8px;
    height: 24px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.kds-card__state-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 9999px;
}
.kds-card__source-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 10px;
    height: 28px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.kds-card__allergen-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 0 8px;
    height: 20px;
    border-radius: 4px;
    background: #EA580C;
    color: #FFFFFF;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    box-shadow: 0 0 0 2px rgba(234, 88, 12, 0.30);
}

/* [Wave T R1 F3 WT-B-R1-002 2026-05-20] Responsive type scale with clamp().
   At 1280px viewport the 4-col grid yields ~300px per card. The previous
   52px queue + 34px elapsed + 26px prefix + 0.15em letter-spaced ATTENTE
   label overflowed and `.kds-card { overflow: hidden }` clipped the seconds
   off the elapsed timer (Wave T R1 evidence: "14:26" rendering as "14:2",
   "ATTENTE" rendering as "ATTEN"). Now sized to fit inside 300px at the
   tightest breakpoint while preserving 2m-readability at ≥1600px. */
.kds-card__main {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 8px; /* tightened from 12px to recover horizontal budget */
    padding: 2px 12px 10px; /* tightened from 16px to recover ~8px */
}
.kds-card__queue,
.kds-card__elapsed-wrap {
    flex-shrink: 0; /* KDS-R1-01 heal: never compress, never overlap */
    min-width: 0; /* allow children to participate in the flex shrink calc */
    overflow: visible; /* ensure timer descenders/digits never clipped */
}
.kds-card__queue {
    color: #111827;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: clamp(36px, 4.2vw, 52px);
    font-weight: 800;
    line-height: 1;
    letter-spacing: -0.03em;
    font-variant-numeric: tabular-nums;
}
.kds-card__queue-prefix {
    font-size: clamp(16px, 2vw, 26px);
    font-weight: 700;
    opacity: 0.55;
    margin-inline-end: 2px;
}
.kds-card__elapsed-wrap {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    overflow: visible;
}
.kds-card__elapsed-label {
    font-size: 10px;
    font-weight: 700;
    /* [Wave T R1 F3 WT-B-R1-002 2026-05-20] letter-spacing reduced 0.15em→0.06em
       so "ATTENTE" fits the elapsed-wrap column without clipping at 1280px.
       Still visually distinct as a small-caps eyebrow above the big timer. */
    letter-spacing: 0.06em;
    text-transform: uppercase;
    white-space: nowrap;
    /* KDS-R1-02 heal: removed opacity:0.75 which dropped contrast below WCAG AA. */
}
.kds-card__elapsed {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: clamp(22px, 2.8vw, 34px);
    font-weight: 800;
    line-height: 1;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.kds-card__body {
    flex: 1 1 auto;
    min-height: 0; /* defensive: keep flex child scrollable if future cardStyle drops fixed height */
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 4px 16px;
    position: relative;
    /* [Wave U 2026-05-21] Owner-reported bug: chef could not discover scroll
       on long-item orders (Sandwich Cayenne with 4 variations + extras +
       crudités + sauces + supplements). Firefox thin scrollbar hint +
       webkit 8px (was 4px) + darker thumb so the cue is visible at 2m
       chef distance. */
    scrollbar-width: thin;
    scrollbar-color: #6B7280 transparent;
}
.kds-card__body::-webkit-scrollbar {
    width: 8px;
}
.kds-card__body::-webkit-scrollbar-thumb {
    background: #6B7280;
    border-radius: 4px;
}
.kds-card__body::-webkit-scrollbar-thumb:hover {
    background: #4B5563;
}
.kds-card__body::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.04);
    border-radius: 4px;
}
.kds-card__item-block {
    border-top: 1px solid #F3F4F6;
}
.kds-card__item-block:first-child {
    border-top: none;
}

/* [Sprint 2A DEL-3 2026-05-16] Delivery block — sits at the top of the
   card body, quiet teal accent (matches KDS_SOURCE.DELIVERY palette in
   helpers/kdsSource.js). Never out-screams the queue number or timer. */
.kds-card__delivery {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 10px;
    margin: 4px 0 6px;
    border-radius: 8px;
    background: #F0FDFA;
    border-left: 3px solid #0F766E;
}
.kds-card__delivery-row {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    line-height: 1.3;
    color: #0F766E;
    font-weight: 600;
    text-decoration: none;
}
.kds-card__delivery-row--muted {
    color: #115E59;
    font-weight: 500;
}
.kds-card__delivery-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    font-size: 14px;
    flex-shrink: 0;
}
.kds-card__delivery-text {
    min-width: 0;
    overflow-wrap: anywhere;
}
.kds-card__delivery-phone {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-weight: 700;
    letter-spacing: 0.02em;
    cursor: pointer;
}
.kds-card__delivery-phone:hover {
    text-decoration: underline;
}
.kds-card__delivery-phone:focus-visible {
    outline: 2px solid #0F766E;
    outline-offset: 2px;
    border-radius: 4px;
}

.kds-card__body-fade {
    position: sticky;
    bottom: 0;
    height: 0;
    pointer-events: none; /* never intercept scroll wheel / touch */
}
.kds-card__body-fade::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    /* [Wave U 2026-05-21] Reduced 16px → 8px so the fade hints "more content
       below" without masking a full line of text. With the 8px-wide
       scrollbar now visible, the fade is decorative only. */
    height: 8px;
    background: linear-gradient(to top, rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0));
}

.kds-card__cta {
    margin: 4px 8px 8px;
    height: 52px;
    background: #1F2937;
    color: #FFFFFF;
    border: 0;
    border-radius: 12px;
    font-size: 22px;
    font-weight: 700;
    letter-spacing: 0.01em;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    transition: transform 100ms ease;
}
.kds-card__cta:active {
    transform: translateY(1px);
}
.kds-card__cta:focus-visible {
    outline: 4px solid #4B5563;
    outline-offset: 2px;
}

/* [Wave S-2 P-OWNER 2026-05-20] Cash-pending passive badge.
   Replaces the CTA when the order is awaiting cashier encaissement.
   Visual contract: amber/orange tones signal "wait", NEVER green-go.
   Same vertical footprint as the CTA (52px tall + 8px margin) so the
   grid card height stays stable. WCAG: #92400E on #FEF3C7 = 7.94:1 AAA. */
.kds-card__cash-pending {
    margin: 4px 8px 8px;
    height: 52px;
    background: #FEF3C7;
    color: #92400E;
    border: 2px dashed #F59E0B;
    border-radius: 12px;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    cursor: default;
}

/* age critical pulse on time digit (1Hz) */
@keyframes kds-card-pulse-digit {
    0%, 100% { opacity: 1; }
    50%      { opacity: 0.55; }
}
.kds-pulse-digit {
    animation: kds-card-pulse-digit 1s ease-in-out infinite;
}

.kds-card--ready {
    opacity: 0.7;
}
.kds-card--cancelled {
    background: rgba(254, 226, 226, 0.5);
}

@media (prefers-reduced-motion: reduce) {
    .kds-card,
    .kds-pulse-digit,
    .kds-card__cta {
        transition: none !important;
        animation: none !important;
    }
}
</style>
