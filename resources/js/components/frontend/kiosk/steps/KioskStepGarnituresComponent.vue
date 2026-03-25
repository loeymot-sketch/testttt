<template>
  <div class="kiosk-step-garnitures">
    <h3 class="kiosk-step-title">Garnitures incluses</h3>
    
    <div class="kiosk-garnitures-info">
      <span class="kiosk-info-badge">Toutes les garnitures sont incluses</span>
      <span class="kiosk-info-text">Désélectionnez celles que vous ne voulez pas</span>
    </div>
    
    <div class="kiosk-garnitures-list">
      <div
        v-for="garniture in garnitureList"
        :key="garniture.id"
        class="kiosk-garniture-row"
        :class="{ selected: localSelections[garniture.id] }"
        @click="toggleGarniture(garniture.id)"
      >
        <div class="kiosk-garniture-checkbox">
          <span v-if="localSelections[garniture.id]" class="kiosk-check-icon">✓</span>
        </div>
        <div class="kiosk-garniture-info">
          <img v-if="garniture.thumb" :src="garniture.thumb" class="kiosk-garniture-img" />
          <span class="kiosk-garniture-emoji" v-else>{{ garniture.emoji }}</span>
          <span class="kiosk-garniture-name">{{ garniture.name }}</span>
        </div>
        <span class="kiosk-garniture-status">
          {{ localSelections[garniture.id] ? 'INCLUSE' : 'Retirée' }}
        </span>
      </div>
    </div>
    
    <div class="kiosk-garnitures-summary">
      {{ selectedCount }} garniture{{ selectedCount > 1 ? 's' : '' }} sélectionnée{{ selectedCount > 1 ? 's' : '' }}
    </div>
  </div>
</template>

<script>
export default {
  name: 'KioskStepGarnitures',
  props: {
    step: Object,
    item: Object,
    selections: Object
  },
  emits: ['update'],
  data() {
    return {
      localSelections: { ...this.selections.garnitures }
    };
  },
  watch: {
    // Sync when parent initialises garniture defaults after this child mounts
    // (Vue lifecycle: child mounted() runs before parent mounted())
    'selections.garnitures': {
      deep: true,
      handler(newVal) {
        // Only sync when localSelections is still empty (not yet interacted with)
        if (Object.keys(this.localSelections).length === 0) {
          this.localSelections = { ...newVal };
        }
      },
    },
  },
  computed: {
    selectedCount() {
      return Object.values(this.localSelections).filter(Boolean).length;
    },
    garnitureList() {
      // Les garnitures sont des extras avec prix = 0 (gratuites), excluant les sauces
      if (!this.item.extras) return [];
      
      const garnitures = this.item.extras.filter(e => {
        const price = parseFloat(e.convert_price || e.price || 0);
        const name = (e.name || '').toLowerCase();
        return price === 0 && !name.includes('sauce suppl');
      });
      
      return garnitures.map(g => ({
        id: g.id,
        name: g.name,
        thumb: g.thumb || null,
        emoji: this.getEmojiForGarniture(g.name)
      }));
    }
  },
  methods: {
    getEmojiForGarniture(name) {
      const lower = (name || '').toLowerCase();
      if (lower.includes('salade') || lower.includes('laitue') || lower.includes('roquette')) return '🥬';
      if (lower.includes('tomate')) return '🍅';
      if (lower.includes('oignon')) return '🧅';
      if (lower.includes('cornichon') || lower.includes('pickle')) return '🥒';
      if (lower.includes('poivron') || lower.includes('piment')) return '🫑';
      if (lower.includes('chou') || lower.includes('choux')) return '🥗';
      if (lower.includes('carotte')) return '🥕';
      if (lower.includes('fromage') || lower.includes('cheddar') || lower.includes('cheese')) return '🧀';
      return '🥗';
    },
    toggleGarniture(id) {
      const newSelections = { ...this.localSelections };
      newSelections[id] = !newSelections[id];
      this.localSelections = newSelections;
      this.$emit('update', 'garnitures', newSelections);
    }
  }
};
</script>

<style scoped>
.kiosk-step-garnitures {
  padding: 16px;
}

.kiosk-step-title {
  font-size: 24px;
  font-weight: 700;
  text-align: center;
  margin-bottom: 16px;
  color: #1a1a2e;
}

.kiosk-garnitures-info {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  margin-bottom: 24px;
}

.kiosk-info-badge {
  background: #43C6AC;
  color: white;
  padding: 8px 16px;
  border-radius: 20px;
  font-size: 14px;
  font-weight: 600;
}

.kiosk-info-text {
  font-size: 14px;
  color: #666;
}

.kiosk-garnitures-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.kiosk-garniture-row {
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

.kiosk-garniture-row:active {
  transform: scale(0.99);
}

.kiosk-garniture-row.selected {
  border-color: #43C6AC;
  background: #F0FDFB;
}

.kiosk-garniture-row:not(.selected) {
  opacity: 0.7;
}

.kiosk-garniture-checkbox {
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

.kiosk-garniture-row.selected .kiosk-garniture-checkbox {
  border-color: #43C6AC;
  background: #43C6AC;
}

.kiosk-check-icon {
  color: white;
  font-size: 18px;
  font-weight: 700;
}

.kiosk-garniture-info {
  display: flex;
  align-items: center;
  gap: 16px;
  flex: 1;
}

.kiosk-garniture-img {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  object-fit: cover;
}

.kiosk-garniture-emoji {
  font-size: 32px;
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-garniture-name {
  font-size: 18px;
  font-weight: 600;
  color: #1a1a2e;
}

.kiosk-garniture-status {
  font-size: 12px;
  font-weight: 600;
  padding: 6px 12px;
  border-radius: 12px;
  background: #EFF0F6;
  color: #666;
}

.kiosk-garniture-row.selected .kiosk-garniture-status {
  background: #43C6AC;
  color: white;
}

.kiosk-garnitures-summary {
  text-align: center;
  margin-top: 24px;
  font-size: 16px;
  color: #666;
}
</style>
