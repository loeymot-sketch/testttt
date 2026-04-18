<template>
  <div class="kiosk-step-pain">
    <h3 class="kiosk-step-title">{{ $t('kiosk.wizard.step.pain.title') }}</h3>

    <div v-if="painList.length === 0" class="kiosk-step-empty" role="status" aria-live="polite">
      <p>{{ $t('kiosk.wizard.step.pain.empty_hint') }}</p>
    </div>

    <div v-else class="kiosk-pain-grid" role="radiogroup" :aria-label="$t('kiosk.wizard.step.pain.title')">
      <div
        v-for="pain in painList"
        :key="pain.id ?? pain.name"
        class="kiosk-option-card"
        :class="{ selected: localSelection === (pain.id ?? pain.name) }"
        role="radio"
        tabindex="0"
        :aria-checked="localSelection === (pain.id ?? pain.name)"
        :aria-label="pain.name"
        @click="selectPain(pain)"
        @keydown.enter.prevent="selectPain(pain)"
        @keydown.space.prevent="selectPain(pain)"
      >
        <div class="kiosk-pain-media">
          <img
            v-if="pain.displayThumb && !brokenPainThumbs[painThumbKey(pain)]"
            :src="pain.displayThumb"
            :alt="pain.name"
            class="kiosk-pain-thumb"
            loading="lazy"
            @error="onPainThumbError(pain)"
          />
          <span v-else class="kiosk-pain-emoji">{{ pain.emoji }}</span>
        </div>
        <span class="kiosk-pain-name">{{ pain.name }}</span>
        <span v-if="localSelection === (pain.id ?? pain.name)" class="kiosk-pain-action active">✓</span>
        <span v-else class="kiosk-pain-action">+</span>
      </div>
    </div>
    <div v-if="painList.length > 0 && !localSelection" class="kiosk-validation-hint" role="status" aria-live="polite">
      {{ $t('kiosk.wizard.step.pain.hint') }}
    </div>
  </div>
</template>

<script>
import { kioskResolveImageSrc, kioskVariationsForAttribute } from '../../../../helpers/kioskMedia';

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
      localSelection: this.selections.pain || null,
      brokenPainThumbs: {},
    };
  },
  computed: {
    // Whether we have real catalog IDs (integers from DB) or name-only fallback
    hasRealIds() {
      return this.painList.length > 0 && typeof this.painList[0].id === 'number';
    },

    // [AUDIT 2026-04-17 C1] Liste 100% catalogue — plus de fallback hardcodé.
    // Si le produit ne déclare pas d'attribut Pain/Galette, l'étape affiche
    // un état vide (et n'aurait même pas dû être ouverte par le wizard :
    // shouldShowStep('pain') couvre déjà ce cas en amont).
    painList() {
      const attrs = Array.isArray(this.item?.itemAttributes)
        ? this.item.itemAttributes
        : Object.values(this.item?.itemAttributes || {});
      const painAttr = attrs.find(a =>
        (a?.name || '').toLowerCase().includes('pain') ||
        (a?.name || '').toLowerCase().includes('galette')
      );
      if (!painAttr?.id) return [];

      const list = kioskVariationsForAttribute(this.item, painAttr.id);
      if (!Array.isArray(list) || list.length === 0) return [];

      return list
        .filter(v => v != null && Number(v.status) !== 10)
        .map(v => ({
          id: v.id,
          name: v.name,
          emoji: this.getEmojiForPain(v.name),
          attrId: painAttr.id,
          displayThumb: kioskResolveImageSrc(v),
        }));
    },
  },
  watch: {
    'selections.pain'(value) {
      this.localSelection = value || null;
    },
  },
  methods: {
    painThumbKey(pain) {
      return String(pain.id ?? pain.name ?? '');
    },
    onPainThumbError(pain) {
      const k = this.painThumbKey(pain);
      this.brokenPainThumbs = { ...this.brokenPainThumbs, [k]: true };
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

.kiosk-option-card:focus-visible {
  outline: 3px solid rgba(232, 0, 28, 0.55);
  outline-offset: 2px;
}

.kiosk-step-empty {
  text-align: center;
  padding: 40px 20px;
  color: #666;
  font-size: 14px;
}

.kiosk-option-card.selected {
  border-color: rgba(232,0,28,0.18);
  background: rgba(232,0,28,0.02);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
}

.kiosk-pain-media {
  width: 124px;
  height: 124px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 12px;
}

.kiosk-pain-thumb {
  width: 100%;
  height: 100%;
  object-fit: contain;
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
