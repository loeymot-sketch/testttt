<template>
  <div class="kiosk-step-sauce">
    <h3 class="kiosk-step-title">Quelle sauce ?</h3>

    <div class="kiosk-sauce-info">
      <span class="kiosk-sauce-badge">1ere sauce gratuite</span>
      <span v-if="selectedCount > 1" class="kiosk-sauce-extra">
        +{{ selectedCount - 1 }} sauce{{ selectedCount > 2 ? 's' : '' }} supplémentaire ({{ extraSaucePriceLabel }})
      </span>
    </div>

    <div class="kiosk-sauce-grid">
      <div
        v-for="sauce in sauceList"
        :key="sauce.id ?? sauce.name"
        class="kiosk-option-card"
        :class="{ selected: localSelections[sauceKey(sauce)] }"
        @click="toggleSauce(sauce)"
      >
        <div class="kiosk-sauce-media">
          <img v-if="sauce.thumb" :src="sauce.thumb" :alt="sauce.name" class="kiosk-sauce-thumb" />
          <span v-else class="kiosk-sauce-emoji">{{ sauce.emoji }}</span>
        </div>
        <span class="kiosk-sauce-name">{{ sauce.name }}</span>
        <span class="kiosk-sauce-price">{{ getSauceOrder(sauceKey(sauce)) > 1 ? extraSaucePriceLabel : '0,00 €' }}</span>
        <span v-if="getSauceOrder(sauceKey(sauce)) > 0" class="kiosk-sauce-order">{{ getSauceOrder(sauceKey(sauce)) }}</span>
        <span v-else class="kiosk-sauce-add">+</span>
      </div>
    </div>

    <div v-if="selectedCount === 0" class="kiosk-validation-hint">
      Sélectionnez au moins une sauce
    </div>
  </div>
</template>

<script>
export default {
  name: 'KioskStepSauce',
  props: {
    step: Object,
    item: Object,
    selections: Object
  },
  emits: ['update'],
  data() {
    return {
      localSelections: { ...this.selections.sauces },
      sauceOrder: [...(this.selections.sauceOrder || [])]
    };
  },
  computed: {
    selectedCount() {
      return Object.values(this.localSelections).filter(Boolean).length;
    },
    extraSaucePrice() {
      const sauceAttr = this.item?.itemAttributes?.find(a =>
        (a.name || '').toLowerCase().includes('sauce')
      );
      if (sauceAttr && this.item.variations?.[sauceAttr.id]) {
        const sauceVar = this.item.variations[sauceAttr.id].find(v =>
          parseFloat(v.convert_price || v.price || 0) > 0
        );
        if (sauceVar) return parseFloat(sauceVar.convert_price || sauceVar.price || 0.50);
      }
      return 0.50;
    },
    extraSaucePriceLabel() {
      return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(this.extraSaucePrice);
    },
    sauceList() {
      // Lire les sauces depuis les variations DB (attribut "Sauce")
      if (!this.item.itemAttributes) return this.getDefaultSauceList();
      
      const sauceAttr = this.item.itemAttributes.find(a =>
        (a.name || '').toLowerCase().includes('sauce')
      );
      
      if (!sauceAttr || !this.item.variations?.[sauceAttr.id]) {
        return this.getDefaultSauceList();
      }
      
      return this.item.variations[sauceAttr.id].map(v => ({
        id: v.id,
        name: v.name,
        emoji: this.getEmojiForSauce(v.name),
        thumb: v.thumb || null,
      }));
    }
  },
  methods: {
    getDefaultSauceList() {
      // Fallback names only — IDs are null so wizard won't map them to item_variations.
      // Kitchen will receive sauce names via instruction text instead.
      return [
        { id: null, name: 'Algérienne', emoji: '🌶️', thumb: null },
        { id: null, name: 'Blanche', emoji: '🥛', thumb: null },
        { id: null, name: 'Ketchup', emoji: '🍅', thumb: null },
        { id: null, name: 'Mayonnaise', emoji: '🥚', thumb: null },
        { id: null, name: 'Biggy', emoji: '🍔', thumb: null },
        { id: null, name: 'Samouraï', emoji: '🌶️', thumb: null },
      ];
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
    // Use real integer ID when available, otherwise fall back to name as unique key
    sauceKey(sauce) {
      return typeof sauce.id === 'number' ? sauce.id : sauce.name;
    },
    getSauceOrder(key) {
      const index = this.sauceOrder.indexOf(key);
      return index >= 0 ? index + 1 : 0;
    },
    toggleSauce(sauce) {
      const key = this.sauceKey(sauce);
      const newSelections = { ...this.localSelections };
      const newSauceOrder = [...this.sauceOrder];
      if (newSelections[key]) {
        delete newSelections[key];
        const index = newSauceOrder.indexOf(key);
        if (index > -1) newSauceOrder.splice(index, 1);
      } else {
        newSelections[key] = true;
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

.kiosk-option-card.selected {
  border-color: rgba(232,0,28,0.14);
  background: rgba(232,0,28,0.025);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
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
  right: 22px;
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
  right: 22px;
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
