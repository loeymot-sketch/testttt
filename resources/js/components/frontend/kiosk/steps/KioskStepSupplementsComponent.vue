<template>
  <div class="kiosk-step-supplements">
    <h3 class="kiosk-step-title">Suppléments</h3>
    
    <div class="kiosk-supplements-info">
      <span class="kiosk-info-badge">Options payantes</span>
      <span v-if="totalPrice > 0" class="kiosk-supplements-price">
        +{{ formatPrice(totalPrice) }}
      </span>
    </div>
    
    <div v-if="supplementList.length === 0" class="kiosk-empty-state">
      <span class="kiosk-empty-emoji">🍽️</span>
      <p>Aucun supplément disponible pour cet article</p>
    </div>
    
    <div v-else class="kiosk-supplements-list">
      <div
        v-for="supplement in supplementList"
        :key="supplement.id"
        class="kiosk-supplement-row"
        :class="{ selected: localSelections[supplement.id] }"
        @click="toggleSupplement(supplement.id)"
      >
        <div class="kiosk-supplement-checkbox">
          <span v-if="localSelections[supplement.id]" class="kiosk-check-icon">✓</span>
        </div>
        <div class="kiosk-supplement-info">
          <img v-if="supplement.thumb" :src="supplement.thumb" class="kiosk-supplement-img" />
          <span class="kiosk-supplement-emoji" v-else>{{ supplement.emoji }}</span>
          <div class="kiosk-supplement-details">
            <span class="kiosk-supplement-name">{{ supplement.name }}</span>
            <span class="kiosk-supplement-desc">{{ supplement.description || 'Supplément' }}</span>
          </div>
        </div>
        <span class="kiosk-supplement-price">{{ formatPrice(supplement.price) }}</span>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'KioskStepSupplements',
  props: {
    step: Object,
    item: Object,
    selections: Object
  },
  emits: ['update'],
  data() {
    return {
      localSelections: { ...this.selections.supplements }
    };
  },
  computed: {
    supplementList() {
      // Les suppléments sont des extras avec prix > 0 (payants)
      if (!this.item.extras) return [];
      
      return this.item.extras
        .filter(e => parseFloat(e.convert_price || e.price || 0) > 0)
        .map(s => ({
          id: s.id,
          name: s.name,
          price: parseFloat(s.convert_price || s.price || 0),
          description: s.description || '',
          thumb: s.thumb || null,
          emoji: this.getEmojiForSupplement(s.name)
        }));
    },
    totalPrice() {
      return this.supplementList.reduce((sum, s) => {
        if (this.localSelections[s.id]) {
          return sum + s.price;
        }
        return sum;
      }, 0);
    }
  },
  methods: {
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
      return '➕';
    },
    formatPrice(price) {
      return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'EUR'
      }).format(price || 0);
    },
    toggleSupplement(id) {
      const newSelections = { ...this.localSelections };
      newSelections[id] = !newSelections[id];
      this.localSelections = newSelections;
      this.$emit('update', 'supplements', newSelections);
    }
  }
};
</script>

<style scoped>
.kiosk-step-supplements {
  padding: 16px;
}

.kiosk-step-title {
  font-size: 24px;
  font-weight: 700;
  text-align: center;
  margin-bottom: 16px;
  color: #1a1a2e;
}

.kiosk-supplements-info {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  margin-bottom: 24px;
}

.kiosk-info-badge {
  background: #E93C3C;
  color: white;
  padding: 8px 16px;
  border-radius: 20px;
  font-size: 14px;
  font-weight: 600;
}

.kiosk-supplements-price {
  font-size: 20px;
  font-weight: 700;
  color: #E93C3C;
}

.kiosk-empty-state {
  text-align: center;
  padding: 48px 24px;
  color: #999;
}

.kiosk-empty-emoji {
  font-size: 64px;
  display: block;
  margin-bottom: 16px;
}

.kiosk-empty-state p {
  font-size: 18px;
}

.kiosk-supplements-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.kiosk-supplement-row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px 20px;
  border-radius: 16px;
  border: 2px solid #EFF0F6;
  background: white;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.2s ease;
}

.kiosk-supplement-row:active {
  transform: scale(0.99);
}

.kiosk-supplement-row.selected {
  border-color: #E93C3C;
  background: #FFF0F0;
}

.kiosk-supplement-checkbox {
  width: 32px;
  height: 32px;
  border: 3px solid #EFF0F6;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: white;
  flex-shrink: 0;
}

.kiosk-supplement-row.selected .kiosk-supplement-checkbox {
  border-color: #E93C3C;
  background: #E93C3C;
}

.kiosk-check-icon {
  color: white;
  font-size: 18px;
  font-weight: 700;
}

.kiosk-supplement-info {
  display: flex;
  align-items: center;
  gap: 16px;
  flex: 1;
}

.kiosk-supplement-img {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  object-fit: cover;
}

.kiosk-supplement-emoji {
  font-size: 32px;
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-supplement-details {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.kiosk-supplement-name {
  font-size: 18px;
  font-weight: 600;
  color: #1a1a2e;
}

.kiosk-supplement-desc {
  font-size: 14px;
  color: #666;
}

.kiosk-supplement-price {
  font-size: 18px;
  font-weight: 700;
  color: #E93C3C;
}
</style>
