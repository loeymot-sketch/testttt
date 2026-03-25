<template>
  <div class="kiosk-cart">
    <!-- Header -->
    <div class="kiosk-cart-header">
      <button class="kiosk-cart-back" @click="$router.go(-1)">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
          <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <div class="kiosk-cart-header-info">
        <h1 class="kiosk-cart-title">Mon panier</h1>
        <p class="kiosk-cart-item-count">{{ cartCount }} article{{ cartCount > 1 ? 's' : '' }}</p>
      </div>
      <button class="kiosk-cart-clear" @click="showClearConfirm = true" v-if="cartCount > 0">
        Vider
      </button>
    </div>

    <!-- Modal : confirmer vider le panier -->
    <transition name="fade">
      <div v-if="showClearConfirm" class="kiosk-clear-overlay" @click.self="showClearConfirm = false">
        <div class="kiosk-clear-modal">
          <p class="kiosk-clear-title">Vider le panier ?</p>
          <p class="kiosk-clear-sub">Tous les articles seront supprimés.</p>
          <div class="kiosk-clear-actions">
            <button class="kiosk-clear-yes" @click="confirmClear">Oui, vider</button>
            <button class="kiosk-clear-no" @click="showClearConfirm = false">Annuler</button>
          </div>
        </div>
      </div>
    </transition>

    <!-- Panier vide -->
    <div v-if="cartCount === 0" class="kiosk-cart-empty">
      <div class="kiosk-cart-empty-icon">🛒</div>
      <h2>Votre panier est vide</h2>
      <p>Ajoutez des articles depuis le menu</p>
      <button class="kiosk-btn-primary" @click="$router.push({ name: 'kiosk.categories' })">
        Voir le menu
      </button>
    </div>

    <!-- Liste articles -->
    <div v-else class="kiosk-cart-body">
      <div class="kiosk-cart-items">
        <div v-for="(item, idx) in cartItems" :key="idx" class="kiosk-cart-item">
          <!-- Image -->
          <div class="kiosk-cart-item-img">
            <img v-if="item.image" :src="item.image" :alt="item.name" />
            <span v-else class="kiosk-cart-item-emoji">🍽️</span>
          </div>

          <!-- Infos + bouton édition -->
          <div class="kiosk-cart-item-info">
            <div class="kiosk-cart-item-name-row">
              <h3 class="kiosk-cart-item-name">{{ item.name }}</h3>
              <!-- Edit: retire l'article et rouvre le wizard pour le même produit -->
              <button
                v-if="item.item_id"
                class="kiosk-cart-edit-btn"
                @click="editItem(idx)"
                title="Modifier"
              >
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                  <path d="M11.333 2a1.885 1.885 0 0 1 2.667 2.667L5.333 13.333 2 14l.667-3.333L11.333 2Z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            </div>
            <p v-if="item.instruction" class="kiosk-cart-item-note">{{ item.instruction }}</p>
            <span class="kiosk-cart-item-unit">
              {{ formatPrice(item.convert_price + (item.item_variation_total || 0) + (item.item_extra_total || 0)) }} / unité
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
              <button class="kiosk-qty-btn plus" @click="changeQty(idx, item.quantity + 1)">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
              </button>
            </div>
            <span class="kiosk-cart-item-total">
              {{ formatPrice((item.convert_price + (item.item_variation_total || 0) + (item.item_extra_total || 0)) * item.quantity) }}
            </span>
          </div>
        </div>
      </div>

      <!-- Récapitulatif totaux -->
      <div class="kiosk-cart-summary">
        <div class="kiosk-cart-summary-row">
          <span>Sous-total</span>
          <span>{{ formatPrice(cartSubtotal) }}</span>
        </div>
        <div class="kiosk-cart-summary-row loyalty" v-if="loyaltyDiscount > 0">
          <span>🎁 Réduction fidélité</span>
          <span class="green">-{{ formatPrice(loyaltyDiscount) }}</span>
        </div>
        <div class="kiosk-cart-summary-row total">
          <span>Total</span>
          <span class="kiosk-cart-grand-total">{{ formatPrice(cartTotal) }}</span>
        </div>
      </div>

      <!-- Bouton fidélité -->
      <button class="kiosk-btn-loyalty" @click="$router.push({ name: 'kiosk.loyalty' })">
        <span class="kiosk-btn-loyalty-star">★</span>
        <span v-if="loyaltyDiscount > 0">Fidélité appliquée (-{{ formatPrice(loyaltyDiscount) }})</span>
        <span v-else>Avez-vous une carte fidélité ?</span>
        <span class="kiosk-btn-loyalty-arrow">›</span>
      </button>

      <!-- Bouton valider → upsell -->
      <div class="kiosk-cart-actions">
        <button class="kiosk-btn-primary full" @click="proceedToUpsell">
          <span>Valider ma commande</span>
          <span class="kiosk-btn-price">{{ formatPrice(cartTotal) }}</span>
        </button>
        <button class="kiosk-btn-secondary" @click="$router.push({ name: 'kiosk.categories' })">
          + Ajouter des articles
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';

export default {
  name: 'KioskCartComponent',
  computed: {
    ...mapGetters('kioskCart', {
      cartItems: 'items',
      cartCount: 'count',
      cartSubtotal: 'subtotal',
      cartTotal: 'total',
      loyaltyDiscount: 'loyaltyDiscount',
      upsellShown: 'upsellShown',
    }),
  },
  data() {
    return {
      showClearConfirm: false,
    };
  },
  methods: {
    ...mapActions('kioskCart', ['updateQuantity', 'removeItem', 'reset', 'markUpsellShown', 'popItem']),

    changeQty(index, qty) {
      this.updateQuantity({ index, quantity: qty });
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
      } else {
        this.markUpsellShown();
        this.$router.push({ name: 'kiosk.upsell' });
      }
    },

    formatPrice(price) {
      return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(price || 0);
    },
  },
};
</script>

<style scoped>
.kiosk-cart {
  width: 100vw;
  height: 100vh;
  background: var(--kiosk-dark);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* Header */
.kiosk-cart-header {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 24px 32px 20px;
  background: var(--kiosk-dark-2);
  border-bottom: 1px solid rgba(255,255,255,0.07);
  flex-shrink: 0;
}

.kiosk-cart-back {
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

.kiosk-cart-back:active { transform: scale(0.95); background: rgba(255,255,255,0.12); }

.kiosk-cart-header-info { flex: 1; }

.kiosk-cart-title {
  font-size: 26px;
  font-weight: 800;
  color: white;
  margin: 0 0 2px;
}

.kiosk-cart-item-count {
  font-size: 14px;
  color: rgba(255,255,255,0.45);
  margin: 0;
}

.kiosk-cart-clear {
  padding: 10px 20px;
  border-radius: 12px;
  border: 1.5px solid rgba(255,255,255,0.2);
  background: transparent;
  color: rgba(255,255,255,0.6);
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}

.kiosk-cart-clear:active { background: rgba(255,255,255,0.08); }

/* Panier vide */
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

.kiosk-cart-empty-icon { font-size: 80px; line-height: 1; }

.kiosk-cart-empty h2 {
  font-size: 28px;
  font-weight: 800;
  color: white;
  margin: 0;
}

.kiosk-cart-empty p {
  font-size: 16px;
  color: rgba(255,255,255,0.45);
  margin: 0;
}

/* Corps panier */
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
  padding: 20px 32px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* Article */
.kiosk-cart-item {
  background: var(--kiosk-dark-2);
  border-radius: 18px;
  border: 1.5px solid rgba(255,255,255,0.07);
  padding: 16px;
  display: flex;
  align-items: center;
  gap: 16px;
}

.kiosk-cart-item-img {
  width: 72px;
  height: 72px;
  border-radius: 12px;
  overflow: hidden;
  flex-shrink: 0;
  background: rgba(255,255,255,0.05);
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-cart-item-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.kiosk-cart-item-emoji { font-size: 36px; }

.kiosk-cart-item-info { flex: 1; min-width: 0; }

.kiosk-cart-item-name-row {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.kiosk-cart-edit-btn {
  flex-shrink: 0;
  background: rgba(255,255,255,0.07);
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 8px;
  color: rgba(255,255,255,0.55);
  width: 28px; height: 28px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
  padding: 0;
}
.kiosk-cart-edit-btn:hover {
  background: rgba(232,0,28,0.15);
  color: #e8001c;
  border-color: rgba(232,0,28,0.3);
}

.kiosk-cart-item-name {
  font-size: 17px;
  font-weight: 700;
  color: white;
  margin: 0 0 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.kiosk-cart-item-note {
  font-size: 12px;
  color: rgba(255,255,255,0.4);
  margin: 0 0 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.kiosk-cart-item-unit {
  font-size: 13px;
  color: rgba(255,255,255,0.4);
}

/* Contrôles quantité */
.kiosk-cart-item-controls {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 10px;
  flex-shrink: 0;
}

.kiosk-qty-ctrl {
  display: flex;
  align-items: center;
  gap: 0;
  background: rgba(255,255,255,0.06);
  border-radius: 12px;
  border: 1.5px solid rgba(255,255,255,0.1);
  overflow: hidden;
}

.kiosk-qty-btn {
  width: 44px;
  height: 44px;
  border: none;
  background: transparent;
  color: white;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s ease;
}

.kiosk-qty-btn:active { background: rgba(255,255,255,0.1); }

.kiosk-qty-btn.minus { color: rgba(255,255,255,0.6); }
.kiosk-qty-btn.minus:active { color: #ff6b6b; }

.kiosk-qty-num {
  min-width: 36px;
  text-align: center;
  font-size: 18px;
  font-weight: 700;
  color: white;
}

.kiosk-cart-item-total {
  font-size: 18px;
  font-weight: 800;
  color: white;
}

/* Résumé totaux */
.kiosk-cart-summary {
  margin: 0 32px;
  background: var(--kiosk-dark-2);
  border-radius: 18px;
  border: 1.5px solid rgba(255,255,255,0.07);
  padding: 20px 24px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.kiosk-cart-summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 16px;
  color: rgba(255,255,255,0.6);
}

.kiosk-cart-summary-row.total {
  font-size: 20px;
  font-weight: 700;
  color: white;
  padding-top: 12px;
  border-top: 1px solid rgba(255,255,255,0.07);
  margin-top: 4px;
}

.kiosk-cart-summary-row.loyalty { color: rgba(255,255,255,0.8); }

.green { color: #2ECC71; font-weight: 700; }

.kiosk-cart-grand-total {
  font-size: 26px;
  font-weight: 900;
  color: white;
}

/* Actions */
.kiosk-cart-actions {
  padding: 20px 32px 32px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* Bouton fidélité */
.kiosk-btn-loyalty {
  width: 100%;
  height: 60px;
  background: linear-gradient(135deg, rgba(255,215,0,0.12), rgba(255,165,0,0.08));
  border: 1.5px solid rgba(255,215,0,0.35);
  border-radius: 16px;
  color: #FFD700;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  transition: background 0.2s, border-color 0.2s;
  margin-bottom: 4px;
}
.kiosk-btn-loyalty:active { background: rgba(255,215,0,0.2); }
.kiosk-btn-loyalty-star { font-size: 20px; }
.kiosk-btn-loyalty-arrow { font-size: 22px; opacity: 0.7; }

/* Boutons réutilisables */
.kiosk-btn-primary {
  width: 100%;
  height: 72px;
  background: var(--kiosk-primary);
  color: white;
  border: none;
  border-radius: 20px;
  font-size: 20px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 28px;
  box-shadow: 0 6px 24px rgba(232,0,28,0.35);
  transition: all 0.15s ease;
}

.kiosk-btn-primary:active { transform: scale(0.98); box-shadow: 0 3px 12px rgba(232,0,28,0.25); }

.kiosk-btn-price {
  font-size: 22px;
  font-weight: 800;
  background: rgba(255,255,255,0.2);
  padding: 6px 16px;
  border-radius: 12px;
}

.kiosk-btn-secondary {
  width: 100%;
  height: 60px;
  background: rgba(255,255,255,0.06);
  color: rgba(255,255,255,0.7);
  border: 1.5px solid rgba(255,255,255,0.12);
  border-radius: 16px;
  font-size: 17px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}

.kiosk-btn-secondary:active { background: rgba(255,255,255,0.1); }

/* Confirm-clear modal */
.kiosk-clear-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.65);
  display: flex; align-items: center; justify-content: center;
  z-index: 999;
}
.kiosk-clear-modal {
  background: #1a1a2e;
  border: 1.5px solid rgba(255,255,255,0.12);
  border-radius: 20px;
  padding: 2rem;
  width: 340px;
  text-align: center;
}
.kiosk-clear-title {
  font-size: 1.3rem;
  font-weight: 700;
  margin: 0 0 0.4rem;
  color: #fff;
}
.kiosk-clear-sub {
  color: rgba(255,255,255,0.5);
  font-size: 0.95rem;
  margin: 0 0 1.5rem;
}
.kiosk-clear-actions {
  display: flex;
  gap: 0.75rem;
}
.kiosk-clear-yes {
  flex: 1;
  background: #e53935;
  color: #fff;
  border: none;
  border-radius: 12px;
  padding: 0.85rem 1rem;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.2s;
}
.kiosk-clear-yes:hover { background: #c62828; }
.kiosk-clear-no {
  flex: 1;
  background: rgba(255,255,255,0.08);
  color: rgba(255,255,255,0.75);
  border: 1px solid rgba(255,255,255,0.15);
  border-radius: 12px;
  padding: 0.85rem 1rem;
  font-size: 1rem;
  cursor: pointer;
}
</style>
