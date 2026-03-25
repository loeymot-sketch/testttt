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
      <p>Chargement des produits...</p>
    </div>

    <!-- Liste produits -->
    <div v-else class="kiosk-prod-list">
      <div
        v-for="(product, idx) in products"
        :key="product.id"
        class="kiosk-prod-card"
        :class="{ 'is-loading': loadingItemId === product.id }"
        :style="{ animationDelay: (idx * 50) + 'ms' }"
        @click="selectProduct(product)"
      >
        <!-- Image -->
        <div class="kiosk-prod-img-wrap">
          <img
            v-if="product.thumb || product.image"
            :src="product.thumb || product.image"
            :alt="product.name"
            class="kiosk-prod-img"
            loading="lazy"
          />
          <div v-else class="kiosk-prod-img-placeholder">
            <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
              <path d="M24 8C15.2 8 8 15.2 8 24s7.2 16 16 16 16-7.2 16-16S32.8 8 24 8z" fill="rgba(255,255,255,0.1)"/>
              <text x="24" y="30" text-anchor="middle" font-size="20">🍽️</text>
            </svg>
          </div>
          <!-- Badge populaire -->
          <div v-if="product.is_featured" class="kiosk-prod-badge">⭐ Populaire</div>
        </div>

        <!-- Contenu -->
        <div class="kiosk-prod-content">
          <div class="kiosk-prod-main">
            <h3 class="kiosk-prod-name">{{ product.name }}</h3>
            <p v-if="product.description" class="kiosk-prod-desc">{{ truncate(product.description, 80) }}</p>
            <!-- Tags suppléments si disponibles -->
            <div class="kiosk-prod-tags" v-if="hasOptions(product)">
              <span class="kiosk-prod-tag">Personnalisable</span>
            </div>
          </div>

          <!-- Prix + bouton -->
          <div class="kiosk-prod-footer">
            <div class="kiosk-prod-price-wrap">
              <span v-if="product.old_price && product.old_price > product.convert_price" class="kiosk-prod-old-price">
                {{ formatPrice(product.old_price) }}
              </span>
              <span class="kiosk-prod-price">{{ formatPrice(product.convert_price) }}</span>
            </div>
            <button class="kiosk-prod-add-btn" @click.stop="selectProduct(product)" :disabled="!!loadingItemId">
              <svg v-if="loadingItemId !== product.id" width="28" height="28" viewBox="0 0 28 28" fill="none">
                <path d="M14 6v16M6 14h16" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
              </svg>
              <div v-else class="kiosk-prod-btn-spinner" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Charger plus -->
    <div v-if="hasMore && !loading" class="kiosk-load-more-wrap">
      <button class="kiosk-load-more-btn" :disabled="loadingMore" @click="loadMore">
        <span v-if="!loadingMore">Voir plus de produits</span>
        <span v-else class="kiosk-load-more-spinner"></span>
      </button>
    </div>

    <!-- Vide / Erreur chargement -->
    <div v-if="!loading && products.length === 0" class="kiosk-prod-empty">
      <div v-if="loadError" class="kiosk-prod-error">
        <span class="kiosk-prod-error-icon">📡</span>
        <p>Impossible de charger les produits.</p>
        <button class="kiosk-prod-retry-btn" @click="loadProducts()">Réessayer</button>
      </div>
      <p v-else>Aucun produit disponible dans cette catégorie.</p>
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
import { mapActions } from 'vuex';

export default {
  name: 'KioskProductListComponent',
  components: { KioskWizardComponent },
  props: { categoryId: { type: [String, Number], required: true } },
  data() {
    return {
      products: [],
      loading: true,
      loadingMore: false,
      loadError: false,
      activeItem: null,
      loadingItemId: null,
      categoryName: this.$route.query.name || 'Produits',
      page: 1,
      hasMore: false,
    };
  },
  mounted() {
    this.loadProducts();
  },
  methods: {
    ...mapActions('kioskCart', ['addItem']),

    async loadProducts(append = false) {
      if (!append) {
        this.loading = true;
        this.loadError = false;
        this.page = 1;
        this.products = [];
      } else {
        this.loadingMore = true;
      }
      try {
        const res = await this.$store.dispatch('frontendItem/lists', {
          item_category_id: this.categoryId,
          paginate: 20,
          page: this.page,
          vuex: false,
        });
        const newItems = res?.data?.data || [];
        this.products = append ? [...this.products, ...newItems] : newItems;
        const meta = res?.data?.meta || res?.data;
        this.hasMore = meta?.last_page
          ? this.page < meta.last_page
          : newItems.length === 20;
      } catch (_) {
        if (!append) {
          this.products = [];
          this.loadError = true;
        }
        this.hasMore = false;
      } finally {
        this.loading = false;
        this.loadingMore = false;
      }
    },

    async loadMore() {
      if (this.loadingMore || !this.hasMore) return;
      this.page++;
      await this.loadProducts(true);
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
    },

    closeWizard() {
      this.activeItem = null;
    },

    formatPrice(price) {
      return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(price || 0);
    },

    truncate(text, max) {
      if (!text) return '';
      return text.length > max ? text.slice(0, max) + '...' : text;
    },
  },
};
</script>

<style scoped>
.kiosk-products {
  width: 100vw;
  height: 100vh;
  background: var(--kiosk-dark);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  position: relative;
}

/* Header */
.kiosk-prod-header {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 24px 32px 20px;
  background: var(--kiosk-dark-2);
  border-bottom: 1px solid rgba(255,255,255,0.07);
  flex-shrink: 0;
}

.kiosk-prod-back {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  border: 1.5px solid rgba(255,255,255,0.15);
  background: rgba(255,255,255,0.06);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
  transition: all 0.15s ease;
}

.kiosk-prod-back:active { background: rgba(255,255,255,0.12); transform: scale(0.95); }

.kiosk-prod-header-info { flex: 1; }

.kiosk-prod-title {
  font-size: 26px;
  font-weight: 800;
  color: white;
  margin: 0 0 2px;
  letter-spacing: -0.3px;
}

.kiosk-prod-count {
  font-size: 14px;
  color: rgba(255,255,255,0.45);
  margin: 0;
}

/* Loading */
.kiosk-prod-loading {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 20px;
  color: rgba(255,255,255,0.6);
  font-size: 18px;
}

.kiosk-spinner {
  width: 48px;
  height: 48px;
  border: 3px solid rgba(255,255,255,0.1);
  border-top-color: var(--kiosk-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* Liste produits */
.kiosk-prod-list {
  flex: 1;
  overflow-y: auto;
  padding: 20px 32px 120px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  scrollbar-width: none;
}

.kiosk-prod-list::-webkit-scrollbar { display: none; }

/* Card produit — style Splash horizontal */
.kiosk-prod-card {
  background: var(--kiosk-dark-2);
  border-radius: 20px;
  border: 1.5px solid rgba(255,255,255,0.07);
  display: flex;
  gap: 0;
  overflow: hidden;
  cursor: pointer;
  animation: cardIn 0.35s ease both;
  transition: transform 0.15s ease, border-color 0.15s ease;
  min-height: 140px;
}

@keyframes cardIn {
  from { opacity: 0; transform: translateX(20px); }
  to   { opacity: 1; transform: translateX(0); }
}

.kiosk-prod-card:active {
  transform: scale(0.985);
  border-color: rgba(232,0,28,0.4);
}

/* Image */
.kiosk-prod-img-wrap {
  position: relative;
  width: 150px;
  flex-shrink: 0;
}

.kiosk-prod-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.3s ease;
}

.kiosk-prod-card:active .kiosk-prod-img { transform: scale(1.03); }

.kiosk-prod-img-placeholder {
  width: 100%;
  height: 100%;
  background: rgba(255,255,255,0.04);
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-prod-badge {
  position: absolute;
  top: 10px;
  left: 10px;
  background: var(--kiosk-accent);
  color: white;
  font-size: 11px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 20px;
}

/* Contenu */
.kiosk-prod-content {
  flex: 1;
  padding: 20px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 12px;
}

.kiosk-prod-main { flex: 1; }

.kiosk-prod-name {
  font-size: 19px;
  font-weight: 700;
  color: white;
  margin: 0 0 6px;
  letter-spacing: -0.2px;
}

.kiosk-prod-desc {
  font-size: 13px;
  color: rgba(255,255,255,0.45);
  margin: 0 0 8px;
  line-height: 1.5;
}

.kiosk-prod-tags { display: flex; gap: 6px; flex-wrap: wrap; }

.kiosk-prod-tag {
  background: rgba(232,0,28,0.15);
  color: #FF6B6B;
  border: 1px solid rgba(232,0,28,0.25);
  font-size: 11px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 20px;
}

.kiosk-prod-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.kiosk-prod-price-wrap {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.kiosk-prod-old-price {
  font-size: 14px;
  color: rgba(255,255,255,0.3);
  text-decoration: line-through;
}

.kiosk-prod-price {
  font-size: 24px;
  font-weight: 800;
  color: white;
}

.kiosk-prod-add-btn {
  width: 56px;
  height: 56px;
  border-radius: 16px;
  background: var(--kiosk-primary);
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 4px 16px rgba(232,0,28,0.4);
  transition: all 0.15s ease;
}

.kiosk-prod-add-btn:active {
  transform: scale(0.92);
  box-shadow: 0 2px 8px rgba(232,0,28,0.3);
}

.kiosk-prod-add-btn:disabled { opacity: 0.6; cursor: default; }

.kiosk-prod-btn-spinner {
  width: 20px;
  height: 20px;
  border: 2px solid rgba(255,255,255,0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

.kiosk-prod-card.is-loading { opacity: 0.75; pointer-events: none; }

/* Vide */
.kiosk-prod-empty {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255,255,255,0.4);
  font-size: 18px;
}

.kiosk-load-more-wrap {
  display: flex;
  justify-content: center;
  padding: 1.5rem 0 0.5rem;
}

.kiosk-load-more-btn {
  background: rgba(255,255,255,0.08);
  border: 1.5px solid rgba(255,255,255,0.15);
  border-radius: 50px;
  color: rgba(255,255,255,0.75);
  font-size: 1rem;
  font-weight: 600;
  letter-spacing: 0.03em;
  padding: 0.85rem 2.5rem;
  cursor: pointer;
  transition: background 0.2s, border-color 0.2s;
  min-width: 200px;
  text-align: center;
}
.kiosk-load-more-btn:hover:not(:disabled) {
  background: rgba(255,255,255,0.14);
  border-color: rgba(255,255,255,0.3);
}
.kiosk-load-more-btn:disabled { opacity: 0.5; cursor: default; }

.kiosk-load-more-spinner {
  display: inline-block;
  width: 20px; height: 20px;
  border: 2.5px solid rgba(255,255,255,0.2);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  vertical-align: middle;
}

/* Wizard overlay */
.kiosk-wizard-overlay {
  position: absolute;
  inset: 0;
  z-index: 50;
  background: var(--kiosk-dark);
}

.slide-up-enter-active, .slide-up-leave-active { transition: transform 0.35s cubic-bezier(0.4,0,0.2,1); }
.slide-up-enter-from { transform: translateY(100%); }
.slide-up-leave-to   { transform: translateY(100%); }

/* Retry error */
.kiosk-prod-error {
  display: flex; flex-direction: column; align-items: center; gap: 1rem;
}
.kiosk-prod-error-icon { font-size: 3rem; }
.kiosk-prod-error p { margin: 0; color: rgba(255,255,255,0.55); }
.kiosk-prod-retry-btn {
  background: #e8001c; color: #fff; border: none;
  border-radius: 50px; padding: 0.7rem 2rem;
  font-size: 1rem; font-weight: 700; cursor: pointer;
  transition: background 0.2s;
}
.kiosk-prod-retry-btn:hover { background: #c0001a; }
</style>
