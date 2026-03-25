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
    
    <div v-if="showBoissonChoice && boissonList.length > 0" class="kiosk-boisson-section">
      <h4 class="kiosk-subtitle">Choisissez votre boisson</h4>
      <div class="kiosk-boisson-grid">
        <div
          v-for="boisson in boissonList"
          :key="boisson.id ?? boisson.name"
          class="kiosk-boisson-card"
          :class="{ selected: localBoisson === (boisson.id ?? boisson.name) }"
          @click="selectBoisson(boisson)"
        >
          <span class="kiosk-boisson-emoji">{{ boisson.emoji }}</span>
          <span class="kiosk-boisson-name">{{ boisson.name }}</span>
        </div>
      </div>
    </div>
    <div v-else-if="showBoissonChoice" class="kiosk-boisson-section">
      <p class="kiosk-boisson-placeholder">Votre boisson sera choisie au comptoir 🥤</p>
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
      // Filter addons for drink/boisson items — map to display objects with REAL addon_item_id
      if (!this.item.addons?.length) return [];

      // Addons may be drink options (specific boissons) — use name heuristic to distinguish
      const isDrinkAddon = (name) => {
        const n = (name || '').toLowerCase();
        return n.includes('coca') || n.includes('fanta') || n.includes('sprite') ||
               n.includes('eau') || n.includes('thé') || n.includes('jus') ||
               n.includes('boisson') || n.includes('soda') || n.includes('drink') ||
               n.includes('limonade') || n.includes('orangina');
      };

      const boissonAddons = this.item.addons.filter(a => isDrinkAddon(a.addon_item_name || a.name));

      if (boissonAddons.length === 0) {
        // No drink addons — return empty list (user just picks "boisson" label without choosing)
        return [];
      }

      return boissonAddons.map(b => ({
        // Use real addon_item_id if numeric, otherwise use addon name as key
        id:   typeof b.addon_item_id === 'number' ? b.addon_item_id : (b.id || null),
        name: b.addon_item_name || b.name || 'Boisson',
        emoji: this.getEmojiForBoisson(b.addon_item_name || b.name),
        // Keep full addon object for instruction building
        _addon: b,
      }));
    },

    // Whether we have real DB ids for boissons (vs name-only fallback)
    hasBoissonIds() {
      return this.boissonList.some(b => typeof b.id === 'number');
    },
  },
  methods: {
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
    selectBoisson(boisson) {
      // boisson is a full object { id, name, emoji, _addon }
      this.localBoisson = boisson.id ?? boisson.name;
      this.$emit('update', 'boissonChoice', this.localBoisson, {
        boissonName: boisson.name,
        boissonId:   typeof boisson.id === 'number' ? boisson.id : null,
      });
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
