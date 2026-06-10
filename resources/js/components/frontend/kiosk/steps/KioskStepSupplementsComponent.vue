<template>
  <div class="kiosk-step-supplements">
    <h3 class="kiosk-step-title">{{ $t('kiosk.wizard.supplements_step_title') }}</h3>

    <div class="kiosk-supplements-info">
      <span class="kiosk-info-badge">{{ $t('kiosk.wizard.supplements_badge_paid') }}</span>
      <span v-if="totalPrice > 0" class="kiosk-supplements-price">
        +{{ formatPrice(totalPrice) }}
      </span>
    </div>

    <div v-if="supplementList.length === 0" class="kiosk-empty-state">
      <span class="kiosk-empty-emoji">🍽️</span>
      <p>{{ $t('kiosk.wizard.supplements_empty') }}</p>
    </div>

    <div v-else class="kiosk-supplements-list">
      <div
        v-for="supplement in supplementList"
        :key="supplement.id"
        class="kiosk-supplement-row"
        :class="{
          selected: supplementCount(supplement.id) > 0,
          'is-selectable': supplementFilterAllowed(supplement) && !isSupplementOos(supplement) && supplementCount(supplement.id) === 0,
          'kiosk-variation--disabled': !supplementFilterAllowed(supplement) || isSupplementOos(supplement),
          'is-out-of-stock': isSupplementOos(supplement),
        }"
        role="group"
        :tabindex="(supplementFilterAllowed(supplement) && !isSupplementOos(supplement)) ? 0 : -1"
        :aria-disabled="(supplementFilterAllowed(supplement) && !isSupplementOos(supplement)) ? 'false' : 'true'"
        :aria-label="`${$t('kiosk.wizard.supplement_qty_label', { name: supplement.name })} ${formatPrice(supplement.price)} ${supplementCount(supplement.id)}`"
        :title="supplementOosTooltip(supplement) || supplementFilterTooltip(supplement)"
        @click="selectFromCard(supplement.id)"
        @keydown.enter.prevent="selectFromCard(supplement.id)"
        @keydown.space.prevent="selectFromCard(supplement.id)"
      >
        <div class="kiosk-supplement-visual">
          <img
            v-if="supplement.thumb && !brokenSupplementThumbs[supplementThumbKey(supplement)]"
            :src="supplement.thumb"
            class="kiosk-supplement-img"
            loading="lazy"
            :alt="supplement.name"
            @error="onSupplementThumbError(supplement)"
          />
          <span class="kiosk-supplement-emoji" v-else>{{ supplement.emoji }}</span>
        </div>
        <div class="kiosk-supplement-details">
          <span class="kiosk-supplement-name">{{ supplement.name }}</span>
          <span class="kiosk-supplement-desc">{{ supplement.description || $t('kiosk.wizard.supplement_default_desc') }}</span>
          <span
            v-if="isSupplementOos(supplement)"
            class="kiosk-extra-oos-badge"
            data-testid="kiosk-extra-oos-badge"
            :aria-label="$t('pos.item_86_d')"
          >{{ $t('pos.item_86_d') }}</span>
        </div>
        <span class="kiosk-supplement-price">
          {{ formatPrice(supplement.price) }}
          <span v-if="supplementCount(supplement.id) > 1" class="kiosk-supplement-multiplier">
            ×{{ supplementCount(supplement.id) }}
          </span>
        </span>
        <div
          v-if="supplementCount(supplement.id) > 0"
          class="kiosk-supplement-qty"
          role="group"
          :aria-label="$t('kiosk.wizard.supplement_qty_label', { name: supplement.name })"
          @click.stop
        >
          <button
            type="button"
            class="kiosk-supplement-qty-btn"
            :disabled="supplementCount(supplement.id) <= 0 || !supplementFilterAllowed(supplement)"
            :aria-label="$t('kiosk.wizard.supplement_decrease', { name: supplement.name })"
            @click="decrementSupplement(supplement.id)"
          >−</button>
          <span class="kiosk-supplement-qty-value" aria-live="polite">
            {{ supplementCount(supplement.id) }}
          </span>
          <button
            type="button"
            class="kiosk-supplement-qty-btn active"
            :disabled="!supplementFilterAllowed(supplement) || isSupplementOos(supplement)"
            :aria-label="$t('kiosk.wizard.supplement_increase', { name: supplement.name })"
            @click="incrementSupplement(supplement.id)"
          >+</button>
        </div>
        <div v-else class="kiosk-supplement-select-hint">
          {{ $t('kiosk.wizard.tap_to_choose') }}
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { kioskResolveImageSrc } from '../../../../helpers/kioskMedia';
import { kioskPriceMixin } from '../../../../helpers/kioskFormatPrice';
import { partitionKioskExtras } from '../../../../helpers/kioskExtrasPartition';
import { isVariationAllowedByFilters } from '../../../../helpers/kioskFilters';

export default {
  name: 'KioskStepSupplements',
  mixins: [kioskPriceMixin],
  props: {
    step: Object,
    item: Object,
    selections: Object,
    activeFilters: { type: Array, default: () => [] },
  },
  emits: ['update'],
  data() {
    return {
      localSelections: { ...this.selections.supplements },
      brokenSupplementThumbs: {},
    };
  },
  watch: {
    'selections.supplements': {
      deep: true,
      handler(newVal) {
        this.localSelections = { ...(newVal || {}) };
      },
    },
  },
  computed: {
    // [AUDIT 2026-04-17 C4] Source unique : helper partitionKioskExtras —
    // les extras "supplements" excluent automatiquement sauces, upgrades
    // frites (étape Formule) et viandes payantes (étape Viande).
    supplementList() {
      return partitionKioskExtras(this.item).supplements.map(s => ({
        id: s.id,
        name: s.name,
        price: s.price,
        description: s.raw?.description || '',
        thumb: kioskResolveImageSrc(s.raw),
        emoji: this.getEmojiForSupplement(s.name),
        raw: s.raw,
        // [HEAL-A 2026-05-08] Surface OOS state for UI marker — order can still
        // pass; we only block selection of this specific row.
        is_available: s.is_available !== false,
        unavailable_reason: s.unavailable_reason || null,
      }));
    },
    totalPrice() {
      return this.supplementList.reduce((sum, s) => {
        return sum + (s.price * this.supplementCount(s.id));
      }, 0);
    }
  },
  methods: {
    normalizeCount(value) {
      if (value === true) return 1;
      const count = parseInt(value, 10);
      if (!Number.isFinite(count) || count <= 0) return 0;
      return Math.min(count, 9);
    },
    supplementCount(id) {
      return this.normalizeCount(this.localSelections?.[id]);
    },
    emitSupplements(nextSelections) {
      const normalized = {};
      Object.entries(nextSelections || {}).forEach(([key, value]) => {
        const count = this.normalizeCount(value);
        if (count > 0) normalized[key] = count;
      });
      this.localSelections = normalized;
      this.$emit('update', 'supplements', normalized);
    },
    supplementThumbKey(supplement) {
      return String(supplement.id ?? supplement.name ?? '');
    },
    onSupplementThumbError(supplement) {
      const k = this.supplementThumbKey(supplement);
      this.brokenSupplementThumbs = { ...this.brokenSupplementThumbs, [k]: true };
    },
    getEmojiForSupplement(name) {
      const lower = (name || '').toLowerCase();
      if (lower.includes('fromage') || lower.includes('cheddar') || lower.includes('cheese')) return '🧀';
      if (lower.includes('bacon')) return '🥓';
      if (lower.includes('oeuf') || lower.includes('egg')) return '🥚';
      if (lower.includes('avocat')) return '🥑';
      if (lower.includes('champignon') || lower.includes('mushroom')) return '🍄';
      if (lower.includes('frites') || lower.includes('fry')) return '🍟';
      if (lower.includes('boisson') || lower.includes('soda') || lower.includes('drink')) return '🥤';
      if (lower.includes('glace') || lower.includes('ice cream')) return '🍦';
      return '🍽️';
    },
    supplementFilterAllowed(supplement) {
      return isVariationAllowedByFilters(supplement?.raw || supplement, this.activeFilters || []);
    },
    supplementFilterTooltip(supplement) {
      if (this.supplementFilterAllowed(supplement)) return '';
      return (this.activeFilters || []).map((f) => this.$t(`kiosk.filters.${f}`)).filter(Boolean).join(', ');
    },
    // [HEAL-A 2026-05-08] OOS helpers — read snapshot is_available propagated
    // by partitionKioskExtras. Order can still submit; only the row is locked.
    isSupplementOos(supplement) {
      if (!supplement) return false;
      if (supplement.is_available === false) return true;
      return supplement?.raw?.is_available === false;
    },
    supplementOosTooltip(supplement) {
      if (!this.isSupplementOos(supplement)) return '';
      return supplement?.unavailable_reason || supplement?.raw?.unavailable_reason || this.$t('pos.item_86_d');
    },
    setSupplementCount(id, count) {
      const s = this.supplementList.find((x) => x.id === id);
      if (s && (!this.supplementFilterAllowed(s) || this.isSupplementOos(s))) {
        const isDecrement = this.normalizeCount(count) < this.supplementCount(id);
        if (!isDecrement) return;
      }
      const next = { ...this.localSelections };
      const normalizedCount = this.normalizeCount(count);
      if (normalizedCount > 0) next[id] = normalizedCount;
      else delete next[id];
      this.emitSupplements(next);
    },
    incrementSupplement(id) {
      this.setSupplementCount(id, this.supplementCount(id) + 1);
    },
    decrementSupplement(id) {
      this.setSupplementCount(id, this.supplementCount(id) - 1);
    },
    selectFromCard(id) {
      if (this.supplementCount(id) > 0) return;
      this.incrementSupplement(id);
    },
    toggleSupplement(id) {
      const s = this.supplementList.find((x) => x.id === id);
      if (s && !this.supplementFilterAllowed(s)) return;
      this.setSupplementCount(id, this.supplementCount(id) > 0 ? 0 : 1);
    }
  }
};
</script>

<style scoped>
.kiosk-step-supplements {
  padding: 6px 18px 24px;
  background: transparent;
  min-height: 100%;
}

.kiosk-step-title {
  font-size: 15px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 12px;
  color: var(--kiosk-text, #333);
}

.kiosk-supplements-info {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-bottom: 14px;
}

.kiosk-info-badge {
  background: var(--kiosk-primary-soft, rgba(244, 80, 30,0.06));
  border: 1px solid var(--kiosk-border, rgba(244, 80, 30,0.2));
  color: var(--kiosk-primary, #F4501E);
  padding: 6px 16px;
  border-radius: 50px;
  font-size: 12px;
  font-weight: 700;
}

.kiosk-supplements-price {
  font-size: 16px;
  font-weight: 800;
  color: var(--kiosk-primary, #F4501E);
  background: var(--kiosk-primary-soft, rgba(244, 80, 30,0.06));
  padding: 4px 12px;
  border-radius: 50px;
}

.kiosk-empty-state {
  text-align: center;
  padding: 40px 24px;
  color: var(--kiosk-text-muted, #999);
}

.kiosk-empty-emoji {
  font-size: 48px;
  display: block;
  margin-bottom: 12px;
}

.kiosk-empty-state p { font-size: 15px; }

/* [BU-01 2026-06-10] Responsive grid fills the canvas — was rigid 2-col 860px
   which left big side gaps on a portrait borne; now reflows to 3 wide. */
.kiosk-supplements-list {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 24px 20px;
  max-width: 1100px;
  margin: 0 auto;
  align-content: start;
}

.kiosk-supplement-row {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  gap: 8px;
  min-height: 228px;
  padding: 14px 12px 16px;
  border-radius: 20px;
  border: 1px solid var(--kiosk-border, #efefef);
  background: var(--kiosk-surface, #fff);
  cursor: pointer;
  touch-action: manipulation;
  transition:
    border-color 0.18s ease,
    background-color 0.18s ease,
    box-shadow 0.18s ease,
    transform 0.18s ease;
  position: relative;
}

.kiosk-supplement-row:active { transform: scale(0.99); }

.kiosk-supplement-row:focus-visible {
  outline: 3px solid rgba(244, 80, 30, 0.55);
  outline-offset: 2px;
}

/* [BU-02 2026-06-10] Harmonized multi-select ring with the other steps. */
.kiosk-supplement-row.selected {
  border: 2px solid var(--kiosk-primary, #F4501E);
  background: rgba(244, 80, 30, 0.08);
  box-shadow: 0 0 0 3px rgba(244, 80, 30, 0.16), var(--kiosk-shadow-card, none);
}

.kiosk-supplement-row.kiosk-variation--disabled {
  opacity: 0.42;
  filter: grayscale(0.3);
  cursor: not-allowed;
}

/* [HEAL-A 2026-05-08] OOS marker — visual signal that this extra is in rupture
 * stock. The order can still pass; this row alone is locked. */
.kiosk-supplement-row.is-out-of-stock {
  opacity: 0.5;
  filter: grayscale(0.4);
  cursor: not-allowed;
}

.kiosk-extra-oos-badge {
  display: inline-block;
  margin-top: 2px;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--kiosk-primary-soft, rgba(244, 80, 30,0.1));
  color: var(--kiosk-primary, #F4501E);
  border: 1px solid var(--kiosk-border, rgba(244, 80, 30,0.25));
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.kiosk-supplement-row.selected {
  cursor: default;
}

.kiosk-supplement-visual {
  width: 126px;
  height: 126px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-supplement-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.kiosk-supplement-emoji {
  font-size: 46px;
  width: 126px;
  height: 126px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--kiosk-product-media-bg, #f7f7f8);
  border-radius: 50%;
}

.kiosk-supplement-details {
  display: flex;
  flex-direction: column;
  gap: 2px;
  align-items: center;
}

.kiosk-supplement-name {
  font-size: 13px;
  font-weight: 700;
  color: var(--kiosk-text, #444);
  text-align: center;
  text-transform: uppercase;
  line-height: 1.15;
}

.kiosk-supplement-desc {
  font-size: 11px;
  color: var(--kiosk-text-muted, #999);
  text-align: center;
}

.kiosk-supplement-price {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
  font-weight: 800;
  color: var(--kiosk-text, #222);
}

.kiosk-supplement-multiplier {
  min-width: 34px;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--kiosk-primary-soft, rgba(244, 80, 30,0.08));
  color: var(--kiosk-primary, #f4501e);
  font-size: 12px;
}

.kiosk-supplement-qty {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-top: auto;
  padding: 6px;
  border-radius: 999px;
  background: var(--kiosk-surface-alt, #f5f5f6);
  border: 1px solid var(--kiosk-border, #ececec);
}

.kiosk-supplement-select-hint {
  min-height: 44px;
  min-width: 132px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-top: auto;
  padding: 0 16px;
  border-radius: 999px;
  background: var(--kiosk-primary-soft, rgba(244, 80, 30,0.08));
  color: var(--kiosk-primary, #f4501e);
  border: 1px solid var(--kiosk-border, rgba(244, 80, 30,0.18));
  font-size: 12px;
  font-weight: 900;
  text-transform: uppercase;
}

.kiosk-supplement-qty-btn {
  width: 38px;
  height: 38px;
  border-radius: 999px;
  border: 1px solid var(--kiosk-border-strong, #d8d8d8);
  background: var(--kiosk-surface, #fff);
  color: var(--kiosk-text-muted, #777);
  font-size: 22px;
  line-height: 1;
  font-weight: 800;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  touch-action: manipulation;
}

.kiosk-supplement-qty-btn.active {
  border-color: var(--kiosk-primary, #f4501e);
  background: var(--kiosk-primary, #f4501e);
  color: var(--kiosk-text-on-red, #fff);
  box-shadow: var(--kiosk-shadow-cta, 0 10px 20px rgba(244, 80, 30,0.24));
}

.kiosk-supplement-qty-btn:disabled {
  opacity: 0.46;
  cursor: not-allowed;
}

.kiosk-supplement-qty-btn:focus-visible {
  outline: 3px solid rgba(244, 80, 30, 0.55);
  outline-offset: 2px;
}

.kiosk-supplement-qty-value {
  min-width: 28px;
  text-align: center;
  font-size: 18px;
  font-weight: 900;
  color: var(--kiosk-text, #222);
}
</style>
