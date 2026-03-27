<template>
  <div class="kiosk-step-menu">
    <h3 class="kiosk-step-title">Quel menu ?</h3>

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
        <span v-if="localChoice === 'full'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
      </div>
      
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'frites' }"
        @click="selectChoice('frites')"
      >
        <span class="kiosk-menu-emoji">🍟</span>
        <span class="kiosk-menu-name">+ Frites</span>
        <span class="kiosk-menu-desc">Seulement les frites</span>
        <span v-if="localChoice === 'frites'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
      </div>
      
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'boisson' }"
        @click="selectChoice('boisson')"
      >
        <span class="kiosk-menu-emoji">🥤</span>
        <span class="kiosk-menu-name">+ Boisson</span>
        <span class="kiosk-menu-desc">Seulement la boisson</span>
        <span v-if="localChoice === 'boisson'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
      </div>
      
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'none' }"
        @click="selectChoice('none')"
      >
        <span class="kiosk-menu-emoji">🚫</span>
        <span class="kiosk-menu-name">Sans menu</span>
        <span class="kiosk-menu-desc">Article seul</span>
        <span v-if="localChoice === 'none'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
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
          <span v-if="localBoisson === (boisson.id ?? boisson.name)" class="kiosk-menu-action active">✓</span>
          <span v-else class="kiosk-menu-action">+</span>
        </div>
      </div>
    </div>
    <div v-else-if="showBoissonChoice" class="kiosk-boisson-section">
      <p class="kiosk-boisson-placeholder">Votre boisson sera choisie au comptoir 🥤</p>
    </div>

    <!-- Sauce frites — shown when frites are included (full menu or frites only) -->
    <div v-if="showFritesSauce" class="kiosk-boisson-section kiosk-frites-sauce-section">
      <h4 class="kiosk-subtitle">Sauce pour les frites ?</h4>
      <div class="kiosk-boisson-grid">
        <div
          v-for="sauce in fritesSauceList"
          :key="sauce.key"
          class="kiosk-boisson-card"
          :class="{ selected: localFritesSauce === sauce.key }"
          @click="selectFritesSauce(sauce)"
        >
          <span class="kiosk-boisson-emoji">{{ sauce.emoji }}</span>
          <span class="kiosk-boisson-name">{{ sauce.name }}</span>
          <span v-if="localFritesSauce === sauce.key" class="kiosk-menu-action active">✓</span>
          <span v-else class="kiosk-menu-action">+</span>
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
      localBoisson: this.selections.boissonChoice || null,
      localFritesSauce: this.selections.fritesSauce || null,
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
    showFritesSauce() {
      return this.localChoice === 'full' || this.localChoice === 'frites';
    },
    fritesSauceList() {
      return [
        { key: 'ketchup',    name: 'Ketchup',    emoji: '🍅' },
        { key: 'mayo',       name: 'Mayonnaise', emoji: '🥚' },
        { key: 'algerienne', name: 'Algérienne', emoji: '🌶️' },
        { key: 'bbq',        name: 'BBQ',        emoji: '🔥' },
        { key: 'samourai',   name: 'Samouraï',   emoji: '⚔️' },
        { key: 'sans',       name: 'Sans sauce',  emoji: '🚫' },
      ];
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
    selectBoisson(boisson) {
      this.localBoisson = boisson.id ?? boisson.name;
      this.$emit('update', 'boissonChoice', this.localBoisson, {
        boissonName: boisson.name,
        boissonId:   typeof boisson.id === 'number' ? boisson.id : null,
      });
    },
    selectFritesSauce(sauce) {
      this.localFritesSauce = sauce.key;
      this.$emit('update', 'fritesSauce', sauce.key, { fritesSauceName: sauce.name });
    },
    selectChoice(choice) {
      this.localChoice = choice;
      // Reset frites sauce when switching away from frites-inclusive choices
      if (choice === 'none' || choice === 'boisson') {
        this.localFritesSauce = null;
        this.$emit('update', 'fritesSauce', null);
      }
      this.$emit('update', 'menuChoice', choice);
    },
  }
};
</script>

<style scoped>
.kiosk-step-menu {
  padding: 6px 18px 24px;
  background: #fff;
  min-height: 100%;
}

.kiosk-step-title {
  font-size: 15px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 12px;
  color: #333;
}

.kiosk-menu-info {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-bottom: 14px;
}

.kiosk-info-badge {
  background: rgba(232,0,28,0.06);
  border: 1px solid rgba(232,0,28,0.2);
  color: #E8001C;
  padding: 6px 16px;
  border-radius: 50px;
  font-size: 12px;
  font-weight: 700;
}

.kiosk-menu-price {
  font-size: 16px;
  font-weight: 800;
  color: #E8001C;
  background: rgba(232,0,28,0.06);
  padding: 4px 12px;
  border-radius: 50px;
}

.kiosk-menu-options {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 22px 18px;
  margin-bottom: 20px;
  max-width: 820px;
  margin-left: auto;
  margin-right: auto;
}

.kiosk-menu-card {
  min-height: 196px;
  border-radius: 20px;
  border: 1px solid #efefef;
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 14px 12px 16px;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.18s ease;
  position: relative;
}

.kiosk-menu-card:active { transform: scale(0.96); }

.kiosk-menu-card.selected {
  border-color: rgba(232,0,28,0.18);
  background: rgba(232,0,28,0.02);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
}

.kiosk-menu-emoji {
  width: 118px;
  height: 118px;
  border-radius: 50%;
  background: #f7f7f8;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 48px;
  margin-bottom: 12px;
  transition: transform 0.2s;
}

.kiosk-menu-card.selected .kiosk-menu-emoji { transform: scale(1.1); }

.kiosk-menu-name {
  font-size: 12px;
  font-weight: 700;
  color: #444;
  text-align: center;
  text-transform: uppercase;
}

.kiosk-menu-desc {
  font-size: 11px;
  color: #999;
  text-align: center;
  margin-top: 3px;
}

.kiosk-menu-card.selected .kiosk-menu-name { color: #E8001C; }

.kiosk-boisson-section {
  border-top: 1px solid #E0E0E0;
  padding-top: 16px;
  animation: fadeInUp 0.3s ease;
}

@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: translateY(0); }
}

.kiosk-subtitle {
  font-size: 15px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 14px;
  color: #333;
}

.kiosk-boisson-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px 18px;
  max-width: 900px;
  margin: 0 auto;
}

.kiosk-boisson-card {
  min-height: 170px;
  border-radius: 20px;
  border: 1px solid #efefef;
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 12px 10px 14px;
  cursor: pointer;
  touch-action: manipulation;
  transition: all 0.18s ease;
  position: relative;
}

.kiosk-boisson-card:active { transform: scale(0.95); }

.kiosk-boisson-card.selected {
  border-color: rgba(232,0,28,0.18);
  background: rgba(232,0,28,0.02);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.06);
}

.kiosk-boisson-emoji {
  width: 102px;
  height: 102px;
  border-radius: 50%;
  background: #f7f7f8;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 40px;
  margin-bottom: 10px;
}

.kiosk-boisson-name {
  font-size: 12px;
  font-weight: 700;
  color: #444;
  text-align: center;
  line-height: 1.2;
  text-transform: uppercase;
}

.kiosk-boisson-card.selected .kiosk-boisson-name { color: #E8001C; }

.kiosk-boisson-placeholder {
  text-align: center;
  color: #999;
  font-size: 14px;
  padding: 16px 0;
}

.kiosk-frites-sauce-section {
  border-top: 1px solid #E0E0E0;
  padding-top: 20px;
  margin-top: 8px;
  animation: fadeInUp 0.3s ease;
}

.kiosk-menu-action {
  position: absolute;
  top: 12px;
  right: 20px;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: #d7263d;
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  line-height: 1;
  box-shadow: 0 3px 10px rgba(215,38,61,0.2);
  outline: 2px solid rgba(255,255,255,0.85);
}

.kiosk-menu-action.active {
  font-size: 13px;
  font-weight: 800;
}
</style>
