<template>
  <div class="kiosk-step-supplements">
    <h3 class="kiosk-step-title">Quel supplément ?</h3>

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
        <div class="kiosk-supplement-visual">
          <img v-if="supplement.thumb" :src="supplement.thumb" class="kiosk-supplement-img" />
          <span class="kiosk-supplement-emoji" v-else>{{ supplement.emoji }}</span>
        </div>
        <div class="kiosk-supplement-details">
          <span class="kiosk-supplement-name">{{ supplement.name }}</span>
          <span class="kiosk-supplement-desc">{{ supplement.description || 'Supplément' }}</span>
        </div>
        <span class="kiosk-supplement-price">{{ formatPrice(supplement.price) }}</span>
        <span v-if="localSelections[supplement.id]" class="kiosk-supplement-action active">✓</span>
        <span v-else class="kiosk-supplement-action">+</span>
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
  watch: {
    'selections.supplements': {
      deep: true,
      handler(newVal) {
        if (Object.keys(this.localSelections).length === 0) {
          this.localSelections = { ...newVal };
        }
      },
    },
  },
  computed: {
    supplementList() {
      // Les suppléments sont des extras avec prix > 0 (payants)
      // EXCLUS : les sauces (group_label ou nom contenant 'sauce')
      if (!this.item.extras) return [];

      return this.item.extras
        .filter(e => {
          const price = parseFloat(e.convert_price || e.price || 0);
          const groupLabel = (e.group_label || '').toLowerCase();
          const name = (e.name || '').toLowerCase();
          // Exclure si c'est une sauce (par group_label ou par nom en fallback)
          const isSauce = groupLabel.includes('sauce') || (groupLabel === '' && name.includes('sauce'));
          return price > 0 && !isSauce;
        })
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

.kiosk-supplements-info {
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

.kiosk-supplements-price {
  font-size: 16px;
  font-weight: 800;
  color: #E8001C;
  background: rgba(232,0,28,0.06);
  padding: 4px 12px;
  border-radius: 50px;
}

.kiosk-empty-state {
  text-align: center;
  padding: 40px 24px;
  color: #999;
}

.kiosk-empty-emoji {
  font-size: 48px;
  display: block;
  margin-bottom: 12px;
}

.kiosk-empty-state p { font-size: 15px; }

.kiosk-supplements-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 22px 18px;
  max-width: 860px;
  margin: 0 auto;
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
  border: 1px solid #efefef;
  background: #fff;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.18s ease;
  position: relative;
}

.kiosk-supplement-row:active { transform: scale(0.99); }

.kiosk-supplement-row.selected {
  border-color: rgba(232,0,28,0.18);
  background: rgba(232,0,28,0.02);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
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
  background: #f7f7f8;
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
  color: #444;
  text-align: center;
  text-transform: uppercase;
  line-height: 1.15;
}

.kiosk-supplement-desc {
  font-size: 11px;
  color: #999;
  text-align: center;
}

.kiosk-supplement-price {
  font-size: 14px;
  font-weight: 800;
  color: #222;
}

.kiosk-supplement-action {
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

.kiosk-supplement-action.active {
  font-size: 13px;
  font-weight: 800;
}
</style>
