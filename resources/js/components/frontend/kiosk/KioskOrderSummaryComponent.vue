<template>
  <div class="kiosk-order-summary" data-testid="kiosk-order-summary-root">
    <!-- Item principal -->
    <div class="kiosk-summary-item main" data-testid="kiosk-order-summary-main-item">
      <div class="kiosk-summary-img" aria-hidden="true">
        <img v-if="item.thumb" :src="item.thumb" :alt="sanitizeItemName(item.name)" />
        <span v-else class="kiosk-summary-emoji">🍽️</span>
      </div>
      <div class="kiosk-summary-details">
        <span class="kiosk-summary-name" data-testid="kiosk-order-summary-main-name">{{ sanitizeItemName(item.name) }}</span>
        <span class="kiosk-summary-price" data-testid="kiosk-order-summary-main-price">{{ formatPrice(item.convert_price) }}</span>
      </div>
    </div>

    <!-- Sélections -->
    <div class="kiosk-summary-sections" role="list">
      <!-- Pain -->
      <div v-if="selections.pain" class="kiosk-summary-section">
        <h4>{{ $t('kiosk.wizard.summary.bread_type') }}</h4>
        <div class="kiosk-summary-row">
          <span>{{ getPainName() }}</span>
          <span class="kiosk-free">{{ $t('kiosk.wizard.summary.included') }}</span>
        </div>
      </div>
      
      <!-- Viandes (variations gratuites + extras payants : prix affiché par ligne) -->
      <div v-if="selections.totalViandes > 0" class="kiosk-summary-section">
        <h4>{{ $t('kiosk.wizard.summary.meats') }} ({{ selections.totalViandes }})</h4>
        <div v-for="row in viandeDisplayRows" :key="row.key" class="kiosk-summary-row">
          <span v-if="row.count > 0">{{ row.label }} ×{{ row.count }}</span>
          <span v-if="row.count > 0 && row.unitPrice > 0" class="kiosk-price">
            +{{ formatPrice(row.unitPrice * row.count) }}
          </span>
          <span v-else-if="row.count > 0" class="kiosk-free">{{ $t('kiosk.wizard.summary.included') }}</span>
        </div>
      </div>

      <!-- Sauces (filtre _skip défensif : ne jamais afficher comme sauce) -->
      <div v-if="visibleSauceOrder.length > 0" class="kiosk-summary-section">
        <h4>{{ $t('kiosk.wizard.summary.sauces') }} ({{ visibleSauceOrder.length }})</h4>
        <div v-for="(sauceId, index) in visibleSauceOrder" :key="sauceId" class="kiosk-summary-row">
          <span>{{ getSauceName(sauceId) }}</span>
          <span v-if="index === 0" class="kiosk-free">{{ $t('kiosk.wizard.summary.free') }}</span>
          <span v-else class="kiosk-price">+{{ formatPrice(extraSaucePrice) }}</span>
        </div>
      </div>
      
      <!-- Garnitures -->
      <div v-if="selectedGarnituresCount > 0" class="kiosk-summary-section">
        <h4>{{ $t('kiosk.wizard.summary.garnishes') }} ({{ selectedGarnituresCount }})</h4>
        <div class="kiosk-summary-tags">
          <span v-for="id in selectedGarnitureIds" :key="id" class="kiosk-tag">
            {{ getGarnitureName(id) }}
          </span>
        </div>
      </div>
      
      <!-- Suppléments -->
      <div v-if="selectedSupplements.length > 0" class="kiosk-summary-section">
        <h4>{{ $t('kiosk.wizard.summary.supplements') }} ({{ selectedSupplements.length }})</h4>
        <div v-for="supplement in selectedSupplements" :key="supplement.id" class="kiosk-summary-row">
          <span>{{ displaySupplementName(supplement) }}</span>
          <span class="kiosk-price">+{{ formatPrice(supplement.price) }}</span>
        </div>
      </div>
      
      <!-- Menu -->
      <div v-if="selections.menuChoice && selections.menuChoice !== 'none'" class="kiosk-summary-section">
        <h4>{{ $t('kiosk.wizard.summary.menu') }}</h4>
        <div class="kiosk-summary-row">
          <span>{{ getMenuLabel() }}</span>
          <span v-if="menuPrice > 0" class="kiosk-price">+{{ formatPrice(menuPrice) }}</span>
          <span v-else class="kiosk-free">{{ $t('kiosk.wizard.summary.included') }}</span>
        </div>
        <div v-if="selections.boissonChoice" class="kiosk-summary-row boisson">
          <span>{{ $t('kiosk.wizard.summary.boisson_line', { name: getBoissonName() }) }}</span>
        </div>
      </div>

      <!-- Sauces frites (aligné wizard : 1 gratuite, suivantes au prix sauce supp.) -->
      <div v-if="fritesSauceRows.length > 0" class="kiosk-summary-section">
        <h4>{{ $t('kiosk.wizard.summary.fry_sauces') }} ({{ fritesSauceRows.length }})</h4>
        <div v-for="(row, index) in fritesSauceRows" :key="row.key" class="kiosk-summary-row">
          <span>{{ row.label }}</span>
          <span v-if="index === 0" class="kiosk-free">{{ $t('kiosk.wizard.summary.free') }}</span>
          <span v-else class="kiosk-price">+{{ formatPrice(extraSaucePrice) }}</span>
        </div>
      </div>
    </div>
    
    <!-- Total -->
    <div
      class="kiosk-summary-total"
      role="status"
      aria-live="polite"
      data-testid="kiosk-order-summary-total"
    >
      <span>{{ $t('kiosk.total') }}</span>
      <span
        class="kiosk-total-price"
        data-testid="kiosk-order-summary-total-price"
        :aria-label="$t('kiosk.total') + ' ' + formatPrice(runningTotal)"
      >{{ formatPrice(runningTotal) }}</span>
    </div>

    <!-- Quantité -->
    <div class="kiosk-quantity-section" data-testid="kiosk-order-summary-qty">
      <span id="kiosk-order-summary-qty-label">{{ $t('kiosk.wizard.summary.quantity') }}</span>
      <div
        class="kiosk-qty-controls"
        role="group"
        aria-labelledby="kiosk-order-summary-qty-label"
      >
        <button
          @click="decrementQty"
          :disabled="selections.quantity <= 1"
          :aria-label="$t('kiosk.decrease_qty')"
          data-testid="kiosk-order-summary-qty-minus"
        >−</button>
        <span
          data-testid="kiosk-order-summary-qty-value"
          :aria-label="$t('kiosk.quantity_of', { n: selections.quantity })"
          aria-live="polite"
        >{{ selections.quantity }}</span>
        <button
          @click="incrementQty"
          :aria-label="$t('kiosk.increase_qty')"
          data-testid="kiosk-order-summary-qty-plus"
        >+</button>
      </div>
    </div>
  </div>
</template>

<script>
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { sanitizeKioskCustomerFacingText } from '../../../helpers/kioskDisplayText';
import { calculateKioskRunningTotal, getKioskExtraSauceUnitPrice, getKioskMenuAddonPrice } from '../../../helpers/kioskPricing';
import kioskAnalytics from '../../../helpers/kioskAnalytics';

export default {
  name: 'KioskOrderSummary',
  mixins: [kioskPriceMixin],
  props: {
    step: Object,
    item: Object,
    selections: Object
  },
  emits: ['update'],
  beforeUnmount() {
    this.trackRecapAbandoned();
  },
  beforeDestroy() {
    this.trackRecapAbandoned();
  },
  computed: {
    selectedGarnituresCount() {
      return Object.values(this.selections.garnitures || {}).filter(Boolean).length;
    },
    selectedGarnitureIds() {
      return Object.entries(this.selections.garnitures || {})
        .filter(([, selected]) => !!selected)
        .map(([id]) => id);
    },
    selectedSupplements() {
      if (!this.item.extras) return [];

      return this.item.extras
        .filter(e => this.selections.supplements?.[e.id] && parseFloat(e.convert_price || e.price || 0) > 0)
        .map(e => ({
          id: e.id,
          name: e.name,
          price: parseFloat(e.convert_price || e.price || 0)
        }));
    },
    viandeDisplayRows() {
      const meta = this.selections._viandeMeta;
      if (Array.isArray(meta) && meta.length > 0) {
        return meta
          .filter((m) => (m.count || 0) > 0)
          .map((m, idx) => ({
            key: `meta-${idx}-${m.name}`,
            label: sanitizeKioskCustomerFacingText(m.name || ''),
            count: m.count,
            unitPrice: parseFloat(m.price || 0) || 0,
          }))
          .filter((r) => r.label);
      }
      return Object.entries(this.selections.viandes || {})
        .filter(([, c]) => c > 0)
        .map(([key, count]) => ({
          key,
          label: this.formatViandeName(key),
          count,
          unitPrice: 0,
        }));
    },
    // [AUDIT 2026-04-17 C13] Filtre défensif : la sentinelle '_skip' émise
    // jadis par KioskStepSauce (état vide) ne doit jamais apparaître comme
    // une sauce dans le récap.
    visibleSauceOrder() {
      const order = this.selections.sauceOrder || [];
      return order.filter((k) => k != null && k !== '' && String(k) !== '_skip');
    },
    menuPrice() {
      return getKioskMenuAddonPrice(this.item, this.selections.menuChoice);
    },
    extraSaucePrice() {
      return getKioskExtraSauceUnitPrice(this.item);
    },
    fritesSauceRows() {
      const orderAll = this.selections.fritesSauceOrder || [];
      const hasFrites = this.selections.menuChoice === 'full' || this.selections.menuChoice === 'frites';
      if (!hasFrites || orderAll.length === 0) return [];
      const paid = orderAll.filter((k) => k && k !== 'sans');
      if (paid.length === 0 && orderAll.includes('sans')) {
        return [{ key: 'sans', label: this.fritesSauceLabel('sans') }];
      }
      return paid.map((key) => ({ key, label: this.fritesSauceLabel(key) }));
    },
    runningTotal() {
      return calculateKioskRunningTotal(this.item, this.selections);
    }
  },
  methods: {
    sanitizeItemName(name) {
      return sanitizeKioskCustomerFacingText(name || '');
    },
    displaySupplementName(supplement) {
      return sanitizeKioskCustomerFacingText(supplement?.name || '');
    },
    // formatPrice() provided by kioskPriceMixin
    getPainName() {
      const painId = this.selections.pain;
      if (!painId) return this.$t('kiosk.wizard.summary.not_selected_bread');

      const painAttr = this.item.itemAttributes?.find(a =>
        (a.name || '').toLowerCase().includes('pain')
      );
      if (painAttr && this.item.variations?.[painAttr.id]) {
        const pain = this.item.variations[painAttr.id].find(v => v.id === painId);
        return sanitizeKioskCustomerFacingText(pain?.name || String(painId));
      }
      return sanitizeKioskCustomerFacingText(String(painId));
    },
    formatViandeName(key) {
      const raw = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
      return sanitizeKioskCustomerFacingText(raw);
    },
    getSauceName(sauceId) {
      const sauceAttr = this.item.itemAttributes?.find(a =>
        (a.name || '').toLowerCase().includes('sauce')
      );
      const vars = sauceAttr
        ? (this.item.variations?.[String(sauceAttr.id)] || this.item.variations?.[sauceAttr.id])
        : null;
      if (Array.isArray(vars)) {
        const sauce = vars.find(v => String(v.id) === String(sauceId));
        if (sauce) return sanitizeKioskCustomerFacingText(sauce.name);
      }
      return this.$t('kiosk.wizard.summary.sauce_fallback', { id: sauceId });
    },
    fritesSauceLabel(key) {
      if (key == null || key === '') return '';
      const strKey = String(key);
      if (strKey.startsWith('sauce-var-')) {
        const id = strKey.replace('sauce-var-', '');
        return this.getSauceName(id);
      }
      const sauceAttr = this.item.itemAttributes?.find((a) =>
        (a.name || '').toLowerCase().includes('sauce')
      );
      const vars = sauceAttr
        ? this.item.variations?.[String(sauceAttr.id)] || this.item.variations?.[sauceAttr.id]
        : null;
      if (Array.isArray(vars)) {
        const byId = vars.find((v) => String(v.id) === String(key));
        if (byId?.name) return sanitizeKioskCustomerFacingText(byId.name);
      }
      const k = `kiosk.wizard.frites_sauce.${key}`;
      const t = this.$t(k);
      return t !== k ? t : strKey;
    },
    getGarnitureName(id) {
      const garniture = this.item.extras?.find(e => e.id === parseInt(id));
      if (garniture?.name) return sanitizeKioskCustomerFacingText(garniture.name);
      return this.$t('kiosk.wizard.summary.garniture_fallback', { id });
    },
    getMenuLabel() {
      const mc = this.selections.menuChoice;
      const map = {
        full: 'kiosk.wizard.summary.menu_label_full',
        frites: 'kiosk.wizard.summary.menu_label_frites',
        boisson: 'kiosk.wizard.summary.menu_label_boisson',
        none: 'kiosk.wizard.summary.menu_label_none',
      };
      const path = map[mc];
      return path ? this.$t(path) : mc;
    },
    getBoissonName() {
      const boissonId = this.selections.boissonChoice;
      if (!boissonId) return this.$t('kiosk.wizard.summary.not_selected_drink');

      const boisson = this.item.addons?.find(a => {
        const linked = a.item_addon_id ?? a.addon_item_id;
        return linked === boissonId
          || String(linked) === String(boissonId)
          || a.id === boissonId;
      });

      if (boisson) {
        return sanitizeKioskCustomerFacingText(
          boisson.addon_item_name || boisson.name || this.$t('kiosk.wizard.summary.drink_generic')
        );
      }

      if (typeof boissonId === 'string') return sanitizeKioskCustomerFacingText(boissonId);

      return this.$t('kiosk.wizard.summary.drink_fallback_id', { id: boissonId });
    },
    incrementQty() {
      this.$emit('update', 'quantity', (this.selections.quantity || 1) + 1);
    },
    decrementQty() {
      if (this.selections.quantity > 1) {
        this.$emit('update', 'quantity', this.selections.quantity - 1);
      }
    },
    trackRecapAbandoned() {
      if (this.step?.type !== 'recap' || this.selections?._summaryConfirmed) {
        return;
      }

      try {
        kioskAnalytics.track('wizard_abandoned', {
          item_id: this.item?.id || null,
          step: 'recap',
        });
      } catch (_) { /* silent */ }
    }
  }
};
</script>

<style scoped>
.kiosk-order-summary {
  padding: 10px 18px 22px;
  background: var(--kiosk-surface);
  min-height: 100%;
}

.kiosk-summary-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  background: var(--kiosk-surface);
  border: 1px solid var(--kiosk-border);
  border-radius: 18px;
  margin-bottom: 12px;
}

.kiosk-summary-item.main {
  border: 2px solid var(--kiosk-primary);
  background: var(--kiosk-primary-soft);
}

.kiosk-summary-img {
  width: 56px;
  height: 56px;
  border-radius: 10px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--kiosk-surface-alt);
  flex-shrink: 0;
}

.kiosk-summary-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.kiosk-summary-emoji { font-size: 28px; }

.kiosk-summary-details {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.kiosk-summary-name {
  font-size: 16px;
  font-weight: 700;
  color: var(--kiosk-text);
}

.kiosk-summary-price {
  font-size: 14px;
  color: var(--kiosk-primary);
  font-weight: 700;
}

.kiosk-summary-sections {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 12px;
}

.kiosk-summary-section {
  background: var(--kiosk-surface);
  border: 1px solid var(--kiosk-border);
  border-radius: 16px;
  padding: 12px 16px;
}

.kiosk-summary-section h4 {
  font-size: 10px;
  font-weight: 700;
  color: var(--kiosk-text-muted);
  text-transform: uppercase;
  margin: 0 0 8px;
  letter-spacing: 1px;
}

.kiosk-summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 5px 0;
  border-bottom: 1px solid var(--kiosk-border);
  font-size: 13px;
  color: var(--kiosk-text-muted);
}

.kiosk-summary-row:last-child { border-bottom: none; }

.kiosk-summary-row.boisson {
  padding-left: 14px;
  color: var(--kiosk-text-muted);
  font-size: 12px;
}

.kiosk-free {
  color: var(--kiosk-success);
  font-weight: 700;
  font-size: 11px;
}

.kiosk-price {
  color: var(--kiosk-primary);
  font-weight: 700;
  font-size: 12px;
}

.kiosk-summary-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.kiosk-tag {
  background: rgba(27,138,58,0.1);
  border: 1px solid var(--kiosk-success);
  color: var(--kiosk-success);
  padding: 4px 10px;
  border-radius: 50px;
  font-size: 11px;
  font-weight: 600;
}

.kiosk-summary-total {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 18px;
  background: var(--kiosk-primary-soft);
  border: 1px solid var(--kiosk-primary);
  border-radius: 16px;
  margin-bottom: 12px;
}

.kiosk-summary-total span:first-child {
  color: var(--kiosk-text-muted);
  font-size: 15px;
  font-weight: 700;
}

.kiosk-total-price {
  color: var(--kiosk-primary);
  font-size: 24px;
  font-weight: 900;
}

.kiosk-quantity-section {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 12px 18px;
  background: var(--kiosk-surface);
  border: 1px solid var(--kiosk-border);
  border-radius: 16px;
}

.kiosk-quantity-section > span {
  font-size: 14px;
  font-weight: 600;
  color: var(--kiosk-text-muted);
}

.kiosk-qty-controls {
  display: flex;
  align-items: center;
  gap: 10px;
}

.kiosk-qty-controls button {
  width: 44px;
  height: 44px;
  border: none;
  background: var(--kiosk-surface-alt);
  color: var(--kiosk-text);
  font-size: 22px;
  font-weight: 700;
  cursor: pointer;
  touch-action: manipulation;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
  border-radius: 50%;
  border: 1.5px solid var(--kiosk-border);
}

.kiosk-qty-controls button:first-child {
  color: var(--kiosk-text-muted);
}

.kiosk-qty-controls button:last-child {
  background: var(--kiosk-primary);
  border-color: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
}

.kiosk-qty-controls button:active:not(:disabled) {
  transform: scale(0.92);
}

.kiosk-qty-controls button:disabled {
  opacity: 0.58;
  cursor: not-allowed;
}

.kiosk-qty-controls button:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, var(--kiosk-primary));
  outline-offset: 2px;
}

.kiosk-qty-controls span {
  font-size: 20px;
  font-weight: 800;
  color: var(--kiosk-text);
  min-width: 40px;
  text-align: center;
}
</style>
