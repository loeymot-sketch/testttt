<template>
  <div class="kiosk-step-taille">
    <h3 class="kiosk-step-title">{{ $t('kiosk.wizard.step.taille.title') }}</h3>
    <div
      v-if="tailleOptions.length > 0"
      class="kiosk-taille-grid"
      role="radiogroup"
      :aria-label="$t('kiosk.wizard.step.taille.title')"
      data-testid="kiosk-step-taille-grid"
    >
      <div
        v-for="option in tailleOptions"
        :key="option.key"
        class="kiosk-taille-card"
        :class="{ selected: localSelection === option.key }"
        role="radio"
        tabindex="0"
        :aria-checked="localSelection === option.key"
        :aria-label="`${option.label} (${option.viandesLabel})`"
        @click="selectTaille(option)"
        @keydown.enter.prevent="selectTaille(option)"
        @keydown.space.prevent="selectTaille(option)"
        data-testid="kiosk-step-taille-option"
      >
        <div
          v-if="option.displayThumb && !brokenTailleThumbs[tailleThumbKey(option)]"
          class="kiosk-taille-media"
          data-testid="kiosk-step-taille-media"
        >
          <img
            :src="option.displayThumb"
            :alt="option.label"
            class="kiosk-taille-img"
            loading="lazy"
            @error="onTailleThumbError(option)"
          />
        </div>
        <span class="kiosk-taille-badge" data-testid="kiosk-step-taille-badge">{{ option.badge }}</span>
        <span class="kiosk-taille-label" data-testid="kiosk-step-taille-label">{{ option.label }}</span>
        <span class="kiosk-taille-viandes" data-testid="kiosk-step-taille-counter">{{ option.viandesLabel }}</span>
        <span v-if="localSelection === option.key" class="kiosk-taille-action active">✓</span>
        <span v-else class="kiosk-taille-action">+</span>
      </div>
    </div>
    <div v-else class="kiosk-step-empty" role="status" aria-live="polite">
      <p>{{ $t('kiosk.wizard.step.taille.empty_hint') }}</p>
    </div>
    <div v-if="tailleOptions.length > 0 && !localSelection" class="kiosk-validation-hint" role="status" aria-live="polite">
      {{ $t('kiosk.wizard.step.taille.hint') }}
    </div>
  </div>
</template>

<script>
import { kioskResolveImageSrc, kioskVariationsForAttribute } from '../../../../helpers/kioskMedia';

// [AUDIT-P2] Dedicated size step for tacos (S / M / L / XL).
// Replaces the fragile name-based heuristic (detectViandeCount) with an explicit
// customer choice. The wizard reads selections.taille to set maxViandes on the
// viande step, so the customer always picks the right number of meats.
export default {
  name: 'KioskStepTaille',
  props: {
    step: Object,
    item: Object,
    selections: Object,
  },
  emits: ['update'],
  data() {
    return {
      localSelection: this.selections.taille || null,
      brokenTailleThumbs: {},
    };
  },
  computed: {
    tailleOptions() {
      const attrs = Array.isArray(this.item?.itemAttributes)
        ? this.item.itemAttributes
        : Object.values(this.item?.itemAttributes || {});
      const tailleAttr = attrs.find((attr) => String(attr?.role || '').toLowerCase() === 'size');
      const tailleVars = kioskVariationsForAttribute(this.item, tailleAttr?.id);

      if (!tailleAttr || !Array.isArray(tailleVars) || tailleVars.length === 0) {
        return [];
      }

      return tailleVars.map(v => {
        const lower = (v.name || '').toLowerCase();
        const viandeCount = lower.includes('xxl') || lower.includes('4') ? 4
          : lower.includes('xl') || lower.includes('3') ? 3
          : lower.includes(' l') || lower.includes('2') ? 2
          : 1;
        return {
          key: v.id,
          badge: v.name,
          label: v.name,
          viandesLabel: this.formatViandesCountLabel(viandeCount),
          viandeCount,
          attrId: tailleAttr.id,
          realId: v.id,
          displayThumb: kioskResolveImageSrc(v),
        };
      });
    },
  },
  watch: {
    'selections.taille'(value) {
      this.localSelection = value || null;
    },
  },
  methods: {
    formatViandesCountLabel(count) {
      if (count === 1) return this.$t('kiosk.wizard.step.taille.meats_1', { n: count });
      return this.$t('kiosk.wizard.step.taille.meats_n', { n: count });
    },
    tailleThumbKey(option) {
      return String(option.key ?? option.label ?? '');
    },
    onTailleThumbError(option) {
      const k = this.tailleThumbKey(option);
      this.brokenTailleThumbs = { ...this.brokenTailleThumbs, [k]: true };
    },
    selectTaille(option) {
      this.localSelection = option.key;
      // Emit taille key + meta so wizard can update maxViandes for the viande step
      this.$emit('update', 'taille', option.key, {
        viandeCount: option.viandeCount,
        label: option.label,
        attrId: option.attrId,
        realId: option.realId,
      });
    },
  },
};
</script>

<style scoped>
.kiosk-step-taille {
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

.kiosk-taille-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px 18px;
  max-width: 620px;
  margin: 0 auto;
}

.kiosk-taille-card {
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
  gap: 4px;
}

.kiosk-taille-card::after { display: none; }
.kiosk-taille-card:active { transform: scale(0.96); }

.kiosk-taille-card:focus-visible {
  outline: 3px solid rgba(232, 0, 28, 0.55);
  outline-offset: 2px;
}

.kiosk-taille-card.selected {
  border-color: rgba(232,0,28,0.18);
  background: rgba(232,0,28,0.02);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
}

.kiosk-taille-media {
  width: 100px;
  height: 100px;
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-taille-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.kiosk-taille-badge {
  width: 124px;
  height: 124px;
  border-radius: 50%;
  background: #f7f7f8;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 44px;
  font-weight: 900;
  color: #1A1A1A;
  line-height: 1;
  margin-bottom: 8px;
}

.kiosk-taille-card.selected .kiosk-taille-badge { color: #E8001C; }

.kiosk-taille-label {
  font-size: 15px;
  font-weight: 600;
  color: #555;
  text-align: center;
}

.kiosk-taille-viandes {
  font-size: 12px;
  font-weight: 500;
  color: #999;
  text-align: center;
}

.kiosk-taille-card.selected .kiosk-taille-viandes { color: rgba(232,0,28,0.6); }

.kiosk-taille-action {
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

.kiosk-taille-action.active {
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
