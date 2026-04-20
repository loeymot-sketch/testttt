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
      <button type="button"
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
      <div class="kiosk-cart-promo" data-testid="kiosk-cart-promo">
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

      <!-- Bouton fidélité -->
      <button type="button"
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
          data-testid="kiosk-cart-checkout"
        >
          <span>{{ $t('kiosk.validate_order') }}</span>
          <span class="kiosk-btn-price">{{ formatPrice(cartTotal) }}</span>
        </button>
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

// [GAP-22-1] Order type constants — KIOSK=sur place, TAKEAWAY=à emporter
const ORDER_TYPE_KIOSK    = 25;
const ORDER_TYPE_TAKEAWAY = 10;

export default {
  name: 'KioskCartComponent',
  mixins: [kioskPriceMixin],

  inject: {
    showToast: { default: () => () => {} },
  },

  data() {
    return {
      showClearConfirm: false,
      ORDER_TYPE_KIOSK,
      ORDER_TYPE_TAKEAWAY,
      maxItemQty: window.foodkingConfig?.maxItemQty ?? 20,
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
    ...mapGetters('kioskMenu', ['categories', 'selectedCategoryId']),
    /** Phase A — skip upsell when all lines are in "skip after cart" categories */
    shouldSkipKioskUpsell() {
      return shouldSkipKioskUpsellScreen(this.cartItems, this.categories);
    },
  },
  methods: {
    ...mapActions('kioskCart', [
      'updateQuantity', 'removeItem', 'reset', 'markUpsellShown', 'popItem', 'setOrderType',
      // Kiosk Phase 9.1.6 — actions promo (validate lecture-seule + clear local).
      'validatePromo', 'clearPromo',
      // [P-MEGA-05] Édition d'une ligne sans suppression intermédiaire.
      'startEditingCartItem',
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

    proceedToUpsell() {
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
    },

    // formatPrice() is provided by kioskPriceMixin — reads currency from globalState.lists
  },
};
</script>

<style scoped>
.kiosk-cart {
  width: 100vw;
  height: 100vh;
  background: var(--kiosk-surface-alt);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.kiosk-order-type-bar {
  display: flex;
  gap: 12px;
  padding: 16px 28px 0;
  flex-shrink: 0;
}

.kiosk-order-type-btn {
  flex: 1;
  height: 64px;
  border-radius: 14px;
  border: 2px solid var(--kiosk-border);
  background: var(--kiosk-surface);
  color: var(--kiosk-text-mute);
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
  background: var(--kiosk-primary-soft);
  color: var(--kiosk-text);
}

.kiosk-order-type-btn:active { transform: scale(0.97); }

.kiosk-order-type-icon { font-size: 22px; line-height: 1; }

.kiosk-order-type-label {
  font-size: 14px;
  font-weight: 700;
}

.kiosk-cart-item-selections {
  font-size: 11px;
  color: var(--kiosk-text-mute);
  margin: 2px 0 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}

.kiosk-cart-header {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 20px 28px 16px;
  background: var(--kiosk-surface);
  border-bottom: 1px solid var(--kiosk-border);
  flex-shrink: 0;
}

.kiosk-cart-back {
  width: 44px;
  height: 44px;
  border-radius: 12px;
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
  font-size: 22px;
  font-weight: 800;
  color: var(--kiosk-text);
  margin: 0 0 2px;
}

.kiosk-cart-item-count {
  font-size: 13px;
  color: var(--kiosk-text-mute);
  margin: 0;
}

.kiosk-cart-clear {
  padding: 8px 16px;
  border-radius: 10px;
  border: 1.5px solid var(--kiosk-border);
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
  padding: 16px 24px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.kiosk-cart-item {
  background: var(--kiosk-surface);
  border-radius: 14px;
  border: 1.5px solid var(--kiosk-border);
  padding: 14px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: var(--kiosk-shadow-card);
}

.kiosk-cart-item-img {
  width: 64px;
  height: 64px;
  border-radius: 10px;
  overflow: hidden;
  flex-shrink: 0;
  background: var(--kiosk-surface-alt);
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-cart-item-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.kiosk-cart-item-emoji { font-size: 32px; }

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
  border-radius: 6px;
  color: var(--kiosk-text-mute);
  width: 26px; height: 26px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
  padding: 0;
}
.kiosk-cart-edit-btn:hover {
  background: var(--kiosk-primary-soft);
  color: var(--kiosk-primary);
  border-color: rgba(232,0,28,0.2);
}

.kiosk-cart-item-name {
  font-size: 15px;
  font-weight: 700;
  color: var(--kiosk-text);
  margin: 0 0 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
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
  font-size: 12px;
  color: var(--kiosk-text-mute);
}

.kiosk-cart-item-controls {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
  flex-shrink: 0;
}

.kiosk-qty-ctrl {
  display: flex;
  align-items: center;
  gap: 0;
  background: var(--kiosk-surface-alt);
  border-radius: 10px;
  border: 1.5px solid var(--kiosk-border);
  overflow: hidden;
}

.kiosk-qty-btn {
  width: 38px;
  height: 38px;
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
  min-width: 32px;
  text-align: center;
  font-size: 16px;
  font-weight: 700;
  color: var(--kiosk-text);
}

.kiosk-cart-item-total {
  font-size: 16px;
  font-weight: 800;
  color: var(--kiosk-text);
}

.kiosk-cart-summary {
  margin: 0 24px;
  background: var(--kiosk-surface);
  border-radius: 14px;
  border: 1.5px solid var(--kiosk-border);
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.kiosk-cart-summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 15px;
  color: var(--kiosk-text-muted);
}

.kiosk-cart-summary-row.total {
  font-size: 18px;
  font-weight: 700;
  color: var(--kiosk-text);
  padding-top: 10px;
  border-top: 1px solid var(--kiosk-border);
  margin-top: 4px;
}

.kiosk-cart-summary-row.loyalty { color: var(--kiosk-text-muted); }

.green { color: var(--kiosk-success); font-weight: 700; }

.kiosk-cart-grand-total {
  font-size: 22px;
  font-weight: 900;
  color: var(--kiosk-primary);
}

.kiosk-cart-actions {
  padding: 16px 24px 28px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* Kiosk Phase 9.1.6 — Section code promo panier. */
.kiosk-cart-promo {
  padding: 12px 24px 0;
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
  border: 1.5px solid #E5E5E5;
  background: #fff;
  font-size: 15px;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  min-height: 44px;
}
.kiosk-cart-promo-input:focus {
  border-color: var(--kiosk-primary, #E8001C);
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
  background: var(--kiosk-primary, #E8001C);
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
  background: #E8F5EC;
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
  height: 52px;
  background: rgba(255,215,0,0.08);
  border: 1.5px solid rgba(255,215,0,0.3);
  border-radius: 12px;
  color: var(--kiosk-warning);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  transition: background 0.2s;
  margin-bottom: 4px;
}
.kiosk-btn-loyalty:active { background: rgba(255,215,0,0.15); }
.kiosk-btn-loyalty-star { font-size: 18px; }
.kiosk-btn-loyalty-arrow { font-size: 20px; opacity: 0.7; }

.kiosk-btn-primary {
  width: 100%;
  height: 60px;
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
  font-size: 18px;
  font-weight: 800;
  background: rgba(255,255,255,0.2);
  padding: 4px 14px;
  border-radius: 10px;
}

.kiosk-btn-secondary {
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
