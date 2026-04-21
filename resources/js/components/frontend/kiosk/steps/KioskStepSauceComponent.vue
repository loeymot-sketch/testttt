<template>
  <div class="kiosk-step-sauce">
    <h3 class="kiosk-step-title">{{ $t('kiosk.wizard.step.sauce.title') }}</h3>

    <div class="kiosk-sauce-info">
      <span class="kiosk-sauce-badge">{{ $t('kiosk.wizard.step.sauce.first_free') }}</span>
      <span v-if="selectedCount > 1" class="kiosk-sauce-extra">
        {{ sauceExtraLine }}
      </span>
    </div>

    <div v-if="sauceList.length === 0" class="kiosk-step-empty" role="status" aria-live="polite">
      <p>{{ $t('kiosk.wizard.step.sauce.empty_hint') }}</p>
      <button type="button"
        class="kiosk-btn-continue"
        :aria-label="$t('kiosk.wizard.step.sauce.skip_btn')"
        @click="$emit('update', 'sauceOrder', ['_skip'])">{{ $t('kiosk.wizard.step.sauce.skip_btn') }}</button>
    </div>

    <div v-else class="kiosk-sauce-grid">
      <div
        v-for="(sauce, sIdx) in sauceList"
        :key="sauce.rowKey || ('sauce-' + sIdx + '-' + String(sauce.id ?? sauce.name ?? 'x'))"
        class="kiosk-option-card"
        :class="{
          selected: !!localSelections[selectionKey(sauce)],
          'kiosk-variation--disabled': !sauceFilterAllowed(sauce),
        }"
        role="checkbox"
        :tabindex="sauceFilterAllowed(sauce) ? 0 : -1"
        :aria-checked="!!localSelections[selectionKey(sauce)]"
        :aria-disabled="sauceFilterAllowed(sauce) ? 'false' : 'true'"
        :aria-label="sauce.name"
        :title="sauceFilterTooltip(sauce)"
        @click="toggleSauce(sauce)"
        @keydown.enter.prevent="toggleSauce(sauce)"
        @keydown.space.prevent="toggleSauce(sauce)"
      >
        <div class="kiosk-sauce-media">
          <img
            v-if="sauce.thumb && !brokenSauceThumbs[sauceThumbKey(sauce)]"
            :key="`${sauceThumbKey(sauce)}-${sauce.thumb}`"
            :src="sauce.thumb"
            :alt="sauce.name"
            class="kiosk-sauce-thumb"
            loading="lazy"
            @error="onSauceThumbError(sauce)"
          />
          <span v-else class="kiosk-sauce-emoji">{{ sauce.emoji }}</span>
        </div>
        <span class="kiosk-sauce-name">{{ sauce.name }}</span>
        <span class="kiosk-sauce-price">{{ sauceUnitPriceLabel(sauce) }}</span>
        <span v-if="getSauceOrder(sauceKey(sauce)) > 0" class="kiosk-sauce-order">{{ getSauceOrder(sauceKey(sauce)) }}</span>
        <span v-else class="kiosk-sauce-add">+</span>
      </div>
    </div>

    <div v-if="selectedCount === 0" class="kiosk-validation-hint" role="status" aria-live="polite">
      {{ $t('kiosk.wizard.step.sauce.hint') }}
    </div>
  </div>
</template>

<script>
import { kioskResolveImageSrc } from '../../../../helpers/kioskMedia';
import { kioskPriceMixin } from '../../../../helpers/kioskFormatPrice';
import { getKioskExtraSauceUnitPrice } from '../../../../helpers/kioskPricing';
import { isVariationAllowedByFilters } from '../../../../helpers/kioskFilters';

export default {
  name: 'KioskStepSauce',
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
      localSelections: { ...this.selections.sauces },
      sauceOrder: [...(this.selections.sauceOrder || [])],
      brokenSauceThumbs: {},
    };
  },
  watch: {
    selections: {
      deep: true,
      handler(s) {
        this.localSelections = { ...(s.sauces || {}) };
        this.sauceOrder = [...(s.sauceOrder || [])];
      },
    },
  },
  computed: {
    selectedCount() {
      return Object.values(this.localSelections).filter(Boolean).length;
    },
    extraSaucePrice() {
      return getKioskExtraSauceUnitPrice(this.item);
    },
    extraSaucePriceLabel() {
      return this.formatPrice(this.extraSaucePrice);
    },
    sauceExtraLine() {
      const n = this.selectedCount - 1;
      const price = this.extraSaucePriceLabel;
      return n === 1
        ? this.$t('kiosk.wizard.step.sauce.extra_one', { n, price })
        : this.$t('kiosk.wizard.step.sauce.extra_many', { n, price });
    },
    sauceList() {
      try {
        return this.computeSauceList();
      } catch (e) {
        if (typeof console !== 'undefined' && console.warn) {
          console.warn('[KioskStepSauce] sauceList → catalog error', e);
        }
        // [AUDIT 2026-04-17 C1] Plus de fallback hardcodé : on retourne [] et
        // l'état vide (template v-if="sauceList.length === 0") prend le relais
        // avec un bouton "continuer sans sauce" (_skip défensif).
        return [];
      }
    }
  },
  methods: {
    /** API / JSON : itemAttributes parfois objet indexé ; .find sinon TypeError → grille vide */
    normalizeAttributes(attrs) {
      if (attrs == null) return [];
      if (Array.isArray(attrs)) return attrs;
      return Object.values(attrs);
    },
    /** variations[attrId] : tableau ou objet ; clés string/number */
    variationsRowsForAttribute(variations, attrId) {
      if (variations == null || attrId == null) return [];
      const raw =
        variations[String(attrId)] ??
        variations[attrId];
      if (raw == null) return [];
      if (Array.isArray(raw)) return raw;
      if (typeof raw === 'object') return Object.values(raw);
      return [];
    },
    isSauceLikeAttributeName(name) {
      const n = (name || '').toLowerCase();
      return (
        n.includes('sauce') ||
        n.includes('condiment') ||
        n.includes('dressing') ||
        n.includes('dip')
      );
    },
    computeSauceList() {
      const attrs = this.normalizeAttributes(this.item?.itemAttributes);
      const sauceAttr = attrs.find((a) => this.isSauceLikeAttributeName(a.name));

      const sauceVariations = sauceAttr
        ? this.variationsRowsForAttribute(this.item?.variations, sauceAttr.id)
        : [];

      if (!sauceAttr || sauceVariations.length === 0) {
        // [AUDIT 2026-04-17 C1] Plus de fallback : si le catalogue ne déclare
        // pas de sauce, on retourne vide (l'étape est alors masquée par
        // KioskWizard.shouldShowStep ou affiche le bouton "continuer sans").
        return [];
      }

      // Backend : Status::ACTIVE = 5, INACTIVE = 10 (pas 0/1)
      return sauceVariations
        .filter((v) => v != null && Number(v.status) !== 10)
        .map((v) => ({
          rowKey: v.id != null && v.id !== '' ? `sauce-var-${v.id}` : null,
          id: v.id,
          name: v.name,
          emoji: this.getEmojiForSauce(v.name),
          thumb: kioskResolveImageSrc(v),
          raw: v,
        }));
    },
    sauceUnitPriceLabel(sauce) {
      return this.getSauceOrder(this.sauceKey(sauce)) > 1 ? this.extraSaucePriceLabel : this.formatPrice(0);
    },
    selectionKey(sauce) {
      return String(this.sauceKey(sauce));
    },
    getEmojiForSauce(name) {
      const lower = (name || '').toLowerCase();
      if (lower.includes('algérienne') || lower.includes('samouraï') || lower.includes('harissa')) return '🌶️';
      if (lower.includes('blanche') || lower.includes('fromagère') || lower.includes('cheddar')) return '🧀';
      if (lower.includes('ketchup') || lower.includes('tomate') || lower.includes('bbq')) return '🍅';
      if (lower.includes('mayonnaise') || lower.includes('mayo')) return '🥚';
      if (lower.includes('biggy') || lower.includes('burger') || lower.includes('moutarde')) return '🍔';
      if (lower.includes('tartare') || lower.includes('poivre')) return '🧂';
      return '🥄';
    },
    // ID numérique (number ou string JSON) → number pour cohérence avec item.variations
    sauceKey(sauce) {
      if (sauce.id !== null && sauce.id !== undefined && sauce.id !== '') {
        const n = Number(sauce.id);
        if (!Number.isNaN(n) && Number.isFinite(n)) return n;
      }
      return sauce.name;
    },
    sauceThumbKey(sauce) {
      return String(sauce.id ?? sauce.name ?? '');
    },
    onSauceThumbError(sauce) {
      const k = this.sauceThumbKey(sauce);
      this.brokenSauceThumbs = { ...this.brokenSauceThumbs, [k]: true };
    },
    getSauceOrder(key) {
      const index = this.sauceOrder.findIndex(k => String(k) === String(key));
      return index >= 0 ? index + 1 : 0;
    },
    sauceFilterAllowed(sauce) {
      return isVariationAllowedByFilters(sauce?.raw || sauce, this.activeFilters || []);
    },
    sauceFilterTooltip(sauce) {
      if (this.sauceFilterAllowed(sauce)) return '';
      return (this.activeFilters || []).map((f) => this.$t(`kiosk.filters.${f}`)).filter(Boolean).join(', ');
    },
    toggleSauce(sauce) {
      if (!this.sauceFilterAllowed(sauce)) return;
      const key = this.sauceKey(sauce);
      const selKey = String(key);
      const newSelections = { ...this.localSelections };
      const newSauceOrder = [...this.sauceOrder];
      const orderIndex = newSauceOrder.findIndex(k => String(k) === String(key));
      if (newSelections[selKey]) {
        delete newSelections[selKey];
        if (orderIndex > -1) newSauceOrder.splice(orderIndex, 1);
      } else {
        newSelections[selKey] = true;
        newSauceOrder.push(key);
      }
      this.localSelections = newSelections;
      this.sauceOrder = newSauceOrder;
      this.$emit('update', 'sauces', newSelections);
      this.$emit('update', 'sauceOrder', newSauceOrder);
    }
  }
};
</script>

<style scoped>
.kiosk-step-sauce {
  padding: 6px 18px 26px;
  background: #fff;
  min-height: 100%;
}

.kiosk-step-title {
  font-size: 15px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 10px;
  color: #333;
}

.kiosk-sauce-info {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  margin-bottom: 14px;
}

.kiosk-sauce-badge {
  background: transparent;
  border: none;
  color: #7d7d7d;
  padding: 0;
  border-radius: 50px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.kiosk-sauce-extra {
  font-size: 12px;
  color: #E8001C;
  font-weight: 600;
  background: rgba(232,0,28,0.05);
  padding: 4px 10px;
  border-radius: 50px;
}

.kiosk-sauce-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 24px 18px;
  max-width: 1040px;
  margin: 0 auto;
}

.kiosk-option-card {
  min-height: 188px;
  border-radius: 20px;
  border: 1px solid transparent;
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 10px 10px 14px;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.18s ease;
  position: relative;
}

.kiosk-option-card:active { transform: scale(0.95); }

/* [AUDIT 2026-04-17 C6] Keyboard focus ring — tactile cards are now role=checkbox. */
.kiosk-option-card:focus-visible {
  outline: 3px solid rgba(232, 0, 28, 0.55);
  outline-offset: 2px;
}

.kiosk-option-card.selected {
  border-color: rgba(232,0,28,0.14);
  background: rgba(232,0,28,0.025);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
}

.kiosk-option-card.kiosk-variation--disabled {
  opacity: 0.42;
  filter: grayscale(0.3);
  cursor: not-allowed;
}

.kiosk-sauce-media {
  width: 112px;
  height: 112px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 6px 0 12px;
}

.kiosk-sauce-thumb {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.kiosk-sauce-emoji {
  width: 112px;
  height: 112px;
  border-radius: 50%;
  background: #f7f7f8;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 46px;
  transition: transform 0.2s;
}

.kiosk-option-card.selected .kiosk-sauce-emoji { transform: scale(1.08); }

.kiosk-sauce-name {
  font-size: 12px;
  font-weight: 700;
  color: #3f3f3f;
  text-align: center;
  line-height: 1.2;
  text-transform: uppercase;
  min-height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-sauce-price {
  margin-top: 2px;
  font-size: 12px;
  font-weight: 700;
  color: #222;
}

.kiosk-sauce-order {
  position: absolute;
  top: 12px;
  inset-inline-end: 22px;
  width: 28px;
  height: 28px;
  background: #d7263d;
  color: white;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 800;
  animation: popIn 0.2s cubic-bezier(0.34,1.56,0.64,1);
}

.kiosk-sauce-add {
  position: absolute;
  top: 12px;
  inset-inline-end: 22px;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: #d7263d;
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  line-height: 1;
  box-shadow: 0 3px 10px rgba(215,38,61,0.2);
  outline: 2px solid rgba(255,255,255,0.85);
}

@keyframes popIn {
  from { transform: scale(0); }
  to   { transform: scale(1); }
}

.kiosk-validation-hint {
  text-align: center;
  margin-top: 16px;
  font-size: 13px;
  color: #E8001C;
  font-weight: 600;
  padding: 8px 14px;
  background: rgba(232,0,28,0.06);
  border-radius: 10px;
}
</style>
