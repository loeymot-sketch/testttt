<template>
  <div class="kiosk-order-summary">
    <h3 class="kiosk-summary-title">Récapitulatif de votre commande</h3>
    
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
          <span v-else class="kiosk-price">+0.50€</span>
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
export default {
  name: 'KioskOrderSummary',
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
    runningTotal() {
      let total = parseFloat(this.item.convert_price) || 0;
      
      // Sauces supplémentaires
      if (this.selections.sauceOrder.length > 1) {
        total += (this.selections.sauceOrder.length - 1) * 0.50;
      }
      
      // Suppléments
      total += this.selectedSupplements.reduce((sum, s) => sum + s.price, 0);
      
      // Menu
      total += this.menuPrice;
      
      return total * (this.selections.quantity || 1);
    }
  },
  methods: {
    formatPrice(price) {
      return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'EUR'
      }).format(price || 0);
    },
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
  padding: 16px;
}

.kiosk-summary-title {
  font-size: 22px;
  font-weight: 700;
  text-align: center;
  margin-bottom: 24px;
  color: #1a1a2e;
}

.kiosk-summary-item {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px;
  background: white;
  border-radius: 16px;
  margin-bottom: 16px;
}

.kiosk-summary-item.main {
  border: 2px solid #E93C3C;
}

.kiosk-summary-img {
  width: 64px;
  height: 64px;
  border-radius: 12px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #F8F9FA;
}

.kiosk-summary-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.kiosk-summary-emoji {
  font-size: 36px;
}

.kiosk-summary-details {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.kiosk-summary-name {
  font-size: 18px;
  font-weight: 700;
  color: #1a1a2e;
}

.kiosk-summary-price {
  font-size: 16px;
  color: #E93C3C;
  font-weight: 600;
}

.kiosk-summary-sections {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-bottom: 16px;
}

.kiosk-summary-section {
  background: white;
  border-radius: 12px;
  padding: 16px;
}

.kiosk-summary-section h4 {
  font-size: 14px;
  font-weight: 700;
  color: #666;
  text-transform: uppercase;
  margin: 0 0 12px 0;
  letter-spacing: 0.5px;
}

.kiosk-summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  border-bottom: 1px solid #F0F0F0;
  font-size: 16px;
}

.kiosk-summary-row:last-child {
  border-bottom: none;
}

.kiosk-summary-row.boisson {
  padding-left: 16px;
  color: #666;
}

.kiosk-free {
  color: #43C6AC;
  font-weight: 600;
  font-size: 14px;
}

.kiosk-price {
  color: #E93C3C;
  font-weight: 600;
}

.kiosk-summary-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.kiosk-tag {
  background: #F0FDF4;
  color: #166534;
  padding: 6px 12px;
  border-radius: 16px;
  font-size: 14px;
  font-weight: 500;
}

.kiosk-summary-total {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  background: #1a1a2e;
  border-radius: 16px;
  margin-bottom: 16px;
}

.kiosk-summary-total span:first-child {
  color: white;
  font-size: 18px;
  font-weight: 600;
}

.kiosk-total-price {
  color: #E93C3C;
  font-size: 28px;
  font-weight: 800;
}

.kiosk-quantity-section {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 16px;
  background: white;
  border-radius: 12px;
}

.kiosk-quantity-section span {
  font-size: 16px;
  font-weight: 600;
  color: #1a1a2e;
}

.kiosk-qty-controls {
  display: flex;
  align-items: center;
  gap: 16px;
}

.kiosk-qty-controls button {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  border: 2px solid #E93C3C;
  background: white;
  color: #E93C3C;
  font-size: 20px;
  font-weight: 700;
  cursor: pointer;
  touch-action: manipulation;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-qty-controls button:disabled {
  border-color: #ddd;
  color: #aaa;
  cursor: not-allowed;
}

.kiosk-qty-controls span {
  font-size: 20px;
  font-weight: 700;
  min-width: 32px;
  text-align: center;
}
</style>
