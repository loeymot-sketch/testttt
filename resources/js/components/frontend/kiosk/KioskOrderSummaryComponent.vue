<template>
  <div class="kiosk-order-summary" data-testid="kiosk-order-summary-root">
    <!-- Item principal -->
    <div class="kiosk-summary-item main" data-testid="kiosk-order-summary-main-item">
      <div class="kiosk-summary-img" aria-hidden="true">
        <img loading="lazy" decoding="async" v-if="item.thumb" :src="item.thumb" :alt="sanitizeItemName(item.name)" />
        <span v-else class="kiosk-summary-emoji">🍽️</span>
      </div>
      <div class="kiosk-summary-details">
        <span class="kiosk-summary-name" data-testid="kiosk-order-summary-main-name">{{ sanitizeItemName(item.name) }}</span>
        <span class="kiosk-summary-price" data-testid="kiosk-order-summary-main-price">{{ formatPrice(item.convert_price) }}</span>
      </div>
    </div>

    <!-- Sélections -->
    <div class="kiosk-summary-sections" role="list">
      <!-- V3.4 (2026-05-10) Owner gate : ajout image/emoji devant chaque ligne récap
           pour scan visuel rapide (pas obligation de tout lire). -->

      <!-- Pain -->
      <div v-if="selections.pain" class="kiosk-summary-section">
        <div class="kiosk-summary-section-head">
          <h4>{{ $t('kiosk.wizard.summary.bread_type') }}</h4>
          <button
            type="button"
            class="kiosk-summary-edit"
            @click="$emit('modifier', 'pain')"
            :aria-label="$t('kiosk.wizard.summary.edit_section', { section: $t('kiosk.wizard.summary.bread_type') })"
            :data-testid="'kiosk-summary-edit-pain'"
          >{{ $t('kiosk.wizard.summary.edit') }}</button>
        </div>
        <div class="kiosk-summary-row">
          <span class="kiosk-summary-row-thumb" aria-hidden="true">
            <img loading="lazy" decoding="async" v-if="getPainImage()" :src="getPainImage()" alt="" />
            <span v-else>🥖</span>
          </span>
          <span class="kiosk-summary-row-name">{{ getPainName() }}</span>
          <span class="kiosk-free">{{ $t('kiosk.wizard.summary.included') }}</span>
        </div>
      </div>

      <!-- Viandes (variations gratuites + extras payants : prix affiché par ligne) -->
      <div v-if="selections.totalViandes > 0" class="kiosk-summary-section">
        <div class="kiosk-summary-section-head">
          <h4>{{ $t('kiosk.wizard.summary.meats') }} ({{ selections.totalViandes }})</h4>
          <button
            type="button"
            class="kiosk-summary-edit"
            @click="$emit('modifier', 'viande')"
            :aria-label="$t('kiosk.wizard.summary.edit_section', { section: $t('kiosk.wizard.summary.meats') })"
            :data-testid="'kiosk-summary-edit-viande'"
          >{{ $t('kiosk.wizard.summary.edit') }}</button>
        </div>
        <div v-for="row in viandeDisplayRows" :key="row.key" class="kiosk-summary-row">
          <span class="kiosk-summary-row-thumb" aria-hidden="true">
            <img loading="lazy" decoding="async" v-if="row.thumb" :src="row.thumb" alt="" />
            <span v-else>{{ row.emoji || '🥩' }}</span>
          </span>
          <span v-if="row.count > 0" class="kiosk-summary-row-name">{{ row.label }} ×{{ row.count }}</span>
          <span v-if="row.count > 0 && row.unitPrice > 0" class="kiosk-price">
            +{{ formatPrice(row.unitPrice * row.count) }}
          </span>
          <span v-else-if="row.count > 0" class="kiosk-free">{{ $t('kiosk.wizard.summary.included') }}</span>
        </div>
      </div>

      <!-- Sauces -->
      <div v-if="visibleSauceOrder.length > 0" class="kiosk-summary-section">
        <div class="kiosk-summary-section-head">
          <h4>{{ $t('kiosk.wizard.summary.sauces') }} ({{ visibleSauceOrder.length }})</h4>
          <button
            type="button"
            class="kiosk-summary-edit"
            @click="$emit('modifier', 'sauce')"
            :aria-label="$t('kiosk.wizard.summary.edit_section', { section: $t('kiosk.wizard.summary.sauces') })"
            :data-testid="'kiosk-summary-edit-sauce'"
          >{{ $t('kiosk.wizard.summary.edit') }}</button>
        </div>
        <div v-for="(sauceId, index) in visibleSauceOrder" :key="sauceId" class="kiosk-summary-row">
          <span class="kiosk-summary-row-thumb" aria-hidden="true">
            <img loading="lazy" decoding="async" v-if="getSauceImage(sauceId)" :src="getSauceImage(sauceId)" alt="" />
            <span v-else>🥫</span>
          </span>
          <span class="kiosk-summary-row-name">{{ getSauceName(sauceId) }}</span>
          <span v-if="index === 0" class="kiosk-free">{{ $t('kiosk.wizard.summary.free') }}</span>
          <span v-else class="kiosk-price">+{{ formatPrice(extraSaucePrice) }}</span>
        </div>
      </div>

      <!-- Garnitures -->
      <div v-if="selectedGarnituresCount > 0" class="kiosk-summary-section">
        <div class="kiosk-summary-section-head">
          <h4>{{ $t('kiosk.wizard.summary.garnishes') }} ({{ selectedGarnituresCount }})</h4>
          <button
            type="button"
            class="kiosk-summary-edit"
            @click="$emit('modifier', 'garnitures')"
            :aria-label="$t('kiosk.wizard.summary.edit_section', { section: $t('kiosk.wizard.summary.garnishes') })"
            :data-testid="'kiosk-summary-edit-garnitures'"
          >{{ $t('kiosk.wizard.summary.edit') }}</button>
        </div>
        <div class="kiosk-summary-tags">
          <span v-for="id in selectedGarnitureIds" :key="id" class="kiosk-tag">
            <span class="kiosk-tag-thumb" aria-hidden="true">
              <img loading="lazy" decoding="async" v-if="getGarnitureImage(id)" :src="getGarnitureImage(id)" alt="" />
              <span v-else>🥬</span>
            </span>
            {{ getGarnitureName(id) }}
          </span>
        </div>
      </div>

      <!-- Suppléments -->
      <div v-if="selectedSupplements.length > 0" class="kiosk-summary-section">
        <div class="kiosk-summary-section-head">
          <h4>{{ $t('kiosk.wizard.summary.supplements') }} ({{ selectedSupplementsTotalCount }})</h4>
          <button
            type="button"
            class="kiosk-summary-edit"
            @click="$emit('modifier', 'supplements')"
            :aria-label="$t('kiosk.wizard.summary.edit_section', { section: $t('kiosk.wizard.summary.supplements') })"
            :data-testid="'kiosk-summary-edit-supplements'"
          >{{ $t('kiosk.wizard.summary.edit') }}</button>
        </div>
        <div v-for="supplement in selectedSupplements" :key="supplement.id" class="kiosk-summary-row">
          <span class="kiosk-summary-row-thumb" aria-hidden="true">
            <img loading="lazy" decoding="async" v-if="supplement.thumb" :src="supplement.thumb" alt="" />
            <span v-else>🍴</span>
          </span>
          <span class="kiosk-summary-row-name">
            {{ displaySupplementName(supplement) }}
            <strong v-if="supplement.count > 1" class="kiosk-summary-count">×{{ supplement.count }}</strong>
          </span>
          <span class="kiosk-price">+{{ formatPrice(supplement.linePrice) }}</span>
        </div>
      </div>

      <!-- Menu -->
      <div v-if="selections.menuChoice && selections.menuChoice !== 'none'" class="kiosk-summary-section">
        <div class="kiosk-summary-section-head">
          <h4>{{ $t('kiosk.wizard.summary.menu') }}</h4>
          <button
            type="button"
            class="kiosk-summary-edit"
            @click="$emit('modifier', 'menu')"
            :aria-label="$t('kiosk.wizard.summary.edit_section', { section: $t('kiosk.wizard.summary.menu') })"
            :data-testid="'kiosk-summary-edit-menu'"
          >{{ $t('kiosk.wizard.summary.edit') }}</button>
        </div>
        <div class="kiosk-summary-row">
          <span class="kiosk-summary-row-thumb" aria-hidden="true">
            <span>{{ getMenuEmoji() }}</span>
          </span>
          <span class="kiosk-summary-row-name">{{ getMenuLabel() }}</span>
          <span v-if="menuPrice > 0" class="kiosk-price">+{{ formatPrice(menuPrice) }}</span>
          <span v-else class="kiosk-free">{{ $t('kiosk.wizard.summary.included') }}</span>
        </div>
        <div v-if="selections.boissonChoice" class="kiosk-summary-row boisson">
          <span class="kiosk-summary-row-thumb" aria-hidden="true">
            <img loading="lazy" decoding="async" v-if="getBoissonImage()" :src="getBoissonImage()" alt="" />
            <span v-else>🥤</span>
          </span>
          <span class="kiosk-summary-row-name">{{ $t('kiosk.wizard.summary.boisson_line', { name: getBoissonName() }) }}</span>
        </div>
      </div>

      <!-- Sauces frites -->
      <div v-if="fritesSauceRows.length > 0" class="kiosk-summary-section">
        <div class="kiosk-summary-section-head">
          <h4>{{ $t('kiosk.wizard.summary.fry_sauces') }} ({{ fritesSauceRows.length }})</h4>
          <button
            type="button"
            class="kiosk-summary-edit"
            @click="$emit('modifier', 'frites_sauce')"
            :aria-label="$t('kiosk.wizard.summary.edit_section', { section: $t('kiosk.wizard.summary.fry_sauces') })"
            :data-testid="'kiosk-summary-edit-frites_sauce'"
          >{{ $t('kiosk.wizard.summary.edit') }}</button>
        </div>
        <div v-for="(row, index) in fritesSauceRows" :key="row.key" class="kiosk-summary-row">
          <span class="kiosk-summary-row-thumb" aria-hidden="true">
            <span>🍟</span>
          </span>
          <span class="kiosk-summary-row-name">{{ row.label }}</span>
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
        <button type="button"
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
        <button type="button"
          @click="incrementQty"
          :disabled="selections.quantity >= maxItemQty"
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
import {
  calculateKioskRunningTotal,
  getKioskExtraSauceUnitPrice,
  getKioskMenuAddonPrice,
  normalizeKioskSelectionCount,
} from '../../../helpers/kioskPricing';

export default {
  name: 'KioskOrderSummary',
  mixins: [kioskPriceMixin],
  props: {
    step: Object,
    item: Object,
    selections: Object
  },
  emits: ['update', 'modifier'],
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
        .filter(e => normalizeKioskSelectionCount(this.selections.supplements?.[e.id]) > 0 && parseFloat(e.convert_price || e.price || 0) > 0)
        .map(e => ({
          id: e.id,
          name: e.name,
          thumb: e.thumb || e.image || e.cover || null,
          count: normalizeKioskSelectionCount(this.selections.supplements?.[e.id]),
          price: parseFloat(e.convert_price || e.price || 0),
          linePrice: parseFloat(e.convert_price || e.price || 0) * normalizeKioskSelectionCount(this.selections.supplements?.[e.id])
        }));
    },
    selectedSupplementsTotalCount() {
      return this.selectedSupplements.reduce((sum, supplement) => sum + supplement.count, 0);
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
            thumb: m.thumb || m.image || null,
            emoji: m.emoji || '🥩',
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
          thumb: null,
          emoji: this.guessViandeEmoji(key),
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
    },
    // [F1 heal 2026-06-09] Cap the recap quantity stepper at MAX_ITEM_QTY, in
    // parity with the cart stepper (KioskCartComponent maxItemQty). Without the
    // cap a customer could drive quantity past the cap and the line was then
    // silently clamped by the store with a mismatched total.
    maxItemQty() {
      return (typeof window !== 'undefined' && window.foodkingConfig && window.foodkingConfig.maxItemQty) || 20;
    }
  },
  methods: {
    sanitizeItemName(name) {
      return sanitizeKioskCustomerFacingText(name || '');
    },
    displaySupplementName(supplement) {
      return sanitizeKioskCustomerFacingText(supplement?.name || '');
    },
    /* V3.4 (2026-05-10) Image resolvers per row — fallback emoji si pas d'image. */
    getSauceImage(sauceId) {
      const sauceAttr = this.item.itemAttributes?.find(a =>
        (a.name || '').toLowerCase().includes('sauce')
      );
      const vars = sauceAttr
        ? (this.item.variations?.[String(sauceAttr.id)] || this.item.variations?.[sauceAttr.id])
        : null;
      if (Array.isArray(vars)) {
        const sauce = vars.find(v => String(v.id) === String(sauceId));
        return sauce?.thumb || sauce?.image || sauce?.cover || null;
      }
      return null;
    },
    getGarnitureImage(id) {
      const garniture = this.item.extras?.find(e => e.id === parseInt(id));
      return garniture?.thumb || garniture?.image || garniture?.cover || null;
    },
    getPainImage() {
      const painId = this.selections.pain;
      if (!painId) return null;
      const painAttr = this.item.itemAttributes?.find(a =>
        (a.name || '').toLowerCase().includes('pain')
      );
      if (painAttr && this.item.variations?.[painAttr.id]) {
        const pain = this.item.variations[painAttr.id].find(v => v.id === painId);
        return pain?.thumb || pain?.image || pain?.cover || null;
      }
      return null;
    },
    getBoissonImage() {
      const boissonId = this.selections.boissonChoice;
      if (!boissonId) return null;
      if (this.selections._boissonMeta?.boissonImage || this.selections._boissonMeta?.thumb) {
        return this.selections._boissonMeta.boissonImage || this.selections._boissonMeta.thumb;
      }
      const boisson = this.item.addons?.find(a => {
        const linked = a.item_addon_id ?? a.addon_item_id;
        return linked === boissonId
          || String(linked) === String(boissonId)
          || a.id === boissonId;
      });
      return boisson?.thumb || boisson?.image || boisson?.cover || null;
    },
    getMenuEmoji() {
      const mc = this.selections.menuChoice;
      const map = { full: '🍔', frites: '🍟', boisson: '🥤', none: '🍽️' };
      return map[mc] || '🍽️';
    },
    guessViandeEmoji(key) {
      const k = (key || '').toLowerCase();
      if (k.includes('poulet') || k.includes('chicken') || k.includes('escalope')) return '🍗';
      if (k.includes('nugget') || k.includes('tender')) return '🍗';
      if (k.includes('merguez') || k.includes('saucisse')) return '🌭';
      if (k.includes('cordon') || k.includes('bleu')) return '🍳';
      if (k.includes('haché') || k.includes('hache') || k.includes('mexicain') || k.includes('kefta')) return '🥩';
      return '🥩';
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

      if (this.selections._boissonMeta?.boissonName) {
        return sanitizeKioskCustomerFacingText(this.selections._boissonMeta.boissonName);
      }

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
      const next = Math.min((this.selections.quantity || 1) + 1, this.maxItemQty);
      this.$emit('update', 'quantity', next);
    },
    decrementQty() {
      if (this.selections.quantity > 1) {
        this.$emit('update', 'quantity', this.selections.quantity - 1);
      }
    }
  }
};
</script>

<style scoped>
.kiosk-order-summary {
  padding: 14px 18px 26px;
  background:
    linear-gradient(180deg, rgba(244, 80, 30, 0.08), transparent 130px),
    var(--kiosk-surface);
  min-height: 100%;
}

.kiosk-summary-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 18px;
  background: var(--kiosk-surface-alt);
  border: 1.5px solid var(--kiosk-border);
  border-radius: 18px;
  margin-bottom: 12px;
}

.kiosk-summary-item.main {
  border: 2px solid var(--kiosk-primary);
  background:
    linear-gradient(135deg, rgba(244, 80, 30, 0.24), rgba(244, 80, 30, 0.07)),
    var(--kiosk-surface-alt);
  box-shadow: var(--kiosk-shadow-card);
}

.kiosk-summary-img {
  width: 64px;
  height: 64px;
  border-radius: 16px;
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
  font-size: 18px;
  font-weight: 900;
  color: var(--kiosk-text);
  line-height: 1.15;
}

.kiosk-summary-price {
  font-size: 16px;
  color: var(--kiosk-primary);
  font-weight: 900;
  font-variant-numeric: tabular-nums;
}

.kiosk-summary-sections {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 12px;
}

.kiosk-summary-section {
  background: var(--kiosk-surface-alt);
  border: 1.5px solid var(--kiosk-border);
  border-radius: 16px;
  padding: 14px 16px;
}

/* [OWNER 2026-08-25] Chaque section du récap porte son propre « Modifier », qui
   renvoie DIRECTEMENT à l'étape correspondante du wizard. Sans lui, corriger une
   sauce obligeait à reparcourir tout le parcours depuis la première page. */
.kiosk-summary-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}
.kiosk-summary-section-head h4 {
  margin: 0;
}
.kiosk-summary-edit {
  flex: 0 0 auto;
  /* 44 px de haut : en dessous, le doigt manque la cible sur une borne tactile. */
  min-height: 44px;
  padding: 8px 18px;
  border: 2px solid var(--kiosk-primary-dark);
  border-radius: 999px;
  background: transparent;
  color: var(--kiosk-primary-dark);
  font-size: 15px;
  font-weight: 800;
  letter-spacing: 0.4px;
  cursor: pointer;
  transition: background 0.14s ease, color 0.14s ease;
}
.kiosk-summary-edit:active {
  background: var(--kiosk-primary-dark);
  color: var(--kiosk-text-on-red, #fff);
}
.kiosk-summary-edit:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-focus-ring, currentColor);
  outline-offset: 3px;
}

.kiosk-summary-section h4 {
  font-size: 11px;
  font-weight: 900;
  color: var(--kiosk-text-2, var(--kiosk-text));
  text-transform: uppercase;
  margin: 0 0 10px;
  letter-spacing: 1px;
}

.kiosk-summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 7px 0;
  border-bottom: 1px solid var(--kiosk-border);
  font-size: 14px;
  font-weight: 700;
  color: var(--kiosk-text);
  line-height: 1.3;
}

.kiosk-summary-row:last-child { border-bottom: none; }

.kiosk-summary-row > span:first-child {
  min-width: 0;
  overflow-wrap: anywhere;
}

/* V3.4 (2026-05-10) Owner gate : thumbnail produit devant chaque ligne récap
   pour scan visuel rapide. Image produit OU emoji fallback selon disponibilité. */
.kiosk-summary-row-thumb {
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: #FAFAFA;
  border: 1px solid #EFEFEF;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  font-size: 22px;
  line-height: 1;
}

.kiosk-summary-row-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.kiosk-summary-row-name {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
}

.kiosk-tag-thumb {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #FFFFFF;
  margin-right: 6px;
  overflow: hidden;
  font-size: 14px;
  vertical-align: middle;
}
.kiosk-tag-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.kiosk-tag {
  display: inline-flex;
  align-items: center;
}

.kiosk-summary-row.boisson {
  padding-inline-start: 14px;
  color: var(--kiosk-text-2, var(--kiosk-text-muted));
  font-size: 13px;
}

.kiosk-free {
  color: var(--kiosk-success);
  font-weight: 900;
  font-size: 12px;
  white-space: nowrap;
}

.kiosk-price {
  color: var(--kiosk-primary);
  font-weight: 900;
  font-size: 13px;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.kiosk-summary-count {
  display: inline-flex;
  margin-inline-start: 6px;
  padding: 1px 7px;
  border-radius: 999px;
  background: var(--kiosk-primary-soft, rgba(244,80,30,0.08));
  color: var(--kiosk-primary, #f4501e);
  font-size: 11px;
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
  padding: 5px 11px;
  border-radius: 50px;
  font-size: 12px;
  font-weight: 800;
}

.kiosk-summary-total {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  background: linear-gradient(135deg, var(--kiosk-primary), var(--kiosk-primary-dark));
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 18px;
  margin-bottom: 14px;
  box-shadow: var(--kiosk-shadow-cta);
}

.kiosk-summary-total span:first-child {
  color: var(--kiosk-text-on-red);
  font-size: 16px;
  font-weight: 900;
}

.kiosk-total-price {
  color: var(--kiosk-text-on-red);
  font-size: 28px;
  font-weight: 900;
  font-variant-numeric: tabular-nums;
}

.kiosk-quantity-section {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 12px 18px;
  background: var(--kiosk-surface-alt);
  border: 1.5px solid var(--kiosk-border);
  border-radius: 16px;
}

.kiosk-quantity-section > span {
  font-size: 14px;
  font-weight: 800;
  color: var(--kiosk-text-2, var(--kiosk-text-muted));
}

.kiosk-qty-controls {
  display: flex;
  align-items: center;
  gap: 10px;
}

[dir="rtl"] .kiosk-qty-controls {
  direction: ltr;
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
  transition:
    background-color 0.15s ease,
    border-color 0.15s ease,
    color 0.15s ease,
    transform 0.15s ease;
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
  font-variant-numeric: tabular-nums;
}
</style>
