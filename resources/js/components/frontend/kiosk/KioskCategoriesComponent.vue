<template>
  <div class="kiosk-categories">
    <!-- Header -->
    <div class="kiosk-cats-header">
      <button class="kiosk-cats-back" @click="goBack">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
          <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <div class="kiosk-cats-title-wrap">
        <h1 class="kiosk-cats-title">Que voulez-vous commander ?</h1>
        <p class="kiosk-cats-subtitle">Choisissez une catégorie</p>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="kiosk-cats-loading">
      <div class="kiosk-spinner" />
      <p>Chargement du menu...</p>
    </div>

    <!-- Grille catégories -->
    <div v-else class="kiosk-cats-grid">
      <div
        v-for="(cat, idx) in categories"
        :key="cat.id"
        class="kiosk-cat-card"
        :style="{ animationDelay: (idx * 60) + 'ms' }"
        @click="selectCategory(cat)"
      >
        <!-- Image catégorie -->
        <div class="kiosk-cat-img-wrap">
          <img
            v-if="cat.image_full_path || cat.image"
            :src="cat.image_full_path || cat.image"
            :alt="cat.name"
            class="kiosk-cat-img"
            loading="lazy"
          />
          <div v-else class="kiosk-cat-emoji-fallback">
            {{ getCategoryEmoji(cat.name) }}
          </div>
          <!-- Overlay gradient -->
          <div class="kiosk-cat-img-overlay" />
        </div>

        <!-- Infos -->
        <div class="kiosk-cat-info">
          <h3 class="kiosk-cat-name">{{ cat.name }}</h3>
          <span class="kiosk-cat-count" v-if="cat.items_count > 0">
            {{ cat.items_count }} article{{ cat.items_count > 1 ? 's' : '' }}
          </span>
        </div>

        <!-- Indicateur actif -->
        <div class="kiosk-cat-arrow">›</div>
      </div>
    </div>

    <!-- Erreur / Vide avec bouton Retry -->
    <div v-if="!loading && categories.length === 0" class="kiosk-cats-empty">
      <div v-if="loadError" class="kiosk-cats-error">
        <span class="kiosk-cats-error-icon">📡</span>
        <p>Impossible de charger le menu.</p>
        <button class="kiosk-cats-retry-btn" @click="loadCategories">Réessayer</button>
      </div>
      <p v-else>Aucune catégorie disponible pour le moment.</p>
    </div>
  </div>
</template>

<script>
const EMOJI_MAP = {
  tacos: '🌮', burger: '🍔', sandwich: '🥪', pizza: '🍕',
  kebab: '🥙', frite: '🍟', boisson: '🥤', dessert: '🍰',
  salade: '🥗', wrap: '🫓', assiette: '🍽️', poulet: '🍗',
  plat: '🍽️', entrée: '🥗', viande: '🥩', poisson: '🐟',
};

export default {
  name: 'KioskCategoriesComponent',
  data() {
    return {
      categories: [],
      loading: true,
      loadError: false,
    };
  },
  mounted() {
    this.loadCategories();
  },
  methods: {
    async loadCategories() {
      this.loading = true;
      this.loadError = false;
      try {
        const res = await this.$store.dispatch('frontendItemCategory/lists', {
          paginate: 50,
          vuex: false,
        });
        this.categories = res?.data?.data || [];
      } catch (_) {
        this.categories = [];
        this.loadError = true;
      } finally {
        this.loading = false;
      }
    },
    selectCategory(cat) {
      this.$router.push({ name: 'kiosk.products', params: { categoryId: cat.id }, query: { name: cat.name } });
    },
    goBack() {
      this.$router.push({ name: 'kiosk.idle' });
    },
    getCategoryEmoji(name) {
      const n = (name || '').toLowerCase();
      for (const [key, emoji] of Object.entries(EMOJI_MAP)) {
        if (n.includes(key)) return emoji;
      }
      return '🍽️';
    },
  },
};
</script>

<style scoped>
.kiosk-categories {
  width: 100vw;
  height: 100vh;
  background: var(--kiosk-dark);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* Header */
.kiosk-cats-header {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 24px 32px 20px;
  background: var(--kiosk-dark-2);
  border-bottom: 1px solid rgba(255,255,255,0.07);
  flex-shrink: 0;
}

.kiosk-cats-back {
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

.kiosk-cats-back:active {
  background: rgba(255,255,255,0.12);
  transform: scale(0.95);
}

.kiosk-cats-title-wrap { flex: 1; }

.kiosk-cats-title {
  font-size: 26px;
  font-weight: 800;
  color: white;
  margin: 0 0 2px;
  letter-spacing: -0.3px;
}

.kiosk-cats-subtitle {
  font-size: 15px;
  color: rgba(255,255,255,0.5);
  margin: 0;
}

/* Loading */
.kiosk-cats-loading {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 20px;
  color: rgba(255,255,255,0.6);
  font-size: 18px;
}

.kiosk-spinner {
  width: 48px;
  height: 48px;
  border: 3px solid rgba(255,255,255,0.1);
  border-top-color: var(--kiosk-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* Grille */
.kiosk-cats-grid {
  flex: 1;
  overflow-y: auto;
  padding: 24px 32px 120px;
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
  scrollbar-width: none;
}

.kiosk-cats-grid::-webkit-scrollbar { display: none; }

/* Card catégorie */
.kiosk-cat-card {
  position: relative;
  background: var(--kiosk-dark-2);
  border-radius: 24px;
  overflow: hidden;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  border: 1.5px solid rgba(255,255,255,0.07);
  animation: cardIn 0.4s ease both;
  transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
  min-height: 200px;
}

@keyframes cardIn {
  from { opacity: 0; transform: translateY(20px) scale(0.97); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

.kiosk-cat-card:active {
  transform: scale(0.97);
  border-color: var(--kiosk-primary);
  box-shadow: 0 0 0 2px rgba(232,0,28,0.3);
}

/* Image */
.kiosk-cat-img-wrap {
  position: relative;
  height: 150px;
  overflow: hidden;
  flex-shrink: 0;
}

.kiosk-cat-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.3s ease;
}

.kiosk-cat-card:active .kiosk-cat-img {
  transform: scale(1.04);
}

.kiosk-cat-img-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(to bottom, transparent 40%, rgba(15,15,26,0.7) 100%);
}

.kiosk-cat-emoji-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 72px;
  background: linear-gradient(135deg, rgba(232,0,28,0.15), rgba(255,107,53,0.15));
}

/* Infos */
.kiosk-cat-info {
  padding: 16px 20px 12px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.kiosk-cat-name {
  font-size: 20px;
  font-weight: 700;
  color: white;
  margin: 0;
  letter-spacing: -0.2px;
}

.kiosk-cat-count {
  font-size: 13px;
  color: rgba(255,255,255,0.45);
}

/* Flèche */
.kiosk-cat-arrow {
  position: absolute;
  bottom: 16px;
  right: 20px;
  font-size: 28px;
  font-weight: 300;
  color: rgba(255,255,255,0.3);
  line-height: 1;
}

/* Vide */
.kiosk-cats-empty {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255,255,255,0.4);
  font-size: 18px;
}
.kiosk-cats-error {
  display: flex; flex-direction: column; align-items: center; gap: 1rem;
}
.kiosk-cats-error-icon { font-size: 3rem; }
.kiosk-cats-error p { margin: 0; }
.kiosk-cats-retry-btn {
  background: #e8001c; color: #fff; border: none;
  border-radius: 50px; padding: 0.7rem 2rem;
  font-size: 1rem; font-weight: 700; cursor: pointer;
  transition: background 0.2s;
}
.kiosk-cats-retry-btn:hover { background: #c0001a; }
</style>
