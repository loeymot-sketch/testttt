<template>
  <div class="kiosk-catalogue" data-testid="kiosk-categories-root">
    <div class="kiosk-catalogue-header">
      <div class="kiosk-catalogue-brand">
        <div class="kiosk-brand-thumb-wrap" aria-hidden="true">
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
          <span
            class="kiosk-breadcrumb-current"
            data-testid="kiosk-categories-breadcrumb"
          >{{ selectedCategoryDisplayName || $t('kiosk.catalog.products_fallback') }}</span>
        </div>
      </div>

      <!-- Kiosk Phase 9.1.13 — les chips "Mon compte" / "Allergènes" étaient
           en `disabled`, affichées en permanence mais sans aucune action
           attachée : UX mort + placebo pour l'œil du client. On :
             - rend "Mon compte" cliquable → route `kiosk.loyalty` (déjà
               exposée, utilisée depuis le panier aussi) ;
             - retire "Allergènes" : aucune destination correcte n'existe
               à date (le header wizard affiche déjà les badges allergènes
               du plat via KsAllergenBadge — P9.1.2). La surface "écran
               des 14 allergènes EU" sera ré-introduite en P9.5 (préférences
               alimentaires) une fois le backend `user_dietary_prefs` posé.
           Aucune régression i18n : les 2 clefs FR/EN/AR restent utilisées
           par le wizard header. -->
      <div class="kiosk-catalogue-top-actions">
        <button type="button"
          class="kiosk-top-chip kiosk-top-chip--active"
          :aria-label="$t('kiosk.catalog.my_account')"
          data-testid="kiosk-categories-top-account"
          @click="openMyAccount">
          <span class="kiosk-top-chip-icon" aria-hidden="true">👤</span>
          {{ $t('kiosk.catalog.my_account') }}
        </button>
      </div>
    </div>

    <!-- P-MEGA-09 — Filtres actifs (Vuex + localStorage) : bandeau lisible + reset -->
    <div
      v-if="activeFilters.length > 0"
      class="kiosk-active-filter-banner"
      role="status"
      aria-live="polite"
      data-testid="kiosk-active-filter-banner"
    >
      <span class="kiosk-active-filter-banner__summary">
        {{ $t('kiosk.catalog.filters_label') }}
        ({{ activeFilters.length }})
      </span>
      <ul class="kiosk-active-filter-banner__list">
        <li v-for="f in activeFilters" :key="f">{{ $t('kiosk.filters.' + f) }}</li>
      </ul>
      <button
        type="button"
        class="kiosk-active-filter-banner__clear"
        @click="resetFilters"
        data-testid="kiosk-active-filter-banner-clear"
      >
        {{ $t('kiosk.catalog.filters_reset') }}
      </button>
    </div>

    <!-- Phase 8.5 — Bandeau promos (server-driven, jamais calculé côté client) -->
    <KioskPromoCarouselComponent />

    <!-- [C2] Offline snapshot banner — shown when menu is served from IndexedDB cache -->
    <transition name="slide-down">
      <div
        v-if="fromCache && !loading"
        class="kiosk-cache-banner"
        role="status"
        aria-live="polite"
        data-testid="kiosk-categories-cache-banner"
      >
        <span class="kiosk-cache-banner-icon" aria-hidden="true">📡</span>
        <span>{{ $t('kiosk.catalog.cache_banner') }}</span>
      </div>
    </transition>

    <div
      v-if="loading"
      class="kiosk-catalogue-loading"
      role="status"
      aria-live="polite"
      data-testid="kiosk-categories-loading"
    >
      <div class="kiosk-spinner" aria-hidden="true" />
      <p>{{ $t('kiosk.catalog.loading_menu') }}</p>
    </div>

    <div
      v-else-if="!loading && categories.length === 0"
      class="kiosk-catalogue-empty"
      data-testid="kiosk-categories-empty"
    >
      <div v-if="loadError" class="kiosk-catalogue-error" role="alert">
        <span class="kiosk-catalogue-error-icon" aria-hidden="true">📡</span>
        <p>{{ $t('kiosk.catalog.load_error_title') }}</p>
        <button type="button"
          class="kiosk-catalogue-retry-btn"
          @click="loadCatalogue"
          data-testid="kiosk-categories-retry"
        >{{ $t('kiosk.retry') }}</button>
      </div>
      <p v-else>{{ $t('kiosk.catalog.no_categories') }}</p>
    </div>

    <template v-else>
      <div class="kiosk-catalogue-body">
        <aside
          class="kiosk-sidebar"
          :aria-label="$t('kiosk.catalog.categories_nav_label')"
          role="navigation"
          data-testid="kiosk-categories-sidebar"
        >
          <button type="button"
            v-for="cat in sidebarCategories"
            :key="cat.kioskRowKey"
            class="kiosk-sidebar-item"
            :class="{ active: isCategoryActive(cat) }"
            :aria-current="isCategoryActive(cat) ? 'page' : undefined"
            :aria-label="displayCategoryName(cat)"
            :data-testid="`kiosk-categories-sidebar-item-${cat.id}`"
            @click="selectCategory(cat)"
          >
            <span class="kiosk-sidebar-name">{{ displayCategoryName(cat) }}</span>
            <div class="kiosk-sidebar-thumb-wrap" aria-hidden="true">
              <img
                v-if="cat.image_full_path || cat.image"
                :src="cat.image_full_path || cat.image"
                :alt="''"
                class="kiosk-sidebar-thumb"
                loading="lazy"
              />
              <div v-else class="kiosk-sidebar-thumb-fallback">
                {{ getCategoryEmoji(cat.name) }}
              </div>
            </div>
          </button>
        </aside>

        <main
          ref="productZone"
          class="kiosk-product-zone"
          data-testid="kiosk-categories-products"
        >
          <!-- Transition locale (le shell ne refait plus slide-left à chaque ?cat= — voir KioskAppComponent) -->
          <transition name="kiosk-cat-pane" mode="out-in">
            <div
              :key="`${selectedCategoryId}-${kioskSandwichSubcolumn || 'sig'}`"
              class="kiosk-product-zone-transition"
            >
              <div class="kiosk-product-zone-header">
                <h1 class="kiosk-zone-title" data-testid="kiosk-categories-zone-title">{{ selectedCategoryDisplayName }}</h1>
                <p class="kiosk-zone-subtitle" data-testid="kiosk-categories-zone-count">
                  {{ filteredProductCount }}
                  {{ filteredProductCount > 1 ? $t('kiosk.catalog.product_many') : $t('kiosk.catalog.product_one') }}
                </p>
              </div>

              <!-- Phase 8.4 — Filter chips row (DATA_CONTRACT §9.3) -->
              <div
                class="kiosk-filter-bar"
                role="group"
                :aria-label="$t('kiosk.catalog.filters_label') || 'Filtres'"
                data-testid="kiosk-filter-bar"
              >
                <KsFilterChip
                  v-for="f in kioskFilterDefs"
                  :key="f.key"
                  :filter="f.key"
                  :icon="f.icon"
                  :label="$t(f.i18n) || f.key"
                  :active="activeFilters.includes(f.key)"
                  :data-testid="`kiosk-filter-${f.key}`"
                  @toggle="toggleFilter"
                />
              </div>

              <div class="kiosk-product-grid" role="list">
                <div
                  v-for="product in catalogProducts"
                  :key="product.id"
                  class="kiosk-product-card"
                  :class="{
                    'is-loading': loadingItemId === product.id,
                    'kiosk-product-card--filtered-out': !isProductCatalogAllowed(product),
                  }"
                  role="button"
                  :tabindex="isProductCatalogAllowed(product) ? 0 : -1"
                  :aria-disabled="!isProductCatalogAllowed(product) ? 'true' : 'false'"
                  :aria-label="sanitizeItemName(product.name)"
                  :aria-busy="loadingItemId === product.id ? 'true' : 'false'"
                  :data-testid="`kiosk-product-card-${product.id}`"
                  :title="productFilteredOutTooltip(product)"
                  @click="onProductCardActivate(product, $event)"
                  @keydown.enter.prevent="onProductCardActivate(product, $event)"
                  @keydown.space.prevent="onProductCardActivate(product, $event)"
                >
                  <div class="kiosk-product-media">
                    <img
                      v-if="product.thumb || product.image"
                      :src="product.thumb || product.image"
                      :alt="''"
                      class="kiosk-product-image"
                      loading="lazy"
                      aria-hidden="true"
                    />
                    <div v-else class="kiosk-product-image-fallback" aria-hidden="true">
                      <span class="kiosk-product-emoji">{{ getCategoryEmoji(product.name) }}</span>
                    </div>

                    <span
                      v-if="getProductBadge(product)"
                      class="kiosk-product-badge"
                      :data-testid="`kiosk-product-badge-${product.id}`"
                    >
                      {{ getProductBadge(product) }}
                    </span>

                    <button type="button"
                      class="kiosk-product-add"
                      @click.stop="onProductCardActivate(product, $event)"
                      :disabled="!!loadingItemId || !isProductCatalogAllowed(product)"
                      :aria-label="$t('kiosk.catalog.add', { name: sanitizeItemName(product.name) })"
                      :data-testid="`kiosk-product-add-${product.id}`">
                      <span v-if="loadingItemId === product.id" class="kiosk-product-add-spinner" aria-hidden="true"></span>
                      <span v-else aria-hidden="true">+</span>
                    </button>
                  </div>

                  <div class="kiosk-product-copy">
                    <h3 class="kiosk-product-name">{{ sanitizeItemName(product.name) }}</h3>
                    <div v-if="productBadges(product).length" class="kiosk-product-flag-row" aria-hidden="false">
                      <KsBadge
                        v-for="b in productBadges(product)"
                        :key="b.color + b.label"
                        :color="b.color"
                        soft
                        size="sm"
                      >{{ b.label }}</KsBadge>
                    </div>
                    <KsAllergenBadge
                      v-if="productAllergens(product).length"
                      compact
                      :allergens="productAllergens(product)"
                      :customer-allergens="customerAllergenCodes"
                      :data-testid="`kiosk-product-allergens-${product.id}`"
                    />
                    <p v-if="product.description" class="kiosk-product-desc">
                      {{ truncate(product.description, 68) }}
                    </p>
                    <span
                      class="kiosk-product-price"
                      :data-testid="`kiosk-product-price-${product.id}`"
                    >{{ formatPrice(product.convert_price) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </transition>
        </main>
      </div>

      <div
        class="kiosk-bottom-bar"
        role="region"
        :aria-label="$t('kiosk.catalog.cart_summary_label')"
        data-testid="kiosk-categories-bottom-bar"
      >
        <div class="kiosk-bottom-summary">
          <button type="button"
            class="kiosk-bottom-cart"
            @click="goToCart"
            :disabled="cartCount === 0"
            :aria-label="$t('kiosk.catalog.open_cart_label', { n: cartCount })"
            data-testid="kiosk-categories-cart-indicator"
          >
            <span class="kiosk-bottom-cart-icon" aria-hidden="true">
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

          <div class="kiosk-bottom-total" data-testid="kiosk-categories-cart-total">{{ formatPrice(cartTotal) }}</div>
        </div>

        <div class="kiosk-bottom-actions">
          <button type="button"
            class="kiosk-bottom-abandon"
            @click="abandonOrder"
            data-testid="kiosk-categories-abandon"
          >
            {{ $t('kiosk.catalog.abandon_order') }}
          </button>

          <button type="button"
            class="kiosk-bottom-pay"
            @click="goToCart"
            :disabled="cartCount === 0"
            data-testid="kiosk-categories-pay"
          >
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
// [PHASE-6.4] Analytics — event `menu_viewed` au mount, `category_selected` sur clic.
// [PHASE-8.4/8.8] Ajouts filtres produits + events item_opened.
import kioskAnalytics from '../../../helpers/kioskAnalytics';
import { KIOSK_FILTERS, applyKioskFilters, extractAllergenCodes } from '../../../helpers/kioskFilters';
import KsFilterChip from './ds/KsFilterChip.vue';
import KsAllergenBadge from './ds/KsAllergenBadge.vue';
import KsBadge from './ds/KsBadge.vue';
import KioskPromoCarouselComponent from './KioskPromoCarouselComponent.vue';

const EMOJI_MAP = {
  tacos: '🌮', burger: '🍔', sandwich: '🥪', pizza: '🍕',
  kebab: '🥙', frite: '🍟', boisson: '🥤', dessert: '🍰',
  salade: '🥗', wrap: '🫓', assiette: '🍽️', poulet: '🍗',
  plat: '🍽️', entrée: '🥗', viande: '🥩', poisson: '🐟',
};

export default {
  name: 'KioskCategoriesComponent',
  components: {
    KioskWizardComponent,
    KsFilterChip,
    KsAllergenBadge,
    KsBadge,
    KioskPromoCarouselComponent,
  },
  mixins: [kioskPriceMixin],
  inject: {
    showToast: { default: () => () => {} },
  },
  data() {
    return {
      loadError: false,
      activeItem: null,
      loadingItemId: null,
      kioskFilterDefs: [
        { key: 'halal',       icon: '🕌', i18n: 'kiosk.filters.halal' },
        { key: 'vegetarian',  icon: '🥗', i18n: 'kiosk.filters.vegetarian' },
        { key: 'pork_free',   icon: '🚫🥓', i18n: 'kiosk.filters.pork_free' },
        { key: 'gluten_free', icon: '🌾', i18n: 'kiosk.filters.gluten_free' },
        { key: 'spicy',       icon: '🌶️', i18n: 'kiosk.filters.spicy' },
        { key: 'under_10',    icon: '💶', i18n: 'kiosk.filters.under_10' },
      ],
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
    // [P-MEGA-09 defensive] Read kioskFilter via getters with module-absent fallback,
    // so legacy specs (and hosts not registering the module) don't crash on .length / .includes.
    activeFilters() {
      try { return this.$store?.getters?.['kioskFilter/activeFilters'] || []; }
      catch (_) { return []; }
    },
    hydrated() {
      try { return !!this.$store?.getters?.['kioskFilter/hydrated']; }
      catch (_) { return false; }
    },
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
    /** Liste complète (jamais masquée par v-if — greyout uniquement). */
    catalogProducts() {
      return this.selectedCategoryId
        ? this.kioskCatalogItems
        : (this.allItems || []);
    },
    /** IDs passant applyKioskFilters ; null si aucun filtre → tout autorisé. */
    allowedProductIdSet() {
      if (!this.activeFilters?.length) return null;
      const allowed = applyKioskFilters(this.catalogProducts, this.activeFilters);
      return new Set(allowed.map((i) => i.id));
    },
    filteredProductCount() {
      if (!this.activeFilters?.length) return this.catalogProducts.length;
      return applyKioskFilters(this.catalogProducts, this.activeFilters).length;
    },
    customerAllergenCodes() {
      // Alimenté par scan loyalty — sinon vide. Lu depuis le store kioskSettings.
      const cust = this.$store.state.kioskSettings?.customerProfile;
      return Array.isArray(cust?.declared_allergens) ? cust.declared_allergens : [];
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
    if (!this.hydrated) {
      try { await this.$store.dispatch('kioskFilter/init'); } catch (_) { /* module not registered (legacy host) */ }
    }
    await this.loadCatalogue();
    if (!this.$route.query.cat && this.selectedCategoryId) {
      this.replaceCategoryQuery(this.selectedCategoryId, this.kioskSandwichSubcolumn === 'cold');
    }
    // [PHASE-6.4] Analytics : menu chargé / vu par l'utilisateur. Ne pas émettre
    // l'ID catégorie courante (ça arrive via category_selected quand il clique).
    try {
      kioskAnalytics.track('menu_viewed', {
        categories_count: Array.isArray(this.categories) ? this.categories.length : 0,
      });
    } catch (_) {}
  },
  methods: {
    ...mapActions('kioskMenu', ['fetchMenu', 'selectKioskCategory']),
    ...mapActions('kioskCart', ['addItem', 'reset']),

    isProductCatalogAllowed(product) {
      const set = this.allowedProductIdSet;
      if (!set) return true;
      return set.has(product.id);
    },
    productFilteredOutTooltip(product) {
      if (this.isProductCatalogAllowed(product)) return '';
      const names = (this.activeFilters || []).map((f) => this.$t(`kiosk.filters.${f}`));
      return names.filter(Boolean).join(', ');
    },
    onProductCardActivate(product, evt) {
      if (!this.isProductCatalogAllowed(product)) {
        if (evt && typeof evt.preventDefault === 'function') evt.preventDefault();
        return;
      }
      this.openProduct(product);
    },

    // Kiosk Phase 9.1.13 — wire du chip "Mon compte" vers l'écran loyalty.
    // Trace analytics optionnelle : si l'event `loyalty_scanned` n'est pas
    // approprié ici (on n'a rien scanné), on reste silencieux et on laisse
    // `KioskLoyaltyComponent` émettre ses events propres.
    openMyAccount() {
      try {
        this.$router.push({ name: 'kiosk.loyalty' });
      } catch (_) { /* navigation garde indisponible (tests) → no-op */ }
    },

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
      // [PHASE-6.4] Analytics : sélection catégorie. On passe l'ID et le sous-filtre
      // (cold/hot) pour permettre l'analyse des préférences.
      try {
        kioskAnalytics.track('category_selected', {
          category_id: cat.id,
          parent_category_id: cat.parent_id ?? cat.parentId ?? null,
          sandwich_sub: cold ? 'cold' : null,
        });
      } catch (_) {}
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
      // Phase 8.8 — Analytics : item_opened (sans PII ; juste ID + contexte
      // filtre actif pour analyse de discovery).
      try {
        kioskAnalytics.track('item_opened', {
          item_id: product.id,
          category_id: product.item_category_id ?? null,
          active_filters: Array.isArray(this.activeFilters) ? this.activeFilters.slice() : [],
        });
      } catch (_) {}
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
      // [AUDIT 2026-04-17 C14] Format normalisé identique à
      // KioskWizardComponent.buildCartItem :
      //   - item_variations : Array<{ id, variation_name, name }>
      //   - item_extras     : Array<{ id, name }>
      // Évite la divergence de format qui forçait kioskCart.submitOrder à
      // gérer deux shapes (le legacy { variations, names } et le tableau).
      const basePrice = parseFloat(item.convert_price) || 0;
      return {
        item_id: item.id,
        item_category_id: item.item_category_id ?? null,
        name: item.name,
        image: item.thumb || item.cover || item.image || null,
        quantity: 1,
        convert_price: basePrice,
        currency_price: item.currency_price,
        discount: 0,
        item_variation_total: 0,
        item_extra_total: 0,
        item_variations: [],
        item_extras: [],
        total: basePrice,
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

    // Phase 8.4 — badges diététiques (soft, discrets) projetés depuis flags DB.
    productBadges(product) {
      const out = [];
      if (product.is_chef_pick) {
        out.push({ color: 'chef-pick', label: this.$t('kiosk.badges.chef_pick') || 'Coup de cœur' });
      }
      if (product.is_new) {
        out.push({ color: 'new', label: this.$t('kiosk.badges.new') || 'Nouveau' });
      }
      if (product.is_halal) {
        out.push({ color: 'halal', label: this.$t('kiosk.badges.halal') || 'Halal' });
      }
      if (product.is_vegetarian) {
        out.push({ color: 'veg', label: this.$t('kiosk.badges.vegetarian') || 'Végétarien' });
      }
      if (product.is_spicy) {
        out.push({ color: 'spicy', label: this.$t('kiosk.badges.spicy') || 'Piquant' });
      }
      return out;
    },
    productAllergens(product) {
      return extractAllergenCodes(product);
    },
    toggleFilter(key) {
      if (!KIOSK_FILTERS.includes(key)) return;
      const wasOn = this.activeFilters.includes(key);
      try { this.$store.dispatch('kioskFilter/toggle', key); } catch (_) {}
      try {
        kioskAnalytics.track('filter_toggled', {
          filter: key,
          active: !wasOn,
          active_filters: this.activeFilters.slice(),
          result_count: this.filteredProductCount,
        });
      } catch (_) {}
    },
    resetFilters() {
      if (this.activeFilters.length === 0) return;
      try { this.$store.dispatch('kioskFilter/reset'); } catch (_) {}
      try {
        kioskAnalytics.track('filter_reset', { result_count: this.filteredProductCount });
      } catch (_) {}
    },
  },
};
</script>

<style scoped>
/* Phase 8.4 — Filter bar */
/* P-MEGA-09 — bandeau filtres persistés */
.kiosk-active-filter-banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 16px;
  padding: 10px 20px;
  background: rgba(232, 0, 28, 0.06);
  border-bottom: 1px solid rgba(232, 0, 28, 0.2);
  font-size: 14px;
  font-weight: 600;
  color: var(--kiosk-primary-dark, #a41020);
}
.kiosk-active-filter-banner__summary { flex-shrink: 0; }
.kiosk-active-filter-banner__list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 14px;
  list-style: none;
  margin: 0;
  padding: 0;
  flex: 1;
  min-width: 0;
}
.kiosk-active-filter-banner__list li {
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.85);
  border: 1px solid rgba(232, 0, 28, 0.22);
  font-size: 13px;
}
.kiosk-active-filter-banner__clear {
  margin-left: auto;
  background: transparent;
  border: none;
  color: var(--kiosk-primary, #e8001c);
  text-decoration: underline;
  cursor: pointer;
  font-weight: 700;
  font-size: calc(13px * var(--kiosk-text-scale, 1));
  padding: 8px 12px;
}
.kiosk-active-filter-banner__clear:hover,
.kiosk-active-filter-banner__clear:focus-visible {
  color: var(--kiosk-primary-dark, #a41020);
  outline: none;
}
.kiosk-product-card--filtered-out {
  opacity: 0.42;
  filter: grayscale(0.35);
  cursor: not-allowed;
}
.kiosk-product-card--filtered-out:active {
  transform: none;
}

.kiosk-filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: var(--kiosk-space-2, 8px);
  padding: var(--kiosk-space-3, 12px) 0 var(--kiosk-space-4, 16px);
  align-items: center;
}
.kiosk-filter-reset {
  background: transparent;
  border: none;
  color: var(--kiosk-text-muted, #5A5A5A);
  text-decoration: underline;
  cursor: pointer;
  padding: 8px 12px;
  font-size: calc(13px * var(--kiosk-text-scale, 1));
}
.kiosk-filter-reset:hover { color: var(--kiosk-primary, #E8001C); }

.kiosk-product-flag-row {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-bottom: 4px;
}

.kiosk-catalogue {
  width: 100vw;
  height: 100vh;
  display: flex;
  flex-direction: column;
  background: var(--kiosk-surface);
  overflow: hidden;
  position: relative;
}

.kiosk-catalogue-header {
  height: 82px;
  padding: 0 22px 0 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--kiosk-surface);
  border-bottom: 1px solid var(--kiosk-border);
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
  background: var(--kiosk-surface-alt);
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: var(--kiosk-shadow-card);
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
  color: var(--kiosk-text-mute);
  letter-spacing: 0.08em;
}

.kiosk-breadcrumb-current {
  font-size: 26px;
  font-weight: 800;
  color: var(--kiosk-text);
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
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.02em;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  opacity: 0.92;
  cursor: pointer;
  transition: transform 0.1s ease, opacity 0.15s ease;
}
/* Kiosk Phase 9.1.13 — état interactif du chip "Mon compte". */
.kiosk-top-chip--active:hover,
.kiosk-top-chip--active:focus-visible {
  opacity: 1;
  outline: none;
}
.kiosk-top-chip--active:active {
  transform: scale(0.97);
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
  color: var(--kiosk-warning);
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
  color: var(--kiosk-text-muted);
}

.kiosk-catalogue-loading {
  flex-direction: column;
  gap: 18px;
}

.kiosk-spinner {
  width: 42px;
  height: 42px;
  border: 3px solid var(--kiosk-border);
  border-top-color: var(--kiosk-primary);
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
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
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
  background: var(--kiosk-surface);
  border-right: 1px solid var(--kiosk-border);
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
  background: var(--kiosk-surface-alt);
}

.kiosk-sidebar-item.active {
  border-bottom-color: var(--kiosk-primary);
  background: linear-gradient(180deg, var(--kiosk-primary-soft), transparent);
}

.kiosk-sidebar-thumb-wrap {
  width: 72px;
  height: 72px;
  border-radius: 14px;
  overflow: hidden;
  background: var(--kiosk-surface-alt);
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: var(--kiosk-shadow-card);
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
  color: var(--kiosk-text-muted);
  text-align: center;
  text-transform: uppercase;
  min-height: 26px;
  display: flex;
  align-items: flex-end;
}

.kiosk-sidebar-item.active .kiosk-sidebar-name {
  color: var(--kiosk-primary-dark);
}

.kiosk-product-zone {
  background: var(--kiosk-surface);
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
  color: var(--kiosk-text);
  text-transform: uppercase;
  letter-spacing: -0.03em;
}

.kiosk-zone-subtitle {
  margin: 4px 0 0;
  font-size: 14px;
  color: var(--kiosk-text-mute);
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
  outline: var(--kiosk-focus-width) solid var(--kiosk-focus-ring);
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
  background: var(--kiosk-surface-alt);
  border-radius: 16px;
}

.kiosk-product-emoji {
  font-size: 72px;
}

.kiosk-product-badge {
  position: absolute;
  top: 10px;
  left: 18px;
  background: var(--kiosk-primary-dark);
  color: white;
  font-size: 11px;
  font-weight: 800;
  padding: 5px 8px;
  border-radius: 6px;
  transform: rotate(-4deg);
  box-shadow: var(--kiosk-shadow-card);
}

.kiosk-product-add {
  position: absolute;
  top: 12px;
  right: 18px;
  width: 38px;
  height: 38px;
  border: none;
  border-radius: 50%;
  background: var(--kiosk-primary-dark);
  color: var(--kiosk-text-on-red);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  font-weight: 600;
  box-shadow: var(--kiosk-shadow-cta);
  outline: 2px solid rgba(255,255,255,0.85);
}

.kiosk-product-add:disabled {
  opacity: 0.7;
}

.kiosk-product-add-spinner {
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255,255,255,0.35);
  border-top-color: var(--kiosk-text-on-red);
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
  color: var(--kiosk-primary-dark);
  text-transform: uppercase;
}

.kiosk-product-desc {
  margin: 6px auto 0;
  max-width: 82%;
  font-size: 12px;
  color: var(--kiosk-text-muted);
  line-height: 1.35;
}

.kiosk-product-price {
  display: block;
  margin-top: 6px;
  font-size: 16px;
  font-weight: 700;
  color: var(--kiosk-text);
}

.kiosk-bottom-bar {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 96px;
  display: grid;
  grid-template-rows: 40px 56px;
  background: var(--kiosk-surface);
  border-top: 1px solid var(--kiosk-border);
  z-index: 20;
}

.kiosk-bottom-summary {
  display: grid;
  grid-template-columns: 1fr auto;
  border-bottom: 1px solid var(--kiosk-border);
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
  background: var(--kiosk-surface);
  font-size: 15px;
  font-weight: 800;
  letter-spacing: 0.01em;
}

.kiosk-bottom-abandon {
  color: var(--kiosk-primary-dark);
  border-right: 1px solid var(--kiosk-border);
}

.kiosk-bottom-cart {
  color: var(--kiosk-text-muted);
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
  color: var(--kiosk-primary-dark);
  white-space: nowrap;
  background: var(--kiosk-primary-soft);
}

.kiosk-bottom-pay {
  background: var(--kiosk-primary-soft);
  color: var(--kiosk-primary-dark);
}

.kiosk-bottom-pay:not(:disabled) {
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
}

.kiosk-bottom-pay:disabled {
  background: var(--kiosk-primary-soft);
  color: var(--kiosk-primary-dark);
}

.kiosk-wizard-overlay {
  position: absolute;
  inset: 0;
  background: var(--kiosk-surface);
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
