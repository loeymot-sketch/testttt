<template>
  <div class="kiosk-products">
    <!-- Header avec nom catégorie -->
    <div class="kiosk-prod-header">
      <button class="kiosk-prod-back" @click="$router.push({ name: 'kiosk.categories' })">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
          <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <div class="kiosk-prod-header-info">
        <h1 class="kiosk-prod-title">{{ categoryName }}</h1>
        <p class="kiosk-prod-count" v-if="!loading">{{ products.length }} article{{ products.length > 1 ? 's' : '' }}</p>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="kiosk-prod-loading">
      <div class="kiosk-spinner" />
      <p>{{ $t('kiosk.loading') }}</p>
    </div>

    <!-- Grille produits 2 colonnes — style Splash -->
    <div v-else class="kiosk-prod-grid">
      <div
        v-for="(product, idx) in products"
        :key="product.id"
        class="kiosk-prod-card"
        :class="{ 'is-loading': loadingItemId === product.id }"
        :style="{ animationDelay: (idx * 40) + 'ms' }"
        @click="selectProduct(product)"
      >
        <!-- Zone image (haut de carte) -->
        <div class="kiosk-prod-img-wrap">
          <img
            v-if="product.thumb || product.image"
            :src="product.thumb || product.image"
            :alt="product.name"
            class="kiosk-prod-img"
            loading="lazy"
          />
          <div v-else class="kiosk-prod-img-placeholder">
            <span class="kiosk-prod-emoji">{{ getCategoryEmoji(product.name) }}</span>
          </div>
          <!-- Overlay gradient bas -->
          <div class="kiosk-prod-img-overlay" />
          <!-- Badge populaire -->
          <div v-if="product.is_featured == 5" class="kiosk-prod-badge">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
              <path d="M6 1l1.5 3 3.3.5-2.4 2.3.6 3.2L6 8.5l-3 1.5.6-3.2L1.2 4.5l3.3-.5L6 1z" fill="white"/>
            </svg>
            Populaire
          </div>
          <!-- Badge personnalisable -->
          <div v-if="hasOptions(product)" class="kiosk-prod-badge-custom">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
              <path d="M9.5 2.5L7 5l-1.5-.5L5 3l2.5-2.5L9.5 2.5zM3 7l1.5 1.5-2 2.5L1 11l.5-1.5L3 7z" stroke="white" stroke-width="1.2" stroke-linecap="round"/>
            </svg>
            À personnaliser
          </div>
          <!-- Spinner de chargement -->
          <div v-if="loadingItemId === product.id" class="kiosk-prod-loading-overlay">
            <div class="kiosk-prod-btn-spinner" />
          </div>
        </div>

        <!-- Infos (bas de carte) -->
        <div class="kiosk-prod-content">
          <h3 class="kiosk-prod-name">{{ product.name }}</h3>
          <p v-if="product.description" class="kiosk-prod-desc">{{ truncate(product.description, 55) }}</p>

          <!-- Prix + bouton -->
          <div class="kiosk-prod-footer">
            <div class="kiosk-prod-price-wrap">
              <span v-if="product.old_price && product.old_price > product.convert_price" class="kiosk-prod-old-price">
                {{ formatPrice(product.old_price) }}
              </span>
              <span class="kiosk-prod-price">{{ formatPrice(product.convert_price) }}</span>
            </div>
            <button class="kiosk-prod-add-btn" @click.stop="selectProduct(product)" :disabled="!!loadingItemId">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                <path d="M12 5v14M5 12h14" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Vide / Erreur chargement -->
    <div v-if="!loading && products.length === 0" class="kiosk-prod-empty">
      <div v-if="loadError" class="kiosk-prod-error">
        <span class="kiosk-prod-error-icon">📡</span>
        <p>{{ $t('kiosk.error_loading') }}</p>
        <button class="kiosk-prod-retry-btn" @click="loadProducts()">{{ $t('kiosk.retry') }}</button>
      </div>
      <p v-else>{{ $t('kiosk.no_items') }}</p>
    </div>

    <!-- Wizard overlay -->
    <transition name="slide-up">
      <div v-if="activeItem" class="kiosk-wizard-overlay">
        <KioskWizardComponent
          :item="activeItem"
          :on-add-to-cart="addToCartAndClose"
          :on-close="closeWizard"
        />
      </div>
    </transition>
  </div>
</template>

<script>
import KioskWizardComponent from './KioskWizardComponent.vue';
import { mapActions, mapGetters } from 'vuex';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';

export default {
  name: 'KioskProductListComponent',
  components: { KioskWizardComponent },
  mixins: [kioskPriceMixin],
  props: { categoryId: { type: [String, Number], required: true } },

  inject: {
    showToast: { default: () => () => {} },
  },

  data() {
    return {
      loadError:     false,
      activeItem:    null,
      loadingItemId: null,
      categoryName:  this.$route.query.name || '',
    };
  },

  computed: {
    ...mapGetters('kioskMenu', ['loading', 'isStale']),

    // Products filtered from cache — instantaneous, no network call
    products() {
      const id = parseInt(this.categoryId, 10) || this.categoryId;
      return this.$store.getters['kioskMenu/itemsByCategory'](id);
    },
  },

  mounted() {
    // If menu cache is stale or empty, fetch it (fills cache for all categories)
    if (this.isStale || this.$store.getters['kioskMenu/allItems'].length === 0) {
      this.loadProducts();
    }
  },

  methods: {
    ...mapActions('kioskCart', ['addItem']),
    ...mapActions('kioskMenu', ['fetchMenu']),

    async loadProducts() {
      this.loadError = false;
      try {
        const branchId = this.$store.state.kioskCart?.branchId;
        await this.fetchMenu({ branchId });
      } catch (_) {
        this.loadError = true;
      }
    },

    async selectProduct(product) {
      if (this.loadingItemId) return; // éviter double-tap
      this.loadingItemId = product.id;
      try {
        // SimpleItemResource ne contient pas extras/variations — toujours fetch details
        const res = await this.$store.dispatch('frontendItem/details', product.id);
        const detail = res?.data?.data || res?.data || product;
        if (this.hasOptions(detail)) {
          this.activeItem = detail;
        } else {
          this.addItem({
            item_id: detail.id,
            name: detail.name,
            image: detail.thumb || detail.cover,
            quantity: 1,
            convert_price: parseFloat(detail.convert_price) || 0,
            currency_price: detail.currency_price,
            discount: 0,
            item_variation_total: 0,
            item_extra_total: 0,
            item_variations: { variations: {}, names: {} },
            item_extras: { extras: [], names: [] },
            instruction: null,
          });
          this.showToast(`${detail.name} ${this.$t('kiosk.add')} ✓`, 'success', 1800);
        }
      } catch (_) {
        // Fallback : ajout simple sans wizard
        this.addItem({
          item_id: product.id,
          name: product.name,
          image: product.thumb,
          quantity: 1,
          convert_price: parseFloat(product.convert_price) || 0,
          currency_price: product.currency_price,
          discount: 0,
          item_variation_total: 0,
          item_extra_total: 0,
          item_variations: { variations: {}, names: {} },
          item_extras: { extras: [], names: [] },
          instruction: null,
        });
        this.showToast(`${product.name} ${this.$t('kiosk.add')} ✓`, 'success', 1800);
      } finally {
        this.loadingItemId = null;
      }
    },

    hasOptions(detail) {
      return (detail.itemAttributes?.length > 0) ||
             (detail.extras?.length > 0) ||
             (detail.addons?.length > 0) ||
             (detail.variations && Object.keys(detail.variations).length > 0) ||
             !!detail.has_menu;
    },

    addToCartAndClose(cartItem) {
      this.addItem(cartItem);
      this.closeWizard();
      this.showToast(`${cartItem.name} ${this.$t('kiosk.add')} ✓`, 'success', 1800);
    },

    closeWizard() {
      this.activeItem = null;
    },

    // formatPrice() provided by kioskPriceMixin

    truncate(text, max) {
      if (!text) return '';
      return text.length > max ? text.slice(0, max) + '...' : text;
    },

    getCategoryEmoji(name) {
      const n = (name || '').toLowerCase();
      if (n.includes('tacos')) return '🌮';
      if (n.includes('burger')) return '🍔';
      if (n.includes('sandwich')) return '🥪';
      if (n.includes('pizza')) return '🍕';
      if (n.includes('kebab')) return '🥙';
      if (n.includes('frite')) return '🍟';
      if (n.includes('boisson') || n.includes('coca') || n.includes('jus')) return '🥤';
      if (n.includes('dessert') || n.includes('gâteau')) return '🍰';
      if (n.includes('salade')) return '🥗';
      if (n.includes('poulet')) return '🍗';
      if (n.includes('viande') || n.includes('bœuf') || n.includes('agneau')) return '🥩';
      if (n.includes('poisson')) return '🐟';
      if (n.includes('wrap')) return '🫓';
      if (n.includes('assiette') || n.includes('plat')) return '🍽️';
      return '🍽️';
    },
  },
};

</script>

<style scoped>
.kiosk-products {
  width: 100vw;
  height: 100vh;
  background: #fff;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  position: relative;
}

.kiosk-prod-header {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 20px 28px 16px;
  background: white;
  border-bottom: 1px solid #E0E0E0;
  flex-shrink: 0;
}

.kiosk-prod-back {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  border: 1.5px solid #E0E0E0;
  background: white;
  color: #1A1A1A;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
  transition: all 0.15s ease;
}

.kiosk-prod-back:active { background: #F7F7F8; transform: scale(0.95); }

.kiosk-prod-header-info { flex: 1; }

.kiosk-prod-title {
  font-size: 22px;
  font-weight: 800;
  color: #1A1A1A;
  margin: 0 0 2px;
}

.kiosk-prod-count {
  font-size: 13px;
  color: #999;
  margin: 0;
}

.kiosk-prod-loading {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 20px;
  color: #999;
  font-size: 16px;
}

.kiosk-spinner {
  width: 40px;
  height: 40px;
  border: 3px solid #E0E0E0;
  border-top-color: #E8001C;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.kiosk-prod-grid {
  flex: 1;
  overflow-y: auto;
  padding: 16px 20px 120px;
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 14px;
  align-content: start;
  scrollbar-width: none;
}

.kiosk-prod-grid::-webkit-scrollbar { display: none; }

.kiosk-prod-card {
  background: white;
  border-radius: 16px;
  border: 1.5px solid #E0E0E0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  cursor: pointer;
  animation: cardIn 0.35s ease both;
  transition: transform 0.15s ease, border-color 0.15s ease;
  position: relative;
  box-shadow: 0 1px 6px rgba(0,0,0,0.04);
}

@keyframes cardIn {
  from { opacity: 0; transform: translateY(14px) scale(0.97); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

.kiosk-prod-card:active {
  transform: scale(0.97);
  border-color: #E8001C;
}

.kiosk-prod-img-wrap {
  position: relative;
  width: 100%;
  height: 180px;
  overflow: hidden;
  flex-shrink: 0;
  background: #F7F7F8;
}

.kiosk-prod-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.3s ease;
  display: block;
}

.kiosk-prod-card:active .kiosk-prod-img { transform: scale(1.04); }

.kiosk-prod-img-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(to bottom, transparent 60%, rgba(0,0,0,0.08) 100%);
  pointer-events: none;
}

.kiosk-prod-img-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #F7F7F8;
}

.kiosk-prod-emoji { font-size: 56px; line-height: 1; }

.kiosk-prod-badge {
  position: absolute;
  top: 8px;
  left: 8px;
  background: #E8001C;
  color: white;
  font-size: 10px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 20px;
  display: flex;
  align-items: center;
  gap: 4px;
}

.kiosk-prod-badge-custom {
  position: absolute;
  top: 8px;
  right: 8px;
  background: rgba(0,0,0,0.6);
  color: white;
  font-size: 10px;
  font-weight: 600;
  padding: 4px 8px;
  border-radius: 20px;
  display: flex;
  align-items: center;
  gap: 4px;
}

.kiosk-prod-loading-overlay {
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,0.7);
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-prod-content {
  flex: 1;
  padding: 12px 14px 14px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.kiosk-prod-name {
  font-size: 15px;
  font-weight: 700;
  color: #1A1A1A;
  margin: 0;
  line-height: 1.3;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.kiosk-prod-desc {
  font-size: 12px;
  color: #999;
  margin: 0;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.kiosk-prod-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: auto;
}

.kiosk-prod-price-wrap {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.kiosk-prod-old-price {
  font-size: 11px;
  color: #ccc;
  text-decoration: line-through;
  line-height: 1;
}

.kiosk-prod-price {
  font-size: 18px;
  font-weight: 900;
  color: #1A1A1A;
}

.kiosk-prod-add-btn {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: #E8001C;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 8px rgba(232,0,28,0.3);
  transition: all 0.15s ease;
}

.kiosk-prod-add-btn:active {
  transform: scale(0.88);
}

.kiosk-prod-add-btn:disabled { opacity: 0.5; cursor: default; }

.kiosk-prod-btn-spinner {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(255,255,255,0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

.kiosk-prod-card.is-loading { opacity: 0.7; pointer-events: none; }

.kiosk-prod-empty {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #999;
  font-size: 16px;
}

.kiosk-load-more-wrap {
  display: flex;
  justify-content: center;
  padding: 1.5rem 0 0.5rem;
}

.kiosk-load-more-btn {
  background: #F7F7F8;
  border: 1.5px solid #E0E0E0;
  border-radius: 50px;
  color: #555;
  font-size: 1rem;
  font-weight: 600;
  padding: 0.85rem 2.5rem;
  cursor: pointer;
  min-width: 200px;
  text-align: center;
}
.kiosk-load-more-btn:hover:not(:disabled) {
  background: #EFEFEF;
}
.kiosk-load-more-btn:disabled { opacity: 0.5; cursor: default; }

.kiosk-load-more-spinner {
  display: inline-block;
  width: 20px; height: 20px;
  border: 2.5px solid #E0E0E0;
  border-top-color: #E8001C;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  vertical-align: middle;
}

.kiosk-wizard-overlay {
  position: absolute;
  inset: 0;
  z-index: 50;
  background: #fff;
}

.slide-up-enter-active, .slide-up-leave-active { transition: transform 0.35s cubic-bezier(0.4,0,0.2,1); }
.slide-up-enter-from { transform: translateY(100%); }
.slide-up-leave-to   { transform: translateY(100%); }

.kiosk-prod-error {
  display: flex; flex-direction: column; align-items: center; gap: 1rem;
}
.kiosk-prod-error-icon { font-size: 3rem; }
.kiosk-prod-error p { margin: 0; color: #999; }
.kiosk-prod-retry-btn {
  background: #E8001C; color: #fff; border: none;
  border-radius: 50px; padding: 0.7rem 2rem;
  font-size: 1rem; font-weight: 700; cursor: pointer;
}
</style>
