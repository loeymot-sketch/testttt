<template>
  <div class="kiosk-step-menu">
    <h3 class="kiosk-step-title">{{ $t('kiosk.wizard.menu.title') }}</h3>

    <div class="kiosk-menu-info">
      <span v-if="menuInfoBadge" class="kiosk-info-badge">{{ menuInfoBadge }}</span>
      <span v-if="menuPrice > 0" class="kiosk-menu-price">
        +{{ formatPrice(menuPrice) }}
      </span>
    </div>

    <div
      v-if="needsExplicitMenuChoice"
      class="kiosk-validation-hint kiosk-menu-validation-hint"
      role="status"
    >
      {{ $t('kiosk.wizard.menu.hint_need_choice') }}
    </div>

    <div class="kiosk-menu-options">
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'full' }"
        @click="selectChoice('full')"
      >
        <span class="kiosk-menu-emoji">🍟🥤</span>
        <span class="kiosk-menu-name">{{ $t('kiosk.wizard.menu.full_name') }}</span>
        <span class="kiosk-menu-desc">{{ $t('kiosk.wizard.menu.full_desc') }}</span>
        <span v-if="localChoice === 'full'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
      </div>
      
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'frites' }"
        @click="selectChoice('frites')"
      >
        <span class="kiosk-menu-emoji">🍟</span>
        <span class="kiosk-menu-name">{{ $t('kiosk.wizard.menu.frites_name') }}</span>
        <span class="kiosk-menu-desc">{{ $t('kiosk.wizard.menu.frites_desc') }}</span>
        <span v-if="localChoice === 'frites'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
      </div>
      
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'boisson' }"
        @click="selectChoice('boisson')"
      >
        <span class="kiosk-menu-emoji">🥤</span>
        <span class="kiosk-menu-name">{{ $t('kiosk.wizard.menu.boisson_name') }}</span>
        <span class="kiosk-menu-desc">{{ $t('kiosk.wizard.menu.boisson_desc') }}</span>
        <span v-if="localChoice === 'boisson'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
      </div>
      
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'none' }"
        @click="selectChoice('none')"
      >
        <span class="kiosk-menu-emoji">🚫</span>
        <span class="kiosk-menu-name">{{ $t('kiosk.wizard.menu.none_name') }}</span>
        <span class="kiosk-menu-desc">{{ $t('kiosk.wizard.menu.none_desc') }}</span>
        <span v-if="localChoice === 'none'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
      </div>
    </div>

    <div v-if="showBoissonChoice && boissonList.length > 0" class="kiosk-boisson-section">
      <h4 class="kiosk-subtitle">{{ $t('kiosk.wizard.menu.boisson_section_title') }}</h4>
      <div
        v-if="needsExplicitBoissonSelection"
        class="kiosk-validation-hint kiosk-boisson-validation-hint"
        role="status"
      >
        {{ $t('kiosk.wizard.menu.boisson_hint') }}
      </div>
      <div class="kiosk-boisson-grid">
        <div
          v-for="boisson in boissonList"
          :key="boisson.id ?? boisson.name"
          class="kiosk-boisson-card"
          :class="{ selected: localBoisson === (boisson.id ?? boisson.name) }"
          @click="selectBoisson(boisson)"
        >
          <div class="kiosk-boisson-visual">
            <img
              v-if="boisson.displayThumb && !brokenBoissonThumbs[boissonThumbKey(boisson)]"
              :src="boisson.displayThumb"
              :alt="boisson.name"
              class="kiosk-boisson-img"
              loading="lazy"
              @error="onBoissonThumbError(boisson)"
            />
            <span v-else class="kiosk-boisson-emoji">{{ boisson.emoji }}</span>
          </div>
          <span class="kiosk-boisson-name">{{ boisson.name }}</span>
          <span v-if="localBoisson === (boisson.id ?? boisson.name)" class="kiosk-menu-action active">✓</span>
          <span v-else class="kiosk-menu-action">+</span>
        </div>
      </div>
    </div>
    <div v-else-if="showBoissonChoice" class="kiosk-boisson-section">
      <p class="kiosk-boisson-placeholder">{{ $t('kiosk.wizard.menu.boisson_counter') }}</p>
    </div>

    <!-- Sauce frites — shown when frites are included (full menu or frites only) -->
    <div v-if="showFritesSauce" class="kiosk-boisson-section kiosk-frites-sauce-section">
      <h4 class="kiosk-subtitle">{{ $t('kiosk.wizard.menu.frites_sauce_title') }}</h4>
      <div class="kiosk-boisson-grid">
        <div
          v-for="sauce in fritesSauceList"
          :key="sauce.key"
          class="kiosk-boisson-card"
          :class="{ selected: isFritesSauceSelected(sauce.key) }"
          @click="toggleFritesSauce(sauce)"
        >
          <span class="kiosk-boisson-emoji">{{ sauce.emoji }}</span>
          <span class="kiosk-boisson-name">{{ sauce.name }}</span>
          <span class="kiosk-frites-sauce-price">{{ fritesSaucePriceLabel(sauce.key) }}</span>
          <span v-if="getFritesSauceOrder(sauce.key) > 0" class="kiosk-sauce-order">{{ getFritesSauceOrder(sauce.key) }}</span>
          <span v-else class="kiosk-menu-action">+</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { kioskResolveImageSrc } from '../../../../helpers/kioskMedia';
import { kioskDrinkAddonRowsFromItem } from '../../../../helpers/kioskDrinkAddons';
import { kioskPriceMixin } from '../../../../helpers/kioskFormatPrice';
import { getKioskExtraSauceUnitPrice, getKioskMenuAddonPrice } from '../../../../helpers/kioskPricing';

export default {
  name: 'KioskStepMenu',
  mixins: [kioskPriceMixin],
  props: {
    step: Object,
    item: Object,
    selections: Object
  },
  emits: ['update'],
  data() {
    return {
      localChoice: this.selections.menuChoice || null,
      localBoisson: this.selections.boissonChoice || null,
      localFritesSauceOrder: this.normalizeFritesSauceOrder(this.selections),
      brokenBoissonThumbs: {},
    };
  },
  computed: {
    /** Aligné sur KioskWizard canAdvance : null = pas encore choisi */
    needsExplicitMenuChoice() {
      return this.localChoice === null || this.localChoice === undefined;
    },
    /** P1 : menu full/boisson + liste boissons chargée depuis l’API */
    needsExplicitBoissonSelection() {
      if (!this.showBoissonChoice || this.boissonList.length === 0) return false;
      const b = this.localBoisson;
      return b === null || b === undefined || b === '';
    },
    menuInfoBadge() {
      if (this.localChoice === 'none') return null;
      if (this.localChoice === 'full') return this.$t('kiosk.wizard.menu.badge_frites_boisson');
      if (this.localChoice === 'frites') return this.$t('kiosk.wizard.menu.badge_frites');
      if (this.localChoice === 'boisson') return this.$t('kiosk.wizard.menu.badge_boisson');
      return null;
    },
    menuPrice() {
      return getKioskMenuAddonPrice(this.item, this.localChoice);
    },
    showBoissonChoice() {
      return this.localChoice === 'full' || this.localChoice === 'boisson';
    },
    showFritesSauce() {
      return this.localChoice === 'full' || this.localChoice === 'frites';
    },
    fritesSauceList() {
      const rows = [
        { key: 'ketchup', emoji: '🍅' },
        { key: 'mayo', emoji: '🥚' },
        { key: 'algerienne', emoji: '🌶️' },
        { key: 'bbq', emoji: '🔥' },
        { key: 'samourai', emoji: '⚔️' },
        { key: 'sans', emoji: '🚫' },
      ];
      return rows.map((r) => ({
        ...r,
        name: this.$t(`kiosk.wizard.frites_sauce.${r.key}`),
      }));
    },
    boissonList() {
      const boissonAddons = kioskDrinkAddonRowsFromItem(this.item);
      if (boissonAddons.length === 0) return [];

      return boissonAddons.map(b => {
        // API (ItemAddonResource) expose `item_addon_id` = produit lié ; garder compat `addon_item_id`
        const rawAddonItemId = b.item_addon_id ?? b.addon_item_id;
        let rowId = null;
        if (rawAddonItemId != null && rawAddonItemId !== '') {
          const n = Number(rawAddonItemId);
          if (!Number.isNaN(n) && Number.isFinite(n)) rowId = n;
        }
        if (rowId == null && typeof b.id === 'number') rowId = b.id;
        return {
          id: rowId,
          name: b.addon_item_name || b.name || this.$t('kiosk.wizard.menu.drink_fallback_name'),
          emoji: this.getEmojiForBoisson(b.addon_item_name || b.name),
          displayThumb: kioskResolveImageSrc(b),
          _addon: b,
        };
      });
    },
    fritesExtraUnitLabel() {
      return this.formatPrice(getKioskExtraSauceUnitPrice(this.item));
    },
  },
  watch: {
    selections: {
      deep: true,
      handler(sel) {
        if (Array.isArray(sel.fritesSauceOrder)) {
          this.localFritesSauceOrder = [...sel.fritesSauceOrder];
        }
      },
    },
  },
  methods: {
    normalizeFritesSauceOrder(sel) {
      if (Array.isArray(sel.fritesSauceOrder) && sel.fritesSauceOrder.length) {
        return [...sel.fritesSauceOrder];
      }
      if (sel.fritesSauce) return [sel.fritesSauce];
      return [];
    },
    boissonThumbKey(boisson) {
      return String(boisson.id ?? boisson.name ?? '');
    },
    onBoissonThumbError(boisson) {
      const k = this.boissonThumbKey(boisson);
      this.brokenBoissonThumbs = { ...this.brokenBoissonThumbs, [k]: true };
    },
    getEmojiForBoisson(name) {
      const lower = (name || '').toLowerCase();
      if (lower.includes('coca') || lower.includes('cola')) return '🥤';
      if (lower.includes('fanta') || lower.includes('orange')) return '🍊';
      if (lower.includes('sprite') || lower.includes('citron') || lower.includes('lemon')) return '🍋';
      if (lower.includes('eau') || lower.includes('water')) return '💧';
      if (lower.includes('thé') || lower.includes('tea') || lower.includes('ice tea')) return '🧊';
      if (lower.includes('jus') || lower.includes('orange') || lower.includes('juice')) return '🧃';
      return '🥤';
    },
    selectBoisson(boisson) {
      this.localBoisson = boisson.id ?? boisson.name;
      this.$emit('update', 'boissonChoice', this.localBoisson, {
        boissonName: boisson.name,
        boissonId:   typeof boisson.id === 'number' ? boisson.id : null,
      });
    },
    isFritesSauceSelected(key) {
      return this.localFritesSauceOrder.includes(key);
    },
    getFritesSauceOrder(key) {
      const i = this.localFritesSauceOrder.indexOf(key);
      return i >= 0 ? i + 1 : 0;
    },
    fritesSaucePriceLabel(key) {
      const ord = this.getFritesSauceOrder(key);
      if (ord <= 0) return ' ';
      return ord > 1 ? this.fritesExtraUnitLabel : this.formatPrice(0);
    },
    emitFritesSauceOrder() {
      const order = [...this.localFritesSauceOrder];
      this.$emit('update', 'fritesSauceOrder', order);
      this.$emit('update', 'fritesSauce', order[0] ?? null);
    },
    toggleFritesSauce(sauce) {
      let order = [...this.localFritesSauceOrder];
      const key = sauce.key;
      if (key === 'sans') {
        order = ['sans'];
      } else {
        order = order.filter(k => k !== 'sans');
        const idx = order.indexOf(key);
        if (idx >= 0) order.splice(idx, 1);
        else order.push(key);
      }
      this.localFritesSauceOrder = order;
      this.emitFritesSauceOrder();
    },
    selectChoice(choice) {
      this.localChoice = choice;
      // Reset frites sauce when switching away from frites-inclusive choices
      if (choice === 'none' || choice === 'boisson') {
        this.localFritesSauceOrder = [];
        this.$emit('update', 'fritesSauceOrder', []);
        this.$emit('update', 'fritesSauce', null);
      }
      // Plus de formule « boisson » : nettoyer choix boisson (évite fantôme au récap)
      if (choice === 'none' || choice === 'frites') {
        this.localBoisson = null;
        this.$emit('update', 'boissonChoice', null);
      }
      this.$emit('update', 'menuChoice', choice);
    },
  }
};
</script>

<style scoped>
.kiosk-step-menu {
  padding: 6px 18px 24px;
  background: #fff;
  min-height: 100%;
}

.kiosk-step-title {
  font-size: 15px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 12px;
  color: #333;
}

.kiosk-validation-hint.kiosk-menu-validation-hint {
  text-align: center;
  margin: 0 12px 12px;
  font-size: 13px;
  font-weight: 600;
  color: #E8001C;
  padding: 10px 14px;
  background: rgba(232, 0, 28, 0.06);
  border-radius: 12px;
}

.kiosk-validation-hint.kiosk-boisson-validation-hint {
  text-align: center;
  margin: 0 0 12px;
  font-size: 13px;
  font-weight: 600;
  color: #E8001C;
  padding: 10px 14px;
  background: rgba(232, 0, 28, 0.06);
  border-radius: 12px;
}

.kiosk-menu-info {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-bottom: 14px;
}

.kiosk-info-badge {
  background: rgba(232,0,28,0.06);
  border: 1px solid rgba(232,0,28,0.2);
  color: #E8001C;
  padding: 6px 16px;
  border-radius: 50px;
  font-size: 12px;
  font-weight: 700;
}

.kiosk-menu-price {
  font-size: 16px;
  font-weight: 800;
  color: #E8001C;
  background: rgba(232,0,28,0.06);
  padding: 4px 12px;
  border-radius: 50px;
}

.kiosk-menu-options {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 22px 18px;
  margin-bottom: 20px;
  max-width: 820px;
  margin-left: auto;
  margin-right: auto;
}

.kiosk-menu-card {
  min-height: 196px;
  border-radius: 20px;
  border: 1px solid #efefef;
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 14px 12px 16px;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.18s ease;
  position: relative;
}

.kiosk-menu-card:active { transform: scale(0.96); }

.kiosk-menu-card.selected {
  border-color: rgba(232,0,28,0.18);
  background: rgba(232,0,28,0.02);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
}

.kiosk-menu-emoji {
  width: 118px;
  height: 118px;
  border-radius: 50%;
  background: #f7f7f8;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 48px;
  margin-bottom: 12px;
  transition: transform 0.2s;
}

.kiosk-menu-card.selected .kiosk-menu-emoji { transform: scale(1.1); }

.kiosk-menu-name {
  font-size: 12px;
  font-weight: 700;
  color: #444;
  text-align: center;
  text-transform: uppercase;
}

.kiosk-menu-desc {
  font-size: 11px;
  color: #999;
  text-align: center;
  margin-top: 3px;
}

.kiosk-menu-card.selected .kiosk-menu-name { color: #E8001C; }

.kiosk-boisson-section {
  border-top: 1px solid #E0E0E0;
  padding-top: 16px;
  animation: fadeInUp 0.3s ease;
}

@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: translateY(0); }
}

.kiosk-subtitle {
  font-size: 15px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 14px;
  color: #333;
}

.kiosk-boisson-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px 18px;
  max-width: 900px;
  margin: 0 auto;
}

.kiosk-boisson-card {
  min-height: 170px;
  border-radius: 20px;
  border: 1px solid #efefef;
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 12px 10px 14px;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.18s ease;
  position: relative;
}

.kiosk-boisson-card:active { transform: scale(0.95); }

.kiosk-boisson-card.selected {
  border-color: rgba(232,0,28,0.18);
  background: rgba(232,0,28,0.02);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
}

.kiosk-boisson-visual {
  width: 102px;
  height: 102px;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-boisson-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.kiosk-boisson-emoji {
  width: 102px;
  height: 102px;
  border-radius: 50%;
  background: #f7f7f8;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 40px;
}

.kiosk-boisson-name {
  font-size: 12px;
  font-weight: 700;
  color: #444;
  text-align: center;
  line-height: 1.2;
  text-transform: uppercase;
}

.kiosk-boisson-card.selected .kiosk-boisson-name { color: #E8001C; }

.kiosk-boisson-placeholder {
  text-align: center;
  color: #999;
  font-size: 14px;
  padding: 16px 0;
}

.kiosk-frites-sauce-section {
  border-top: 1px solid #E0E0E0;
  padding-top: 20px;
  margin-top: 8px;
  animation: fadeInUp 0.3s ease;
}

.kiosk-frites-sauce-price {
  font-size: 11px;
  font-weight: 700;
  color: #333;
  margin-top: 4px;
  min-height: 14px;
}

.kiosk-frites-sauce-section .kiosk-sauce-order {
  position: absolute;
  top: 12px;
  right: 20px;
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
}

.kiosk-menu-action {
  position: absolute;
  top: 12px;
  right: 20px;
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

.kiosk-menu-action.active {
  font-size: 13px;
  font-weight: 800;
}
</style>
