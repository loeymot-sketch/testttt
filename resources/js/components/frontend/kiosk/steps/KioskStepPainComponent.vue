<template>
  <div class="kiosk-step-pain">
    <h3 class="kiosk-step-title">Choisissez votre type de pain</h3>
    <div class="kiosk-pain-grid">
      <div
        v-for="pain in painList"
        :key="pain.id"
        class="kiosk-option-card"
        :class="{ selected: localSelection === (pain.id ?? pain.name) }"
        @click="selectPain(pain)"
      >
        <span class="kiosk-pain-emoji">{{ pain.emoji }}</span>
        <span class="kiosk-pain-name">{{ pain.name }}</span>
        <span v-if="localSelection === (pain.id ?? pain.name)" class="kiosk-pain-action active">✓</span>
        <span v-else class="kiosk-pain-action">+</span>
      </div>
    </div>
    <div v-if="!localSelection" class="kiosk-validation-hint">
      Sélectionnez un type de pain pour continuer
    </div>
  </div>
</template>

<script>
export default {
  name: 'KioskStepPain',
  props: {
    step: Object,
    item: Object,
    selections: Object
  },
  emits: ['update'],
  data() {
    return {
      localSelection: this.selections.pain || null
    };
  },
  computed: {
    // Whether we have real catalog IDs (integers from DB) or name-only fallback
    hasRealIds() {
      return this.painList.length > 0 && typeof this.painList[0].id === 'number';
    },

    painList() {
      if (!this.item?.itemAttributes) return this.getDefaultPainList();

      const painAttr = this.item.itemAttributes.find(a =>
        (a.name || '').toLowerCase().includes('pain') ||
        (a.name || '').toLowerCase().includes('galette')
      );

      if (!painAttr || !this.item.variations?.[painAttr.id]?.length) {
        return this.getDefaultPainList();
      }

      return this.item.variations[painAttr.id].map(v => ({
        id: v.id,           // Real integer DB id
        name: v.name,
        emoji: this.getEmojiForPain(v.name),
        attrId: painAttr.id,
      }));
    },
  },
  methods: {
    getDefaultPainList() {
      // IDs are null when we have no catalog data — wizard buildCartItem will
      // only add pain to item_variations when id is a real integer.
      return [
        { id: null, name: 'Pain', emoji: '🥖', attrId: null },
        { id: null, name: 'Galette', emoji: '🥙', attrId: null },
      ];
    },
    getEmojiForPain(name) {
      const lower = (name || '').toLowerCase();
      if (lower.includes('galette')) return '🥙';
      if (lower.includes('pain')) return '🥖';
      return '🍞';
    },
    selectPain(pain) {
      this.localSelection = pain.id ?? pain.name; // Store real ID or name as label
      // Emit full pain object so wizard can decide whether to map to item_variations
      this.$emit('update', 'pain', this.localSelection, {
        realId: typeof pain.id === 'number' ? pain.id : null,
        attrId: pain.attrId,
        name:   pain.name,
      });
    },
  }
};
</script>

<style scoped>
.kiosk-step-pain {
  padding: 6px 18px 24px;
  background: #fff;
  min-height: 100%;
}

.kiosk-step-title {
  font-size: 15px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 18px;
  color: #333;
}

.kiosk-pain-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px 18px;
  max-width: 620px;
  margin: 0 auto;
}

.kiosk-option-card {
  min-height: 206px;
  border-radius: 20px;
  border: 1px solid #efefef;
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 14px 12px 20px;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.18s ease;
  position: relative;
}

.kiosk-option-card::after { display: none; }

.kiosk-option-card:active { transform: scale(0.96); }

.kiosk-option-card.selected {
  border-color: rgba(232,0,28,0.18);
  background: rgba(232,0,28,0.02);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
}

.kiosk-pain-emoji {
  width: 124px;
  height: 124px;
  border-radius: 50%;
  background: #f7f7f8;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 60px;
  margin-bottom: 12px;
  transition: transform 0.2s;
}

.kiosk-option-card.selected .kiosk-pain-emoji { transform: scale(1.1); }

.kiosk-pain-name {
  font-size: 14px;
  font-weight: 700;
  color: #444;
  text-align: center;
  text-transform: uppercase;
}

.kiosk-option-card.selected .kiosk-pain-name { color: #E8001C; }

.kiosk-pain-action {
  position: absolute;
  top: 12px;
  right: 20px;
  width: 28px;
  height: 28px;
  background: #d7263d;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  color: white;
  line-height: 1;
  box-shadow: 0 3px 10px rgba(215,38,61,0.2);
  outline: 2px solid rgba(255,255,255,0.85);
}

.kiosk-pain-action.active {
  font-size: 13px;
  font-weight: 800;
}

.kiosk-validation-hint {
  text-align: center;
  margin-top: 20px;
  font-size: 14px;
  color: #E8001C;
  font-weight: 500;
  padding: 10px 20px;
  background: rgba(232,0,28,0.06);
  border-radius: 10px;
  max-width: 400px;
  margin-left: auto;
  margin-right: auto;
}
</style>
