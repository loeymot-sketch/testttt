<template>
  <div class="kiosk-step-menu">
    <h3 class="kiosk-step-title">En menu ?</h3>
    
    <div class="kiosk-menu-info">
      <span class="kiosk-info-badge">Frites + Boisson</span>
      <span v-if="menuPrice > 0" class="kiosk-menu-price">
        +{{ formatPrice(menuPrice) }}
      </span>
    </div>
    
    <div class="kiosk-menu-options">
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'full' }"
        @click="selectChoice('full')"
      >
        <span class="kiosk-menu-emoji">🍟🥤</span>
        <span class="kiosk-menu-name">Menu Complet</span>
        <span class="kiosk-menu-desc">Frites + Boisson</span>
      </div>
      
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'frites' }"
        @click="selectChoice('frites')"
      >
        <span class="kiosk-menu-emoji">🍟</span>
        <span class="kiosk-menu-name">+ Frites</span>
        <span class="kiosk-menu-desc">Seulement les frites</span>
      </div>
      
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'boisson' }"
        @click="selectChoice('boisson')"
      >
        <span class="kiosk-menu-emoji">🥤</span>
        <span class="kiosk-menu-name">+ Boisson</span>
        <span class="kiosk-menu-desc">Seulement la boisson</span>
      </div>
      
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'none' }"
        @click="selectChoice('none')"
      >
        <span class="kiosk-menu-emoji">🚫</span>
        <span class="kiosk-menu-name">Sans menu</span>
        <span class="kiosk-menu-desc">Article seul</span>
      </div>
    </div>
    
    <div v-if="showBoissonChoice" class="kiosk-boisson-section">
      <h4 class="kiosk-subtitle">Choisissez votre boisson</h4>
      <div class="kiosk-boisson-grid">
        <div
          v-for="boisson in boissonList"
          :key="boisson.id"
          class="kiosk-boisson-card"
          :class="{ selected: localBoisson === boisson.id }"
          @click="selectBoisson(boisson.id)"
        >
          <span class="kiosk-boisson-emoji">{{ boisson.emoji }}</span>
          <span class="kiosk-boisson-name">{{ boisson.name }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'KioskStepMenu',
  props: {
    step: Object,
    item: Object,
    selections: Object
  },
  emits: ['update'],
  data() {
    return {
      localChoice: this.selections.menuChoice || null,
      localBoisson: this.selections.boissonChoice || null
    };
  },
  computed: {
    menuPrice() {
      if (!this.item.addons || this.localChoice === 'none') return 0;
      
      const menuAddon = this.item.addons.find(a => 
        (a.addon_item_name || '').toLowerCase().includes('menu')
      );
      
      if (!menuAddon) return 0;
      
      return parseFloat(menuAddon.addon_item_convert_price || menuAddon.price || 0);
    },
    showBoissonChoice() {
      return this.localChoice === 'full' || this.localChoice === 'boisson';
    },
    boissonList() {
      // Chercher les boissons dans les addons
      if (!this.item.addons) return this.getDefaultBoissonList();
      
      const boissons = this.item.addons.filter(a =>
        (a.addon_item_name || '').toLowerCase().includes('boisson') ||
        (a.addon_item_name || '').toLowerCase().includes('soda') ||
        (a.addon_item_name || '').toLowerCase().includes('drink')
      );
      
      if (boissons.length === 0) return this.getDefaultBoissonList();
      
      return boissons.map((b, index) => ({
        id: b.addon_item_id || b.id || index,
        name: b.addon_item_name || b.name || 'Boisson',
        emoji: this.getEmojiForBoisson(b.addon_item_name || b.name)
      }));
    }
  },
  methods: {
    getDefaultBoissonList() {
      return [
        { id: 1, name: 'Coca-Cola', emoji: '🥤' },
        { id: 2, name: 'Coca Zéro', emoji: '🥤' },
        { id: 3, name: 'Fanta', emoji: '🥤' },
        { id: 4, name: 'Sprite', emoji: '🥤' },
        { id: 5, name: 'Eau', emoji: '💧' },
        { id: 6, name: 'Thé', emoji: '🧊' }
      ];
    },
    getEmojiForBoisson(name) {
      const lower = (name || '').toLowerCase();
      if (lower.includes('coca') || lower.includes('cola')) return '🥤';
      if (lower.includes('fanta') || lower.includes('orange')) return '🍊';
      if (lower.includes('sprite') || lower.includes('citron') || lower.includes('lemon')) return '🍋';
      if (lower.includes('eau') || lower.includes('water')) return '💧';
      if (lower.includes('thé') || lower.includes('tea') || lower.includes('ice tea')) return '🧊';
      if (lower.includes('jus') || lower.includes('orange') || lower.includes('juice')) return '🧃';
      return '🥤';
    },
    formatPrice(price) {
      return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'EUR'
      }).format(price || 0);
    },
    selectChoice(choice) {
      this.localChoice = choice;
      this.$emit('update', 'menuChoice', choice);
    },
    selectBoisson(boissonId) {
      this.localBoisson = boissonId;
      this.$emit('update', 'boissonChoice', boissonId);
    }
  }
};
</script>

<style scoped>
.kiosk-step-menu {
  padding: 16px;
}

.kiosk-step-title {
  font-size: 24px;
  font-weight: 700;
  text-align: center;
  margin-bottom: 16px;
  color: #1a1a2e;
}

.kiosk-menu-info {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  margin-bottom: 24px;
}

.kiosk-info-badge {
  background: #E93C3C;
  color: white;
  padding: 8px 16px;
  border-radius: 20px;
  font-size: 14px;
  font-weight: 600;
}

.kiosk-menu-price {
  font-size: 20px;
  font-weight: 700;
  color: #E93C3C;
}

.kiosk-menu-options {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
  margin-bottom: 32px;
}

.kiosk-menu-card {
  min-height: 120px;
  border-radius: 16px;
  border: 3px solid #EFF0F6;
  background: white;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 16px;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.2s ease;
}

.kiosk-menu-card:active {
  transform: scale(0.98);
}

.kiosk-menu-card.selected {
  border-color: #E93C3C;
  background: #FFF0F0;
  box-shadow: 0 4px 20px rgba(233, 60, 60, 0.15);
}

.kiosk-menu-emoji {
  font-size: 36px;
  margin-bottom: 8px;
}

.kiosk-menu-name {
  font-size: 16px;
  font-weight: 600;
  color: #1a1a2e;
  text-align: center;
}

.kiosk-menu-desc {
  font-size: 12px;
  color: #666;
  text-align: center;
  margin-top: 4px;
}

.kiosk-boisson-section {
  border-top: 2px solid #EFF0F6;
  padding-top: 24px;
}

.kiosk-subtitle {
  font-size: 20px;
  font-weight: 600;
  text-align: center;
  margin-bottom: 16px;
  color: #1a1a2e;
}

.kiosk-boisson-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}

.kiosk-boisson-card {
  min-height: 80px;
  border-radius: 12px;
  border: 2px solid #EFF0F6;
  background: white;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 12px;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.2s ease;
}

.kiosk-boisson-card:active {
  transform: scale(0.98);
}

.kiosk-boisson-card.selected {
  border-color: #E93C3C;
  background: #FFF0F0;
}

.kiosk-boisson-emoji {
  font-size: 28px;
  margin-bottom: 4px;
}

.kiosk-boisson-name {
  font-size: 12px;
  font-weight: 600;
  color: #1a1a2e;
  text-align: center;
}
</style>
