<template>
  <div class="kiosk-step-sauce">
    <h3 class="kiosk-step-title">Choisissez votre sauce</h3>
    
    <div class="kiosk-sauce-info">
      <span class="kiosk-sauce-badge">1ère sauce gratuite</span>
      <span v-if="selectedCount > 1" class="kiosk-sauce-extra">
        +{{ selectedCount - 1 }} sauce{{ selectedCount > 2 ? 's' : '' }} supplémentaire (0.50€)
      </span>
    </div>
    
    <div class="kiosk-sauce-grid">
      <div
        v-for="sauce in sauceList"
        :key="sauce.id"
        class="kiosk-option-card"
        :class="{ selected: localSelections[sauce.id] }"
        @click="toggleSauce(sauce.id)"
      >
        <span class="kiosk-sauce-emoji">{{ sauce.emoji }}</span>
        <span class="kiosk-sauce-name">{{ sauce.name }}</span>
        <span v-if="getSauceOrder(sauce.id) > 0" class="kiosk-sauce-order">{{ getSauceOrder(sauce.id) }}</span>
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
        emoji: this.getEmojiForSauce(v.name)
      }));
    }
  },
  methods: {
    getDefaultSauceList() {
      return [
        { id: 1, name: 'Algérienne', emoji: '🌶️' },
        { id: 2, name: 'Blanche', emoji: '🥛' },
        { id: 3, name: 'Ketchup', emoji: '🍅' },
        { id: 4, name: 'Mayonnaise', emoji: '🥚' },
        { id: 5, name: 'Biggy', emoji: '🍔' },
        { id: 6, name: 'Samouraï', emoji: '🌶️' }
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
    getSauceOrder(sauceId) {
      const index = this.sauceOrder.indexOf(sauceId);
      return index >= 0 ? index + 1 : 0;
    },
    toggleSauce(id) {
      const newSelections = { ...this.localSelections };
      const newSauceOrder = [...this.sauceOrder];
      if (newSelections[id]) {
        // Décocher
        delete newSelections[id];
        const index = newSauceOrder.indexOf(id);
        if (index > -1) newSauceOrder.splice(index, 1);
      } else {
        // Cocher
        newSelections[id] = true;
        newSauceOrder.push(id);
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
  padding: 16px;
}

.kiosk-step-title {
  font-size: 24px;
  font-weight: 700;
  text-align: center;
  margin-bottom: 16px;
  color: #1a1a2e;
}

.kiosk-sauce-info {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  margin-bottom: 24px;
}

.kiosk-sauce-badge {
  background: #43C6AC;
  color: white;
  padding: 8px 16px;
  border-radius: 20px;
  font-size: 14px;
  font-weight: 600;
}

.kiosk-sauce-extra {
  font-size: 16px;
  color: #E93C3C;
  font-weight: 500;
}

.kiosk-sauce-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

.kiosk-option-card {
  min-height: 100px;
  border-radius: 16px;
  border: 3px solid #EFF0F6;
  background: white;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 16px;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.2s ease;
  position: relative;
}

.kiosk-option-card:active {
  transform: scale(0.98);
}

.kiosk-option-card.selected {
  border-color: #E93C3C;
  background: #FFF0F0;
}

.kiosk-sauce-emoji {
  font-size: 36px;
  margin-bottom: 8px;
}

.kiosk-sauce-name {
  font-size: 16px;
  font-weight: 600;
  color: #1a1a2e;
  text-align: center;
}

.kiosk-sauce-order {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 28px;
  height: 28px;
  background: #E93C3C;
  color: white;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  font-weight: 700;
}

.kiosk-validation-hint {
  text-align: center;
  margin-top: 24px;
  font-size: 18px;
  color: #E93C3C;
  font-weight: 500;
}
</style>
