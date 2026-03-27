<template>
  <div class="kiosk-upsell">
    <!-- Skip si chargement trop long -->
    <div v-if="loading" class="kiosk-upsell-loading">
      <div class="kiosk-spinner" />
    </div>

    <template v-else>
      <!-- Header -->
      <div class="kiosk-upsell-header">
        <h1 class="kiosk-upsell-title">Et pour terminer ?</h1>
        <p class="kiosk-upsell-subtitle">Ajoutez quelque chose à votre commande</p>
      </div>

      <!-- Grille suggestions -->
      <div v-if="suggestions.length > 0" class="kiosk-upsell-grid">
        <div
          v-for="item in suggestions"
          :key="item.id"
          class="kiosk-upsell-card"
          :class="{ selected: selectedIds.includes(item.id) }"
          @click="toggleItem(item)"
        >
          <!-- Image -->
          <div class="kiosk-upsell-img-wrap">
            <img v-if="item.thumb || item.image" :src="item.thumb || item.image" :alt="item.name" class="kiosk-upsell-img" />
            <div v-else class="kiosk-upsell-img-fallback">{{ getEmoji(item.name) }}</div>
          </div>

          <!-- Infos -->
          <div class="kiosk-upsell-info">
            <h3 class="kiosk-upsell-item-name">{{ item.name }}</h3>
            <span class="kiosk-upsell-item-price">{{ formatPrice(item.convert_price) }}</span>
          </div>

          <!-- Checkmark -->
          <transition name="pop">
            <div v-if="selectedIds.includes(item.id)" class="kiosk-upsell-check">
              <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                <path d="M4 10l5 5 7-8" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
          </transition>

          <!-- Bouton +/- -->
          <div class="kiosk-upsell-add">
            <span v-if="!selectedIds.includes(item.id)" class="kiosk-upsell-plus">+</span>
            <span v-else class="kiosk-upsell-minus">−</span>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="kiosk-upsell-actions">
        <button
          v-if="selectedIds.length > 0"
          class="kiosk-btn-primary"
          @click="addAndContinue"
        >
          <span>Ajouter ({{ selectedIds.length }}) et continuer</span>
          <span class="kiosk-btn-price">+{{ formatPrice(addedTotal) }}</span>
        </button>
        <button class="kiosk-upsell-skip" @click="skip">
          Non merci, continuer sans
          <span class="kiosk-upsell-skip-timer" v-if="autoSkipRemaining < AUTO_SKIP_SECONDS">
            ({{ autoSkipRemaining }}s)
          </span>
        </button>

        <!-- Auto-skip progress bar -->
        <div v-if="!loading" class="kiosk-upsell-autoskip-bar">
          <div class="kiosk-upsell-autoskip-fill" :style="{ width: autoSkipPct + '%' }" />
        </div>
      </div>
    </template>
  </div>
</template>

<script>
import { mapActions } from 'vuex';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';

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
          this.skip();
          return;
        }
        // Start auto-skip countdown once suggestions are loaded
        this.startAutoSkip();
      } catch (_) {
        this.skip();
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
          this.skip();
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
      this.showToast(
        count === 1 ? `${this.selectedItems[0].name} ajouté !` : `${count} articles ajoutés !`,
        'success'
      );
      this.$router.push({ name: 'kiosk.payment' });
    },

    skip() {
      this.$router.push({ name: 'kiosk.payment' });
    },

    getEmoji(name) {
      const n = (name || '').toLowerCase();
      for (const [key, emoji] of Object.entries(DESSERT_EMOJI)) {
        if (n.includes(key)) return emoji;
      }
      return '🍽️';
    },

    // formatPrice() provided by kioskPriceMixin
  },
};
</script>

<style scoped>
.kiosk-upsell {
  width: 100vw;
  height: 100vh;
  background: #fff;
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
  border: 3px solid #e8e8e8;
  border-top-color: var(--kiosk-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.kiosk-upsell-header {
  padding: 26px 28px 18px;
  text-align: center;
  flex-shrink: 0;
  border-bottom: 1px solid #ececec;
}

.kiosk-upsell-title {
  font-size: 30px;
  font-weight: 800;
  color: #1f1f1f;
  margin: 0 0 6px;
  letter-spacing: -0.03em;
}

.kiosk-upsell-subtitle {
  font-size: 16px;
  color: #777;
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
  background: #fff;
  border-radius: 18px;
  border: 1.5px solid #ececec;
  overflow: hidden;
  cursor: pointer;
  position: relative;
  transition: all 0.2s ease;
  box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.kiosk-upsell-card.selected {
  border-color: var(--kiosk-primary);
  box-shadow: 0 0 0 2px rgba(232,0,28,0.10);
}

.kiosk-upsell-card:active { transform: scale(0.98); }

.kiosk-upsell-img-wrap {
  height: 150px;
  overflow: hidden;
  background: #f7f7f8;
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
  background: #f7f7f8;
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
  color: #1f1f1f;
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
  color: #d7263d;
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
  box-shadow: 0 2px 8px rgba(232,0,28,0.3);
  outline: 2px solid rgba(255,255,255,0.92);
}

.kiosk-upsell-add {
  position: absolute;
  bottom: 10px;
  right: 10px;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: #d7263d;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  font-weight: 300;
  color: #fff;
  outline: 2px solid rgba(255,255,255,0.92);
}

.kiosk-upsell-card.selected .kiosk-upsell-add { display: none; }

.kiosk-upsell-actions {
  padding: 18px 24px 24px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  flex-shrink: 0;
  background: #fff;
  border-top: 1px solid #ececec;
}

.kiosk-btn-primary {
  width: 100%;
  height: 64px;
  background: var(--kiosk-primary);
  color: white;
  border: none;
  border-radius: 14px;
  font-size: 18px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
  box-shadow: 0 6px 20px rgba(232,0,28,0.2);
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
  background: #fff;
  color: #666;
  border: 1.5px solid #e4e4e4;
  border-radius: 12px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}

.kiosk-upsell-skip:active { background: #f7f7f8; color: #444; }

.kiosk-upsell-skip-timer {
  font-size: 0.8em;
  color: #999;
  margin-left: 0.4rem;
}

.kiosk-upsell-autoskip-bar {
  width: 100%;
  height: 3px;
  background: #ececec;
  border-radius: 2px;
  overflow: hidden;
  margin-top: 0.5rem;
}

.kiosk-upsell-autoskip-fill {
  height: 100%;
  background: rgba(232,0,28,0.4);
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
