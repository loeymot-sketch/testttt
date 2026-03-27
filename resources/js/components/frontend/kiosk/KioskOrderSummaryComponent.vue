<template>
  <div class="kiosk-order-summary">
    <!-- Item principal -->
    <div class="kiosk-summary-item main">
      <div class="kiosk-summary-img">
        <img v-if="item.thumb" :src="item.thumb" />
        <span v-else class="kiosk-summary-emoji">🍽️</span>
      </div>
      <div class="kiosk-summary-details">
        <span class="kiosk-summary-name">{{ item.name }}</span>
        <span class="kiosk-summary-price">{{ formatPrice(item.convert_price) }}</span>
      </div>
    </div>
    
    <!-- Sélections -->
    <div class="kiosk-summary-sections">
      <!-- Pain -->
      <div v-if="selections.pain" class="kiosk-summary-section">
        <h4>Type de pain</h4>
        <div class="kiosk-summary-row">
          <span>{{ getPainName() }}</span>
          <span class="kiosk-free">Inclus</span>
        </div>
      </div>
      
      <!-- Viandes -->
      <div v-if="selections.totalViandes > 0" class="kiosk-summary-section">
        <h4>Viandes ({{ selections.totalViandes }})</h4>
        <div v-for="(count, key) in selections.viandes" :key="key" class="kiosk-summary-row">
          <span v-if="count > 0">{{ formatViandeName(key) }} x{{ count }}</span>
        </div>
      </div>
      
      <!-- Sauces -->
      <div v-if="selections.sauceOrder.length > 0" class="kiosk-summary-section">
        <h4>Sauces ({{ selections.sauceOrder.length }})</h4>
        <div v-for="(sauceId, index) in selections.sauceOrder" :key="sauceId" class="kiosk-summary-row">
          <span>{{ getSauceName(sauceId) }}</span>
          <span v-if="index === 0" class="kiosk-free">Gratuite</span>
          <span v-else class="kiosk-price">+{{ formatPrice(extraSaucePrice) }}</span>
        </div>
      </div>
      
      <!-- Garnitures -->
      <div v-if="selectedGarnituresCount > 0" class="kiosk-summary-section">
        <h4>Garnitures ({{ selectedGarnituresCount }})</h4>
        <div class="kiosk-summary-tags">
          <span v-for="(selected, id) in selections.garnitures" :key="id" v-if="selected" class="kiosk-tag">
            {{ getGarnitureName(id) }}
          </span>
        </div>
      </div>
      
      <!-- Suppléments -->
      <div v-if="selectedSupplements.length > 0" class="kiosk-summary-section">
        <h4>Suppléments ({{ selectedSupplements.length }})</h4>
        <div v-for="supplement in selectedSupplements" :key="supplement.id" class="kiosk-summary-row">
          <span>{{ supplement.name }}</span>
          <span class="kiosk-price">+{{ formatPrice(supplement.price) }}</span>
        </div>
      </div>
      
      <!-- Menu -->
      <div v-if="selections.menuChoice && selections.menuChoice !== 'none'" class="kiosk-summary-section">
        <h4>Menu</h4>
        <div class="kiosk-summary-row">
          <span>{{ getMenuLabel() }}</span>
          <span v-if="menuPrice > 0" class="kiosk-price">+{{ formatPrice(menuPrice) }}</span>
          <span v-else class="kiosk-free">Inclus</span>
        </div>
        <div v-if="selections.boissonChoice" class="kiosk-summary-row boisson">
          <span>→ {{ getBoissonName() }}</span>
        </div>
      </div>
    </div>
    
    <!-- Total -->
    <div class="kiosk-summary-total">
      <span>Total</span>
      <span class="kiosk-total-price">{{ formatPrice(runningTotal) }}</span>
    </div>
    
    <!-- Quantité -->
    <div class="kiosk-quantity-section">
      <span>Quantité :</span>
      <div class="kiosk-qty-controls">
        <button @click="decrementQty" :disabled="selections.quantity <= 1">−</button>
        <span>{{ selections.quantity }}</span>
        <button @click="incrementQty">+</button>
      </div>
    </div>
  </div>
</template>

<script>
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';

export default {
  name: 'KioskOrderSummary',
  mixins: [kioskPriceMixin],
  props: {
    step: Object,
    item: Object,
    selections: Object
  },
  emits: ['update'],
  computed: {
    selectedGarnituresCount() {
      return Object.values(this.selections.garnitures || {}).filter(Boolean).length;
    },
    selectedSupplements() {
      if (!this.item.extras) return [];
      
      return this.item.extras
        .filter(e => this.selections.supplements?.[e.id] && parseFloat(e.convert_price || e.price || 0) > 0)
        .map(e => ({
          id: e.id,
          name: e.name,
          price: parseFloat(e.convert_price || e.price || 0)
        }));
    },
    menuPrice() {
      if (!this.item.addons || this.selections.menuChoice === 'none') return 0;
      
      const menuAddon = this.item.addons.find(a => 
        (a.addon_item_name || '').toLowerCase().includes('menu')
      );
      
      if (!menuAddon) return 0;
      
      const fullPrice = parseFloat(menuAddon.addon_item_convert_price || menuAddon.price || 0);
      
      switch (this.selections.menuChoice) {
        case 'full': return fullPrice;
        case 'frites': return fullPrice * 0.6;
        case 'boisson': return fullPrice * 0.4;
        default: return 0;
      }
    },
    extraSaucePrice() {
      // Read sauce extra price from DB variations if available, fallback to 0.50€
      const sauceAttr = this.item.itemAttributes?.find(a =>
        (a.name || '').toLowerCase().includes('sauce')
      );
      if (sauceAttr && this.item.variations?.[sauceAttr.id]) {
        const sauceVar = this.item.variations[sauceAttr.id].find(v =>
          parseFloat(v.convert_price || v.price || 0) > 0
        );
        if (sauceVar) return parseFloat(sauceVar.convert_price || sauceVar.price || 0.50);
      }
      return 0.50;
    },
    runningTotal() {
      let total = parseFloat(this.item.convert_price) || 0;

      // Sauces supplémentaires — prix depuis DB ou fallback 0.50€
      if (this.selections.sauceOrder.length > 1) {
        total += (this.selections.sauceOrder.length - 1) * this.extraSaucePrice;
      }

      // Suppléments
      total += this.selectedSupplements.reduce((sum, s) => sum + s.price, 0);

      // Menu
      total += this.menuPrice;

      return total * (this.selections.quantity || 1);
    }
  },
  methods: {
    // formatPrice() provided by kioskPriceMixin
    getPainName() {
      const painId = this.selections.pain;
      if (!painId) return 'Non sélectionné';
      
      const painAttr = this.item.itemAttributes?.find(a => 
        (a.name || '').toLowerCase().includes('pain')
      );
      if (painAttr && this.item.variations?.[painAttr.id]) {
        const pain = this.item.variations[painAttr.id].find(v => v.id === painId);
        return pain?.name || painId;
      }
      return painId;
    },
    formatViandeName(key) {
      return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    },
    getSauceName(sauceId) {
      const sauceAttr = this.item.itemAttributes?.find(a => 
        (a.name || '').toLowerCase().includes('sauce')
      );
      if (sauceAttr && this.item.variations?.[sauceAttr.id]) {
        const sauce = this.item.variations[sauceAttr.id].find(v => v.id === sauceId);
        return sauce?.name || `Sauce #${sauceId}`;
      }
      return `Sauce #${sauceId}`;
    },
    getGarnitureName(id) {
      const garniture = this.item.extras?.find(e => e.id === parseInt(id));
      return garniture?.name || `Garniture #${id}`;
    },
    getMenuLabel() {
      const labels = {
        full: 'Menu complet (frites + boisson)',
        frites: 'Avec frites',
        boisson: 'Avec boisson',
        none: 'Sans menu'
      };
      return labels[this.selections.menuChoice] || this.selections.menuChoice;
    },
    getBoissonName() {
      const boissonId = this.selections.boissonChoice;
      if (!boissonId) return 'Non sélectionnée';
      
      // Chercher dans les addons
      const boisson = this.item.addons?.find(a => 
        (a.addon_item_id || a.id) === boissonId
      );
      
      if (boisson) return boisson.addon_item_name || boisson.name || 'Boisson';
      
      return `Boisson #${boissonId}`;
    },
    incrementQty() {
      this.$emit('update', 'quantity', (this.selections.quantity || 1) + 1);
    },
    decrementQty() {
      if (this.selections.quantity > 1) {
        this.$emit('update', 'quantity', this.selections.quantity - 1);
      }
    }
  }
};
</script>

<style scoped>
.kiosk-order-summary {
  padding: 10px 18px 22px;
  background: #fff;
  min-height: 100%;
}

.kiosk-summary-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  background: white;
  border: 1px solid #E9E9E9;
  border-radius: 18px;
  margin-bottom: 12px;
}

.kiosk-summary-item.main {
  border: 2px solid #E8001C;
  background: rgba(232,0,28,0.03);
}

.kiosk-summary-img {
  width: 56px;
  height: 56px;
  border-radius: 10px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #F7F7F8;
  flex-shrink: 0;
}

.kiosk-summary-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.kiosk-summary-emoji { font-size: 28px; }

.kiosk-summary-details {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.kiosk-summary-name {
  font-size: 16px;
  font-weight: 700;
  color: #1A1A1A;
}

.kiosk-summary-price {
  font-size: 14px;
  color: #E8001C;
  font-weight: 700;
}

.kiosk-summary-sections {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 12px;
}

.kiosk-summary-section {
  background: white;
  border: 1px solid #E9E9E9;
  border-radius: 16px;
  padding: 12px 16px;
}

.kiosk-summary-section h4 {
  font-size: 10px;
  font-weight: 700;
  color: #999;
  text-transform: uppercase;
  margin: 0 0 8px;
  letter-spacing: 1px;
}

.kiosk-summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 5px 0;
  border-bottom: 1px solid #F0F0F0;
  font-size: 13px;
  color: #555;
}

.kiosk-summary-row:last-child { border-bottom: none; }

.kiosk-summary-row.boisson {
  padding-left: 14px;
  color: #999;
  font-size: 12px;
}

.kiosk-free {
  color: #27ae60;
  font-weight: 700;
  font-size: 11px;
}

.kiosk-price {
  color: #E8001C;
  font-weight: 700;
  font-size: 12px;
}

.kiosk-summary-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.kiosk-tag {
  background: rgba(46,204,113,0.08);
  border: 1px solid rgba(46,204,113,0.2);
  color: #27ae60;
  padding: 4px 10px;
  border-radius: 50px;
  font-size: 11px;
  font-weight: 600;
}

.kiosk-summary-total {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 18px;
  background: #f7e5e8;
  border: 1px solid #efd2d7;
  border-radius: 16px;
  margin-bottom: 12px;
}

.kiosk-summary-total span:first-child {
  color: #7b4a52;
  font-size: 15px;
  font-weight: 700;
}

.kiosk-total-price {
  color: #d7263d;
  font-size: 24px;
  font-weight: 900;
}

.kiosk-quantity-section {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 12px 18px;
  background: white;
  border: 1px solid #E9E9E9;
  border-radius: 16px;
}

.kiosk-quantity-section > span {
  font-size: 14px;
  font-weight: 600;
  color: #555;
}

.kiosk-qty-controls {
  display: flex;
  align-items: center;
  gap: 10px;
}

.kiosk-qty-controls button {
  width: 44px;
  height: 44px;
  border: none;
  background: #F7F7F8;
  color: #1A1A1A;
  font-size: 22px;
  font-weight: 700;
  cursor: pointer;
  touch-action: manipulation;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
  border-radius: 50%;
  border: 1.5px solid #E0E0E0;
}

.kiosk-qty-controls button:first-child {
  color: #777;
}

.kiosk-qty-controls button:last-child {
  background: #E8001C;
  border-color: #E8001C;
  color: white;
}

.kiosk-qty-controls button:active:not(:disabled) {
  transform: scale(0.92);
}

.kiosk-qty-controls button:disabled {
  opacity: 0.58;
  cursor: not-allowed;
}

.kiosk-qty-controls span {
  font-size: 20px;
  font-weight: 800;
  color: #1A1A1A;
  min-width: 40px;
  text-align: center;
}
</style>
