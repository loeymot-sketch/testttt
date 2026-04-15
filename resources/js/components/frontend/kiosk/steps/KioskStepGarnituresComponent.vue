<template>
  <div class="kiosk-step-garnitures">
    <h3 class="kiosk-step-title">{{ $t('kiosk.wizard.step.garnitures.title') }}</h3>

    <div class="kiosk-garnitures-info">
      <span class="kiosk-info-badge">{{ $t('kiosk.wizard.step.garnitures.all_included') }}</span>
      <span class="kiosk-info-text">{{ $t('kiosk.wizard.step.garnitures.deselect_hint') }}</span>
    </div>

    <div class="kiosk-garnitures-list">
      <div
        v-for="garniture in garnitureList"
        :key="garniture.id"
        class="kiosk-garniture-row"
        :class="{ selected: localSelections[garniture.id], removed: !localSelections[garniture.id] }"
        @click="toggleGarniture(garniture.id)"
      >
        <div class="kiosk-garniture-visual">
          <img
            v-if="garniture.displayThumb && !brokenGarnitureThumbs[garnitureThumbKey(garniture)]"
            :src="garniture.displayThumb"
            :alt="garniture.name"
            class="kiosk-garniture-img"
            loading="lazy"
            @error="onGarnitureThumbError(garniture)"
          />
          <span class="kiosk-garniture-emoji" v-else>{{ garniture.emoji }}</span>
          <span v-if="!localSelections[garniture.id]" class="kiosk-garniture-strike"></span>
        </div>
        <span class="kiosk-garniture-name">{{ garniture.name }}</span>
        <span class="kiosk-garniture-status">{{ localSelections[garniture.id] ? $t('kiosk.wizard.step.garnitures.with') : $t('kiosk.wizard.step.garnitures.without') }}</span>
        <span v-if="localSelections[garniture.id]" class="kiosk-garniture-action active">✓</span>
        <span v-else class="kiosk-garniture-action">+</span>
      </div>
    </div>

    <div class="kiosk-garnitures-summary">
      {{ garnituresSummaryText }}
    </div>
  </div>
</template>

<script>
import { kioskResolveImageSrc } from '../../../../helpers/kioskMedia';

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
      localSelections: { ...this.selections.garnitures },
      brokenGarnitureThumbs: {},
      userInteracted: false,
    };
  },
  mounted() {
    // Adopt parent garnitures immediately if already populated at mount time
    const parentGarnitures = this.selections.garnitures;
    if (parentGarnitures && Object.keys(parentGarnitures).length > 0 && Object.keys(this.localSelections).length === 0) {
      this.localSelections = { ...parentGarnitures };
    }
  },
  watch: {
    'selections.garnitures': {
      deep: true,
      handler(newVal) {
        if (this.userInteracted) return;
        this.$nextTick(() => {
          if (!this.userInteracted && Object.keys(this.localSelections).length === 0) {
            this.localSelections = { ...newVal };
          }
        });
      },
    },
  },
  computed: {
    selectedCount() {
      return Object.values(this.localSelections).filter(Boolean).length;
    },
    garnituresSummaryText() {
      const n = this.selectedCount;
      if (n === 0) return this.$t('kiosk.wizard.step.garnitures.summary_zero');
      if (n === 1) return this.$t('kiosk.wizard.step.garnitures.summary_one', { n });
      return this.$t('kiosk.wizard.step.garnitures.summary_many', { n });
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
        displayThumb: kioskResolveImageSrc(g),
        emoji: this.getEmojiForGarniture(g.name)
      }));
    }
  },
  methods: {
    garnitureThumbKey(g) {
      return String(g.id ?? g.name ?? '');
    },
    onGarnitureThumbError(g) {
      const k = this.garnitureThumbKey(g);
      this.brokenGarnitureThumbs = { ...this.brokenGarnitureThumbs, [k]: true };
    },
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
      this.userInteracted = true;
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
  padding: 6px 18px 26px;
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

.kiosk-garnitures-info {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  margin-bottom: 14px;
}

.kiosk-info-badge {
  background: transparent;
  border: none;
  color: #7d7d7d;
  padding: 0;
  border-radius: 50px;
  font-size: 11px;
  font-weight: 600;
}

.kiosk-info-text {
  font-size: 11px;
  color: #999;
}

.kiosk-garnitures-list {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 24px 18px;
  max-width: 980px;
  margin: 0 auto;
}

.kiosk-garniture-row {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  gap: 8px;
  min-height: 196px;
  padding: 10px 10px 14px;
  border-radius: 20px;
  border: 1px solid transparent;
  background: #fff;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.18s ease;
  position: relative;
}

.kiosk-garniture-row:active { transform: scale(0.99); }

.kiosk-garniture-row.selected {
  border-color: rgba(232,0,28,0.14);
  background: rgba(232,0,28,0.025);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
}

.kiosk-garniture-visual {
  width: 118px;
  height: 118px;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.kiosk-garniture-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.kiosk-garniture-emoji {
  font-size: 44px;
  width: 118px;
  height: 118px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f7f7f8;
  border-radius: 50%;
}

.kiosk-garniture-strike {
  position: absolute;
  width: 130px;
  height: 2px;
  background: rgba(199, 62, 79, 0.7);
  transform: rotate(-38deg);
  border-radius: 2px;
}

.kiosk-garniture-name {
  font-size: 12px;
  font-weight: 700;
  color: #444;
  text-align: center;
  text-transform: uppercase;
  line-height: 1.2;
  min-height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-garniture-status {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 0;
  border-radius: 50px;
  background: transparent;
  color: #999;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.kiosk-garniture-row.selected .kiosk-garniture-status {
  color: #d7263d;
}

.kiosk-garniture-action {
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

.kiosk-garniture-action.active {
  font-size: 13px;
  font-weight: 800;
}

.kiosk-garnitures-summary {
  text-align: center;
  margin-top: 16px;
  font-size: 13px;
  color: #999;
  font-weight: 500;
}
</style>
