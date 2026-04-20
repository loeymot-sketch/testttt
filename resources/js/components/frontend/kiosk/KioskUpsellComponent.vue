<template>
  <div class="kiosk-upsell" data-testid="kiosk-upsell-root">
    <!-- Skip si chargement trop long -->
    <div
      v-if="loading"
      class="kiosk-upsell-loading"
      role="status"
      aria-live="polite"
      :aria-label="$t('kiosk.upsell_screen.title')"
      data-testid="kiosk-upsell-loading"
    >
      <div class="kiosk-spinner" aria-hidden="true" />
    </div>

    <template v-else>
      <!-- Header -->
      <div class="kiosk-upsell-header">
        <h1 class="kiosk-upsell-title" data-testid="kiosk-upsell-title">{{ $t('kiosk.upsell_screen.title') }}</h1>
        <p class="kiosk-upsell-subtitle">{{ $t('kiosk.upsell_screen.subtitle') }}</p>
      </div>

      <!-- Grille suggestions -->
      <div
        v-if="suggestions.length > 0"
        class="kiosk-upsell-grid"
        role="list"
        :aria-label="$t('kiosk.upsell_screen.title')"
        data-testid="kiosk-upsell-grid"
      >
        <div
          v-for="item in suggestions"
          :key="item.id"
          class="kiosk-upsell-card"
          :class="{ selected: selectedIds.includes(item.id) }"
          role="listitem"
          tabindex="0"
          :aria-pressed="selectedIds.includes(item.id)"
          :aria-label="sanitizeItemName(item.name) + ' — ' + formatPrice(item.convert_price)"
          :data-testid="'kiosk-upsell-card-' + item.id"
          @click="toggleItem(item)"
          @keydown.enter.prevent="toggleItem(item)"
          @keydown.space.prevent="toggleItem(item)"
        >
          <!-- Image -->
          <div class="kiosk-upsell-img-wrap" aria-hidden="true">
            <img v-if="item.thumb || item.image" :src="item.thumb || item.image" :alt="item.name" class="kiosk-upsell-img" />
            <div v-else class="kiosk-upsell-img-fallback">{{ getEmoji(item.name) }}</div>
          </div>

          <!-- Infos -->
          <div class="kiosk-upsell-info">
            <h3 class="kiosk-upsell-item-name" :data-testid="'kiosk-upsell-card-name-' + item.id">{{ sanitizeItemName(item.name) }}</h3>
            <span class="kiosk-upsell-item-price" :data-testid="'kiosk-upsell-card-price-' + item.id">{{ formatPrice(item.convert_price) }}</span>
          </div>

          <!-- Checkmark -->
          <transition name="pop">
            <div v-if="selectedIds.includes(item.id)" class="kiosk-upsell-check" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                <path d="M4 10l5 5 7-8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
          </transition>

          <!-- Bouton +/- -->
          <div class="kiosk-upsell-add" aria-hidden="true">
            <span v-if="!selectedIds.includes(item.id)" class="kiosk-upsell-plus">+</span>
            <span v-else class="kiosk-upsell-minus">−</span>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="kiosk-upsell-actions">
        <button type="button"
          v-if="selectedIds.length > 0"
          class="kiosk-btn-primary"
          :disabled="_adding"
          @click="addAndContinue"
          data-testid="kiosk-upsell-add-continue"
        >
          <span>{{ $t('kiosk.upsell_screen.add_continue', { n: selectedIds.length }) }}</span>
          <span class="kiosk-btn-price" aria-hidden="true">+{{ formatPrice(addedTotal) }}</span>
        </button>
        <button type="button"
          class="kiosk-upsell-skip"
          @click="skip"
          data-testid="kiosk-upsell-skip"
          :aria-label="$t('kiosk.upsell_screen.skip')"
        >
          {{ $t('kiosk.upsell_screen.skip') }}
          <span class="kiosk-upsell-skip-timer" v-if="autoSkipRemaining < AUTO_SKIP_SECONDS">
            {{ $t('kiosk.upsell_screen.skip_timer', { n: autoSkipRemaining }) }}
          </span>
        </button>

        <!-- Auto-skip progress bar -->
        <div
          v-if="!loading"
          class="kiosk-upsell-autoskip-bar"
          role="progressbar"
          :aria-valuenow="Math.round(autoSkipPct)"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-label="$t('kiosk.upsell_screen.skip_timer', { n: autoSkipRemaining })"
          data-testid="kiosk-upsell-autoskip-bar"
        >
          <div class="kiosk-upsell-autoskip-fill" :style="{ width: autoSkipPct + '%' }" />
        </div>
      </div>
    </template>
  </div>
</template>

<script>
import { mapActions } from 'vuex';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { sanitizeKioskCustomerFacingText } from '../../../helpers/kioskDisplayText';
// [PHASE-6.4] Analytics upsell — shown (via store plugin sur markUpsellShown),
// accepted (addAndContinue), rejected (skip explicite ou auto).
import kioskAnalytics from '../../../helpers/kioskAnalytics';

const DESSERT_EMOJI = { dessert: '🍰', gâteau: '🎂', glace: '🍦', boisson: '🥤', café: '☕', jus: '🧃', eau: '💧', coca: '🥤', frite: '🍟' };

const AUTO_SKIP_SECONDS = 30;

export default {
  name: 'KioskUpsellComponent',
  mixins: [kioskPriceMixin],

  inject: {
    showToast: { default: () => () => {} },
  },

  data() {
    return {
      suggestions: [],
      selectedItems: [],
      loading: true,
      autoSkipRemaining: AUTO_SKIP_SECONDS,
      autoSkipPct: 100,
      _autoSkipTimer: null,
      _adding: false,
    };
  },
  computed: {
    selectedIds() { return this.selectedItems.map(i => i.id); },
    addedTotal() { return this.selectedItems.reduce((s, i) => s + parseFloat(i.convert_price || 0), 0); },
    AUTO_SKIP_SECONDS() { return AUTO_SKIP_SECONDS; },
  },
  mounted() {
    this.loadSuggestions();
  },
  beforeUnmount() {
    this.clearAutoSkip();
  },
  methods: {
    ...mapActions('kioskCart', ['addItem']),

    async loadSuggestions() {
      this.loading = true;
      try {
        const res = await this.$store.dispatch('kioskCart/fetchUpsellItems');
        const items = res?.data?.data || [];
        // Prendre max 6 suggestions
        this.suggestions = items.slice(0, 6);
        if (this.suggestions.length === 0) {
          this.skip('no_suggestions');
          return;
        }
        // [PHASE-6.4] Analytics : l'écran upsell est affiché avec N suggestions.
        try {
          kioskAnalytics.track('upsell_shown', {
            suggested_count: this.suggestions.length,
          });
        } catch (_) {}
        // Start auto-skip countdown once suggestions are loaded
        this.startAutoSkip();
      } catch (_) {
        this.skip('load_error');
        return;
      } finally {
        this.loading = false;
      }
    },

    startAutoSkip() {
      this.autoSkipRemaining = AUTO_SKIP_SECONDS;
      this.autoSkipPct = 100;
      const step = 100 / (AUTO_SKIP_SECONDS * 10);
      this._autoSkipTimer = setInterval(() => {
        this.autoSkipPct = Math.max(0, this.autoSkipPct - step);
        this.autoSkipRemaining = Math.ceil((this.autoSkipPct / 100) * AUTO_SKIP_SECONDS);
        if (this.autoSkipPct <= 0) {
          this.clearAutoSkip();
          this.skip('auto_timer');
        }
      }, 100);
    },

    clearAutoSkip() {
      if (this._autoSkipTimer) {
        clearInterval(this._autoSkipTimer);
        this._autoSkipTimer = null;
      }
    },

    toggleItem(item) {
      // Reset countdown on any interaction
      this.clearAutoSkip();
      this.startAutoSkip();
      const idx = this.selectedItems.findIndex(i => i.id === item.id);
      if (idx >= 0) {
        this.selectedItems.splice(idx, 1);
      } else {
        this.selectedItems.push(item);
      }
    },

    addAndContinue() {
      if (this._adding || this.selectedItems.length === 0) return;
      this._adding = true;
      this.selectedItems.forEach(item => {
        this.addItem({
          item_id: item.id,
          name: item.name,
          image: item.thumb || item.image,
          quantity: 1,
          convert_price: parseFloat(item.convert_price) || 0,
          currency_price: item.currency_price,
          discount: 0,
          item_variation_total: 0,
          item_extra_total: 0,
          item_variations: { variations: {}, names: {} },
          item_extras: { extras: [], names: [] },
          instruction: null,
        });
      });
      const count = this.selectedItems.length;
      const firstName = this.sanitizeItemName(this.selectedItems[0]?.name || '');
      this.showToast(
        count === 1
          ? this.$t('kiosk.upsell_screen.toast_added_one', { name: firstName })
          : this.$t('kiosk.upsell_screen.toast_added_many', { n: count }),
        'success'
      );
      // [PHASE-6.4] Analytics : upsell accepté (nb items ajoutés, pas de nom en clair).
      try {
        kioskAnalytics.track('upsell_accepted', {
          items_count: count,
          suggested_count: Array.isArray(this.suggestions) ? this.suggestions.length : 0,
        });
      } catch (_) {}
      this.$router.push({ name: 'kiosk.payment' }).catch(() => {
        this._adding = false;
      });
    },

    skip(reason = 'user') {
      // [PHASE-6.4] Analytics : upsell refusé ou auto-skippé (timer).
      try {
        kioskAnalytics.track('upsell_rejected', {
          reason: typeof reason === 'string' ? reason : 'user',
          suggested_count: Array.isArray(this.suggestions) ? this.suggestions.length : 0,
        });
      } catch (_) {}
      this.$router.push({ name: 'kiosk.payment' });
    },

    getEmoji(name) {
      const n = (name || '').toLowerCase();
      for (const [key, emoji] of Object.entries(DESSERT_EMOJI)) {
        if (n.includes(key)) return emoji;
      }
      return '🍽️';
    },

    sanitizeItemName(name) {
      return sanitizeKioskCustomerFacingText(name || '');
    },

    // formatPrice() provided by kioskPriceMixin
  },
};
</script>

<style scoped>
.kiosk-upsell {
  width: 100vw;
  height: 100vh;
  background: var(--kiosk-surface);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.kiosk-upsell-loading {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-spinner {
  width: 48px;
  height: 48px;
  border: 3px solid var(--kiosk-border);
  border-top-color: var(--kiosk-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.kiosk-upsell-header {
  padding: 26px 28px 18px;
  text-align: center;
  flex-shrink: 0;
  border-bottom: 1px solid var(--kiosk-border);
}

.kiosk-upsell-title {
  font-size: 30px;
  font-weight: 800;
  color: var(--kiosk-text);
  margin: 0 0 6px;
  letter-spacing: -0.03em;
}

.kiosk-upsell-subtitle {
  font-size: 16px;
  color: var(--kiosk-text-muted);
  margin: 0;
}

.kiosk-upsell-grid {
  flex: 1;
  overflow-y: auto;
  padding: 18px 24px 8px;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 18px;
  align-content: start;
  scrollbar-width: none;
}

.kiosk-upsell-grid::-webkit-scrollbar { display: none; }

.kiosk-upsell-card {
  background: var(--kiosk-surface);
  border-radius: 18px;
  border: 1.5px solid var(--kiosk-border);
  overflow: hidden;
  cursor: pointer;
  position: relative;
  transition: all 0.2s ease;
  box-shadow: var(--kiosk-shadow-card);
}

.kiosk-upsell-card.selected {
  border-color: var(--kiosk-primary);
  box-shadow: 0 0 0 3px var(--kiosk-primary-soft);
}

.kiosk-upsell-card:active { transform: scale(0.98); }
.kiosk-upsell-card:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, var(--kiosk-primary));
  outline-offset: 3px;
}

.kiosk-upsell-img-wrap {
  height: 150px;
  overflow: hidden;
  background: var(--kiosk-surface-alt);
}

.kiosk-upsell-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.3s ease;
}

.kiosk-upsell-card:active .kiosk-upsell-img { transform: scale(1.03); }

.kiosk-upsell-img-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 56px;
  background: var(--kiosk-surface-alt);
}

.kiosk-upsell-info {
  padding: 12px 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.kiosk-upsell-item-name {
  font-size: 15px;
  font-weight: 700;
  color: var(--kiosk-text);
  margin: 0;
  line-height: 1.25;
  min-height: 38px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.kiosk-upsell-item-price {
  font-size: 16px;
  font-weight: 800;
  color: var(--kiosk-primary);
}

.kiosk-upsell-check {
  position: absolute;
  top: 10px;
  right: 10px;
  width: 32px;
  height: 32px;
  background: var(--kiosk-primary);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: var(--kiosk-shadow-card);
  outline: 2px solid var(--kiosk-surface);
}

.kiosk-upsell-add {
  position: absolute;
  bottom: 10px;
  right: 10px;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: var(--kiosk-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  font-weight: 300;
  color: var(--kiosk-text-on-red);
  outline: 2px solid var(--kiosk-surface);
}

.kiosk-upsell-card.selected .kiosk-upsell-add { display: none; }

.kiosk-upsell-actions {
  padding: 18px 24px 24px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  flex-shrink: 0;
  background: var(--kiosk-surface);
  border-top: 1px solid var(--kiosk-border);
}

.kiosk-btn-primary {
  width: 100%;
  height: 64px;
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  border: none;
  border-radius: 14px;
  font-size: 18px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
  box-shadow: var(--kiosk-shadow-cta);
  transition: all 0.15s ease;
}

.kiosk-btn-primary:active { transform: scale(0.98); }

.kiosk-btn-price {
  font-size: 17px;
  font-weight: 800;
  background: rgba(255,255,255,0.18);
  padding: 6px 14px;
  border-radius: 10px;
}

.kiosk-upsell-skip {
  width: 100%;
  height: 52px;
  background: var(--kiosk-surface);
  color: var(--kiosk-text-muted);
  border: 1.5px solid var(--kiosk-border);
  border-radius: 12px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}

.kiosk-upsell-skip:active { background: var(--kiosk-surface-alt); color: var(--kiosk-text); }

.kiosk-upsell-skip-timer {
  font-size: 0.8em;
  color: var(--kiosk-text-muted);
  margin-left: 0.4rem;
}

.kiosk-upsell-autoskip-bar {
  width: 100%;
  height: 3px;
  background: var(--kiosk-border);
  border-radius: 2px;
  overflow: hidden;
  margin-top: 0.5rem;
}

.kiosk-upsell-autoskip-fill {
  height: 100%;
  background: var(--kiosk-primary);
  border-radius: 2px;
  transition: width 0.1s linear;
}

.pop-enter-active { animation: popIn 0.25s cubic-bezier(0.34,1.56,0.64,1); }
.pop-leave-active { animation: popIn 0.2s ease reverse; }

@keyframes popIn {
  from { transform: scale(0); opacity: 0; }
  to   { transform: scale(1); opacity: 1; }
}
</style>
