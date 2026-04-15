<template>
  <div class="kiosk-cart">
    <!-- Header -->
    <div class="kiosk-cart-header">
      <button class="kiosk-cart-back" type="button" @click="goBackFromCart">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
          <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <div class="kiosk-cart-header-info">
        <h1 class="kiosk-cart-title">{{ $t('kiosk.your_cart') }}</h1>
        <p class="kiosk-cart-item-count">
          {{ cartCount }} {{ cartCount > 1 ? $t('kiosk.article_plural') : $t('kiosk.article_singular') }}
        </p>
      </div>
      <button class="kiosk-cart-clear" @click="showClearConfirm = true" v-if="cartCount > 0">
        {{ $t('kiosk.clear_cart') }}
      </button>
    </div>

    <!-- Modal : confirmer vider le panier -->
    <transition name="fade">
      <div v-if="showClearConfirm" class="kiosk-clear-overlay" @click.self="showClearConfirm = false">
        <div class="kiosk-clear-modal">
          <p class="kiosk-clear-title">{{ $t('kiosk.clear_cart') }}</p>
          <p class="kiosk-clear-sub">{{ $t('kiosk.clear_cart_confirm') }}</p>
          <div class="kiosk-clear-actions">
            <button class="kiosk-clear-yes" @click="confirmClear">{{ $t('kiosk.yes_clear') }}</button>
            <button class="kiosk-clear-no" @click="showClearConfirm = false">{{ $t('kiosk.cancel') }}</button>
          </div>
        </div>
      </div>
    </transition>

    <!-- Panier vide -->
    <div v-if="cartCount === 0" class="kiosk-cart-empty">
      <div class="kiosk-cart-empty-icon">🛒</div>
      <h2>{{ $t('kiosk.empty_cart') }}</h2>
      <p>{{ $t('kiosk.empty_cart_hint') }}</p>
      <button class="kiosk-btn-primary" @click="$router.push({ name: 'kiosk.categories' })">
        {{ $t('kiosk.add_items') }}
      </button>
    </div>

    <!-- [GAP-22-1] Sélecteur Sur place / À emporter — inspiré Splash -->
    <div v-if="cartCount > 0" class="kiosk-order-type-bar">
      <button
        class="kiosk-order-type-btn"
        :class="{ active: orderType === ORDER_TYPE_KIOSK }"
        @click="selectOrderType(ORDER_TYPE_KIOSK)"
      >
        <span class="kiosk-order-type-icon">🍽️</span>
        <span class="kiosk-order-type-label">{{ $t('kiosk.dine_in') }}</span>
      </button>
      <button
        class="kiosk-order-type-btn"
        :class="{ active: orderType === ORDER_TYPE_TAKEAWAY }"
        @click="selectOrderType(ORDER_TYPE_TAKEAWAY)"
      >
        <span class="kiosk-order-type-icon">🥡</span>
        <span class="kiosk-order-type-label">{{ $t('kiosk.takeaway') }}</span>
      </button>
    </div>

    <!-- Liste articles -->
    <div v-if="cartCount > 0" class="kiosk-cart-body">
      <div class="kiosk-cart-items">
        <!-- [FIX] Use stable composite key instead of array index to avoid re-render issues -->
        <div v-for="(item, idx) in cartItems" :key="item.item_id ? `${item.item_id}-${idx}` : idx" class="kiosk-cart-item">
          <!-- Image -->
          <div class="kiosk-cart-item-img">
            <img v-if="item.image" :src="item.image" :alt="displayCartItemName(item)" />
            <span v-else class="kiosk-cart-item-emoji">🍽️</span>
          </div>

          <!-- Infos + bouton édition -->
          <div class="kiosk-cart-item-info">
            <div class="kiosk-cart-item-name-row">
              <h3 class="kiosk-cart-item-name">{{ displayCartItemName(item) }}</h3>
              <!-- Edit: retire l'article et rouvre le wizard pour le même produit -->
              <button
                v-if="item.item_id"
                class="kiosk-cart-edit-btn"
                @click="editItem(idx)"
                :title="$t('kiosk.edit_item_aria')"
              >
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                  <path d="M11.333 2a1.885 1.885 0 0 1 2.667 2.667L5.333 13.333 2 14l.667-3.333L11.333 2Z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            </div>
            <!-- [GAP-22-2] Afficher les sélections wizard (variations, extras) -->
            <div v-if="getItemSelectionSummary(item)" class="kiosk-cart-item-selections">
              {{ getItemSelectionSummary(item) }}
            </div>
            <p v-if="item.instruction" class="kiosk-cart-item-note">{{ displayCartInstruction(item) }}</p>
            <span class="kiosk-cart-item-unit">
              {{ formatPrice((parseFloat(item.convert_price) || 0) + (item.item_variation_total || 0) + (item.item_extra_total || 0)) }}
              {{ $t('kiosk.per_unit') }}
            </span>
          </div>

          <!-- Contrôles quantité + suppression -->
          <div class="kiosk-cart-item-controls">
            <div class="kiosk-qty-ctrl">
              <button class="kiosk-qty-btn minus" @click="changeQty(idx, item.quantity - 1)">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <path d="M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
              </button>
              <span class="kiosk-qty-num">{{ item.quantity }}</span>
              <button class="kiosk-qty-btn plus" :disabled="item.quantity >= maxItemQty" @click="changeQty(idx, item.quantity + 1)">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
              </button>
            </div>
            <!-- [KIOSK-17] item.total is always present (computed by ADD_ITEM / UPDATE_QUANTITY) -->
            <span class="kiosk-cart-item-total">
              {{ formatPrice(item.total) }}
            </span>
          </div>
        </div>
      </div>

      <!-- Récapitulatif totaux -->
      <div class="kiosk-cart-summary">
        <div class="kiosk-cart-summary-row">
          <span>{{ $t('kiosk.subtotal') }}</span>
          <span>{{ formatPrice(cartSubtotal) }}</span>
        </div>
        <div class="kiosk-cart-summary-row loyalty" v-if="loyaltyDiscount > 0">
          <span>🎁 {{ $t('kiosk.discount_loyalty') }}</span>
          <span class="green">-{{ formatPrice(loyaltyDiscount) }}</span>
        </div>
        <div class="kiosk-cart-summary-row total">
          <span>{{ $t('kiosk.total') }}</span>
          <span class="kiosk-cart-grand-total">{{ formatPrice(cartTotal) }}</span>
        </div>
      </div>

      <!-- Bouton fidélité -->
      <button class="kiosk-btn-loyalty" @click="$router.push({ name: 'kiosk.loyalty' })">
        <span class="kiosk-btn-loyalty-star">★</span>
        <span v-if="loyaltyDiscount > 0">{{ $t('kiosk.loyalty_applied', { amount: formatPrice(loyaltyDiscount) }) }}</span>
        <span v-else>{{ $t('kiosk.loyalty_prompt') }}</span>
        <span class="kiosk-btn-loyalty-arrow">›</span>
      </button>

      <!-- Bouton valider → upsell -->
      <div class="kiosk-cart-actions">
        <button class="kiosk-btn-primary full" @click="proceedToUpsell">
          <span>{{ $t('kiosk.validate_order') }}</span>
          <span class="kiosk-btn-price">{{ formatPrice(cartTotal) }}</span>
        </button>
        <button class="kiosk-btn-secondary" @click="$router.push({ name: 'kiosk.categories' })">
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
    }),
    ...mapGetters('kioskMenu', ['categories', 'selectedCategoryId']),
    /** Phase A — skip upsell when all lines are in "skip after cart" categories */
    shouldSkipKioskUpsell() {
      return shouldSkipKioskUpsellScreen(this.cartItems, this.categories);
    },
  },
  methods: {
    ...mapActions('kioskCart', ['updateQuantity', 'removeItem', 'reset', 'markUpsellShown', 'popItem', 'setOrderType']),

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
    async editItem(index) {
      const item = await this.popItem(index);
      if (!item?.item_id) return;
      this.$router.push({
        name: 'kiosk.wizard',
        params: { itemId: String(item.item_id) },
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
  background: #F7F7F8;
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
  border: 2px solid #E0E0E0;
  background: white;
  color: #999;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.kiosk-order-type-btn.active {
  border-color: #E8001C;
  background: rgba(232,0,28,0.04);
  color: #1A1A1A;
}

.kiosk-order-type-btn:active { transform: scale(0.97); }

.kiosk-order-type-icon { font-size: 22px; line-height: 1; }

.kiosk-order-type-label {
  font-size: 14px;
  font-weight: 700;
}

.kiosk-cart-item-selections {
  font-size: 11px;
  color: #999;
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
  background: white;
  border-bottom: 1px solid #E0E0E0;
  flex-shrink: 0;
}

.kiosk-cart-back {
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

.kiosk-cart-back:active { transform: scale(0.95); background: #F7F7F8; }

.kiosk-cart-header-info { flex: 1; }

.kiosk-cart-title {
  font-size: 22px;
  font-weight: 800;
  color: #1A1A1A;
  margin: 0 0 2px;
}

.kiosk-cart-item-count {
  font-size: 13px;
  color: #999;
  margin: 0;
}

.kiosk-cart-clear {
  padding: 8px 16px;
  border-radius: 10px;
  border: 1.5px solid #E0E0E0;
  background: white;
  color: #E8001C;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}

.kiosk-cart-clear:active { background: #F7F7F8; }

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
  color: #1A1A1A;
  margin: 0;
}

.kiosk-cart-empty p {
  font-size: 15px;
  color: #999;
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
  background: white;
  border-radius: 14px;
  border: 1.5px solid #E0E0E0;
  padding: 14px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}

.kiosk-cart-item-img {
  width: 64px;
  height: 64px;
  border-radius: 10px;
  overflow: hidden;
  flex-shrink: 0;
  background: #F7F7F8;
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
  background: #F7F7F8;
  border: 1px solid #E0E0E0;
  border-radius: 6px;
  color: #999;
  width: 26px; height: 26px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
  padding: 0;
}
.kiosk-cart-edit-btn:hover {
  background: rgba(232,0,28,0.08);
  color: #E8001C;
  border-color: rgba(232,0,28,0.2);
}

.kiosk-cart-item-name {
  font-size: 15px;
  font-weight: 700;
  color: #1A1A1A;
  margin: 0 0 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.kiosk-cart-item-note {
  font-size: 11px;
  color: #999;
  margin: 0 0 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.kiosk-cart-item-unit {
  font-size: 12px;
  color: #999;
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
  background: #F7F7F8;
  border-radius: 10px;
  border: 1.5px solid #E0E0E0;
  overflow: hidden;
}

.kiosk-qty-btn {
  width: 38px;
  height: 38px;
  border: none;
  background: transparent;
  color: #1A1A1A;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s ease;
}

.kiosk-qty-btn:active { background: rgba(0,0,0,0.05); }

.kiosk-qty-btn.minus { color: #777; }
.kiosk-qty-btn.minus:active { color: #E8001C; }

.kiosk-qty-num {
  min-width: 32px;
  text-align: center;
  font-size: 16px;
  font-weight: 700;
  color: #1A1A1A;
}

.kiosk-cart-item-total {
  font-size: 16px;
  font-weight: 800;
  color: #1A1A1A;
}

.kiosk-cart-summary {
  margin: 0 24px;
  background: white;
  border-radius: 14px;
  border: 1.5px solid #E0E0E0;
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
  color: #555;
}

.kiosk-cart-summary-row.total {
  font-size: 18px;
  font-weight: 700;
  color: #1A1A1A;
  padding-top: 10px;
  border-top: 1px solid #E0E0E0;
  margin-top: 4px;
}

.kiosk-cart-summary-row.loyalty { color: #555; }

.green { color: #27ae60; font-weight: 700; }

.kiosk-cart-grand-total {
  font-size: 22px;
  font-weight: 900;
  color: #E8001C;
}

.kiosk-cart-actions {
  padding: 16px 24px 28px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.kiosk-btn-loyalty {
  width: 100%;
  height: 52px;
  background: rgba(255,215,0,0.08);
  border: 1.5px solid rgba(255,215,0,0.3);
  border-radius: 12px;
  color: #b8860b;
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
  background: #E8001C;
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
  box-shadow: 0 4px 16px rgba(232,0,28,0.25);
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
  background: white;
  color: #555;
  border: 1.5px solid #E0E0E0;
  border-radius: 12px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}

.kiosk-btn-secondary:active { background: #F7F7F8; }

.kiosk-clear-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center;
  z-index: 999;
}
.kiosk-clear-modal {
  background: white;
  border: 1.5px solid #E0E0E0;
  border-radius: 20px;
  padding: 2rem;
  width: 340px;
  text-align: center;
  box-shadow: 0 16px 48px rgba(0,0,0,0.15);
}
.kiosk-clear-title {
  font-size: 1.3rem;
  font-weight: 700;
  margin: 0 0 0.4rem;
  color: #1A1A1A;
}
.kiosk-clear-sub {
  color: #999;
  font-size: 0.95rem;
  margin: 0 0 1.5rem;
}
.kiosk-clear-actions {
  display: flex;
  gap: 0.75rem;
}
.kiosk-clear-yes {
  flex: 1;
  background: #E8001C;
  color: #fff;
  border: none;
  border-radius: 12px;
  padding: 0.85rem 1rem;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
}
.kiosk-clear-no {
  flex: 1;
  background: #F7F7F8;
  color: #555;
  border: 1px solid #E0E0E0;
  border-radius: 12px;
  padding: 0.85rem 1rem;
  font-size: 1rem;
  cursor: pointer;
}
</style>
