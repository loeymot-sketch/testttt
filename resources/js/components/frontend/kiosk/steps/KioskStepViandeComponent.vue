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

    <!-- Grille 2 colonnes style Splash -->
    <div class="kiosk-viande-grid">
      <div
        v-for="viande in viandeList"
        :key="viande.id"
        class="kiosk-viande-card"
        :class="{ active: (localSelections[viande.key] || 0) > 0 }"
      >
        <!-- Image/Emoji en haut -->
        <div class="kiosk-viande-visual">
          <img v-if="viande.thumb" :src="viande.thumb" class="kiosk-viande-img" />
          <span class="kiosk-viande-emoji" v-else>{{ viande.emoji }}</span>
        </div>

        <!-- Nom viande -->
        <span class="kiosk-viande-name">{{ viande.name }}</span>

        <!-- Contrôles +/- en bas de carte -->
        <div class="kiosk-viande-controls">
          <button
            @click="decrement(viande.key)"
            class="kiosk-viande-qty-btn"
            :disabled="(localSelections[viande.key] || 0) === 0"
          >−</button>
          <span class="kiosk-viande-qty-value">{{ localSelections[viande.key] || 0 }}</span>
          <button
            @click="increment(viande.key)"
            class="kiosk-viande-qty-btn plus"
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
      // [AUDIT-P2] Prefer explicit taille selection from parent wizard
      if (this.selections._tailleMeta?.viandeCount) {
        return this.selections._tailleMeta.viandeCount;
      }
      // Fallback to name heuristic only when no explicit taille choice
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

.kiosk-viande-counter {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin-bottom: 14px;
}

.kiosk-counter-badge {
  background: #F7F7F8;
  border: 2px solid #E0E0E0;
  padding: 8px 24px;
  border-radius: 50px;
  font-size: 18px;
  font-weight: 800;
  color: #555;
  transition: all 0.25s ease;
}

.kiosk-counter-badge.complete {
  background: rgba(46,204,113,0.1);
  border-color: #27ae60;
  color: #27ae60;
}

.kiosk-complete-badge {
  font-size: 14px;
  font-weight: 700;
  color: #27ae60;
}

.kiosk-viande-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 22px 18px;
  max-width: 820px;
  margin: 0 auto;
}

.kiosk-viande-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: space-between;
  min-height: 234px;
  padding: 14px 14px 16px;
  border-radius: 20px;
  border: 1px solid #efefef;
  background: #fff;
  transition: all 0.18s ease;
  cursor: pointer;
  touch-action: manipulation;
  position: relative;
}

.kiosk-viande-card:active { transform: scale(0.97); }

.kiosk-viande-card.active {
  border-color: rgba(232,0,28,0.18);
  background: rgba(232,0,28,0.02);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
}

.kiosk-viande-visual {
  width: 122px;
  height: 122px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 6px;
}

.kiosk-viande-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.kiosk-viande-emoji {
  width: 122px;
  height: 122px;
  border-radius: 50%;
  background: #f7f7f8;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 56px;
  line-height: 1;
}

.kiosk-viande-name {
  font-size: 13px;
  font-weight: 700;
  color: #444;
  text-align: center;
  margin-bottom: 8px;
  line-height: 1.2;
  text-transform: uppercase;
}

.kiosk-viande-card.active .kiosk-viande-name { color: #E8001C; }

.kiosk-viande-controls {
  display: flex;
  align-items: center;
  gap: 0;
  background: #F7F7F8;
  border: 1.5px solid #E0E0E0;
  border-radius: 14px;
  overflow: hidden;
  margin-top: auto;
}

.kiosk-viande-card.active .kiosk-viande-controls {
  border-color: rgba(232,0,28,0.3);
  background: rgba(232,0,28,0.04);
}

.kiosk-viande-qty-btn {
  width: 44px;
  height: 44px;
  border: none;
  background: transparent;
  color: #999;
  font-size: 22px;
  font-weight: 700;
  cursor: pointer;
  touch-action: manipulation;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
}

.kiosk-viande-qty-btn:active:not(:disabled) {
  background: rgba(0,0,0,0.05);
  color: #1A1A1A;
}

.kiosk-viande-qty-btn.plus { color: #E8001C; }

.kiosk-viande-qty-btn.plus:active:not(:disabled) {
  background: #E8001C;
  color: white;
}

.kiosk-viande-qty-btn:disabled {
  color: #b0b0b0;
  cursor: not-allowed;
}

.kiosk-viande-qty-value {
  font-size: 18px;
  font-weight: 800;
  color: #1A1A1A;
  min-width: 36px;
  text-align: center;
}

.kiosk-viande-card.active .kiosk-viande-qty-value { color: #E8001C; }

.kiosk-validation-hint {
  text-align: center;
  margin-top: 20px;
  font-size: 14px;
  color: #E8001C;
  font-weight: 500;
  padding: 10px 20px;
  background: rgba(232,0,28,0.06);
  border-radius: 10px;
}
</style>
