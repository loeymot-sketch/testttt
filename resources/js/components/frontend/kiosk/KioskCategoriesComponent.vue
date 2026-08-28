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

              <!-- Le message doit être VU par le client, donc dans la zone
                   produits qu'il regarde — pas dans la branche « catalogue
                   vide », qui ne se rend jamais quand il y a des produits. -->
              <div v-if="itemError" class="kiosk-item-error" role="alert" data-testid="kiosk-item-error">
                <span class="kiosk-item-error-icon" aria-hidden="true">📡</span>
                <p>{{ $t('kiosk.catalog.item_options_error', { name: itemError }) }}</p>
              </div>
              <div class="kiosk-product-grid" :class="productGridLayoutClass" :style="productGridStyle" role="list">
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
                      @keydown.enter.stop.prevent="onProductCardActivate(product, $event)"
                      @keydown.space.stop.prevent="onProductCardActivate(product, $event)"
                      :disabled="!!loadingItemId || !isProductCatalogAllowed(product)"
                      :aria-label="$t('kiosk.catalog.add', { name: sanitizeItemName(product.name) })"
                      :aria-describedby="`kiosk-product-meta-${product.id}`"
                      :data-testid="`kiosk-product-add-${product.id}`">
                      <span v-if="loadingItemId === product.id" class="kiosk-product-add-spinner" aria-hidden="true"></span>
                      <span v-else aria-hidden="true">+</span>
                    </button>
                  </div>

                  <div class="kiosk-product-copy" :id="`kiosk-product-meta-${product.id}`">
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
      // Nom du produit dont les options n'ont pas pu être chargées, après DEUX
      // tentatives. Non nul = le message est affiché et RIEN n'a été ajouté.
      itemError: null,
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
    //
    // [OWNER 2026-08-24] UNE SEULE COLONNE, TOUTES CATÉGORIES CONFONDUES.
    // Le propriétaire : « je voulais toujours les produits prennent la taille
    // complète de la borne […] pas juste des petits produits ». Les deux colonnes
    // donnaient des cartes de 370 px sur un écran de 1080 (un tiers de la largeur),
    // et un nombre IMPAIR de produits laissait un trou : mesuré à 3 tacos, la
    // grille affichait 2 + 1 avec une case vide et ~40 % de l'écran blanc dessous.
    // Chaque produit occupe désormais toute la largeur ; seule la HAUTEUR varie
    // selon le nombre, pour que 3 produits remplissent l'écran sans qu'une
    // catégorie de 15 boissons devienne 15 écrans de défilement.
    // [OWNER 2026-08-25] « ça affiche selon le nombre d'article, TOUS les produits, pas que 3 ».
    // La hauteur n'est plus choisie par paliers : elle est CALCULÉE à partir du nombre
    // d'articles (cf. --kiosk-produits, posé par productGridStyle) pour que la catégorie
    // entière tienne dans l'écran, sans défilement, qu'elle contienne 2 bols ou 15 boissons.
    //
    // Au-delà de 3 produits, la carte bascule en HORIZONTAL (photo à gauche). Ce n'est pas
    // une préférence : à 6 produits la carte ne fait plus que ~245 px de haut, et une photo
    // EMPILÉE au-dessus du texte ne laisse alors de place ni à l'une ni à l'autre. Couchée,
    // la photo garde toute la hauteur de la carte et le texte toute la largeur restante.
    productGridLayoutClass() {
      const n = this.catalogProducts.length;
      if (n <= 1) return 'kiosk-product-grid--solo';
      if (n === 2) return 'kiosk-product-grid--duo';
      if (n === 3) return 'kiosk-product-grid--trio';
      if (n <= 9) return 'kiosk-product-grid--dense';
      // Au-delà de 9, la carte passe sous ~140 px : le nom, les pastilles de régime,
      // la description ET le prix ne tiennent plus ensemble. Mesuré sur les 15 boissons :
      // c'est le PRIX qui passait sous le bord et disparaissait. Un produit sans prix
      // affiché sur une borne n'est pas acceptable — on allège le reste, jamais le prix.
      return ['kiosk-product-grid--dense', 'kiosk-product-grid--minimal'];
    },
    /** Expose le nombre d'articles au CSS — c'est lui qui divise la hauteur disponible. */
    productGridStyle() {
      return { '--kiosk-produits': String(Math.max(1, this.catalogProducts.length)) };
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
    /**
     * [OWNER 2026-08-24] Un CRAN PAR TAILLE, pas deux paliers pour quatre tailles.
     *
     * Jusqu'ici `--size-l` couvrait L, XL ET XXL : mesuré sur la borne (1080×1920),
     * l'image du Tacos L et celle du Tacos XL faisaient toutes deux 366×355 px —
     * strictement identiques. Le client voyait donc « L = XL » alors que le XL est
     * le plus grand, et payait 2 € de plus sans rien voir de différent.
     * Le propriétaire : « entre le M le L et le XL ça doit être visiblement […]
     * avec l'œil on fera la différence entre les tailles ».
     *
     * `maxi` / `grande` / `large` restent volontairement au cran L : ce sont des
     * libellés flous (une « grande frite » n'est pas un XL), et les promouvoir
     * changerait des produits que personne n'a demandé de toucher.
     */
    productSizeClass(product) {
      const name = String(product?.name || '').trim().toLowerCase();
      const last = name.split(/\s+/).pop();
      if (last === 'xxl') {
        return 'kiosk-product-image--size-xxl';
      }
      if (last === 'xl') {
        return 'kiosk-product-image--size-xl';
      }
      if (last === 'l' || /\b(grande?|large|maxi)$/.test(name)) {
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
      // [REPLAN_8 2026-08-24] `.prevent` sur `keydown.space` déplace l'activation du keyup natif
      // vers le keydown : un bouton natif ne répète PAS sur Espace maintenu, un handler keydown
      // si. Sans cette garde, un doigt posé sur la barre d'espace ajouterait le produit autant de
      // fois que le clavier répète. `repeat` distingue la frappe des répétitions automatiques.
      if (evt && evt.repeat) {
        if (typeof evt.preventDefault === 'function') evt.preventDefault();
        return;
      }
      // Le clic est déjà gardé par `:disabled="!!loadingItemId"`, mais la carte extérieure
      // (`@click` ligne 166) ne l'est pas : on refuse toute activation pendant un chargement en
      // cours, quel que soit le chemin d'entrée.
      if (this.loadingItemId) {
        if (evt && typeof evt.preventDefault === 'function') evt.preventDefault();
        return;
      }
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
      // [FIX 2026-08-08] Cet appel dit si le produit se compose ou non. Tant
      // qu'il n'a pas répondu, on NE SAIT PAS — et on ne doit donc rien mettre
      // au panier. L'ancien `catch` ajoutait l'article directement : un Suprême
      // partait à 7,00 € sans pain, sans sauce et sans viande, sans un mot au
      // client, qui pouvait valider. La cuisine recevait une commande
      // impossible à préparer. Reproduit en coupant cet appel : panier 0 → 1,
      // wizard jamais ouvert, aucun message.
      //
      // Le premier échec est le plus souvent un 401 « jeton expiré » : la borne
      // se reconnecte toute seule dans la foulée (kiosk-login), donc une
      // deuxième tentative suffit. Si elle échoue aussi, on le DIT et on
      // n'ajoute rien : mieux vaut un client qui retouche l'écran qu'une
      // commande impossible en cuisine.
      const charger = async () => {
        const res = await this.$store.dispatch('frontendItem/details', {
          // Même contrat que KioskWizard (surface=kiosk) : variations/extras filtrés borne
          id: product.id,
          surface: 'kiosk',
        });
        return res?.data?.data || res?.data || null;
      };
      const traiter = (detail) => {
        if (this.hasOptions(detail)) {
          this.activeItem = detail;
        } else {
          this.addItem(this.buildSimpleCartItem(detail));
          // FoodKing brand V2 (2026-05-10) — owner request : pas de toast sur
          // add-to-cart, le KsCartBottomSheet rend l'ajout visible directement.
        }
      };
      try {
        this.itemError = null;
        const detail = await charger();
        if (!detail) throw new Error('details vides');
        traiter(detail);
      } catch (_) {
        try {
          await new Promise((r) => setTimeout(r, 600)); // laisse la reconnexion aboutir
          const detail = await charger();
          if (!detail) throw new Error('details vides');
          traiter(detail);
        } catch (_) {
          // Aucun ajout : on ignore ce que le produit exige comme choix.
          this.itemError = product.name || '';
        }
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

.kiosk-item-error {
  display: flex; align-items: center; gap: 14px;
  margin: 0 0 14px; padding: 16px 20px;
  background: #FFF4EF; border: 2px solid #F4501E; border-radius: 14px;
  color: #1A1A1A; font-size: 20px; line-height: 1.35;
}
.kiosk-item-error p { margin: 0; }
.kiosk-item-error-icon { font-size: 30px; }

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

/* [OWNER 2026-08-24] Une seule colonne, quelle que soit la catégorie : chaque
   produit occupe TOUTE la largeur de la borne. Les 2 colonnes rendaient des
   cartes de 370 px sur 1080 et laissaient une case vide dès que le nombre de
   produits était impair. */
.kiosk-product-grid {
  /* [OWNER 2026-08-25] Nombre d'articles de la catégorie, posé par productGridStyle.
     Le 3 n'est qu'un repli si le style inline manque (test unitaire, rendu partiel). */
  --kiosk-produits: 3;
  /* Hauteur utile entre le haut de la grille et la barre du panier — MESURÉE sur la
     borne (1592 px sur 1920), pas estimée. On garde 1 % de marge : à 82,9 vh pile,
     l'arrondi des sous-pixels faisait dépasser la dernière carte de 2 px, et le
     dernier produit disparaissait sous la barre du panier. */
  --kiosk-zone: 82vh;
  /* L'espacement se resserre quand les produits se multiplient : à 15 boissons,
     14 intervalles de 24 px mangeraient 336 px, soit un cinquième de l'écran. */
  --kiosk-gap: max(10px, calc(24px - (var(--kiosk-produits) - 3) * 1.5px));

  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--kiosk-gap);
}

/* [OWNER 2026-08-25] LA HAUTEUR SE CALCULE, ELLE NE SE CHOISIT PLUS PAR PALIERS.
   Le propriétaire : « ça affiche selon le nombre d'article, TOUS les produits, pas
   que 3 ». Les paliers fixes de la veille laissaient 3 produits à l'écran quelle que
   soit la catégorie — donc Sandwichs (5), Burgers (6), Frites (6) et Boissons (15)
   obligeaient à faire défiler pour découvrir la carte.
   Une seule formule : la hauteur utile MOINS les intervalles, divisée par le nombre
   d'articles. Une catégorie entière tient toujours dans l'écran. */
/* 1 à 3 produits : carte VERTICALE, grande photo au-dessus du texte. */
.kiosk-product-grid--solo .kiosk-product-media,
.kiosk-product-grid--duo .kiosk-product-media,
.kiosk-product-grid--trio .kiosk-product-media { height: 64%; }

/* 4 produits et plus : la photo passe À GAUCHE.
   Ce n'est pas un goût : à 6 produits la carte tombe à ~245 px de haut, et une photo
   EMPILÉE au-dessus du texte ne laisse alors de place ni à la photo ni au texte.
   Couchée, elle garde toute la hauteur de la carte, et le texte toute la largeur. */
.kiosk-product-grid--dense .kiosk-product-card {
  display: flex;
  align-items: center;
  gap: clamp(12px, 2vw, 24px);
  padding: 12px 96px 12px 14px;   /* la marge droite réserve la place du bouton + */
}
.kiosk-product-grid--dense .kiosk-product-media {
  flex: 0 0 auto;
  /* La photo produit est en 3:2. Dans une colonne à largeur fixe elle se retrouve
     bridée par la LARGEUR et flotte au milieu du vide : mesuré, 160 px de photo dans
     une carte de 298. On donne donc à la boîte le rapport de la photo et on la laisse
     prendre toute la hauteur — c'est la hauteur qui commande, la largeur suit. */
  height: 86%;
  width: auto;
  aspect-ratio: 3 / 2;
  max-width: 46%;
  border-radius: 22px;
  /* Rendue NON positionnée pour que le bouton + s'ancre à la CARTE : ancré à la photo,
     il se posait en plein milieu du visuel. */
  position: static;
}
.kiosk-product-grid--dense .kiosk-product-copy {
  flex: 1 1 auto;
  min-width: 0;                    /* sans quoi un nom long pousse la carte hors écran */
}
/* 10 produits et plus : on n'affiche plus que ce qui sert à CHOISIR — le nom et le
   prix. Les pastilles de régime, les allergènes et la description sont masqués : à
   96 px de haut ils poussaient le prix hors de la carte, et `overflow: hidden` le
   coupait net. Le détail reste accessible en ouvrant le produit. */
.kiosk-product-grid--minimal .kiosk-product-flag-row,
.kiosk-product-grid--minimal .kiosk-product-desc,
.kiosk-product-grid--minimal .ks-allergen-badge {
  display: none;
}
.kiosk-product-grid--minimal .kiosk-product-copy {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 20px;
}
.kiosk-product-grid--minimal .kiosk-product-name {
  margin: 0;
}

/* Le bouton + était ancré à la PHOTO : couché, il se serait posé au milieu du texte.
   On l'ancre à la carte, centré sur le bord droit — même geste, même cible tactile. */
.kiosk-product-grid--dense .kiosk-product-add {
  position: absolute;
  inset-block-start: 50%;
  inset-inline-end: 18px;
  transform: translateY(-50%);
  bottom: auto;
}

/* [BORNE-UX 2026-07-11 #3] Différence de taille visible entre variantes.
   [OWNER 2026-08-24] Quatre crans au lieu de deux : le L et le XL partageaient
   `--size-l` et sortaient au pixel près à la même taille (366×355 mesurés sur
   les deux). Les valeurs restent SOUS 1 : l'image occupe déjà 94 % de sa boîte,
   et la carte est en `overflow: hidden` — au-delà, le tacos serait rogné.
   L'écart entre deux crans consécutifs est d'environ +15 %, visible à l'œil. */
.kiosk-product-image--size-xxl { transform: scale(1.06); }
.kiosk-product-image--size-xl  { transform: scale(0.98); }
.kiosk-product-image--size-l   { transform: scale(0.85); }
.kiosk-product-image--size-m   { transform: scale(0.72); }

.kiosk-product-card {
  position: relative;
  /* [OWNER 2026-08-25] La hauteur se CALCULE sur le nombre d'articles pour que la
     catégorie entière tienne dans l'écran : hauteur utile − intervalles, divisé par le
     nombre de produits. Les 392 px fixes d'avant laissaient 3 produits visibles quelle
     que soit la catégorie — donc 15 boissons demandaient cinq écrans de défilement. */
  /* HAUTEUR FERME, pas un plancher : `min-height` laissait le contenu repousser la
     carte (mesuré : 602 px pour une cible de 514), et trois produits débordaient de
     l'écran. Une hauteur explicite permet aussi aux enfants en % de se résoudre. */
  height: calc(
    (var(--kiosk-zone, 82vh) - (var(--kiosk-produits, 3) - 1) * var(--kiosk-gap, 24px))
    / var(--kiosk-produits, 3)
  );
  min-height: 0;
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

/* Le contrôle natif « ajouter » fournit le parcours clavier de toute carte. */
.kiosk-product-add:focus-visible {
  outline: var(--kiosk-focus-width) solid var(--kiosk-focus-ring);
  outline-offset: 5px;
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
