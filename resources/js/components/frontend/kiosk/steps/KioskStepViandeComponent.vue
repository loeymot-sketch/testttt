<template>
  <div class="kiosk-step-viande">
    <h3 class="kiosk-step-title">
      {{ viandeStepTitle }}
    </h3>

    <div class="kiosk-viande-counter">
      <div class="kiosk-counter-badge" :class="{ complete: includedQuotaComplete }">
        {{ includedQuotaSelected }} / {{ maxViandes }}
      </div>
      <span v-if="includedQuotaComplete" class="kiosk-complete-badge">✓ {{ $t('kiosk.wizard.step.viande.complete') }}</span>
      <span v-if="paidSelected > 0" class="kiosk-paid-badge">+{{ paidSelected }} supplément{{ paidSelected > 1 ? 's' : '' }}</span>
    </div>

    <p v-if="viandeList.length > 0" class="kiosk-viande-instruction" role="status" aria-live="polite">
      {{ viandeInstructionText }}
    </p>

    <div v-if="viandeList.length === 0" class="kiosk-step-empty" role="status" aria-live="polite">
      <p>{{ $t('kiosk.wizard.step.viande.empty_hint') }}</p>
    </div>

    <div v-else class="kiosk-viande-grid">
      <div
        v-for="viande in viandeList"
        :key="viande.key"
        class="kiosk-viande-card"
        :class="{
          active: (localSelections[viande.key] || 0) > 0,
          'is-paid': viande.price > 0,
          'is-selectable': canSelectFromCard(viande),
          'kiosk-variation--disabled': !variationFilterAllowed(viande) || isViandeOos(viande),
          'is-out-of-stock': isViandeOos(viande),
        }"
        role="group"
        :tabindex="(variationFilterAllowed(viande) && !isViandeOos(viande)) ? 0 : -1"
        :aria-label="viandeCardAriaLabel(viande)"
        :aria-disabled="(variationFilterAllowed(viande) && !isViandeOos(viande)) ? 'false' : 'true'"
        :title="viandeOosTooltip(viande) || variationFilterTooltip(viande)"
        @click="selectFromCard(viande)"
        @keydown.enter.prevent="selectFromCard(viande)"
        @keydown.space.prevent="selectFromCard(viande)"
      >
        <span v-if="viande.price > 0" class="kiosk-viande-badge-paid">
          +{{ formatPrice(viande.price) }}
        </span>
        <span
          v-if="isViandeOos(viande)"
          class="kiosk-extra-oos-badge"
          data-testid="kiosk-extra-oos-badge"
          :aria-label="$t('pos.item_86_d')"
        >{{ $t('pos.item_86_d') }}</span>

        <div class="kiosk-viande-visual">
          <img
            v-if="viande.thumb && !brokenViandeThumbs[viandeThumbKey(viande)]"
            :src="viande.thumb"
            :alt="viande.name"
            class="kiosk-viande-img"
            loading="lazy"
            @error="onViandeThumbError(viande)"
          />
          <span class="kiosk-viande-emoji" v-else>{{ viande.emoji }}</span>
        </div>

        <span class="kiosk-viande-name">{{ viande.name }}</span>
        <span
          v-if="viandeCardMetaLabel(viande)"
          class="kiosk-viande-meta"
          :class="{
            'is-paid': viande.price > 0,
            'is-selected': (localSelections[viande.key] || 0) > 0 && viande.price <= 0,
          }"
        >
          {{ viandeCardMetaLabel(viande) }}
        </span>

        <div
          v-if="(localSelections[viande.key] || 0) > 0"
          class="kiosk-viande-controls"
          role="group"
          :aria-label="viande.name"
          @click.stop
        >
          <button type="button"
            @click="decrement(viande.key)"
            class="kiosk-viande-qty-btn"
            :disabled="(localSelections[viande.key] || 0) === 0"
            :aria-label="$t('kiosk.wizard.step.viande.remove_one', { name: viande.name })">−</button>
          <span class="kiosk-viande-qty-value" aria-live="polite">{{ localSelections[viande.key] || 0 }}</span>
          <button type="button"
            @click="increment(viande.key)"
            class="kiosk-viande-qty-btn plus"
            :disabled="!canIncrement(viande)"
            :aria-label="$t('kiosk.wizard.step.viande.add_one', { name: viande.name })">+</button>
        </div>
        <div v-else class="kiosk-viande-select-hint">
          {{ $t('kiosk.wizard.step.viande.tap_to_choose') }}
        </div>
      </div>
    </div>

    <div v-if="viandeList.length > 0 && !includedQuotaComplete" class="kiosk-validation-hint" role="status" aria-live="polite">
      {{ viandeHintRemaining }}
    </div>
  </div>
</template>

<script>
import { kioskViandeCatalogForItem } from '../../../../helpers/kioskViandeCatalog';
import { kioskVariationsForAttribute } from '../../../../helpers/kioskMedia';
import { isVariationAllowedByFilters } from '../../../../helpers/kioskFilters';
import { kioskPriceMixin } from '../../../../helpers/kioskFormatPrice';

export default {
  name: 'KioskStepViande',
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
      localSelections: { ...this.selections.viandes },
      brokenViandeThumbs: {},
    };
  },
  computed: {
    maxViandes() {
      // Single source of truth: the parent wizard always seeds _tailleMeta.viandeCount
      // via detectViandeCount() or the taille step selection. No local heuristic.
      return this.selections._tailleMeta?.viandeCount || 1;
    },
    totalSelected() {
      return Object.values(this.localSelections).reduce((sum, v) => sum + (v || 0), 0);
    },
    includedViandeRows() {
      return this.viandeList.filter((v) => v.source !== 'extra');
    },
    hasIncludedViandeOptions() {
      return this.includedViandeRows.length > 0;
    },
    includedSelected() {
      const sourceRows = this.hasIncludedViandeOptions ? this.includedViandeRows : this.viandeList;
      return sourceRows.reduce((sum, viande) => sum + (this.localSelections[viande.key] || 0), 0);
    },
    paidSelected() {
      return this.viandeList
        .filter((v) => v.source === 'extra')
        .reduce((sum, viande) => sum + (this.localSelections[viande.key] || 0), 0);
    },
    includedQuotaSelected() {
      return this.includedSelected;
    },
    includedQuotaComplete() {
      return this.includedQuotaSelected >= this.maxViandes;
    },
    viandeStepTitle() {
      const n = this.maxViandes;
      return n === 1
        ? this.$t('kiosk.wizard.step.viande.title_one', { n })
        : this.$t('kiosk.wizard.step.viande.title_many', { n });
    },
    viandeHintRemaining() {
      const n = this.maxViandes - this.includedQuotaSelected;
      if (n <= 0) return '';
      return n === 1
        ? this.$t('kiosk.wizard.step.viande.hint_need_one', { n })
        : this.$t('kiosk.wizard.step.viande.hint_need_many', { n });
    },
    viandeInstructionText() {
      if (this.includedQuotaComplete) {
        return this.$t('kiosk.wizard.step.viande.instruction_complete');
      }
      const remaining = Math.max(this.maxViandes - this.includedQuotaSelected, 0);
      if (this.includedQuotaSelected > 0) {
        return remaining === 1
          ? this.$t('kiosk.wizard.step.viande.instruction_remaining_one', { n: remaining })
          : this.$t('kiosk.wizard.step.viande.instruction_remaining_many', { n: remaining });
      }
      if (this.maxViandes === 1) {
        return this.$t('kiosk.wizard.step.viande.instruction_one');
      }
      return this.$t('kiosk.wizard.step.viande.instruction_many', { n: this.maxViandes });
    },
    // [AUDIT 2026-04-17 C3] Catalogue viandes dynamique = variations (prix 0)
    // + extras marqués viande (prix > 0), fusionnés en une liste ordonnée
    // (variations d'abord, puis extras payants). Plus de fallback hardcodé.
    viandeList() {
      return kioskViandeCatalogForItem(this.item);
    }
  },
  watch: {
    'selections.viandes': {
      deep: true,
      handler(value) {
        this.localSelections = { ...(value || {}) };
      },
    },
  },
  methods: {
    viandeThumbKey(viande) {
      return String(viande.id ?? viande.key ?? '');
    },
    onViandeThumbError(viande) {
      const k = this.viandeThumbKey(viande);
      this.brokenViandeThumbs = { ...this.brokenViandeThumbs, [k]: true };
    },
    emitUpdate() {
      this.$emit('update', 'viandes', { ...this.localSelections });
      this.$emit('update', 'totalViandes', this.totalSelected);
      // [AUDIT 2026-04-17 C3] Meta enrichie : inclut `source` (variation/extra)
      // et `price` pour que le wizard/pricing puisse :
      //   - mapper les variations vers item_variations,
      //   - mapper les extras payants vers item_extras (avec quantité),
      //   - inclure le surplus payant dans calculateKioskRunningTotal.
      const selectedMeta = this.viandeList
        .filter(v => (this.localSelections[v.key] || 0) > 0)
        .map(v => ({
          id: v.id,
          key: v.key,
          name: v.name,
          price: v.price,
          source: v.source,
          attrId: v.attrId,
          count: this.localSelections[v.key],
        }));
      this.$emit('update', '_viandeMeta', selectedMeta);
    },
    resolveCatalogObjectForViande(viande) {
      if (!this.item || !viande) return null;
      if (viande.source === 'extra') {
        return this.item.extras?.find((e) => e.id === viande.id) || null;
      }
      const attrs = Array.isArray(this.item.itemAttributes)
        ? this.item.itemAttributes
        : Object.values(this.item.itemAttributes || {});
      const viandeAttr = attrs.find((a) => String(a?.name || '').toLowerCase().includes('viande'));
      if (!viandeAttr) return null;
      const list = kioskVariationsForAttribute(this.item, viandeAttr.id);
      return Array.isArray(list) ? (list.find((v) => v && v.id === viande.id) || null) : null;
    },
    variationFilterAllowed(viande) {
      const raw = this.resolveCatalogObjectForViande(viande);
      return isVariationAllowedByFilters(raw || { name: viande.name }, this.activeFilters || []);
    },
    variationFilterTooltip(viande) {
      if (this.variationFilterAllowed(viande)) return '';
      return (this.activeFilters || []).map((f) => this.$t(`kiosk.filters.${f}`)).filter(Boolean).join(', ');
    },
    isPaidViande(viande) {
      return viande?.source === 'extra' || parseFloat(viande?.price || 0) > 0;
    },
    canIncrement(viande) {
      if (!viande || !this.variationFilterAllowed(viande)) return false;
      // [HEAL-A 2026-05-08] Block adding an OOS viande (rupture). Decrement
      // remains possible elsewhere via decrement() so client can correct.
      if (this.isViandeOos(viande)) return false;
      const current = this.localSelections[viande.key] || 0;
      if (this.isPaidViande(viande) && this.hasIncludedViandeOptions) {
        return current < 9;
      }
      return this.includedQuotaSelected < this.maxViandes;
    },
    // [HEAL-A 2026-05-08] OOS read on viande — both extra-source (paid) and
    // variation-source (free, defensive — backend may extend later).
    isViandeOos(viande) {
      if (!viande) return false;
      return viande.is_available === false;
    },
    viandeOosTooltip(viande) {
      if (!this.isViandeOos(viande)) return '';
      return viande.unavailable_reason || this.$t('pos.item_86_d');
    },
    canSelectFromCard(viande) {
      if (!viande || !this.variationFilterAllowed(viande)) return false;
      if ((this.localSelections[viande.key] || 0) > 0) return false;
      return this.canIncrement(viande);
    },
    viandeCardAriaLabel(viande) {
      const count = this.localSelections[viande.key] || 0;
      const price = viande.price > 0 ? ` ${this.formatPrice(viande.price)}` : '';
      return `${viande.name}${price}, ${count}`;
    },
    viandeCardMetaLabel(viande) {
      if (viande.price > 0) {
        return this.$t('kiosk.wizard.step.viande.meta_paid', { price: this.formatPrice(viande.price) });
      }
      if ((this.localSelections[viande.key] || 0) > 0) {
        return this.$t('kiosk.wizard.step.viande.meta_selected');
      }
      return '';
    },
    selectFromCard(viande) {
      if (!this.canSelectFromCard(viande)) return;
      this.increment(viande.key);
    },
    increment(key) {
      const viande = this.viandeList.find((v) => v.key === key);
      if (!this.canIncrement(viande)) return;
      this.localSelections[key] = (this.localSelections[key] || 0) + 1;
      this.emitUpdate();
    },
    decrement(key) {
      if ((this.localSelections[key] || 0) > 0) {
        this.localSelections[key]--;
        this.emitUpdate();
      }
    }
  }
};
</script>

<style scoped>
.kiosk-step-viande {
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

.kiosk-viande-counter {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin-bottom: 8px;
}

.kiosk-viande-instruction {
  max-width: 720px;
  margin: 0 auto 16px;
  padding: 10px 16px;
  border-radius: 999px;
  border: 1px solid var(--kiosk-border, rgba(255,255,255,0.14));
  background: var(--kiosk-surface-alt, rgba(255,255,255,0.06));
  color: var(--kiosk-text-muted, #d4d4d8);
  font-size: 13px;
  font-weight: 800;
  line-height: 1.25;
  text-align: center;
  text-wrap: balance;
}

.kiosk-counter-badge {
  background: var(--kiosk-surface-alt, #F7F7F8);
  border: 2px solid var(--kiosk-border, #E0E0E0);
  padding: 8px 24px;
  border-radius: 50px;
  font-size: 18px;
  font-weight: 800;
  color: var(--kiosk-text-muted, #555);
  transition:
    background-color 0.25s ease,
    border-color 0.25s ease,
    color 0.25s ease;
}

.kiosk-counter-badge.complete {
  background: rgba(34,197,94,0.14);
  border-color: var(--kiosk-success, #27ae60);
  color: var(--kiosk-success, #27ae60);
}

.kiosk-complete-badge {
  font-size: 14px;
  font-weight: 700;
  color: var(--kiosk-success, #27ae60);
}

.kiosk-paid-badge {
  font-size: 13px;
  font-weight: 800;
  color: var(--kiosk-warning, #F59E0B);
  padding: 7px 12px;
  border-radius: 999px;
  background: rgba(245, 158, 11, 0.14);
  border: 1px solid rgba(245, 158, 11, 0.28);
}

.kiosk-viande-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 14px 18px;
  max-width: 900px;
  margin: 0 auto;
}

.kiosk-viande-card {
  display: grid;
  grid-template-columns: 116px minmax(0, 1fr);
  grid-template-rows: auto auto auto;
  align-items: center;
  justify-content: initial;
  column-gap: 16px;
  row-gap: 8px;
  min-height: 138px;
  padding: 14px 18px;
  border-radius: 22px;
  border: 1px solid var(--kiosk-border, #efefef);
  background:
    linear-gradient(180deg, rgba(255,255,255,0.98), rgba(255,250,245,0.98));
  transition:
    border-color 0.18s ease,
    background-color 0.18s ease,
    box-shadow 0.18s ease,
    transform 0.18s ease;
  cursor: pointer;
  touch-action: manipulation;
  position: relative;
}

.kiosk-viande-card:active { transform: scale(0.97); }

.kiosk-viande-card:focus-visible {
  outline: 3px solid rgba(232, 0, 28, 0.55);
  outline-offset: 2px;
}

.kiosk-viande-card.is-paid {
  border-color: rgba(245, 158, 11, 0.34);
}

.kiosk-viande-badge-paid {
  position: absolute;
  top: 10px;
  inset-inline-end: 12px;
  background: var(--kiosk-warning, #E86B00);
  color: var(--kiosk-text-on-red, #fff);
  font-size: 11px;
  font-weight: 800;
  padding: 3px 8px;
  border-radius: 20px;
  letter-spacing: 0.02em;
  box-shadow: 0 2px 6px rgba(232, 107, 0, 0.25);
  z-index: 1;
}

.kiosk-step-empty {
  text-align: center;
  padding: 40px 20px;
  color: var(--kiosk-text-muted, #666);
  font-size: 14px;
}

.kiosk-viande-card.active {
  border-color: var(--kiosk-primary, #E8001C);
  background:
    linear-gradient(180deg, rgba(255,255,255,1), rgba(255,245,247,0.98));
  box-shadow: 0 0 0 2px var(--kiosk-primary-light, rgba(232,0,28,0.08)), var(--kiosk-shadow-card, none);
}

.kiosk-viande-card.is-selectable::after {
  content: '';
  position: absolute;
  inset: 8px;
  border-radius: 16px;
  border: 1px solid transparent;
  pointer-events: none;
}

.kiosk-viande-visual {
  grid-row: 1 / 4;
  width: 110px;
  height: 96px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0;
  border-radius: 18px;
  overflow: hidden;
  background: var(--kiosk-product-media-bg, #f7f7f8);
}

.kiosk-viande-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.kiosk-viande-emoji {
  width: 100%;
  height: 100%;
  border-radius: 18px;
  background: var(--kiosk-product-media-bg, #f7f7f8);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 44px;
  line-height: 1;
}

.kiosk-viande-name {
  justify-self: start;
  align-self: end;
  font-size: 16px;
  font-weight: 950;
  color: var(--kiosk-text, #444);
  text-align: start;
  margin: 0;
  line-height: 1.12;
  text-transform: uppercase;
  overflow-wrap: anywhere;
}

.kiosk-viande-card.active .kiosk-viande-name { color: var(--kiosk-primary, #E8001C); }

.kiosk-viande-meta {
  justify-self: start;
  min-height: 24px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin: 0;
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(232,0,28,0.09);
  color: var(--kiosk-primary, #e8001c);
  border: 1px solid rgba(232,0,28,0.12);
  font-size: 11px;
  font-weight: 900;
  line-height: 1;
  text-transform: uppercase;
}

.kiosk-viande-meta.is-selected {
  background: rgba(34,197,94,0.12);
  color: var(--kiosk-success, #27ae60);
  border-color: rgba(34,197,94,0.16);
  font-size: 11px;
  font-weight: 900;
  line-height: 1;
  text-transform: uppercase;
}

.kiosk-viande-meta.is-paid {
  background: rgba(245, 158, 11, 0.14);
  color: var(--kiosk-warning, #F59E0B);
}

.kiosk-viande-card.kiosk-variation--disabled {
  opacity: 0.42;
  filter: grayscale(0.3);
  cursor: not-allowed;
}

/* [HEAL-A 2026-05-08] OOS marker — viande in rupture stock */
.kiosk-viande-card.is-out-of-stock {
  opacity: 0.5;
  filter: grayscale(0.4);
  cursor: not-allowed;
}

.kiosk-extra-oos-badge {
  display: inline-block;
  margin-top: 4px;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--kiosk-primary-soft, rgba(232,0,28,0.1));
  color: var(--kiosk-primary, #E8001C);
  border: 1px solid var(--kiosk-border, rgba(232,0,28,0.25));
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.kiosk-viande-controls {
  justify-self: start;
  display: flex;
  align-items: center;
  gap: 0;
  background: var(--kiosk-surface-alt, #F7F7F8);
  border: 1.5px solid var(--kiosk-border, #E0E0E0);
  border-radius: 14px;
  overflow: hidden;
  margin-top: 0;
}

.kiosk-viande-select-hint {
  justify-self: start;
  min-height: 44px;
  min-width: 132px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-top: 0;
  padding: 0 16px;
  border-radius: 999px;
  background: var(--kiosk-primary-soft, rgba(232,0,28,0.08));
  color: var(--kiosk-primary, #e8001c);
  border: 1px solid var(--kiosk-border, rgba(232,0,28,0.18));
  font-size: 12px;
  font-weight: 900;
  text-transform: uppercase;
}

.kiosk-viande-card.active .kiosk-viande-controls {
  border-color: var(--kiosk-primary, #E8001C);
  background: var(--kiosk-primary-light, rgba(232,0,28,0.04));
}

.kiosk-viande-qty-btn {
  width: 44px;
  height: 44px;
  border: none;
  background: transparent;
  color: var(--kiosk-text-muted, #999);
  font-size: 22px;
  font-weight: 700;
  cursor: pointer;
  touch-action: manipulation;
  display: flex;
  align-items: center;
  justify-content: center;
  transition:
    background-color 0.15s ease,
    color 0.15s ease,
    transform 0.15s ease;
}

.kiosk-viande-qty-btn:focus-visible {
  outline: 3px solid rgba(232, 0, 28, 0.55);
  outline-offset: 2px;
}

.kiosk-viande-qty-btn:active:not(:disabled) {
  background: rgba(0,0,0,0.05);
  color: var(--kiosk-text, #1A1A1A);
}

.kiosk-viande-qty-btn.plus { color: var(--kiosk-primary, #E8001C); }

.kiosk-viande-qty-btn.plus:active:not(:disabled) {
  background: var(--kiosk-primary, #E8001C);
  color: var(--kiosk-text-on-red, #fff);
}

.kiosk-viande-qty-btn:disabled {
  color: var(--kiosk-text-mute, #b0b0b0);
  cursor: not-allowed;
}

.kiosk-viande-qty-value {
  font-size: 18px;
  font-weight: 800;
  color: var(--kiosk-text, #1A1A1A);
  min-width: 36px;
  text-align: center;
}

.kiosk-viande-card.active .kiosk-viande-qty-value { color: var(--kiosk-primary, #E8001C); }

.kiosk-validation-hint {
  text-align: center;
  margin-top: 20px;
  font-size: 14px;
  color: var(--kiosk-primary, #E8001C);
  font-weight: 500;
  padding: 10px 20px;
  background: var(--kiosk-primary-light, rgba(232,0,28,0.06));
  border-radius: 10px;
}

@media (max-width: 760px) {
  .kiosk-viande-grid {
    grid-template-columns: 1fr;
  }

  .kiosk-viande-card {
    grid-template-columns: 96px minmax(0, 1fr);
  }

  .kiosk-viande-visual {
    width: 92px;
    height: 82px;
  }
}
</style>
