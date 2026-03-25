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
  padding: 16px;
}

.kiosk-step-title {
  font-size: 24px;
  font-weight: 700;
  text-align: center;
  margin-bottom: 32px;
  color: #1a1a2e;
}

.kiosk-pain-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 24px;
  max-width: 600px;
  margin: 0 auto;
}

.kiosk-option-card {
  min-height: 140px;
  border-radius: 20px;
  border: 3px solid #EFF0F6;
  background: white;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.2s ease;
}

.kiosk-option-card:active {
  transform: scale(0.98);
}

.kiosk-option-card.selected {
  border-color: #E93C3C;
  background: #FFF0F0;
  box-shadow: 0 4px 20px rgba(233, 60, 60, 0.15);
}

.kiosk-pain-emoji {
  font-size: 48px;
  margin-bottom: 12px;
}

.kiosk-pain-name {
  font-size: 20px;
  font-weight: 600;
  color: #1a1a2e;
}

.kiosk-validation-hint {
  text-align: center;
  margin-top: 32px;
  font-size: 18px;
  color: #E93C3C;
  font-weight: 500;
}
</style>
