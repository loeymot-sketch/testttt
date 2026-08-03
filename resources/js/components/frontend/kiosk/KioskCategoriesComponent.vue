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

              <div class="kiosk-product-grid" :class="productGridLayoutClass" role="list">
                <div
                  v-for="product in catalogProducts"
                  :key="product.id"
                  class="kiosk-product-card"
                  :class="{
                    'is-loading': loadingItemId === product.id,
                    'kiosk-product-card--filtered-out': !isProductCatalogAllowed(product),
                  }"
                  role="listitem"
                  :aria-disabled="!isProductCatalogAllowed(product) ? 'true' : 'false'"
                  :aria-busy="loadingItemId === product.id ? 'true' : 'false'"
                  :data-testid="`kiosk-product-card-${product.id}`"
                  :title="productFilteredOutTooltip(product)"
                  @click="onProductCardActivate(product, $event)"
                >
                  <div class="kiosk-product-media">
                    <img
                      v-if="product.thumb || product.image"
                      :src="product.thumb || product.image"
                      :alt="''"
                      class="kiosk-product-image"
                      :class="productSizeClass(product)"
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
                    <h2 class="kiosk-product-name">{{ sanitizeItemName(product.name) }}</h2>
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

      <!-- FoodKing brand V2 (2026-05-10) — Cart bottom-sheet visible direct
           sur la welcome page dès qu'un item est ajouté. Remplace le toast
           d'ajout (owner refuse les notifications). -->
      <KsCartBottomSheet
        :items="cartItems"
        :format-price="formatPrice"
        @increment="incrementCartItem"
        @decrement="decrementCartItem"
      />

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
        <!-- V3.6.1 (2026-05-10) Adversarial fix P1-8 : :key force remount sur
             changement d'item, garantit selections fresh (pas de ghost
             fritesStyleExtraId carried-over d'un produit précédent). -->
        <KioskWizardComponent
          :key="activeItem.id"
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
import kioskAnalytics from '../../../helpers/kioskAnalytics';
import { extractAllergenCodes } from '../../../helpers/kioskFilters';
import KsAllergenBadge from './ds/KsAllergenBadge.vue';
import KsBadge from './ds/KsBadge.vue';
import KsCartBottomSheet from './ds/KsCartBottomSheet.vue';
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
    KsAllergenBadge,
    KsBadge,
    KsCartBottomSheet,
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
    ...mapState('kioskCart', { cartItems: 'items' }),
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
    filteredProductCount() {
      return this.catalogProducts.length;
    },
    // [BORNE-UX 2026-07-11] Layout adaptatif : peu de produits → cartes très
    // grandes qui remplissent l'espace (2 tacos = empilés ~80%). Owner.
    productGridLayoutClass() {
      const n = this.catalogProducts.length;
      if (n <= 1) return 'kiosk-product-grid--solo';
      if (n === 2) return 'kiosk-product-grid--duo';
      if (n <= 4) return 'kiosk-product-grid--quad';
      return '';
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
    loading(val) {
      if (!val) this.syncCategoryRouteIfNeeded();
    },
    categories: {
      handler(val) {
        if (val?.length) this.$nextTick(() => this.syncCategoryRouteIfNeeded());
      },
    },
  },
  async mounted() {
    if (!this.ensureOrderTypeSelected()) {
      return;
    }
    await this.loadCatalogue();
    this.syncCategoryRouteIfNeeded();
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
    ...mapActions('kioskCart', ['addItem', 'reset', 'updateQuantity', 'removeItem']),

    // [BORNE-UX 2026-07-11 #3] Variantes de taille : la grande (L/XL) est rendue
    // ~30 % plus grande que la petite (M/S) pour que la différence saute aux yeux
    // (ex Tacos M vs Tacos L). Détection par suffixe de taille du nom produit.
    productSizeClass(product) {
      const name = String(product?.name || '').trim().toLowerCase();
      const last = name.split(/\s+/).pop();
      if (last === 'l' || last === 'xl' || last === 'xxl' || /\b(grande?|large|maxi)$/.test(name)) {
        return 'kiosk-product-image--size-l';
      }
      if (last === 'm' || last === 's' || /\b(petite?|moyen(?:ne)?|small)$/.test(name)) {
        return 'kiosk-product-image--size-m';
      }
      return '';
    },

    hasExplicitOrderType() {
      const getter = this.$store?.getters?.['kioskCart/hasExplicitOrderType'];
      return getter !== false;
    },
    ensureOrderTypeSelected() {
      if (this.hasExplicitOrderType()) return true;
      try {
        this.$router.replace({ name: 'kiosk.idle' });
      } catch (_) {}
      return false;
    },

    isProductCatalogAllowed(product) {
      if (this.isProductUnavailable(product)) return false;
      return true;
    },
    isProductUnavailable(product) {
      if (!product) return false;
      if (product.is_available === false || product.is_available === 0 || product.is_available === '0') {
        return true;
      }
      const status = Number(product.status);
      return status === 0 || status === 2 || status === 10;
    },
    productFilteredOutTooltip(product) {
      if (this.isProductCatalogAllowed(product)) return '';
      if (this.isProductUnavailable(product)) {
        return product.unavailable_reason || this.$t('pos.item_86_d') || 'Épuisé';
      }
      return '';
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

    /** Met ?cat= dès que le menu est prêt (évite URL nue /categories + états bizarres après SPA). */
    syncCategoryRouteIfNeeded() {
      if (this.loading) return;
      if (!this.categories?.length || !this.selectedCategoryId) return;
      if (this.$route.query.cat) return;
      this.replaceCategoryQuery(this.selectedCategoryId, this.kioskSandwichSubcolumn === 'cold');
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
      if (!this.ensureOrderTypeSelected()) return;
      if (this.loadingItemId) return;
      this.loadingItemId = product.id;
      // Phase 8.8 — Analytics : item_opened (sans PII ; juste ID + contexte
      // filtre actif pour analyse de discovery).
      try {
        kioskAnalytics.track('item_opened', {
          item_id: product.id,
          category_id: product.item_category_id ?? null,
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
          // FoodKing brand V2 (2026-05-10) — owner request : pas de toast sur
          // add-to-cart, le KsCartBottomSheet rend l'ajout visible directement.
        }
      } catch (_) {
        this.addItem(this.buildSimpleCartItem(product));
        // FoodKing brand V2 — toast retiré (cf. KsCartBottomSheet ci-dessous).
      } finally {
        this.loadingItemId = null;
      }
    },

    hasOptions(detail) {
      // V3.5 (2026-05-10) Owner gate : `addons` (3 default seeded sur TOUS les
      // items pour menu full/frites/boisson) ne devrait PAS déclencher le wizard
      // pour des produits simples (boissons, desserts) sans variations ni extras.
      // → on retire `addons.length > 0` du check.
      // V3.6 (2026-05-10) Owner gate : la catégorie "Suppléments" doit toujours
      // s'ajouter direct, même si extras techniques présents en DB.
      // Detail API expose `category_name` (string flat) — pas `category.name`.
      // V3.6.1 adversarial fix P0-1 : check robuste via normalize+startsWith
      // + fallback ID 318. Tolère "Supplément"/"Supplements"/"SUPPLÉMENT".
      const catName = (detail.category_name || detail.category?.name || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '') // strip diacritics: "suppléments" → "supplements"
        .trim();
      const catId = parseInt(detail.item_category_id, 10);
      const isSupplementCategory =
        catName.startsWith('supplement') || // catches "supplement", "supplements", "supplément(s)"
        catId === 318;
      if (isSupplementCategory) return false;
      return (detail.itemAttributes?.length > 0) ||
             (detail.extras?.length > 0) ||
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
      // FoodKing brand V2 (2026-05-10) — owner request : pas de toast sur
      // add-to-cart depuis le wizard. L'item apparaît dans KsCartBottomSheet.
    },

    incrementCartItem(index) {
      const item = this.cartItems[index];
      if (!item) return;
      const next = (item.quantity || 0) + 1;
      const max = window.foodkingConfig?.maxItemQty ?? 20;
      if (next > max) return;
      this.updateQuantity({ index, quantity: next });
    },

    decrementCartItem(index) {
      const item = this.cartItems[index];
      if (!item) return;
      const next = (item.quantity || 0) - 1;
      if (next <= 0) {
        this.removeItem(index);
      } else {
        this.updateQuantity({ index, quantity: next });
      }
    },

    closeWizard() {
      this.activeItem = null;
    },

    goToCart() {
      if (!this.ensureOrderTypeSelected()) return;
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
      if (this.isProductUnavailable(product)) return this.$t('pos.item_86_d') || 'Épuisé';
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
  },
};
</script>

<style scoped>
.kiosk-product-card--filtered-out {
  opacity: 0.42;
  filter: grayscale(0.35);
  cursor: not-allowed;
}
.kiosk-product-card--filtered-out:active {
  transform: none;
}

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
  background: var(--kiosk-page-bg, var(--kiosk-bg));
  overflow: hidden;
  position: relative;
}

.kiosk-catalogue-header {
  height: 96px;
  padding-block: 0;
  padding-inline: 24px 30px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--kiosk-surface);
  border-bottom: 1px solid var(--kiosk-border);
  box-shadow: var(--kiosk-shadow-sticky);
  flex-shrink: 0;
}

.kiosk-catalogue-brand {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.kiosk-brand-thumb-wrap {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  overflow: hidden;
  background: var(--kiosk-product-media-bg, var(--kiosk-surface-alt));
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
  font-size: 34px;
}

.kiosk-catalogue-breadcrumb {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  min-width: 0;
}

.kiosk-breadcrumb-muted {
  font-size: 14px;
  font-weight: 800;
  color: var(--kiosk-text-mute);
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.kiosk-breadcrumb-current {
  font-size: clamp(28px, 3.2vw, 38px);
  font-weight: 900;
  color: var(--kiosk-text);
  text-transform: uppercase;
  letter-spacing: 0;
  white-space: nowrap;
}

.kiosk-catalogue-top-actions {
  display: flex;
  gap: 10px;
  flex-shrink: 0;
}

.kiosk-top-chip {
  min-height: 54px;
  height: auto;
  padding: 0 20px;
  border-radius: 999px;
  border: 2px solid var(--kiosk-primary-dark, #DC4517);
  background: var(--kiosk-primary-dark, #DC4517);
  color: #FFFFFF;
  font-size: 14px;
  font-weight: 900;
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
  box-shadow: 0 0 0 3px var(--kiosk-focus-ring, #2563eb);
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
  /* [BORNE-UX 2026-07-11] Sidebar catégories ≥ doublée (124→256px) : chaque
     catégorie plus visible + mieux distinguée (owner). */
  grid-template-columns: clamp(256px, 24vw, 340px) 1fr;
  min-height: 0;
}

.kiosk-sidebar {
  background: var(--kiosk-surface);
  border-inline-end: 1px solid var(--kiosk-border);
  padding: 10px 8px 128px;
  overflow-y: auto;
  overscroll-behavior-y: contain;
  scrollbar-width: thin;
  scrollbar-color: rgba(255,255,255,0.24) transparent;
}

.kiosk-sidebar::-webkit-scrollbar { width: 6px; }
.kiosk-sidebar::-webkit-scrollbar-track { background: transparent; }
.kiosk-sidebar::-webkit-scrollbar-thumb {
  background: rgba(255,255,255,0.24);
  border-radius: 999px;
}

.kiosk-sidebar-item {
  width: 100%;
  /* [BORNE-UX 2026-07-11] Chaque catégorie = une carte distincte (fond + bordure)
     pour bien séparer catégorie de catégorie (owner). */
  border: 2px solid var(--kiosk-border);
  background: var(--kiosk-surface-alt);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 18px 12px 16px;
  margin-bottom: 14px;
  border-radius: 24px;
  cursor: pointer;
  transition: transform 0.16s ease, background 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease;
}

.kiosk-sidebar-item:active {
  background: var(--kiosk-surface-alt);
  transform: scale(0.98);
}

.kiosk-sidebar-item.active {
  border-color: var(--kiosk-primary-dark, #DC4517);
  background: var(--kiosk-primary-dark, #DC4517);
  box-shadow: var(--kiosk-shadow-card);
}

.kiosk-sidebar-thumb-wrap {
  /* [BORNE-UX 2026-07-11] Miniature catégorie agrandie (~74→120px). */
  width: clamp(104px, 11vw, 132px);
  height: clamp(104px, 11vw, 132px);
  border-radius: 50%;
  overflow: hidden;
  background: var(--kiosk-product-media-bg, var(--kiosk-surface-alt));
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: var(--kiosk-shadow-card);
}

.kiosk-sidebar-thumb {
  width: 100%;
  height: 100%;
  /* contain (pas cover) pour respecter les visuels détourés sans arrière-plan. */
  object-fit: contain;
  padding: 6px;
}

.kiosk-sidebar-thumb-fallback {
  font-size: 32px;
}

.kiosk-sidebar-name {
  width: 100%;
  /* [BORNE-UX 2026-07-11] Libellé catégorie agrandi (~12→18px) pour lisibilité. */
  font-size: clamp(16px, 1.5vw, 20px);
  line-height: 1.12;
  font-weight: 900;
  color: var(--kiosk-text);
  text-align: center;
  text-transform: uppercase;
  min-height: 24px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  overflow-wrap: anywhere;
}

.kiosk-sidebar-item.active .kiosk-sidebar-name {
  color: #FFFFFF;
}

.kiosk-product-zone {
  background: transparent;
  overflow-y: auto;
  padding: 22px 28px 142px;
  scrollbar-width: none;
}

/* FoodKing brand V3 (2026-05-10) — sheet horizontal compact ~150px.
   Padding total = 150 (sheet) + 118 (bar) + 32 (clearance) ≈ 300px. */
.kiosk-catalogue:has([data-testid="kiosk-cart-bottom-sheet"]) .kiosk-product-zone {
  padding-bottom: 300px;
}
.kiosk-catalogue:has([data-testid="kiosk-cart-bottom-sheet"]) .kiosk-sidebar {
  padding-bottom: 300px;
}

.kiosk-product-zone::-webkit-scrollbar { display: none; }

.kiosk-product-zone-transition {
  min-height: 0;
}

/* Changement de catégorie : fondu doux local, sans glissement plein écran.
   [A-003 test-e2e 2026-07-17] blur(4px) plein pane retiré : captures montraient
   le contenu central FIGÉ flou (prix illisibles) après transition, et un filter
   plein viewport coûte cher au GPU de la borne (mandat perf owner). Le fondu
   opacité seul suffit. */
.kiosk-cat-pane-enter-active,
.kiosk-cat-pane-leave-active {
  transition: opacity 0.18s ease-out;
}

.kiosk-cat-pane-enter-from,
.kiosk-cat-pane-leave-to {
  opacity: 0;
}

.kiosk-product-zone-header {
  padding: 2px 4px 18px;
}

.kiosk-zone-title {
  margin: 0;
  font-size: clamp(34px, 4vw, 48px);
  font-weight: 900;
  color: var(--kiosk-text);
  text-transform: uppercase;
  letter-spacing: 0;
  line-height: 1.04;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.kiosk-zone-subtitle {
  margin: 4px 0 0;
  font-size: 14px;
  color: var(--kiosk-text-mute);
}

.kiosk-product-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 24px;
}

/* [BORNE-UX 2026-07-11] Layout adaptatif : occuper le MAXIMUM d'espace selon le
   nombre de produits, sans déborder de la zone allouée (owner). */
.kiosk-product-grid--solo {
  grid-template-columns: minmax(0, 1fr);
}
.kiosk-product-grid--solo .kiosk-product-card { min-height: min(74vh, 760px); }
.kiosk-product-grid--solo .kiosk-product-media { height: min(56vh, 580px); }

/* 2 produits (ex Tacos M/L) : empilés haut/bas, très grands (~80% de la zone). */
.kiosk-product-grid--duo {
  grid-template-columns: minmax(0, 1fr);
  gap: 28px;
}
.kiosk-product-grid--duo .kiosk-product-card { min-height: min(41vh, 470px); }
.kiosk-product-grid--duo .kiosk-product-media { height: min(30vh, 360px); }

/* 3-4 produits : 2 colonnes mais cartes agrandies. */
.kiosk-product-grid--quad .kiosk-product-card { min-height: min(44vh, 470px); }
.kiosk-product-grid--quad .kiosk-product-media { height: min(30vh, 320px); }

/* [BORNE-UX 2026-07-11 #3] Différence de taille visible entre variantes :
   l'image L est ~30 % plus grande que la M (owner). */
.kiosk-product-image--size-l { transform: scale(1.18); }
.kiosk-product-image--size-m { transform: scale(0.9); }

.kiosk-product-card {
  position: relative;
  min-height: 392px;
  padding: 16px 18px 18px;
  border-radius: 30px;
  border: 1.5px solid var(--kiosk-border);
  background: var(--kiosk-surface);
  box-shadow: var(--kiosk-shadow-card);
  cursor: pointer;
  overflow: hidden;
  transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
}

.kiosk-product-card:active {
  transform: scale(0.98);
}

/* [AUDIT 2026-04-17 C6] Keyboard focus ring — card is now role=button. */
.kiosk-product-card:focus-visible {
  outline: var(--kiosk-focus-width) solid var(--kiosk-focus-ring);
  outline-offset: 3px;
}

.kiosk-product-media {
  position: relative;
  height: 234px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 28px;
  background: var(--kiosk-product-media-bg, var(--kiosk-surface-alt));
}

.kiosk-product-image {
  width: 94%;
  height: 94%;
  object-fit: contain;
  filter: drop-shadow(0 18px 28px rgba(0,0,0,0.18));
}

.kiosk-product-image-fallback {
  width: 176px;
  height: 176px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--kiosk-surface);
  border-radius: 50%;
  box-shadow: inset 0 -8px 18px rgba(0,0,0,0.08);
}

.kiosk-product-emoji {
  font-size: 72px;
}

.kiosk-product-badge {
  position: absolute;
  top: 10px;
  inset-inline-start: 18px;
  background: var(--kiosk-primary-dark);
  color: var(--kiosk-text-on-red, #fff);
  font-size: 11px;
  font-weight: 800;
  padding: 5px 8px;
  border-radius: 999px;
  transform: rotate(-4deg);
  box-shadow: var(--kiosk-shadow-card);
}

.kiosk-product-add {
  position: absolute;
  bottom: -12px;
  inset-inline-end: 16px;
  width: 64px;
  height: 64px;
  border: none;
  border-radius: 50%;
  background: var(--kiosk-primary-dark);
  color: var(--kiosk-text-on-red);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 36px;
  font-weight: 900;
  box-shadow: var(--kiosk-shadow-cta);
  outline: 4px solid var(--kiosk-surface);
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
  margin-top: 16px;
  text-align: start;
}

.kiosk-product-name {
  margin: 0;
  font-size: clamp(22px, 2.6vw, 30px);
  font-weight: 900;
  line-height: 1.15;
  color: var(--kiosk-text);
  text-transform: uppercase;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.kiosk-product-desc {
  margin: 8px 0 0;
  max-width: calc(100% - 58px);
  font-size: 14px;
  color: var(--kiosk-text-muted);
  line-height: 1.35;
}

.kiosk-product-price {
  display: block;
  margin-top: 10px;
  font-size: 24px;
  font-weight: 900;
  color: var(--kiosk-primary);
  font-variant-numeric: tabular-nums;
}

.kiosk-bottom-bar {
  position: absolute;
  inset-inline: 0;
  bottom: 0;
  height: 118px;
  display: grid;
  grid-template-rows: 48px 70px;
  background: var(--kiosk-surface);
  border-top: 1px solid var(--kiosk-border);
  box-shadow: var(--kiosk-shadow-sticky);
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
  font-size: 17px;
  font-weight: 900;
  letter-spacing: 0.01em;
}

.kiosk-bottom-abandon {
  color: var(--kiosk-primary-dark);
  border-inline-end: 1px solid var(--kiosk-border);
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
  font-size: 24px;
  font-weight: 900;
  color: var(--kiosk-primary);
  white-space: nowrap;
  background: var(--kiosk-primary-soft);
  font-variant-numeric: tabular-nums;
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
  background: var(--kiosk-surface-alt);
  color: var(--kiosk-text-mute);
  border-inline-start: 1px solid var(--kiosk-border);
  cursor: not-allowed;
}

.kiosk-wizard-overlay {
  position: fixed;
  inset: 0;
  background: var(--kiosk-surface);
  z-index: 180;
}

.slide-up-enter-active,
.slide-up-leave-active {
  transition: transform 0.32s cubic-bezier(0.4, 0, 0.2, 1);
}

.slide-up-enter-from,
.slide-up-leave-to {
  transform: translateY(100%);
}

/* =============================================================================
   CV1-KIOSK-VISUAL-REDESIGN-001 V2.C — Bold Appétissant overrides
   Plan : §9.2.3
   Refonte chirurgicale : redéclare les classes les plus visibles avec
   --kiosk-bold-* tokens. Aucun changement de template, layout, dimensions
   ou data-testid — les sentinels Playwright/Vitest restent verts.
   Light + dark via cascade [data-kiosk-theme='dark'].
   Bug categories black à l'écran : pré-existant (tenant sans menu seedé /
   route guard), non causé par ce override CSS — vérifié par revert/test.
   ============================================================================= */
.kiosk-catalogue {
  background: var(--kiosk-bold-bg, #FFF8F1);
  color: var(--kiosk-bold-text-primary, #1A1410);
}

/* HEADER — sticky brand + breadcrumb + top chips */
.kiosk-catalogue-header {
  background: var(--kiosk-bold-surface, #FFFFFF);
  border-bottom: 1px solid var(--kiosk-bold-border, #E8DDD4);
  box-shadow: var(--kiosk-shadow-sticky-bold, 0 -8px 24px rgba(26, 20, 16, 0.06));
}

.kiosk-brand-thumb-wrap {
  background: var(--kiosk-bold-surface-subtle, #FBF2E6);
  box-shadow: var(--kiosk-shadow-card-bold, 0 4px 16px rgba(26, 20, 16, 0.08));
}

.kiosk-breadcrumb-muted {
  font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
  color: var(--kiosk-bold-text-secondary, #6B5D52);
  font-weight: var(--kiosk-font-weight-bold, 700);
}

.kiosk-breadcrumb-current {
  font-family: var(--kiosk-font-display, 'Fraunces', Georgia, serif);
  font-weight: var(--kiosk-display-weight-black, 900);
  color: var(--kiosk-bold-text-primary, #1A1410);
  text-transform: none;
  letter-spacing: var(--kiosk-display-tracking-snug, -0.02em);
  font-variation-settings: 'opsz' 36;
}

/* TOP CHIP — compte client */
.kiosk-top-chip {
  border-color: var(--kiosk-bold-primary-dark, #DC4517);
  background: var(--kiosk-bold-primary-dark, #DC4517);
  color: #FFFFFF;
  font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
  font-weight: var(--kiosk-font-weight-bold, 700);
  letter-spacing: 0.04em;
  box-shadow: var(--kiosk-shadow-cta-bold, 0 12px 32px rgba(230, 57, 70, 0.32));
  opacity: 1;
  transition: transform var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-spring, cubic-bezier(0.34, 1.56, 0.64, 1)),
              box-shadow var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}
.kiosk-top-chip--active:hover,
.kiosk-top-chip--active:focus-visible {
  background: var(--kiosk-bold-primary-hover, #97000D);
  box-shadow: var(--kiosk-shadow-cta-bold-hover, 0 16px 40px rgba(230, 57, 70, 0.42));
  transform: translateY(-2px) scale(1.03);
}

/* CACHE BANNER */
.kiosk-cache-banner {
  background: var(--kiosk-bold-warning-soft, #FEF3C7);
  border-bottom-color: var(--kiosk-bold-warning, #F59E0B);
  color: var(--kiosk-bold-warning-text, #92400E);
  font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
  font-weight: var(--kiosk-font-weight-bold, 700);
}

/* LOADING + EMPTY */
.kiosk-catalogue-loading,
.kiosk-catalogue-empty {
  color: var(--kiosk-bold-text-secondary, #6B5D52);
}
.kiosk-spinner {
  border-color: var(--kiosk-bold-border, #E8DDD4);
  border-top-color: var(--kiosk-bold-primary, #E63946);
}

/* PRODUCT ZONE HEADER */
.kiosk-zone-title {
  font-family: var(--kiosk-font-display, 'Fraunces', Georgia, serif);
  font-weight: var(--kiosk-display-weight-black, 900);
  color: var(--kiosk-bold-text-primary, #1A1410);
  letter-spacing: var(--kiosk-display-tracking-snug, -0.02em);
}
.kiosk-zone-subtitle {
  font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
  color: var(--kiosk-bold-text-secondary, #6B5D52);
  font-weight: var(--kiosk-font-weight-medium, 500);
}

/* PRODUCT CARDS */
.kiosk-product-card {
  background: var(--kiosk-bold-surface, #FFFFFF);
  border: 2px solid var(--kiosk-bold-border, #E8DDD4);
  box-shadow: var(--kiosk-shadow-card-bold, 0 4px 16px rgba(26, 20, 16, 0.08));
  transition: transform var(--kiosk-duration-card, 240ms) var(--kiosk-motion-spring, cubic-bezier(0.34, 1.56, 0.64, 1)),
              box-shadow var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1)),
              border-color var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}
.kiosk-product-card:hover:not(.kiosk-product-card--filtered-out) {
  transform: translateY(-3px);
  box-shadow: var(--kiosk-shadow-card-bold-hover, 0 8px 24px rgba(26, 20, 16, 0.12));
  border-color: var(--kiosk-bold-primary, #E63946);
}

.kiosk-product-name {
  font-family: var(--kiosk-font-display, 'Fraunces', Georgia, serif);
  font-weight: var(--kiosk-display-weight-bold, 700);
  color: var(--kiosk-bold-text-primary, #1A1410);
  letter-spacing: -0.01em;
}

.kiosk-product-desc {
  font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
  color: var(--kiosk-bold-text-secondary, #6B5D52);
  font-weight: var(--kiosk-font-weight-medium, 500);
}

.kiosk-product-price {
  font-family: var(--kiosk-font-display, 'Fraunces', Georgia, serif);
  font-weight: var(--kiosk-display-weight-black, 900);
  color: var(--kiosk-bold-primary, #E63946);
  letter-spacing: var(--kiosk-display-tracking-snug, -0.02em);
}

.kiosk-product-add {
  background: var(--kiosk-bold-primary, #E63946);
  color: var(--kiosk-bold-text-on-primary, #FFF5E8);
  border: 0;
  box-shadow: var(--kiosk-shadow-cta-bold, 0 12px 32px rgba(230, 57, 70, 0.32));
  transition: transform var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-spring, cubic-bezier(0.34, 1.56, 0.64, 1)),
              background var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}
.kiosk-product-add:hover:not(:disabled) {
  background: var(--kiosk-bold-primary-hover, #DC4517);
  transform: scale(1.08);
}
.kiosk-product-add:active:not(:disabled) {
  transform: scale(0.94);
}

.kiosk-product-badge {
  background: var(--kiosk-bold-accent, #FFB627);
  color: var(--kiosk-bold-text-on-accent, #1A1410);
  font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
  font-weight: var(--kiosk-font-weight-bold, 700);
}

.kiosk-product-image-fallback {
  background: var(--kiosk-bold-surface-subtle, #FBF2E6);
}

/* BOTTOM BAR */
.kiosk-bottom-bar {
  background: var(--kiosk-bold-surface, #FFFFFF);
  border-top: 1px solid var(--kiosk-bold-border, #E8DDD4);
  box-shadow: var(--kiosk-shadow-sticky-bold, 0 -8px 24px rgba(26, 20, 16, 0.06));
}

.kiosk-bottom-cart {
  background: var(--kiosk-bold-surface-subtle, #FBF2E6);
  color: var(--kiosk-bold-text-primary, #1A1410);
  border: 1px solid var(--kiosk-bold-border, #E8DDD4);
  font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
  font-weight: var(--kiosk-font-weight-bold, 700);
}
.kiosk-bottom-cart:not(:disabled):hover {
  background: var(--kiosk-bold-surface, #FFFFFF);
  border-color: var(--kiosk-bold-text-primary, #1A1410);
}

.kiosk-bottom-total {
  font-family: var(--kiosk-font-display, 'Fraunces', Georgia, serif);
  font-weight: var(--kiosk-display-weight-black, 900);
  font-size: calc(32px * var(--kiosk-text-scale, 1));
  color: var(--kiosk-bold-primary, #E63946);
  letter-spacing: var(--kiosk-display-tracking-snug, -0.02em);
  font-variation-settings: 'opsz' 32;
}

.kiosk-bottom-abandon {
  color: var(--kiosk-bold-text-secondary, #6B5D52);
  font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
  font-weight: var(--kiosk-font-weight-bold, 700);
  background: transparent;
  border: 2px solid var(--kiosk-bold-border-strong, #1A1410);
}
.kiosk-bottom-abandon:hover {
  background: var(--kiosk-bold-text-primary, #1A1410);
  color: var(--kiosk-bold-text-inverse, #FFF5E8);
}

.kiosk-bottom-pay {
  background: var(--kiosk-bold-primary, #E63946);
  color: var(--kiosk-bold-text-on-primary, #FFF5E8);
  border: 0;
  font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
  font-weight: var(--kiosk-font-weight-black, 900);
  letter-spacing: 0.04em;
  box-shadow: var(--kiosk-shadow-cta-bold, 0 12px 32px rgba(230, 57, 70, 0.32));
  transition: transform var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-spring, cubic-bezier(0.34, 1.56, 0.64, 1)),
              background var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1)),
              box-shadow var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}
.kiosk-bottom-pay:not(:disabled):hover {
  background: var(--kiosk-bold-primary-hover, #DC4517);
  transform: translateY(-2px);
  box-shadow: var(--kiosk-shadow-cta-bold-hover, 0 16px 40px rgba(230, 57, 70, 0.42));
}
.kiosk-bottom-pay:disabled {
  background: var(--kiosk-bold-text-tertiary, #9C8C7E);
  box-shadow: none;
}

/* Reduced motion guard */
[data-kiosk-reduced-motion='true'] .kiosk-product-card,
[data-kiosk-reduced-motion='true'] .kiosk-top-chip,
[data-kiosk-reduced-motion='true'] .kiosk-product-add,
[data-kiosk-reduced-motion='true'] .kiosk-bottom-pay {
  transition: none;
  transform: none !important;
}
</style>
