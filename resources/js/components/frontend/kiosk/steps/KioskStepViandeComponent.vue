<template>
  <div class="kiosk-step-viande">
    <h3 class="kiosk-step-title">
      Choisissez {{ maxViandes }} viande{{ maxViandes > 1 ? 's' : '' }}
    </h3>
    
    <div class="kiosk-viande-counter">
      <div class="kiosk-counter-badge" :class="{ complete: totalSelected >= maxViandes }">
        {{ totalSelected }} / {{ maxViandes }}
      </div>
      <span v-if="totalSelected >= maxViandes" class="kiosk-complete-badge">✅ Complet</span>
    </div>
    
    <div class="kiosk-viande-list">
      <div
        v-for="viande in viandeList"
        :key="viande.id"
        class="kiosk-viande-row"
        :class="{ active: (localSelections[viande.key] || 0) > 0 }"
      >
        <div class="kiosk-viande-info">
          <img v-if="viande.thumb" :src="viande.thumb" class="kiosk-viande-img" />
          <span class="kiosk-viande-emoji" v-else>{{ viande.emoji }}</span>
          <span class="kiosk-viande-name">{{ viande.name }}</span>
        </div>
        <div class="kiosk-viande-controls">
          <button
            @click="decrement(viande.key)"
            class="kiosk-qty-btn"
            :disabled="(localSelections[viande.key] || 0) === 0"
          >−</button>
          <span class="kiosk-qty-value">{{ localSelections[viande.key] || 0 }}</span>
          <button
            @click="increment(viande.key)"
            class="kiosk-qty-btn"
            :disabled="totalSelected >= maxViandes"
          >+</button>
        </div>
      </div>
    </div>
    
    <div v-if="totalSelected < maxViandes" class="kiosk-validation-hint">
      Sélectionnez {{ maxViandes - totalSelected }} viande{{ maxViandes - totalSelected > 1 ? 's' : '' }} pour continuer
    </div>
  </div>
</template>

<script>
export default {
  name: 'KioskStepViande',
  props: {
    step: Object,
    item: Object,
    selections: Object
  },
  emits: ['update'],
  data() {
    return {
      localSelections: { ...this.selections.viandes }
    };
  },
  computed: {
    maxViandes() {
      const name = (this.item.name || '').toLowerCase();
      if (name.includes('xxl') || name.includes('4 viande')) return 4;
      if (name.includes('xl') || name.includes('3 viande')) return 3;
      if (name.includes(' l ') || name.includes('2 viande')) return 2;
      return 1;
    },
    totalSelected() {
      return Object.values(this.localSelections).reduce((sum, v) => sum + (v || 0), 0);
    },
    viandeList() {
      // Lire les viandes depuis les variations DB (attribut "Viande")
      if (!this.item.itemAttributes) return this.getDefaultViandeList();
      
      const viandeAttr = this.item.itemAttributes.find(a =>
        (a.name || '').toLowerCase().includes('viande')
      );
      
      if (!viandeAttr || !this.item.variations?.[viandeAttr.id]) {
        return this.getDefaultViandeList();
      }
      
      return this.item.variations[viandeAttr.id].map(v => ({
        id: v.id,
        key: v.name.toLowerCase().replace(/\s+/g, '_'),
        name: v.name,
        thumb: v.thumb || null,
        emoji: this.getEmojiForViande(v.name)
      }));
    }
  },
  methods: {
    getDefaultViandeList() {
      // Fallback: no real DB IDs — wizard uses instruction text only
      return [
        { id: null, key: 'poulet',   name: 'Poulet',   thumb: null, emoji: '🍗' },
        { id: null, key: 'boeuf',    name: 'Bœuf',     thumb: null, emoji: '🥩' },
        { id: null, key: 'merguez',  name: 'Merguez',  thumb: null, emoji: '🌭' },
        { id: null, key: 'nuggets',  name: 'Nuggets',  thumb: null, emoji: '🍗' },
      ];
    },
    getEmojiForViande(name) {
      const lower = (name || '').toLowerCase();
      if (lower.includes('poulet') || lower.includes('nugget')) return '🍗';
      if (lower.includes('boeuf') || lower.includes('steak')) return '🥩';
      if (lower.includes('merguez')) return '🌭';
      if (lower.includes('poisson')) return '🐟';
      if (lower.includes('crevette')) return '🦐';
      return '🥩';
    },
    emitUpdate() {
      this.$emit('update', 'viandes', { ...this.localSelections });
      this.$emit('update', 'totalViandes', this.totalSelected);
      // Emit meta so wizard can map first selected viande to item_variations
      const selectedMeta = this.viandeList
        .filter(v => (this.localSelections[v.key] || 0) > 0)
        .map(v => ({ id: v.id, key: v.key, name: v.name, count: this.localSelections[v.key] }));
      this.$emit('update', '_viandeMeta', selectedMeta);
    },
    increment(key) {
      if (this.totalSelected < this.maxViandes) {
        this.localSelections[key] = (this.localSelections[key] || 0) + 1;
        this.emitUpdate();
      }
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
  padding: 16px;
}

.kiosk-step-title {
  font-size: 24px;
  font-weight: 700;
  text-align: center;
  margin-bottom: 24px;
  color: #1a1a2e;
}

.kiosk-viande-counter {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  margin-bottom: 32px;
}

.kiosk-counter-badge {
  background: #EFF0F6;
  padding: 12px 24px;
  border-radius: 16px;
  font-size: 20px;
  font-weight: 700;
  color: #1a1a2e;
}

.kiosk-counter-badge.complete {
  background: #43C6AC;
  color: white;
}

.kiosk-complete-badge {
  font-size: 18px;
  font-weight: 600;
  color: #43C6AC;
}

.kiosk-viande-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.kiosk-viande-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-radius: 16px;
  border: 2px solid #EFF0F6;
  background: white;
  min-height: 80px;
  transition: all 0.2s ease;
}

.kiosk-viande-row.active {
  border-color: #E93C3C;
  background: #FFF5F5;
}

.kiosk-viande-info {
  display: flex;
  align-items: center;
  gap: 16px;
}

.kiosk-viande-img {
  width: 56px;
  height: 56px;
  border-radius: 12px;
  object-fit: cover;
}

.kiosk-viande-emoji {
  font-size: 36px;
  width: 56px;
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-viande-name {
  font-size: 20px;
  font-weight: 600;
  color: #1a1a2e;
}

.kiosk-viande-controls {
  display: flex;
  align-items: center;
  gap: 16px;
}

.kiosk-qty-btn {
  width: 56px;
  height: 56px;
  border-radius: 12px;
  border: 2px solid #E93C3C;
  background: white;
  color: #E93C3C;
  font-size: 24px;
  font-weight: 700;
  cursor: pointer;
  touch-action: manipulation;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
}

.kiosk-qty-btn:active:not(:disabled) {
  transform: scale(0.95);
  background: #FFF0F0;
}

.kiosk-qty-btn:disabled {
  border-color: #ddd;
  color: #aaa;
  cursor: not-allowed;
}

.kiosk-qty-value {
  font-size: 24px;
  font-weight: 700;
  color: #1a1a2e;
  min-width: 40px;
  text-align: center;
}

.kiosk-validation-hint {
  text-align: center;
  margin-top: 32px;
  font-size: 18px;
  color: #E93C3C;
  font-weight: 500;
}
</style>
