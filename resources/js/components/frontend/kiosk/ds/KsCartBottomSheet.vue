<template>
  <!-- FoodKing brand V2 (2026-05-10) — Cart bottom-sheet visible toujours
       quand cart > 0 sur la welcome page. Remplace le toast add-to-cart
       (owner ne veut PAS de notification, veut voir les items en bas direct).
       Owner spec :
        - Image produit + nom + prix + boutons +/− + delete (− avec count=1)
        - Affichage permanent quand cartCount > 0
        - Design flat (pas marketing-heavy) -->
  <transition name="kiosk-cart-strip-slide">
    <div
      v-if="items.length > 0"
      class="kiosk-cart-strip"
      role="region"
      :aria-label="ariaLabel"
      data-testid="kiosk-cart-bottom-sheet"
    >
      <div class="kiosk-cart-strip-header">
        <span class="kiosk-cart-strip-title">{{ headerLabel }}</span>
        <span class="kiosk-cart-strip-count" data-testid="kiosk-cart-bottom-sheet-count">
          {{ items.length }} {{ items.length > 1 ? itemPluralLabel : itemSingularLabel }}
        </span>
      </div>

      <div class="kiosk-cart-strip-list" role="list">
        <div
          v-for="(item, idx) in items"
          :key="idx + '-' + item.item_id"
          class="kiosk-cart-strip-item"
          role="listitem"
          :data-testid="`kiosk-cart-bottom-sheet-item-${idx}`"
        >
          <!-- V3 layout horizontal compact (owner request 2026-05-10) :
               [IMG 64×64] Nom + prix          [- N +]
               Card height ~80px, sheet total ~150px (-60% vs v2). -->
          <div class="kiosk-cart-strip-thumb">
            <img
              v-if="item.image"
              :src="item.image"
              :alt="item.name"
              class="kiosk-cart-strip-img"
              loading="lazy"
            />
            <div v-else class="kiosk-cart-strip-img-fallback" aria-hidden="true">🍔</div>
          </div>

          <div class="kiosk-cart-strip-info">
            <div class="kiosk-cart-strip-name">{{ truncate(item.name, 22) }}</div>
            <div class="kiosk-cart-strip-price">{{ formatPrice(item.total) }}</div>
          </div>

          <div class="kiosk-cart-strip-qty" role="group" :aria-label="qtyLabel">
            <button
              type="button"
              class="kiosk-cart-strip-btn kiosk-cart-strip-btn--minus"
              :aria-label="item.quantity > 1 ? decrementLabel : removeLabel"
              :data-testid="`kiosk-cart-bottom-sheet-decrement-${idx}`"
              @click="$emit('decrement', idx)"
            >
              <svg v-if="item.quantity > 1" width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <path d="M5 10h10" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
              </svg>
              <svg v-else width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <path d="M3 6h14M8 6V4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2m1 0v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6h10Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
            <span
              class="kiosk-cart-strip-quantity"
              :data-testid="`kiosk-cart-bottom-sheet-qty-${idx}`"
            >{{ item.quantity }}</span>
            <button
              type="button"
              class="kiosk-cart-strip-btn kiosk-cart-strip-btn--plus"
              :aria-label="incrementLabel"
              :data-testid="`kiosk-cart-bottom-sheet-increment-${idx}`"
              @click="$emit('increment', idx)"
            >
              <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <path d="M10 5v10M5 10h10" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>
  </transition>
</template>

<script>
/**
 * KsCartBottomSheet — Persistent cart strip pour la welcome page.
 * -----------------------------------------------------------------------------
 * FoodKing brand V2 — design refresh 2026-05-10 (owner request).
 *
 * Remplace le toast "produit ajouté" (owner refuse les notifications) par une
 * bande visible permanente qui montre les items panier avec leur image,
 * nom, prix, et boutons +/−. Le bouton − sur quantity=1 fonctionne comme
 * delete (icône poubelle).
 *
 * Props :
 *  - items : Array<CartItem> (forme buildSimpleCartItem du store kioskCart)
 *  - formatPrice : Function — formatter monétaire (kioskPriceMixin)
 *  - labels : Object — i18n strings overrides (FR par défaut)
 *
 * Emits :
 *  - increment(index) : ajouter 1 à la qty
 *  - decrement(index) : retirer 1 (caller décide remove si qty===1)
 *
 * Discipline :
 *  - Aucune logique pricing / business (read-only display)
 *  - Pas de side-effect sur le store (delegate à l'appelant)
 *  - Style scoped, tokens --kiosk-bold-* pour cohérence light mode
 */
export default {
    name: 'KsCartBottomSheet',
    props: {
        items: {
            type: Array,
            default: () => [],
        },
        formatPrice: {
            type: Function,
            required: true,
        },
        labels: {
            type: Object,
            default: () => ({}),
        },
    },
    emits: ['increment', 'decrement'],
    computed: {
        ariaLabel() {
            return this.labels.ariaRegion || 'Aperçu de votre panier';
        },
        headerLabel() {
            return this.labels.header || 'Votre commande';
        },
        itemSingularLabel() {
            return this.labels.itemSingular || 'article';
        },
        itemPluralLabel() {
            return this.labels.itemPlural || 'articles';
        },
        qtyLabel() {
            return this.labels.qty || 'Quantité';
        },
        incrementLabel() {
            return this.labels.increment || 'Ajouter un';
        },
        decrementLabel() {
            return this.labels.decrement || 'Retirer un';
        },
        removeLabel() {
            return this.labels.remove || 'Supprimer du panier';
        },
    },
    methods: {
        truncate(str, max) {
            if (!str || typeof str !== 'string') return '';
            return str.length > max ? str.slice(0, Math.max(0, max - 1)) + '…' : str;
        },
    },
};
</script>

<style scoped>
/* FoodKing brand V3 (2026-05-10) — Cart bottom-sheet HORIZONTAL compact.
   Owner v3 feedback : "30-40% plus petit, image réelle, design smart".
   Layout par card : [IMG 64] Nom + prix [-N+] horizontal, ~80px tall.
   Total sheet ~150-160px (vs 364px v2). */
.kiosk-cart-strip {
    position: absolute;
    left: 0;
    right: 0;
    bottom: var(--kiosk-bottom-bar-h, 118px);
    z-index: 30;
    background: #FFFFFF;
    border-top: 2px solid #0F0F0F;
    box-shadow: 0 -8px 20px rgba(15, 15, 15, 0.08);
    padding: 10px 16px 12px;
    isolation: isolate;
    transform: translateZ(0);
    backdrop-filter: none;
}

.kiosk-cart-strip-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
    padding: 0 2px;
}

.kiosk-cart-strip-title {
    font-family: var(--kiosk-font-display, var(--kiosk-font-latin, sans-serif));
    font-size: 16px;
    font-weight: 800;
    color: #0F0F0F;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.kiosk-cart-strip-count {
    font-size: 12px;
    font-weight: 700;
    color: #FFFFFF;
    background: #F4501E;
    padding: 3px 10px;
    border-radius: 999px;
    letter-spacing: 0.2px;
}

.kiosk-cart-strip-list {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 2px 2px 4px;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #E5E5E5 transparent;
}

.kiosk-cart-strip-list::-webkit-scrollbar {
    height: 3px;
}
.kiosk-cart-strip-list::-webkit-scrollbar-thumb {
    background: #E5E5E5;
    border-radius: 3px;
}

/* Horizontal compact card V3 (owner spec) :
   [thumb 64] [info: name+price] [stepper +/-]
   Width ~280px, height ~80px (3 cards rentrent largement) */
.kiosk-cart-strip-item {
    display: flex;
    flex-direction: row;
    align-items: center;
    flex: 0 0 auto;
    width: 280px;
    padding: 8px 10px 8px 8px;
    background: #FFFFFF;
    border: 1.5px solid #E5E5E5;
    border-radius: 14px;
    scroll-snap-align: start;
    gap: 10px;
    transition: border-color 160ms ease, box-shadow 160ms ease;
}

.kiosk-cart-strip-item:hover {
    border-color: #F4501E;
    box-shadow: 0 3px 10px rgba(244, 80, 30, 0.14);
}

.kiosk-cart-strip-thumb {
    position: relative;
    flex-shrink: 0;
    width: 64px;
    height: 64px;
    border-radius: 10px;
    overflow: hidden;
    background: #FAFAFA;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #EFEFEF;
}

.kiosk-cart-strip-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.kiosk-cart-strip-img-fallback {
    font-size: 32px;
    line-height: 1;
}

.kiosk-cart-strip-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
    justify-content: center;
}

.kiosk-cart-strip-name {
    font-size: 13px;
    font-weight: 700;
    color: #0F0F0F;
    line-height: 1.25;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.kiosk-cart-strip-price {
    font-size: 15px;
    font-weight: 900;
    color: #F4501E;
    line-height: 1;
    letter-spacing: -0.2px;
}

/* Stepper compact +/− horizontal */
.kiosk-cart-strip-qty {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    background: #F7F7F7;
    border-radius: 999px;
    padding: 3px;
    gap: 2px;
}

.kiosk-cart-strip-btn {
    width: 32px;
    height: 32px;
    min-width: 32px;
    border: 0;
    border-radius: 50%;
    background: #FFFFFF;
    color: #0F0F0F;
    font-size: 14px;
    font-weight: 700;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform 120ms ease, background 120ms ease, color 120ms ease;
    -webkit-tap-highlight-color: transparent;
}

.kiosk-cart-strip-btn:hover {
    background: #FFE8DD;
    color: #F4501E;
}

.kiosk-cart-strip-btn:active {
    transform: scale(0.9);
}

.kiosk-cart-strip-btn--plus {
    background: #F4501E;
    color: #FFFFFF;
}

.kiosk-cart-strip-btn--plus:hover {
    background: #DC4517;
    color: #FFFFFF;
}

.kiosk-cart-strip-btn:focus-visible {
    outline: 2px solid #2563EB;
    outline-offset: 2px;
}

.kiosk-cart-strip-quantity {
    flex: 0 0 auto;
    min-width: 22px;
    padding: 0 4px;
    text-align: center;
    font-size: 14px;
    font-weight: 800;
    color: #0F0F0F;
}

/* Slide-up enter / exit transition */
.kiosk-cart-strip-slide-enter-active,
.kiosk-cart-strip-slide-leave-active {
    transition: transform 240ms cubic-bezier(0.4, 0, 0.2, 1),
                opacity 200ms ease;
}

.kiosk-cart-strip-slide-enter-from,
.kiosk-cart-strip-slide-leave-to {
    transform: translateY(20px);
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .kiosk-cart-strip-slide-enter-active,
    .kiosk-cart-strip-slide-leave-active {
        transition: opacity 120ms ease;
    }
    .kiosk-cart-strip-slide-enter-from,
    .kiosk-cart-strip-slide-leave-to {
        transform: none;
    }
}
</style>
