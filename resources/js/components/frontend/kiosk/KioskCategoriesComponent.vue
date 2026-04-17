<template>
  <div class="kiosk-catalogue">
    <div class="kiosk-catalogue-header">
      <div class="kiosk-catalogue-brand">
        <div class="kiosk-brand-thumb-wrap">
          <img
            v-if="selectedCategory && (selectedCategory.image_full_path || selectedCategory.image)"
            :src="selectedCategory.image_full_path || selectedCategory.image"
            :alt="selectedCategoryDisplayName"
            class="kiosk-brand-thumb"
          />
          <div v-else class="kiosk-brand-thumb-fallback">{{ getCategoryEmoji(selectedCategoryDisplayName) }}</div>
        </div>
        <div class="kiosk-catalogue-breadcrumb">
          <span class="kiosk-breadcrumb-muted">{{ $t('kiosk.catalog.nos') }}</span>
          <span class="kiosk-breadcrumb-current">{{ selectedCategoryDisplayName || $t('kiosk.catalog.products_fallback') }}</span>
        </div>
      </div>

      <div class="kiosk-catalogue-top-actions">
        <button class="kiosk-top-chip" type="button" disabled>
          <span class="kiosk-top-chip-icon">👤</span>
          {{ $t('kiosk.catalog.my_account') }}
        </button>
        <button class="kiosk-top-chip" type="button" disabled>
          <span class="kiosk-top-chip-icon">i</span>
          {{ $t('kiosk.catalog.allergens') }}
        </button>
      </div>
    </div>

    <!-- [C2] Offline snapshot banner — shown when menu is served from IndexedDB cache -->
    <transition name="slide-down">
      <div v-if="fromCache && !loading" class="kiosk-cache-banner">
        <span class="kiosk-cache-banner-icon">📡</span>
        <span>{{ $t('kiosk.catalog.cache_banner') }}</span>
      </div>
    </transition>

    <div v-if="loading" class="kiosk-catalogue-loading">
      <div class="kiosk-spinner" />
      <p>{{ $t('kiosk.catalog.loading_menu') }}</p>
    </div>

    <div v-else-if="!loading && categories.length === 0" class="kiosk-catalogue-empty">
      <div v-if="loadError" class="kiosk-catalogue-error">
        <span class="kiosk-catalogue-error-icon">📡</span>
        <p>{{ $t('kiosk.catalog.load_error_title') }}</p>
        <button class="kiosk-catalogue-retry-btn" @click="loadCatalogue">{{ $t('kiosk.retry') }}</button>
      </div>
      <p v-else>{{ $t('kiosk.catalog.no_categories') }}</p>
    </div>

    <template v-else>
      <div class="kiosk-catalogue-body">
        <aside class="kiosk-sidebar">
          <button
            v-for="cat in sidebarCategories"
            :key="cat.kioskRowKey"
            class="kiosk-sidebar-item"
            :class="{ active: isCategoryActive(cat) }"
            @click="selectCategory(cat)"
          >
            <span class="kiosk-sidebar-name">{{ displayCategoryName(cat) }}</span>
            <div class="kiosk-sidebar-thumb-wrap">
              <img
                v-if="cat.image_full_path || cat.image"
                :src="cat.image_full_path || cat.image"
                :alt="displayCategoryName(cat)"
                class="kiosk-sidebar-thumb"
                loading="lazy"
              />
              <div v-else class="kiosk-sidebar-thumb-fallback">
                {{ getCategoryEmoji(cat.name) }}
              </div>
            </div>
          </button>
        </aside>

        <main ref="productZone" class="kiosk-product-zone">
          <!-- Transition locale (le shell ne refait plus slide-left à chaque ?cat= — voir KioskAppComponent) -->
          <transition name="kiosk-cat-pane" mode="out-in">
            <div
              :key="`${selectedCategoryId}-${kioskSandwichSubcolumn || 'sig'}`"
              class="kiosk-product-zone-transition"
            >
              <div class="kiosk-product-zone-header">
                <h1 class="kiosk-zone-title">{{ selectedCategoryDisplayName }}</h1>
                <p class="kiosk-zone-subtitle">
                  {{ filteredProducts.length }}
                  {{ filteredProducts.length > 1 ? $t('kiosk.catalog.product_many') : $t('kiosk.catalog.product_one') }}
                </p>
              </div>

              <div class="kiosk-product-grid">
                <div
                  v-for="product in filteredProducts"
                  :key="product.id"
                  class="kiosk-product-card"
                  :class="{ 'is-loading': loadingItemId === product.id }"
                  role="button"
                  tabindex="0"
                  :aria-label="sanitizeItemName(product.name)"
                  :aria-busy="loadingItemId === product.id ? 'true' : 'false'"
                  @click="openProduct(product)"
                  @keydown.enter.prevent="openProduct(product)"
                  @keydown.space.prevent="openProduct(product)"
                >
                  <div class="kiosk-product-media">
                    <img
                      v-if="product.thumb || product.image"
                      :src="product.thumb || product.image"
                      :alt="sanitizeItemName(product.name)"
                      class="kiosk-product-image"
                      loading="lazy"
                    />
                    <div v-else class="kiosk-product-image-fallback">
                      <span class="kiosk-product-emoji">{{ getCategoryEmoji(product.name) }}</span>
                    </div>

                    <span v-if="getProductBadge(product)" class="kiosk-product-badge">
                      {{ getProductBadge(product) }}
                    </span>

                    <button
                      class="kiosk-product-add"
                      type="button"
                      @click.stop="openProduct(product)"
                      :disabled="!!loadingItemId"
                    >
                      <span v-if="loadingItemId === product.id" class="kiosk-product-add-spinner"></span>
                      <span v-else>+</span>
                    </button>
                  </div>

                  <div class="kiosk-product-copy">
                    <h3 class="kiosk-product-name">{{ sanitizeItemName(product.name) }}</h3>
                    <p v-if="product.description" class="kiosk-product-desc">
                      {{ truncate(product.description, 68) }}
                    </p>
                    <span class="kiosk-product-price">{{ formatPrice(product.convert_price) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </transition>
        </main>
      </div>

      <div class="kiosk-bottom-bar">
        <div class="kiosk-bottom-summary">
          <button class="kiosk-bottom-cart" @click="goToCart" :disabled="cartCount === 0">
            <span class="kiosk-bottom-cart-icon">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                <path d="M3 5h2l2.2 9.2a1 1 0 0 0 .98.8H18a1 1 0 0 0 .98-.8L21 8H8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="10" cy="19" r="1.6" fill="currentColor"/>
                <circle cx="17" cy="19" r="1.6" fill="currentColor"/>
              </svg>
            </span>
            <span>
              {{ cartCount }}
              {{ cartCount > 1 ? $t('kiosk.article_plural') : $t('kiosk.article_singular') }}
            </span>
          </button>

          <div class="kiosk-bottom-total">{{ formatPrice(cartTotal) }}</div>
        </div>

        <div class="kiosk-bottom-actions">
          <button class="kiosk-bottom-abandon" @click="abandonOrder">
            {{ $t('kiosk.catalog.abandon_order') }}
          </button>

          <button class="kiosk-bottom-pay" @click="goToCart" :disabled="cartCount === 0">
            {{ $t('kiosk.catalog.pay') }}
          </button>
        </div>
      </div>
    </template>

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
import { mapActions, mapGetters, mapState } from 'vuex';
import KioskWizardComponent from './KioskWizardComponent.vue';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { sanitizeKioskCustomerFacingText } from '../../../helpers/kioskDisplayText';

const EMOJI_MAP = {
  tacos: '🌮', burger: '🍔', sandwich: '🥪', pizza: '🍕',
  kebab: '🥙', frite: '🍟', boisson: '🥤', dessert: '🍰',
  salade: '🥗', wrap: '🫓', assiette: '🍽️', poulet: '🍗',
  plat: '🍽️', entrée: '🥗', viande: '🥩', poisson: '🐟',
};

export default {
  name: 'KioskCategoriesComponent',
  components: { KioskWizardComponent },
  mixins: [kioskPriceMixin],
  inject: {
    showToast: { default: () => () => {} },
  },
  data() {
    return {
      loadError: false,
      activeItem: null,
      loadingItemId: null,
    };
  },
  computed: {
    ...mapState('kioskMenu', ['kioskSandwichSubcolumn']),
    ...mapGetters('kioskMenu', [
      'categories',
      'allItems',
      'selectedCategoryId',
      'loading',
      'isStale',
      'fromCache',
      'sidebarCategories',
      'kioskCatalogItems',
    ]),
    ...mapGetters('kioskCart', { cartCount: 'count', cartTotal: 'total' }),
    selectedSidebarRow() {
      const sid = parseInt(this.selectedCategoryId, 10);
      const sub = this.kioskSandwichSubcolumn;
      return this.sidebarCategories.find(
        (r) => parseInt(r.id, 10) === sid && (r.kioskSandwichSub ?? null) === (sub ?? null),
      );
    },
    selectedCategory() {
      const sid = this.selectedCategoryId;
      const raw =
        this.categories.find((cat) => parseInt(cat.id, 10) === parseInt(sid, 10)) ||
        this.categories[0] ||
        null;
      const row = this.selectedSidebarRow;
      if (raw && row) {
        return { ...raw, name: row.name };
      }
      return raw;
    },
    selectedCategoryName() {
      return this.selectedCategory?.name || 'Menu';
    },
    selectedCategoryDisplayName() {
      return this.stripLeadingNos(this.selectedCategoryName);
    },
    filteredProducts() {
      if (!this.selectedCategoryId) return this.allItems || [];
      return this.kioskCatalogItems;
    },
  },
  watch: {
    '$route.query': {
      deep: true,
      immediate: true,
      handler(q) {
        if (q?.cat && this.categories.length > 0) {
          this.syncCategoryFromRoute(q.cat, q.sf);
        }
      },
    },
  },
  async mounted() {
    await this.loadCatalogue();
    if (!this.$route.query.cat && this.selectedCategoryId) {
      this.replaceCategoryQuery(this.selectedCategoryId, this.kioskSandwichSubcolumn === 'cold');
    }
  },
  methods: {
    ...mapActions('kioskMenu', ['fetchMenu', 'selectKioskCategory']),
    ...mapActions('kioskCart', ['addItem', 'reset']),

    async loadCatalogue() {
      this.loadError = false;
      try {
        const branchId = this.$store.state.kioskCart?.branchId;
        await this.fetchMenu({ branchId });
        if (this.$route.query.cat) {
          this.syncCategoryFromRoute(this.$route.query.cat, this.$route.query.sf);
        } else if (this.categories.length > 0 && this.selectedCategoryId) {
          this.replaceCategoryQuery(this.selectedCategoryId, this.kioskSandwichSubcolumn === 'cold');
        }
      } catch (_) {
        this.loadError = true;
      }
    },

    scrollProductZoneTop() {
      this.$nextTick(() => {
        const el = this.$refs.productZone;
        if (el && typeof el.scrollTop === 'number') el.scrollTop = 0;
      });
    },

    syncCategoryFromRoute(catId, sf) {
      const normalizedId = parseInt(catId, 10);
      const match = this.categories.find(cat => parseInt(cat.id, 10) === normalizedId);
      if (!match) return;
      const cold = sf === '1' || sf === 1 || sf === true || sf === 'true';
      this.selectKioskCategory({
        categoryId: match.id,
        sandwichSubcolumn: cold ? 'cold' : null,
      });
      this.scrollProductZoneTop();
    },

    replaceCategoryQuery(categoryId, isCold) {
      const q = { cat: String(categoryId) };
      if (isCold) q.sf = '1';
      this.$router.replace({
        name: 'kiosk.categories',
        query: q,
      });
    },

    selectCategory(cat) {
      const cold = cat.kioskSandwichSub === 'cold';
      this.selectKioskCategory({
        categoryId: cat.id,
        sandwichSubcolumn: cold ? 'cold' : null,
      });
      this.replaceCategoryQuery(cat.id, cold);
      this.scrollProductZoneTop();
    },

    isCategoryActive(cat) {
      return (
        parseInt(cat.id, 10) === parseInt(this.selectedCategoryId, 10) &&
        (cat.kioskSandwichSub ?? null) === (this.kioskSandwichSubcolumn ?? null)
      );
    },

    async openProduct(product) {
      if (this.loadingItemId) return;
      this.loadingItemId = product.id;
      try {
        // Même contrat que KioskWizard (surface=kiosk) : variations/extras filtrés borne
        const res = await this.$store.dispatch('frontendItem/details', {
          id: product.id,
          surface: 'kiosk',
        });
        const detail = res?.data?.data || res?.data || product;
        if (this.hasOptions(detail)) {
          this.activeItem = detail;
        } else {
          this.addItem(this.buildSimpleCartItem(detail));
          this.showToast(this.$t('kiosk.item_added', { name: this.sanitizeItemName(detail.name) }), 'success', 1800);
        }
      } catch (_) {
        this.addItem(this.buildSimpleCartItem(product));
        this.showToast(this.$t('kiosk.item_added', { name: this.sanitizeItemName(product.name) }), 'success', 1800);
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

    buildSimpleCartItem(item) {
      return {
        item_id: item.id,
        name: item.name,
        image: item.thumb || item.cover || item.image || null,
        quantity: 1,
        convert_price: parseFloat(item.convert_price) || 0,
        currency_price: item.currency_price,
        discount: 0,
        item_variation_total: 0,
        item_extra_total: 0,
        item_variations: { variations: {}, names: {} },
        item_extras: { extras: [], names: [] },
        instruction: null,
      };
    },

    addToCartAndClose(cartItem) {
      this.addItem(cartItem);
      this.closeWizard();
      this.showToast(this.$t('kiosk.item_added', { name: this.sanitizeItemName(cartItem.name) }), 'success', 1800);
    },

    closeWizard() {
      this.activeItem = null;
    },

    goToCart() {
      if (this.cartCount === 0) return;
      this.$router.push({ name: 'kiosk.cart' });
    },

    abandonOrder() {
      this.reset();
      this.$router.push({ name: 'kiosk.idle' });
    },

    truncate(text, max) {
      if (!text) return '';
      return text.length > max ? text.slice(0, max) + '...' : text;
    },

    sanitizeItemName(name) {
      return sanitizeKioskCustomerFacingText(name || '');
    },

    stripLeadingNos(name) {
      let s = (name || '').trim();
      while (/^nos\s+/i.test(s)) {
        s = s.replace(/^nos\s+/i, '').trim();
      }
      return s || this.$t('kiosk.menu_fallback');
    },

    displayCategoryName(cat) {
      return this.stripLeadingNos(cat?.name || '');
    },

    getCategoryEmoji(name) {
      const n = (name || '').toLowerCase();
      for (const [key, emoji] of Object.entries(EMOJI_MAP)) {
        if (n.includes(key)) return emoji;
      }
      return '🍽️';
    },

    getProductBadge(product) {
      if (product.is_featured == 5) return this.$t('kiosk.catalog.badge_new');
      if (this.hasOptions(product)) return this.$t('kiosk.catalog.badge_customize');
      return '';
    },
  },
};
</script>

<style scoped>
.kiosk-catalogue {
  width: 100vw;
  height: 100vh;
  display: flex;
  flex-direction: column;
  background: #ffffff;
  overflow: hidden;
  position: relative;
}

.kiosk-catalogue-header {
  height: 82px;
  padding: 0 22px 0 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  border-bottom: 1px solid #e8e8e8;
  flex-shrink: 0;
}

.kiosk-catalogue-brand {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.kiosk-brand-thumb-wrap {
  width: 56px;
  height: 56px;
  border-radius: 14px;
  overflow: hidden;
  background: #f7f7f8;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 1px 4px rgba(0,0,0,0.05);
  flex-shrink: 0;
}

.kiosk-brand-thumb {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.kiosk-brand-thumb-fallback {
  font-size: 28px;
}

.kiosk-catalogue-breadcrumb {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  min-width: 0;
}

.kiosk-breadcrumb-muted {
  font-size: 13px;
  font-weight: 600;
  color: #999;
  letter-spacing: 0.08em;
}

.kiosk-breadcrumb-current {
  font-size: 26px;
  font-weight: 800;
  color: #222;
  text-transform: uppercase;
  letter-spacing: -0.02em;
  white-space: nowrap;
}

.kiosk-catalogue-top-actions {
  display: flex;
  gap: 10px;
  flex-shrink: 0;
}

.kiosk-top-chip {
  height: 38px;
  padding: 0 14px;
  border-radius: 999px;
  border: none;
  background: #e8001c;
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.02em;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  opacity: 0.92;
}

.kiosk-top-chip-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 1.5px solid rgba(255,255,255,0.65);
  font-size: 10px;
}

/* [C2] Offline snapshot banner */
.kiosk-cache-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 20px;
  background: rgba(243, 156, 18, 0.12);
  border-bottom: 1px solid rgba(243, 156, 18, 0.35);
  font-size: 13px;
  font-weight: 600;
  color: #c87f00;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.kiosk-cache-banner-icon { flex-shrink: 0; }

.kiosk-catalogue-loading,
.kiosk-catalogue-empty {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #777;
}

.kiosk-catalogue-loading {
  flex-direction: column;
  gap: 18px;
}

.kiosk-spinner {
  width: 42px;
  height: 42px;
  border: 3px solid #e3e3e3;
  border-top-color: #e8001c;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.kiosk-catalogue-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}

.kiosk-catalogue-error-icon { font-size: 42px; }

.kiosk-catalogue-retry-btn {
  background: #e8001c;
  color: #fff;
  border: none;
  border-radius: 999px;
  padding: 12px 24px;
  font-size: 15px;
  font-weight: 700;
}

.kiosk-catalogue-body {
  flex: 1;
  display: grid;
  grid-template-columns: minmax(128px, 17vw) 1fr;
  min-height: 0;
}

.kiosk-sidebar {
  background: #fff;
  border-right: 1px solid #ececec;
  padding: 12px 10px 90px;
  overflow-y: auto;
  scrollbar-width: none;
}

.kiosk-sidebar::-webkit-scrollbar { display: none; }

.kiosk-sidebar-item {
  width: 100%;
  border: none;
  background: transparent;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 10px 6px 14px;
  border-bottom: 3px solid transparent;
  cursor: pointer;
  transition: background 0.16s ease, border-color 0.16s ease;
}

.kiosk-sidebar-item:active {
  background: #fafafa;
}

.kiosk-sidebar-item.active {
  border-bottom-color: #e8001c;
  background: linear-gradient(180deg, rgba(232,0,28,0.06), rgba(232,0,28,0));
}

.kiosk-sidebar-thumb-wrap {
  width: 72px;
  height: 72px;
  border-radius: 14px;
  overflow: hidden;
  background: #f7f7f8;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 1px 4px rgba(0,0,0,0.05);
}

.kiosk-sidebar-thumb {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.kiosk-sidebar-thumb-fallback {
  font-size: 36px;
}

.kiosk-sidebar-name {
  font-size: 11px;
  line-height: 1.15;
  font-weight: 700;
  color: #555;
  text-align: center;
  text-transform: uppercase;
  min-height: 26px;
  display: flex;
  align-items: flex-end;
}

.kiosk-sidebar-item.active .kiosk-sidebar-name {
  color: #b5162c;
}

.kiosk-product-zone {
  background: #ffffff;
  overflow-y: auto;
  padding: 12px 18px 110px;
  scrollbar-width: none;
}

.kiosk-product-zone::-webkit-scrollbar { display: none; }

.kiosk-product-zone-transition {
  min-height: 0;
}

/* Changement de catégorie : fondu doux local, sans glissement plein écran. */
.kiosk-cat-pane-enter-active,
.kiosk-cat-pane-leave-active {
  transition:
    opacity 0.18s ease-out,
    filter 0.18s ease-out;
}

.kiosk-cat-pane-enter-from,
.kiosk-cat-pane-leave-to {
  opacity: 0;
  filter: blur(4px);
}

.kiosk-product-zone-header {
  padding: 2px 4px 14px;
}

.kiosk-zone-title {
  margin: 0;
  font-size: 30px;
  font-weight: 800;
  color: #2b2b2b;
  text-transform: uppercase;
  letter-spacing: -0.03em;
}

.kiosk-zone-subtitle {
  margin: 4px 0 0;
  font-size: 14px;
  color: #999;
}

.kiosk-product-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 24px 22px;
}

.kiosk-product-card {
  position: relative;
  min-height: 350px;
  padding: 4px 8px 10px;
  cursor: pointer;
  /* Pas d’animation par carte au changement de catégorie : évite l’effet en cascade / livre */
}

.kiosk-product-card:active {
  transform: scale(0.985);
}

/* [AUDIT 2026-04-17 C6] Keyboard focus ring — card is now role=button. */
.kiosk-product-card:focus-visible {
  outline: 3px solid rgba(232, 0, 28, 0.55);
  outline-offset: 3px;
}

.kiosk-product-media {
  position: relative;
  height: 250px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-product-image {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.kiosk-product-image-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fafafa;
  border-radius: 16px;
}

.kiosk-product-emoji {
  font-size: 72px;
}

.kiosk-product-badge {
  position: absolute;
  top: 10px;
  left: 18px;
  background: #d7263d;
  color: white;
  font-size: 11px;
  font-weight: 800;
  padding: 5px 8px;
  border-radius: 6px;
  transform: rotate(-4deg);
  box-shadow: 0 2px 8px rgba(215,38,61,0.15);
}

.kiosk-product-add {
  position: absolute;
  top: 12px;
  right: 18px;
  width: 38px;
  height: 38px;
  border: none;
  border-radius: 50%;
  background: #d7263d;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  font-weight: 600;
  box-shadow: 0 3px 10px rgba(215,38,61,0.25);
  outline: 2px solid rgba(255,255,255,0.85);
}

.kiosk-product-add:disabled {
  opacity: 0.7;
}

.kiosk-product-add-spinner {
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255,255,255,0.35);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

.kiosk-product-copy {
  margin-top: 2px;
  text-align: center;
}

.kiosk-product-name {
  margin: 0;
  font-size: 23px;
  font-weight: 800;
  line-height: 1.15;
  color: #b5162c;
  text-transform: uppercase;
}

.kiosk-product-desc {
  margin: 6px auto 0;
  max-width: 82%;
  font-size: 12px;
  color: #777;
  line-height: 1.35;
}

.kiosk-product-price {
  display: block;
  margin-top: 6px;
  font-size: 16px;
  font-weight: 700;
  color: #222;
}

.kiosk-bottom-bar {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 96px;
  display: grid;
  grid-template-rows: 40px 56px;
  background: #fff;
  border-top: 1px solid #e5e5e5;
  z-index: 20;
}

.kiosk-bottom-summary {
  display: grid;
  grid-template-columns: 1fr auto;
  border-bottom: 1px solid #ececec;
}

.kiosk-bottom-actions {
  display: grid;
  grid-template-columns: 1.5fr 1fr;
}

.kiosk-bottom-abandon,
.kiosk-bottom-cart,
.kiosk-bottom-pay,
.kiosk-bottom-total {
  height: 100%;
}

.kiosk-bottom-abandon,
.kiosk-bottom-cart,
.kiosk-bottom-pay {
  border: none;
  background: #fff;
  font-size: 15px;
  font-weight: 800;
  letter-spacing: 0.01em;
}

.kiosk-bottom-abandon {
  color: #c33345;
  border-right: 1px solid #ececec;
}

.kiosk-bottom-cart {
  color: #666;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}

.kiosk-bottom-cart:disabled {
  opacity: 0.55;
}

.kiosk-bottom-cart-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.kiosk-bottom-total {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 18px;
  font-size: 18px;
  font-weight: 800;
  color: #6a4047;
  white-space: nowrap;
  background: #f7e5e8;
}

.kiosk-bottom-pay {
  background: #e0b3ba;
  color: #7a4a52;
}

.kiosk-bottom-pay:not(:disabled) {
  background: #e8001c;
  color: #fff;
}

.kiosk-bottom-pay:disabled {
  background: #ead6d9;
  color: #7a4a52;
}

.kiosk-wizard-overlay {
  position: absolute;
  inset: 0;
  background: #fff;
  z-index: 50;
}

.slide-up-enter-active,
.slide-up-leave-active {
  transition: transform 0.32s cubic-bezier(0.4, 0, 0.2, 1);
}

.slide-up-enter-from,
.slide-up-leave-to {
  transform: translateY(100%);
}
</style>
