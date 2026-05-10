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
            <div class="kiosk-cart-strip-name">{{ truncate(item.name, 32) }}</div>
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
              <span aria-hidden="true">{{ item.quantity > 1 ? '−' : '🗑' }}</span>
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
              <span aria-hidden="true">+</span>
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
.kiosk-cart-strip {
    /* Positioned above the existing kiosk-bottom-bar.
       z-index 25 to beat product card stacking context (cards: z-auto + relative). */
    position: absolute;
    left: 0;
    right: 0;
    bottom: var(--kiosk-bottom-bar-h, 138px);
    z-index: 25;
    background: var(--kiosk-bold-surface, #FFFFFF);
    border-top: 1px solid var(--kiosk-bold-border, #E5E5E5);
    border-bottom: 1px solid var(--kiosk-bold-border, #E5E5E5);
    padding: 12px 16px 14px;
    box-shadow: var(--kiosk-shadow-sticky-bold, 0 -4px 16px rgba(15,15,15,0.06));
    /* Force solid opaque rendering — cover product grid behind. */
    isolation: isolate;
}

.kiosk-cart-strip-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 10px;
    padding: 0 4px;
}

.kiosk-cart-strip-title {
    font-family: var(--kiosk-font-display, var(--kiosk-font-latin));
    font-size: 18px;
    font-weight: 700;
    color: var(--kiosk-bold-text-primary, #0F0F0F);
    letter-spacing: 0.2px;
}

.kiosk-cart-strip-count {
    font-size: 13px;
    font-weight: 600;
    color: var(--kiosk-bold-text-secondary, #5A5A5A);
}

.kiosk-cart-strip-list {
    display: flex;
    gap: 12px;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 4px 4px 8px;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: var(--kiosk-bold-border, #E5E5E5) transparent;
}

.kiosk-cart-strip-list::-webkit-scrollbar {
    height: 4px;
}
.kiosk-cart-strip-list::-webkit-scrollbar-thumb {
    background: var(--kiosk-bold-border, #E5E5E5);
    border-radius: 4px;
}

.kiosk-cart-strip-item {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 0 0 auto;
    min-width: 260px;
    max-width: 320px;
    padding: 8px 10px 8px 8px;
    background: var(--kiosk-bold-surface, #FFFFFF);
    border: 1px solid var(--kiosk-bold-border, #E5E5E5);
    border-radius: 14px;
    scroll-snap-align: start;
}

.kiosk-cart-strip-thumb {
    flex-shrink: 0;
    width: 56px;
    height: 56px;
    border-radius: 10px;
    overflow: hidden;
    background: var(--kiosk-bold-surface-subtle, #F7F7F7);
    display: flex;
    align-items: center;
    justify-content: center;
}

.kiosk-cart-strip-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.kiosk-cart-strip-img-fallback {
    font-size: 24px;
    line-height: 1;
}

.kiosk-cart-strip-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.kiosk-cart-strip-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--kiosk-bold-text-primary, #0F0F0F);
    line-height: 1.25;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.kiosk-cart-strip-price {
    font-size: 15px;
    font-weight: 800;
    color: var(--kiosk-bold-primary, #F4501E);
    line-height: 1.1;
}

.kiosk-cart-strip-qty {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--kiosk-bold-surface-subtle, #F7F7F7);
    border-radius: 999px;
    padding: 4px;
}

.kiosk-cart-strip-btn {
    width: 36px;
    height: 36px;
    min-width: 36px;
    border: 0;
    border-radius: 50%;
    background: var(--kiosk-bold-surface, #FFFFFF);
    color: var(--kiosk-bold-text-primary, #0F0F0F);
    font-size: 18px;
    font-weight: 700;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform 120ms ease, background 120ms ease;
    -webkit-tap-highlight-color: transparent;
}

.kiosk-cart-strip-btn:hover {
    background: var(--kiosk-bold-primary-soft, #FFE8DD);
}

.kiosk-cart-strip-btn:active {
    transform: scale(0.92);
}

.kiosk-cart-strip-btn--plus {
    background: var(--kiosk-bold-primary, #F4501E);
    color: #FFFFFF;
}

.kiosk-cart-strip-btn--plus:hover {
    background: var(--kiosk-bold-primary-hover, #DC4517);
}

.kiosk-cart-strip-btn--minus span {
    font-size: 16px;
}

.kiosk-cart-strip-btn:focus-visible {
    outline: 3px solid var(--kiosk-focus-ring, #2563EB);
    outline-offset: 2px;
}

.kiosk-cart-strip-quantity {
    min-width: 18px;
    text-align: center;
    font-size: 14px;
    font-weight: 700;
    color: var(--kiosk-bold-text-primary, #0F0F0F);
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
