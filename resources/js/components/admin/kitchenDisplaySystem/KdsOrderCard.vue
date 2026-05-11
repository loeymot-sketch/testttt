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
    @keydown.enter="onCta"
  >
    <!-- TOP ACCENT STRIPE — dominant 2m signal -->
    <div class="kds-card__stripe" :style="{ background: stripeColor }"></div>

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
    <div class="kds-card__body">
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

    <!-- FOOTER CTA -->
    <button
      type="button"
      class="kds-card__cta"
      @click.prevent="onCta"
      :aria-label="$t('label.kds_card_cta_ready')"
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
            return this.bucket === 'fresh' ? '#9CA3AF' : this.elapsedColor;
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
            return `Commande ${this.order.queue_number || this.order.id}, source ${this.sourceLabel}, ${this.stateLabel}, attente ${m} minutes ${r} secondes`;
        },
    },
    methods: {
        renderItemLines(item) {
            return renderItem(item).lines;
        },
        onCta() {
            this.$emit('ready', this.order.id);
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
    color: #6B7280;
    background: rgba(0, 0, 0, 0.04);
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

.kds-card__main {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    padding: 2px 16px 10px;
}
.kds-card__queue {
    color: #111827;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 52px;
    font-weight: 800;
    line-height: 1;
    letter-spacing: -0.03em;
    font-variant-numeric: tabular-nums;
}
.kds-card__queue-prefix {
    font-size: 26px;
    font-weight: 700;
    opacity: 0.55;
    margin-inline-end: 2px;
}
.kds-card__elapsed-wrap {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
}
.kds-card__elapsed-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    opacity: 0.75;
}
.kds-card__elapsed {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 34px;
    font-weight: 800;
    line-height: 1;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
}

.kds-card__body {
    flex: 1;
    overflow-y: auto;
    padding: 4px 16px;
    position: relative;
}
.kds-card__body::-webkit-scrollbar {
    width: 4px;
}
.kds-card__body::-webkit-scrollbar-thumb {
    background: #D1D5DB;
    border-radius: 2px;
}
.kds-card__body::-webkit-scrollbar-track {
    background: transparent;
}
.kds-card__item-block {
    border-top: 1px solid #F3F4F6;
}
.kds-card__item-block:first-child {
    border-top: none;
}
.kds-card__body-fade {
    position: sticky;
    bottom: 0;
    height: 0;
    pointer-events: none;
}
.kds-card__body-fade::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 16px;
    background: linear-gradient(to top, #FFFFFF, rgba(255, 255, 255, 0));
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
