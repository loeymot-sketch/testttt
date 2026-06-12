<template>
  <div class="kiosk-cart" data-testid="kiosk-cart-root">
    <!-- Header -->
    <div class="kiosk-cart-header">
      <button type="button"
        class="kiosk-cart-back"
        @click="goBackFromCart"
        :aria-label="$t('kiosk.back')"
        data-testid="kiosk-cart-back">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <div class="kiosk-cart-header-info">
        <h1 class="kiosk-cart-title" data-testid="kiosk-cart-title">{{ $t('kiosk.your_cart') }}</h1>
        <p class="kiosk-cart-item-count" data-testid="kiosk-cart-count">
          {{ cartCount }} {{ cartCount > 1 ? $t('kiosk.article_plural') : $t('kiosk.article_singular') }}
        </p>
      </div>
      <button type="button"
        class="kiosk-cart-clear"
        @click="showClearConfirm = true"
        v-if="cartCount > 0"
        data-testid="kiosk-cart-clear"
      >
        {{ $t('kiosk.clear_cart') }}
      </button>
    </div>

    <!-- Modal : confirmer vider le panier -->
    <transition name="fade">
      <div
        v-if="showClearConfirm"
        class="kiosk-clear-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="kiosk-cart-clear-title"
        data-testid="kiosk-cart-clear-modal"
        @click.self="showClearConfirm = false"
        @keydown.esc="showClearConfirm = false"
      >
        <div class="kiosk-clear-modal">
          <p id="kiosk-cart-clear-title" class="kiosk-clear-title">{{ $t('kiosk.clear_cart') }}</p>
          <p class="kiosk-clear-sub">{{ $t('kiosk.clear_cart_confirm') }}</p>
          <div class="kiosk-clear-actions">
            <button type="button"
              class="kiosk-clear-yes"
              @click="confirmClear"
              data-testid="kiosk-cart-clear-yes"
            >{{ $t('kiosk.yes_clear') }}</button>
            <button type="button"
              class="kiosk-clear-no"
              @click="showClearConfirm = false"
              data-testid="kiosk-cart-clear-no"
            >{{ $t('kiosk.cancel') }}</button>
          </div>
        </div>
      </div>
    </transition>

    <!-- Panier vide -->
    <div
      v-if="cartCount === 0"
      class="kiosk-cart-empty"
      role="status"
      aria-live="polite"
      data-testid="kiosk-cart-empty"
    >
      <div class="kiosk-cart-empty-icon" aria-hidden="true">🛒</div>
      <h2>{{ $t('kiosk.empty_cart') }}</h2>
      <p>{{ $t('kiosk.empty_cart_hint') }}</p>
      <button type="button"
        class="kiosk-btn-primary"
        @click="$router.push({ name: 'kiosk.categories' })"
        data-testid="kiosk-cart-empty-cta"
      >
        {{ $t('kiosk.add_items') }}
      </button>
    </div>

    <!-- [GAP-22-1] Sélecteur Sur place / À emporter — inspiré Splash -->
    <div
      v-if="cartCount > 0"
      class="kiosk-order-type-bar"
      role="radiogroup"
      :aria-label="$t('kiosk.order_type_label')"
      data-testid="kiosk-cart-order-type"
    >
      <!--
        [wave-p-kiosk-2026-05-20 BORNE-001 heal] V1 dine-in gate.
        Mirrors KioskIdleScreenComponent + PosComponent v-if="dineInEnabled"
        per feedback_v1_dine_in_disabled_2026-05-06. The "Sur place" tile must
        stay hidden until V2 floorplan ships — backend OrderRequest:213
        rejects KIOSK order_type when pos_dine_in_enabled=false, so leaving
        this button clickable creates a guaranteed 422 UX dead-end on V1.
      -->
      <button v-if="dineInEnabled" type="button"
        class="kiosk-order-type-btn"
        :class="{ active: orderType === ORDER_TYPE_KIOSK }"
        role="radio"
        :aria-checked="orderType === ORDER_TYPE_KIOSK"
        data-testid="kiosk-cart-order-type-dinein"
        @click="selectOrderType(ORDER_TYPE_KIOSK)"
      >
        <span class="kiosk-order-type-icon" aria-hidden="true">🍽️</span>
        <span class="kiosk-order-type-label">{{ $t('kiosk.dine_in') }}</span>
      </button>
      <button type="button"
        class="kiosk-order-type-btn"
        :class="{ active: orderType === ORDER_TYPE_TAKEAWAY }"
        role="radio"
        :aria-checked="orderType === ORDER_TYPE_TAKEAWAY"
        data-testid="kiosk-cart-order-type-takeaway"
        @click="selectOrderType(ORDER_TYPE_TAKEAWAY)"
      >
        <span class="kiosk-order-type-icon" aria-hidden="true">🥡</span>
        <span class="kiosk-order-type-label">{{ $t('kiosk.takeaway') }}</span>
      </button>
    </div>

    <!-- Liste articles -->
    <div v-if="cartCount > 0" class="kiosk-cart-body">
      <div class="kiosk-cart-items" role="list" data-testid="kiosk-cart-items">
        <!-- [FIX] Use stable composite key instead of array index to avoid re-render issues -->
        <div
          v-for="(item, idx) in cartItems"
          :key="item.item_id ? `${item.item_id}-${idx}` : idx"
          class="kiosk-cart-item"
          role="listitem"
          :data-testid="`kiosk-cart-item-${idx}`"
        >
          <!-- Image -->
          <div class="kiosk-cart-item-img" aria-hidden="true">
            <img v-if="item.image" :src="item.image" :alt="''" />
            <span v-else class="kiosk-cart-item-emoji">🍽️</span>
          </div>

          <!-- Infos + bouton édition -->
          <div class="kiosk-cart-item-info">
            <div class="kiosk-cart-item-name-row">
              <h3 class="kiosk-cart-item-name" :data-testid="`kiosk-cart-item-name-${idx}`">{{ displayCartItemName(item) }}</h3>
              <!-- Edit: retire l'article et rouvre le wizard pour le même produit -->
              <button type="button"
                v-if="item.item_id"
                class="kiosk-cart-edit-btn"
                @click="editItem(idx)"
                :aria-label="$t('kiosk.edit_item_aria')"
                :data-testid="`kiosk-cart-item-edit-${idx}`"
              >
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                  <path d="M11.333 2a1.885 1.885 0 0 1 2.667 2.667L5.333 13.333 2 14l.667-3.333L11.333 2Z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            </div>
            <!-- [GAP-22-2] Afficher les sélections wizard (variations, extras) -->
            <div
              v-if="getItemSelectionSummary(item)"
              class="kiosk-cart-item-selections"
              :data-testid="`kiosk-cart-item-options-${idx}`"
            >
              {{ getItemSelectionSummary(item) }}
            </div>
            <KsAllergenBadge
              v-if="cartLineCatalogItem(item)"
              class="kiosk-cart-item-allergens"
              :item="cartLineCatalogItem(item)"
              :selections="cartLineAllergenSelections(item)"
              :allergens="[]"
              :customer-allergens="customerAllergenCodes"
              :data-testid="`kiosk-cart-item-allergens-${idx}`"
            />
            <p
              v-if="item.instruction"
              class="kiosk-cart-item-note"
              :data-testid="`kiosk-cart-item-note-${idx}`"
            >{{ displayCartInstruction(item) }}</p>
            <span class="kiosk-cart-item-unit">
              {{ formatPrice((parseFloat(item.convert_price) || 0) + (item.item_variation_total || 0) + (item.item_extra_total || 0)) }}
              {{ $t('kiosk.per_unit') }}
            </span>
          </div>

          <!-- Contrôles quantité + suppression -->
          <div class="kiosk-cart-item-controls">
            <div
              class="kiosk-qty-ctrl"
              role="group"
              :aria-label="$t('kiosk.quantity_of', { name: displayCartItemName(item) })"
            >
              <button type="button"
                class="kiosk-qty-btn minus"
                @click="changeQty(idx, item.quantity - 1)"
                :aria-label="$t('kiosk.decrease_qty')"
                :data-testid="`kiosk-cart-item-qty-minus-${idx}`"
              >
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                  <path d="M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
              </button>
              <span
                class="kiosk-qty-num"
                aria-live="polite"
                :data-testid="`kiosk-cart-item-qty-${idx}`"
              >{{ item.quantity }}</span>
              <button type="button"
                class="kiosk-qty-btn plus"
                :disabled="item.quantity >= maxItemQty"
                @click="changeQty(idx, item.quantity + 1)"
                :aria-label="$t('kiosk.increase_qty')"
                :data-testid="`kiosk-cart-item-qty-plus-${idx}`"
              >
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                  <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
              </button>
            </div>
            <!-- FoodKing brand V2 (2026-05-10) — bouton delete explicite (owner
                 demande "really see + add/delete correctement"). Decrement à
                 qty=1 supprime aussi via changeQty, ce bouton donne une voie
                 directe sans nécessiter de tap répété. -->
            <button type="button"
              class="kiosk-cart-item-trash"
              @click="removeItemDirectly(idx)"
              :aria-label="$t('kiosk.remove_item') || 'Supprimer cet article'"
              :data-testid="`kiosk-cart-item-remove-${idx}`"
            >
              <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <path d="M3 6h14M8 6V4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2m1 0v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6h10Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M9 10v4M11 10v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
              </svg>
            </button>
            <!-- [KIOSK-17] item.total is always present (computed by ADD_ITEM / UPDATE_QUANTITY) -->
            <span
              class="kiosk-cart-item-total"
              :data-testid="`kiosk-cart-item-total-${idx}`"
            >
              {{ formatPrice(item.total) }}
            </span>
          </div>
        </div>
      </div>

      <!-- Récapitulatif totaux -->
      <div
        class="kiosk-cart-summary"
        role="region"
        :aria-label="$t('kiosk.subtotal')"
        aria-live="polite"
        data-testid="kiosk-cart-summary"
      >
        <div class="kiosk-cart-summary-row">
          <span>{{ $t('kiosk.subtotal') }}</span>
          <span data-testid="kiosk-cart-subtotal">{{ formatPrice(cartSubtotal) }}</span>
        </div>
        <div class="kiosk-cart-summary-row loyalty" v-if="loyaltyDiscount > 0">
          <span><span aria-hidden="true">🎁</span> {{ $t('kiosk.discount_loyalty') }}</span>
          <span class="green" data-testid="kiosk-cart-loyalty-discount">-{{ formatPrice(loyaltyDiscount) }}</span>
        </div>
        <!-- Kiosk Phase 9.1.6 — Ligne discount promo, ne s'affiche que si appliquée. -->
        <div class="kiosk-cart-summary-row promo" v-if="promoDiscount > 0">
          <span><span aria-hidden="true">🏷️</span> {{ $t('kiosk.discount_promo', { code: promoCode }) }}</span>
          <span class="green" data-testid="kiosk-cart-promo-discount">-{{ formatPrice(promoDiscount) }}</span>
        </div>
        <div class="kiosk-cart-summary-row total">
          <span>{{ $t('kiosk.total') }}</span>
          <span
            class="kiosk-cart-grand-total"
            data-testid="kiosk-cart-total"
          >{{ formatPrice(cartTotal) }}</span>
        </div>
      </div>

      <!-- Kiosk Phase 9.1.6 — Champ code promo (SSOT lecture-seule, revalidé
           serveur à /order). Affiche success / error inline. -->
      <div v-if="discountsEnabled" class="kiosk-cart-promo" data-testid="kiosk-cart-promo">
        <div v-if="!promoCode" class="kiosk-cart-promo-form">
          <label for="kiosk-cart-promo-input" class="kiosk-cart-promo-label">
            {{ $t('kiosk.promo.label') }}
          </label>
          <div class="kiosk-cart-promo-row">
            <input
              id="kiosk-cart-promo-input"
              v-model="promoInput"
              type="text"
              autocomplete="off"
              maxlength="64"
              class="kiosk-cart-promo-input"
              :placeholder="$t('kiosk.promo.placeholder')"
              :aria-invalid="!!promoError"
              :aria-describedby="promoError ? 'kiosk-cart-promo-error' : null"
              :disabled="promoLoading"
              data-testid="kiosk-cart-promo-input"
              @keydown.enter.prevent="applyPromo"
            />
            <button type="button"
              class="kiosk-cart-promo-apply"
              :disabled="promoLoading || !promoInput.trim()"
              data-testid="kiosk-cart-promo-apply"
              @click="applyPromo">
              {{ promoLoading ? $t('kiosk.promo.loading') : $t('kiosk.promo.apply') }}
            </button>
          </div>
          <p
            v-if="promoError"
            id="kiosk-cart-promo-error"
            class="kiosk-cart-promo-error"
            role="alert"
            data-testid="kiosk-cart-promo-error"
          >
            {{ $te(promoError) ? $t(promoError) : promoError }}
          </p>
        </div>
        <div v-else class="kiosk-cart-promo-applied" data-testid="kiosk-cart-promo-applied">
          <span class="kiosk-cart-promo-applied-icon" aria-hidden="true">✓</span>
          <span class="kiosk-cart-promo-applied-text">
            {{ $t('kiosk.promo.applied', { code: promoCode, amount: formatPrice(promoDiscount) }) }}
          </span>
          <button type="button"
            class="kiosk-cart-promo-remove"
            data-testid="kiosk-cart-promo-remove"
            @click="removePromo">
            {{ $t('kiosk.promo.remove') }}
          </button>
        </div>
      </div>

      <!-- Bouton fidélité — masqué quand les remises sont désactivées (V1 F1-dormancy) -->
      <button v-if="discountsEnabled" type="button"
        class="kiosk-btn-loyalty"
        @click="$router.push({ name: 'kiosk.loyalty' })"
        data-testid="kiosk-cart-loyalty-btn"
      >
        <span class="kiosk-btn-loyalty-star" aria-hidden="true">★</span>
        <span v-if="loyaltyDiscount > 0">{{ $t('kiosk.loyalty_applied', { amount: formatPrice(loyaltyDiscount) }) }}</span>
        <span v-else>{{ $t('kiosk.loyalty_prompt') }}</span>
        <span class="kiosk-btn-loyalty-arrow" aria-hidden="true">›</span>
      </button>

      <!-- Bouton valider → upsell -->
      <div class="kiosk-cart-actions">
        <button type="button"
          class="kiosk-btn-primary full"
          @click="proceedToUpsell"
          :disabled="quoteLoading"
          :aria-busy="quoteLoading ? 'true' : 'false'"
          data-testid="kiosk-cart-checkout"
        >
          <span>{{ $t('kiosk.validate_order') }}</span>
          <span class="kiosk-btn-price">{{ formatPrice(cartTotal) }}</span>
        </button>
        <p
          v-if="quoteError"
          class="kiosk-cart-quote-error"
          role="alert"
          data-testid="kiosk-cart-quote-error"
        >
          {{ quoteError }}
        </p>
        <button type="button"
          class="kiosk-btn-secondary"
          @click="$router.push({ name: 'kiosk.categories' })"
          data-testid="kiosk-cart-add-more"
        >
          + {{ $t('kiosk.add_more_items') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { shouldSkipKioskUpsellScreen } from '../../../helpers/kioskUpsellFlow';
import { sanitizeKioskCustomerFacingText } from '../../../helpers/kioskDisplayText';
import { findVariationObjectById, findExtraObjectById } from '../../../helpers/kioskFilters';
import KsAllergenBadge from './ds/KsAllergenBadge.vue';

// [GAP-22-1] Order type constants — KIOSK=sur place, TAKEAWAY=à emporter
const ORDER_TYPE_KIOSK    = 25;
const ORDER_TYPE_TAKEAWAY = 10;

export default {
  name: 'KioskCartComponent',
  mixins: [kioskPriceMixin],
  components: { KsAllergenBadge },

  inject: {
    showToast: { default: () => () => {} },
  },

  data() {
    return {
      showClearConfirm: false,
      ORDER_TYPE_KIOSK,
      ORDER_TYPE_TAKEAWAY,
      maxItemQty: window.foodkingConfig?.maxItemQty ?? 20,
      quoteLoading: false,
      quoteError: null,
      // Kiosk Phase 9.1.6 — Champ local (pas directement dans le store) pour
      // laisser l'utilisateur taper/effacer sans triggerer de validate sur
      // chaque frappe. L'appel réseau n'est déclenché qu'au clic Appliquer.
      promoInput: '',
    };
  },

  computed: {
    ...mapGetters('kioskCart', {
      cartItems: 'items',
      cartCount: 'count',
      cartSubtotal: 'subtotal',
      cartTotal: 'total',
      loyaltyDiscount: 'loyaltyDiscount',
      upsellShown: 'upsellShown',
      orderType: 'orderType',
      promoCode: 'promoCode',
      promoDiscount: 'promoDiscount',
      promoError: 'promoError',
      promoLoading: 'promoLoading',
    }),
    ...mapGetters('kioskMenu', ['categories', 'selectedCategoryId', 'allItems']),
    ...mapGetters('frontendSetting', { frontendSettingsList: 'lists' }),
    /**
     * [wave-p-kiosk-2026-05-20 BORNE-001 heal] V1 dine-in flag.
     * Mirror of KioskIdleScreenComponent.dineInEnabled (verbatim guard
     * pattern — typeof check rejects arrays/objects before string coerce so
     * `String([1]) === '1'` cannot accidentally activate the flag).
     * Defaults to FALSE so a regressed/empty backend stays safe (V1 mandate).
     */
    dineInEnabled() {
      const s = this.frontendSettingsList || {};
      const raw = s.pos_dine_in_enabled ?? s['pos.dine_in_enabled'] ?? 0;
      const t = typeof raw;
      if (t !== 'boolean' && t !== 'number' && t !== 'string') return false;
      return String(raw) === '1' || raw === true;
    },
    /**
     * [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-31 Q2] Hide the coupon/promo form and
     * the loyalty-redeem entry while discretionary discounts are disabled in V1, so a
     * customer can't trigger the backend 422 dead-end. Exposed via
     * window.foodkingConfig.discountsEnabled (master.blade.php). Defaults to FALSE so
     * a missing/empty config stays safe (entries hidden = no dead-end).
     */
    discountsEnabled() {
      return (typeof window !== 'undefined' && window.foodkingConfig)
        ? window.foodkingConfig.discountsEnabled === true
        : false;
    },
    customerAllergenCodes() {
      const profile = this.$store?.getters?.['kioskSettings/customerProfile'];
      if (!profile || !Array.isArray(profile.declared_allergens)) return [];
      return profile.declared_allergens.map(String).filter(Boolean);
    },
    /** Phase A — skip upsell when all lines are in "skip after cart" categories */
    shouldSkipKioskUpsell() {
      return shouldSkipKioskUpsellScreen(this.cartItems, this.categories);
    },
  },
  /**
   * [wave-p-kiosk-2026-05-20 BORNE-001 heal] Ensure frontendSetting/lists is
   * populated so dineInEnabled computed can read pos_dine_in_enabled.
   * KioskAppComponent loads via raw axios into globalState, NOT into the
   * Vuex frontendSetting module — so the cart must dispatch independently.
   * Best-effort: swallow errors and let the default (false) hold.
   */
  mounted() {
    try {
      const current = this.$store?.getters?.['frontendSetting/lists'];
      if (!current || (Array.isArray(current) && current.length === 0)) {
        this.$store?.dispatch?.('frontendSetting/lists').catch(() => {});
      }
    } catch (_e) { /* defaults to dineInEnabled=false — safe */ }
    // [dispute-r1 C-ADV-02 2026-06-12] Restaure le code promo persisté après
    // un reload (re-validation SERVEUR — le montant n'est jamais rejoué
    // localement). Best-effort : un échec laisse simplement l'inline error.
    try {
      const maybe = this.restorePersistedPromo();
      if (maybe && typeof maybe.catch === 'function') maybe.catch(() => {});
    } catch (_e) { /* best-effort */ }
  },
  methods: {
    ...mapActions('kioskCart', [
      'updateQuantity', 'removeItem', 'reset', 'markUpsellShown', 'popItem', 'setOrderType',
      'quoteOrder',
      // Kiosk Phase 9.1.6 — actions promo (validate lecture-seule + clear local).
      'validatePromo', 'clearPromo',
      // [dispute-r1 C-ADV-02] restauration promo persistée au mount (re-validée serveur).
      'restorePersistedPromo',
      // [P-MEGA-05] Édition d'une ligne sans suppression intermédiaire.
      'startEditingCartItem',
      // [bug-kiosk-valider-2026-05-21] Drop cart lines that became unavailable
      // (manual 86, branch-specific flag) BEFORE the Valider click so the
      // backend's PricingService availability guard cannot surface a 422.
      // Persisted Vuex carts can outlive an admin availability flip when the
      // kiosk missed the Echo broadcast (offline blip, app reload), so the
      // pre-flight prune is the correct defense-in-depth — owner reported
      // "ça marche parfois" after retries because subsequent clicks finally
      // refreshed the menu cache and pruning silently dropped the stale line.
      'pruneUnavailableLines',
    ]),

    // Kiosk Phase 9.1.6 — Applique un code promo via /api/frontend/promo/validate.
    // UX: on ne bloque pas l'utilisateur, on affiche un message d'erreur inline
    // et on conserve ce qu'il a tapé si la saisie est invalide (pas de reset input).
    async applyPromo() {
      const code = (this.promoInput || '').trim();
      if (!code) return;
      const res = await this.validatePromo(code);
      if (res && res.valid) {
        this.promoInput = '';
      }
    },
    removePromo() {
      this.clearPromo();
      this.promoInput = '';
    },

    // [GAP-22-1] Select order type and give haptic-like visual feedback
    selectOrderType(type) {
      this.setOrderType(type);
    },

    // Retour explicite vers le catalogue — jamais router.go(-1) car apres
    // panier -> paiement -> replace(panier), l'historique contient encore
    // [panier, paiement, panier] et go(-1) retomberait sur paiement.
    goBackFromCart() {
      const cat = this.selectedCategoryId;
      if (cat != null && cat !== '') {
        this.$router.push({ name: 'kiosk.categories', query: { cat: String(cat) } });
        return;
      }
      this.$router.push({ name: 'kiosk.categories' });
    },

    displayCartItemName(item) {
      return sanitizeKioskCustomerFacingText(item?.name || '');
    },
    displayCartInstruction(item) {
      if (!item?.instruction) return '';
      return sanitizeKioskCustomerFacingText(item.instruction);
    },

    cartLineCatalogItem(line) {
      if (!line?.item_id || !Array.isArray(this.allItems)) return null;
      return this.allItems.find((i) => String(i.id) === String(line.item_id)) || null;
    },

    cartLineAllergenSelections(line) {
      const cat = this.cartLineCatalogItem(line);
      if (!cat) return { variations: [], extras: [] };
      // [W-A BORNE 2026-06-10] Les lignes ajoutées par l'upsell (frozen
      // KioskUpsellComponent.vue:234) portent l'ancien format objet
      // `{ variations: {}, names: {} }` / `{ extras: [], names: [] }` —
      // garder le guard dual-format comme getItemSelectionSummary ([GAP-22-2])
      // sinon `.map` jette un TypeError (pageerror à chaque rendu du panier
      // contenant une ligne upsell).
      const rawVars = Array.isArray(line.item_variations) ? line.item_variations : [];
      const rawExt = Array.isArray(line.item_extras) ? line.item_extras : [];
      const vars = rawVars
        .map((v) => findVariationObjectById(cat, v.id))
        .filter(Boolean);
      const ext = rawExt
        .map((e) => findExtraObjectById(cat, e.id))
        .filter(Boolean);
      return { variations: vars, extras: ext };
    },

    // [GAP-22-2] Récap des choix : supporte item_variations en tableau (wizard) + ancien format names
    getItemSelectionSummary(item) {
      const parts = [];
      const clean = (s) => sanitizeKioskCustomerFacingText(s);

      if (Array.isArray(item.item_variations) && item.item_variations.length > 0) {
        const bits = item.item_variations
          .map((v) => clean(v.name || v.variation_name || ''))
          .filter(Boolean);
        if (bits.length) parts.push(bits.join(', '));
      } else if (item.item_variations?.names) {
        const names = Object.values(item.item_variations.names)
          .map(clean)
          .filter(Boolean);
        if (names.length > 0) parts.push(names.join(', '));
      }

      if (Array.isArray(item.item_extras) && item.item_extras.length > 0) {
        const extras = item.item_extras.map((e) => clean(e.name)).filter(Boolean);
        if (extras.length) parts.push(extras.join(', '));
      } else if (item.item_extras?.names) {
        const raw = Array.isArray(item.item_extras.names)
          ? item.item_extras.names
          : Object.values(item.item_extras.names);
        const extras = raw.map(clean).filter(Boolean);
        if (extras.length) parts.push(extras.join(', '));
      }

      return parts.join(' · ');
    },

    changeQty(index, qty) {
      if (qty <= 0) {
        this.removeItem(index);
        this.showToast(this.$t('kiosk.item_removed'), 'info', 1800);
      } else if (qty > this.maxItemQty) {
        this.showToast(this.$t('kiosk.max_quantity_reached') || `Maximum ${this.maxItemQty} atteint`, 'warning', 1800);
      } else {
        this.updateQuantity({ index, quantity: qty });
      }
    },

    /**
     * FoodKing brand V2 (2026-05-10) — direct remove from explicit trash button
     * (owner demand : voir + ajouter + supprimer "correctement"). Le toast info
     * confirme l'action puisqu'elle est explicite (vs add-to-cart où owner ne
     * veut pas de toast).
     */
    removeItemDirectly(index) {
      const item = this.cartItems?.[index];
      this.removeItem(index);
      this.showToast(
        this.$t('kiosk.item_removed') || `${item?.name || 'Article'} supprimé`,
        'info',
        1500
      );
    },

    /**
     * Remove the item from the cart and re-open the wizard so the customer
     * can change customizations. The wizard is opened in normal mode — the
     * quantity from the old line is NOT pre-set (wizard default = 1 to keep
     * things simple and avoid complexity with selections state).
     */
    /**
     * [P-MEGA-05] Édition d'une ligne du panier — version "safe" :
     * 1. Le store mémorise la cart line via startEditingCartItem (no pop).
     * 2. Le wizard, à son montage, restaure les sélections depuis le
     *    snapshot (`_wizardSelections`) et bascule en mode "edit".
     * 3. À la validation, le wizard fait `replaceEditingCartItem` :
     *    la ligne est remplacée en place (préserve l'ordre, l'index, les
     *    coupons attachés à un slot précis).
     * 4. Si l'utilisateur abandonne (close/abandon), `cancelEditingCartItem`
     *    laisse la ligne intacte. AUCUN risque de perte.
     *
     * Compat ascendante : si pour une raison X le wizard ne bascule pas
     * en mode edit (item supprimé du catalogue, etc.), la ligne originale
     * reste dans le panier (pas de double dépense, pas de panier cassé).
     */
    async editItem(index) {
      const item = this.cartItems[index];
      if (!item?.item_id) return;
      const ok = await this.startEditingCartItem(index);
      if (!ok) return;
      this.$router.push({
        name: 'kiosk.wizard',
        params: { itemId: String(item.item_id) },
        query: { edit: '1' },
      });
    },

    confirmClear() {
      this.showClearConfirm = false;
      this.reset();
      this.$router.push({ name: 'kiosk.categories' });
    },

    async proceedToUpsell() {
      if (this.quoteLoading || this.cartCount === 0) return;

      this.quoteLoading = true;
      this.quoteError = null;

      // [bug-kiosk-valider-2026-05-21] Pre-flight prune of cart lines whose
      // catalog row is currently unavailable (manual 86 / branch flip the
      // kiosk missed). Without this, the backend AvailabilityService rejects
      // the whole quote with "Article N indisponible pour cette branche
      // (manual)." → 422 surfaced as an error toast on the cart screen, owner
      // reported a confusing UX ("erreur au panier, parfois ça disparait").
      // The toast vanishes after 6s by design which matches the "parfois ça
      // disparait" wording. Pruning here turns the 422 into a clean cart
      // state plus an inline notice if anything was dropped.
      const beforeCount = this.cartCount;
      try { this.pruneUnavailableLines(); } catch (_) { /* defensive */ }
      const afterCount = this.cartCount;
      if (afterCount === 0) {
        const msg = this.$t('kiosk.unavailable_items_pruned')
          || 'Certains articles ne sont plus disponibles et ont été retirés du panier.';
        this.quoteError = msg;
        this.showToast(msg, 'warning', 6000);
        this.quoteLoading = false;
        return;
      }
      if (afterCount < beforeCount) {
        // Surface a brief notice and let the user re-tap Valider — gives
        // them the chance to review the updated total before paying.
        const msg = this.$t('kiosk.unavailable_items_pruned')
          || 'Certains articles ne sont plus disponibles et ont été retirés du panier.';
        this.showToast(msg, 'warning', 4500);
        this.quoteLoading = false;
        return;
      }

      try {
        await this.quoteOrder({ orderType: this.orderType });

        if (this.upsellShown) {
          this.$router.push({ name: 'kiosk.payment' });
          return;
        }
        this.markUpsellShown();
        if (this.shouldSkipKioskUpsell) {
          this.$router.push({ name: 'kiosk.payment' });
          return;
        }
        this.$router.push({ name: 'kiosk.upsell' });
      } catch (err) {
        // [dispute-r1 D-002 2026-06-12] Coupure réseau au checkout panier :
        // l'axios « Network Error » brut (EN) fuyait en toast + inline alors
        // que le même cas était déjà mappé FR sur l'écran paiement (K10).
        // Même détection que KioskPaymentComponent.confirmPayment : pas de
        // response + (ERR_NETWORK | 'Network Error' | request émis). Le panier
        // est intégralement conservé (aucun reset sur ce chemin).
        const isNetworkError = !err?.response
          && (err?.code === 'ERR_NETWORK' || err?.message === 'Network Error' || !!err?.request);
        const status = Number(err?.response?.status) || 0;
        let message;
        if (isNetworkError) {
          message = this.$t('kiosk.network_lost_cart');
        } else if (status === 429) {
          // [dispute-r1 C-ADV-01 même classe] « Too Many Attempts. » EN sur le
          // quote → copie FR kiosk dédiée (déjà utilisée par kioskCart.quoteOrder).
          message = this.$t('error.kiosk_rate_limited');
        } else {
          message = err?.response?.data?.message
            || err?.message
            || this.$t('kiosk.pay_screen.invalid_order_response');
        }
        this.quoteError = message;
        this.showToast(message, 'error', 6000);
      } finally {
        this.quoteLoading = false;
      }
    },

    // formatPrice() is provided by kioskPriceMixin — reads currency from globalState.lists
  },
};
</script>

<style scoped>
.kiosk-cart {
  width: 100vw;
  height: 100vh;
  background: var(--kiosk-page-bg, var(--kiosk-bg));
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.kiosk-order-type-bar {
  display: flex;
  gap: 16px;
  padding: 20px 30px 0;
  flex-shrink: 0;
}

.kiosk-order-type-btn {
  flex: 1;
  min-height: 82px;
  height: auto;
  border-radius: 24px;
  border: 2px solid var(--kiosk-border);
  background: var(--kiosk-surface);
  color: var(--kiosk-text-muted);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.kiosk-order-type-btn.active {
  border-color: var(--kiosk-primary);
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  box-shadow: var(--kiosk-shadow-cta);
}

.kiosk-order-type-btn:active { transform: scale(0.97); }
.kiosk-btn-primary[disabled] {
  opacity: 0.62;
  cursor: wait;
}

.kiosk-cart-quote-error {
  width: 100%;
  margin: 8px 0 0;
  color: var(--kiosk-error, #b91c1c);
  font-size: 13px;
  font-weight: 700;
  text-align: center;
}

.kiosk-order-type-icon { font-size: 22px; line-height: 1; }

.kiosk-order-type-label {
  font-size: 17px;
  font-weight: 900;
}

.kiosk-cart-item-selections {
  font-size: 11px;
  color: var(--kiosk-text-mute);
  margin: 2px 0 4px;
  /* [FP-26] 2-line clamp so long customization summaries wrap instead of a hard cut. */
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  max-width: 100%;
}

.kiosk-cart-header {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 24px 32px 20px;
  background: var(--kiosk-surface);
  border-bottom: 1px solid var(--kiosk-border);
  box-shadow: var(--kiosk-shadow-sticky);
  flex-shrink: 0;
}

.kiosk-cart-back {
  width: 60px;
  height: 60px;
  border-radius: 18px;
  border: 1.5px solid var(--kiosk-border);
  background: var(--kiosk-surface);
  color: var(--kiosk-text);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
  transition: all 0.15s ease;
}

.kiosk-cart-back:active { transform: scale(0.95); background: var(--kiosk-surface-alt); }

.kiosk-cart-header-info { flex: 1; }

.kiosk-cart-title {
  font-size: clamp(30px, 4vw, 44px);
  font-weight: 900;
  color: var(--kiosk-text);
  margin: 0 0 2px;
  text-transform: uppercase;
}

.kiosk-cart-item-count {
  font-size: 16px;
  color: var(--kiosk-text-mute);
  margin: 0;
}

.kiosk-cart-clear {
  min-height: 52px;
  padding: 8px 20px;
  border-radius: 999px;
  border: 2px solid var(--kiosk-border);
  background: var(--kiosk-surface);
  color: var(--kiosk-primary);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}

.kiosk-cart-clear:active { background: var(--kiosk-surface-alt); }

.kiosk-cart-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 40px;
  text-align: center;
}

.kiosk-cart-empty-icon { font-size: 72px; line-height: 1; }

.kiosk-cart-empty h2 {
  font-size: 24px;
  font-weight: 800;
  color: var(--kiosk-text);
  margin: 0;
}

.kiosk-cart-empty p {
  font-size: 15px;
  color: var(--kiosk-text-mute);
  margin: 0;
}

.kiosk-cart-body {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  scrollbar-width: none;
}

.kiosk-cart-body::-webkit-scrollbar { display: none; }

.kiosk-cart-items {
  flex: 1;
  padding: 22px 30px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* FoodKing brand V2 (2026-05-10) — modernisation cart recap (owner :
   "année 2000, c'est weird, trop basique"). Card plus aérée, image carrée
   arrondie style modern app, accents Cayenne, hierarchy typo plus marquée. */
.kiosk-cart-item {
  background: #FFFFFF;
  border-radius: 20px;
  border: 1.5px solid #E5E5E5;
  padding: 16px 18px;
  display: flex;
  align-items: center;
  gap: 18px;
  box-shadow: 0 4px 14px rgba(15, 15, 15, 0.04);
  transition: border-color 160ms ease, box-shadow 160ms ease;
}

.kiosk-cart-item:hover {
  border-color: #F4501E;
  box-shadow: 0 6px 18px rgba(244, 80, 30, 0.12);
}

.kiosk-cart-item-img {
  width: 104px;
  height: 104px;
  border-radius: 18px;
  overflow: hidden;
  flex-shrink: 0;
  background: #FAFAFA;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  border: 1px solid #EFEFEF;
}

.kiosk-cart-item-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.kiosk-cart-item-emoji { font-size: 56px; line-height: 1; }

.kiosk-cart-item-info { flex: 1; min-width: 0; }

.kiosk-cart-item-name-row {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.kiosk-cart-edit-btn {
  flex-shrink: 0;
  background: var(--kiosk-surface-alt);
  border: 1px solid var(--kiosk-border);
  border-radius: 50%;
  color: var(--kiosk-text-mute);
  width: 34px; height: 34px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
  padding: 0;
}
.kiosk-cart-edit-btn:hover {
  background: var(--kiosk-primary-soft);
  color: var(--kiosk-primary);
  border-color: rgba(244, 80, 30, 0.24);
}

.kiosk-cart-item-name {
  font-size: 22px;
  font-weight: 900;
  color: #0F0F0F;
  margin: 0 0 4px;
  letter-spacing: -0.2px;
  /* [FP-26] Wrap long product names to 2 lines (matches the product-grid clamp) instead of a
     hard one-line ellipsis cut — worst at ~360px where image + qty controls leave little room. */
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.kiosk-cart-item-note {
  font-size: 11px;
  color: var(--kiosk-text-mute);
  margin: 0 0 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.kiosk-cart-item-unit {
  font-size: 14px;
  color: var(--kiosk-text-mute);
}

.kiosk-cart-item-controls {
  display: flex;
  flex-direction: column;
  align-items: end;
  gap: 8px;
  flex-shrink: 0;
  position: relative;
  /* [UIUX-W4 K7] axe target-size (WCAG 2.2) : la corbeille flottante
     (absolute top:-8px, 36px de haut) déborde de 28px dans ce bloc et
     recouvrait le coin du bouton qty « + » (rectangle libre 23px < 24px).
     Dégagement vertical ≥ 28px pour que la pilule qty démarre sous la
     corbeille — verrouillé par kioskA11yTouchTargets.spec.js. */
  padding-top: 30px;
}

/* FoodKing brand V2 (2026-05-10) — bouton trash explicite à côté du qty stepper */
.kiosk-cart-item-trash {
  position: absolute;
  top: -8px;
  right: -8px;
  width: 36px;
  height: 36px;
  border: 1px solid var(--kiosk-border, #E5E5E5);
  border-radius: 50%;
  background: var(--kiosk-surface, #FFFFFF);
  color: var(--kiosk-text-muted, #5A5A5A);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background 120ms ease, color 120ms ease, transform 120ms ease;
  -webkit-tap-highlight-color: transparent;
  box-shadow: 0 2px 6px rgba(15, 15, 15, 0.06);
}

.kiosk-cart-item-trash:hover {
  background: var(--kiosk-bold-primary-soft, #FFE8DD);
  color: var(--kiosk-bold-primary, #F4501E);
}

.kiosk-cart-item-trash:active {
  transform: scale(0.92);
}

.kiosk-cart-item-trash:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, #2563EB);
  outline-offset: 2px;
}

.kiosk-qty-ctrl {
  display: flex;
  align-items: center;
  gap: 0;
  background: var(--kiosk-surface-alt);
  border-radius: 999px;
  border: 1.5px solid var(--kiosk-border);
  overflow: hidden;
}

[dir="rtl"] .kiosk-qty-ctrl {
  direction: ltr;
}

.kiosk-qty-btn {
  width: 50px;
  height: 50px;
  border: none;
  background: transparent;
  color: var(--kiosk-text);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s ease;
}

.kiosk-qty-btn:active { background: rgba(0,0,0,0.05); }

.kiosk-qty-btn.minus { color: var(--kiosk-text-muted); }
.kiosk-qty-btn.minus:active { color: var(--kiosk-primary); }

.kiosk-qty-num {
  min-width: 38px;
  text-align: center;
  font-size: 20px;
  font-weight: 900;
  color: var(--kiosk-text);
}

.kiosk-cart-item-total {
  font-size: 24px;
  font-weight: 900;
  color: #F4501E;
  letter-spacing: -0.4px;
}

.kiosk-cart-summary {
  margin: 0 30px;
  background: var(--kiosk-surface);
  border-radius: 26px;
  border: 1.5px solid var(--kiosk-border);
  padding: 20px 24px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.kiosk-cart-summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 16px;
  color: var(--kiosk-text-muted);
}

.kiosk-cart-summary-row.total {
  font-size: 24px;
  font-weight: 900;
  color: var(--kiosk-text);
  padding-top: 10px;
  border-top: 1px solid var(--kiosk-border);
  margin-top: 4px;
}

.kiosk-cart-summary-row.loyalty { color: var(--kiosk-text-muted); }

.green { color: var(--kiosk-success); font-weight: 700; }

.kiosk-cart-grand-total {
  font-size: 32px;
  font-weight: 900;
  color: var(--kiosk-primary);
}

.kiosk-cart-actions {
  padding: 18px 30px 32px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* Kiosk Phase 9.1.6 — Section code promo panier. */
.kiosk-cart-promo {
  padding: 14px 30px 0;
}
.kiosk-cart-promo-label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: var(--kiosk-text-muted, #5A5A5A);
  margin-bottom: 6px;
}
.kiosk-cart-promo-row {
  display: flex;
  gap: 8px;
}
.kiosk-cart-promo-input {
  flex: 1;
  height: 48px;
  padding: 0 14px;
  border-radius: 10px;
  border: 1.5px solid var(--kiosk-border);
  background: var(--kiosk-surface);
  color: var(--kiosk-text);
  font-size: 15px;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  min-height: 44px;
}
.kiosk-cart-promo-input:focus {
  border-color: var(--kiosk-primary, #F4501E);
  outline: none;
}
.kiosk-cart-promo-input[aria-invalid="true"] {
  border-color: var(--kiosk-error, #C21E2F);
}
.kiosk-cart-promo-apply {
  height: 48px;
  padding: 0 18px;
  border-radius: 10px;
  border: none;
  background: var(--kiosk-primary, #F4501E);
  color: #fff;
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
  min-height: 44px;
}
.kiosk-cart-promo-apply:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.kiosk-cart-promo-error {
  color: var(--kiosk-error, #C21E2F);
  font-size: 13px;
  margin: 6px 0 0;
  font-weight: 500;
}
.kiosk-cart-promo-applied {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: rgba(27, 138, 58, 0.12);
  border: 1.5px solid var(--kiosk-success, #1B8A3A);
  border-radius: 10px;
  color: var(--kiosk-success, #1B8A3A);
  font-weight: 600;
}
.kiosk-cart-promo-applied-icon {
  font-size: 18px;
}
.kiosk-cart-promo-applied-text {
  flex: 1;
}
.kiosk-cart-promo-remove {
  background: transparent;
  border: none;
  color: var(--kiosk-error, #C21E2F);
  font-weight: 600;
  cursor: pointer;
  text-decoration: underline;
  font-size: 13px;
}

.kiosk-cart-summary-row.promo .green {
  color: var(--kiosk-success, #1B8A3A);
}

.kiosk-btn-loyalty {
  width: 100%;
  min-height: 60px;
  height: auto;
  background: rgba(255,215,0,0.08);
  border: 1.5px solid rgba(255,215,0,0.3);
  border-radius: 18px;
  color: var(--kiosk-warning);
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  transition: background 0.2s;
  margin: 0 30px 4px;
  width: calc(100% - 60px);
}
.kiosk-btn-loyalty:active { background: rgba(255,215,0,0.15); }
.kiosk-btn-loyalty-star { font-size: 18px; }
.kiosk-btn-loyalty-arrow { font-size: 20px; opacity: 0.7; }

.kiosk-btn-primary {
  width: 100%;
  min-height: 76px;
  height: auto;
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  border: none;
  border-radius: 24px;
  font-size: 22px;
  font-weight: 900;
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
  font-size: 18px;
  font-weight: 800;
  background: rgba(255,255,255,0.2);
  padding: 4px 14px;
  border-radius: 10px;
}

.kiosk-btn-secondary {
  width: 100%;
  min-height: 60px;
  height: auto;
  background: var(--kiosk-surface);
  color: var(--kiosk-text-muted);
  border: 1.5px solid var(--kiosk-border);
  border-radius: 18px;
  font-size: 17px;
  font-weight: 800;
  cursor: pointer;
  transition: all 0.15s ease;
}

.kiosk-btn-secondary:active { background: var(--kiosk-surface-alt); }

.kiosk-clear-overlay {
  position: fixed; inset: 0;
  background: var(--kiosk-overlay-modal);
  display: flex; align-items: center; justify-content: center;
  z-index: 999;
}
.kiosk-clear-modal {
  background: var(--kiosk-surface);
  border: 1.5px solid var(--kiosk-border);
  border-radius: 20px;
  padding: 2rem;
  width: 340px;
  text-align: center;
  box-shadow: var(--kiosk-shadow-modal);
}
.kiosk-clear-title {
  font-size: 1.3rem;
  font-weight: 700;
  margin: 0 0 0.4rem;
  color: var(--kiosk-text);
}
.kiosk-clear-sub {
  color: var(--kiosk-text-mute);
  font-size: 0.95rem;
  margin: 0 0 1.5rem;
}
.kiosk-clear-actions {
  display: flex;
  gap: 0.75rem;
}
.kiosk-clear-yes {
  flex: 1;
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  border: none;
  border-radius: 12px;
  padding: 0.85rem 1rem;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
}
.kiosk-clear-no {
  flex: 1;
  background: var(--kiosk-surface-alt);
  color: var(--kiosk-text-muted);
  border: 1px solid var(--kiosk-border);
  border-radius: 12px;
  padding: 0.85rem 1rem;
  font-size: 1rem;
  cursor: pointer;
}
</style>
