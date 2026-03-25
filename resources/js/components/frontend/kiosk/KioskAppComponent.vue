<template>
  <div class="kiosk-app" @touchstart="resetIdleTimer" @click="resetIdleTimer" @keydown="resetIdleTimer">

    <!-- Initialisation : overlay pendant le chargement de la branche -->
    <transition name="fade">
      <div v-if="branchLoading" class="kiosk-init-overlay">
        <div class="kiosk-init-spinner"></div>
        <p class="kiosk-init-label">Démarrage en cours…</p>
      </div>
    </transition>

    <!-- Erreur critique : branche non disponible -->
    <transition name="fade">
      <div v-if="branchError && !branchLoading" class="kiosk-init-overlay kiosk-init-error">
        <div class="kiosk-init-error-icon">⚠️</div>
        <p class="kiosk-init-error-title">Service temporairement indisponible</p>
        <p class="kiosk-init-error-sub">{{ branchError }}</p>
        <button class="kiosk-init-retry-btn" @click="loadBranch">Réessayer</button>
      </div>
    </transition>

    <!-- Panier flottant (visible hors idle) -->
    <transition name="slide-down">
      <div v-if="showCartBar && cartCount > 0" class="kiosk-cart-bar" @click.stop="goToCart">
        <div class="kiosk-cart-bar-left">
          <span class="kiosk-cart-bar-badge">{{ cartCount }}</span>
          <span class="kiosk-cart-bar-label">Mon panier</span>
        </div>
        <div class="kiosk-cart-bar-right">
          <span class="kiosk-cart-bar-total">{{ formatPrice(cartTotal) }}</span>
          <span class="kiosk-cart-bar-arrow">›</span>
        </div>
      </div>
    </transition>

    <!-- Vue enfant (page courante) -->
    <router-view
      v-slot="{ Component }"
      @add-to-cart="handleAddToCart"
      @go-to-cart="goToCart"
      @start-order="startOrder"
      @reset-kiosk="resetKiosk"
    >
      <transition :name="transitionName" mode="out-in">
        <component :is="Component" :key="$route.fullPath" />
      </transition>
    </router-view>

    <!-- Feedback tactile visuel -->
    <transition name="ripple-fade">
      <div v-if="ripple.show" class="kiosk-touch-ripple" :style="rippleStyle" />
    </transition>

    <!-- Modal "Toujours là ?" avant reset idle -->
    <transition name="fade-scale">
      <div v-if="showStillHere" class="kiosk-still-here-overlay" @click.stop="dismissStillHere">
        <div class="kiosk-still-here-modal">
          <div class="kiosk-still-here-icon">😴</div>
          <h2 class="kiosk-still-here-title">Vous êtes toujours là ?</h2>
          <p class="kiosk-still-here-sub">Votre session va expirer dans quelques secondes</p>
          <button class="kiosk-still-here-btn" @click.stop="dismissStillHere">
            Oui, je continue
          </button>
        </div>
      </div>
    </transition>
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';

const IDLE_TIMEOUT_MS  = 60000; // 60s sans interaction
const STILL_HERE_MS    = 50000; // Afficher "Toujours là ?" 10s avant reset
// Ordre canonique pour l'animation de transition directionnelle
const ROUTE_ORDER = [
  'kiosk.idle',
  'kiosk.categories',
  'kiosk.products',
  'kiosk.wizard',
  'kiosk.cart',
  'kiosk.loyalty',
  'kiosk.upsell',
  'kiosk.payment',
  'kiosk.waiting',
  'kiosk.confirmation',
];

export default {
  name: 'KioskAppComponent',
  data() {
    return {
      idleTimer: null,
      stillHereTimer: null,
      showStillHere: false,
      transitionName: 'slide-left',
      ripple: { show: false, x: 0, y: 0 },
      rippleTimer: null,
      branchLoading: true,
      branchError: null,
    };
  },
  computed: {
    ...mapGetters('kioskCart', ['count', 'total']),
    cartCount() { return this.count; },
    cartTotal() { return this.total; },
    showCartBar() {
      const hiddenRoutes = ['kiosk.idle', 'kiosk.cart', 'kiosk.payment', 'kiosk.waiting', 'kiosk.confirmation', 'kiosk.upsell'];
      return !hiddenRoutes.includes(this.$route.name);
    },
    rippleStyle() {
      return { left: this.ripple.x + 'px', top: this.ripple.y + 'px' };
    },
  },
  watch: {
    $route(to, from) {
      const toIdx   = ROUTE_ORDER.indexOf(to.name);
      const fromIdx = ROUTE_ORDER.indexOf(from.name);
      // Unknown routes (e.g. deep links): default forward
      this.transitionName = (fromIdx === -1 || toIdx >= fromIdx) ? 'slide-left' : 'slide-right';
      // Reset idle timer on every navigation
      this.resetIdleTimer();
    },
  },
  mounted() {
    this.startIdleTimer();
    this.loadBranch();
    document.addEventListener('touchstart', this.handleTouch, { passive: true });
  },
  beforeUnmount() {
    this.clearIdleTimer();
    clearTimeout(this.rippleTimer);
    document.removeEventListener('touchstart', this.handleTouch);
  },
  methods: {
    ...mapActions('kioskCart', ['reset', 'setBranch']),
    ...mapActions('frontendBranch', { loadBranchList: 'lists' }),

    async loadBranch() {
      this.branchLoading = true;
      this.branchError = null;
      try {
        const res = await this.loadBranchList({ vuex: false });
        const branch = res?.data?.data?.[0];
        if (branch?.id) {
          this.setBranch(branch.id);
          this.branchLoading = false;
        } else {
          this.branchError = 'Aucune branche disponible. Vérifiez la configuration.';
          this.branchLoading = false;
        }
      } catch (err) {
        const msg = err?.response?.status === 401
          ? 'Session expirée. Veuillez recharger la borne.'
          : 'Connexion au serveur impossible. Vérifiez le réseau.';
        this.branchError = msg;
        this.branchLoading = false;
      }
    },

    startIdleTimer() {
      this.clearIdleTimer();
      // No timer on idle (nobody at kiosk) or waiting (order being prepared)
      const noTimerRoutes = ['kiosk.idle', 'kiosk.waiting'];
      if (noTimerRoutes.includes(this.$route?.name)) return;

      // Show "Still there?" warning at STILL_HERE_MS, then reset at IDLE_TIMEOUT_MS
      this.stillHereTimer = setTimeout(() => {
        this.showStillHere = true;
      }, STILL_HERE_MS);

      this.idleTimer = setTimeout(() => {
        this.showStillHere = false;
        this.resetKiosk();
      }, IDLE_TIMEOUT_MS);
    },

    clearIdleTimer() {
      if (this.idleTimer)     { clearTimeout(this.idleTimer);     this.idleTimer = null; }
      if (this.stillHereTimer){ clearTimeout(this.stillHereTimer); this.stillHereTimer = null; }
      this.showStillHere = false;
    },

    resetIdleTimer() {
      this.startIdleTimer();
    },

    dismissStillHere() {
      this.showStillHere = false;
      this.startIdleTimer();
    },

    handleTouch(e) {
      this.resetIdleTimer();
      if (e.touches?.[0]) {
        this.showRipple(e.touches[0].clientX, e.touches[0].clientY);
      }
    },

    showRipple(x, y) {
      clearTimeout(this.rippleTimer);
      this.ripple = { show: true, x, y };
      this.rippleTimer = setTimeout(() => { this.ripple.show = false; }, 400);
    },

    startOrder() {
      this.reset();
      this.$router.push({ name: 'kiosk.categories' });
    },

    goToCart() {
      this.$router.push({ name: 'kiosk.cart' });
    },

    handleAddToCart(item) {
      this.$store.dispatch('kioskCart/addItem', item);
    },

    resetKiosk() {
      this.reset();
      this.clearIdleTimer();
      this.$router.push({ name: 'kiosk.idle' });
    },

    formatPrice(price) {
      return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(price || 0);
    },
  },
};
</script>

<style>
/* Variables CSS kiosk — scopées à .kiosk-app pour ne pas polluer admin/frontend */
.kiosk-app {
  --kiosk-primary:     #E8001C;
  --kiosk-primary-dark:#C0001A;
  --kiosk-accent:      #FF6B35;
  --kiosk-success:     #2ECC71;
  --kiosk-dark:        #0F0F1A;
  --kiosk-dark-2:      #1A1A2E;
  --kiosk-dark-3:      #16213E;
  --kiosk-surface:     #FFFFFF;
  --kiosk-surface-2:   #F5F5F7;
  --kiosk-surface-3:   #EBEBF0;
  --kiosk-text:        #0F0F1A;
  --kiosk-text-muted:  #6B6B80;
  --kiosk-border:      rgba(0,0,0,0.08);
  --kiosk-shadow:      0 4px 24px rgba(0,0,0,0.12);
  --kiosk-shadow-lg:   0 8px 48px rgba(0,0,0,0.20);
  --kiosk-radius:      20px;
  --kiosk-radius-sm:   12px;
  --kiosk-btn-height:  72px;
  --kiosk-font:        'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;

  -webkit-tap-highlight-color: transparent;
  box-sizing: border-box;
  font-family: var(--kiosk-font);
  /* overflow, user-select et touch-action sont dans le scoped block ci-dessous */
}
.kiosk-app *, .kiosk-app *::before, .kiosk-app *::after {
  -webkit-tap-highlight-color: transparent;
  box-sizing: border-box;
}
</style>

<style scoped>
.kiosk-app {
  position: fixed;
  inset: 0;
  width: 100vw;
  height: 100vh;
  background: var(--kiosk-dark);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  user-select: none;
  touch-action: pan-y;
}

/* Barre panier flottante */
.kiosk-cart-bar {
  position: absolute;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: calc(100% - 48px);
  max-width: 600px;
  background: var(--kiosk-primary);
  border-radius: 20px;
  padding: 16px 24px;
  box-shadow: 0 8px 32px rgba(232, 0, 28, 0.4);
  cursor: pointer;
  gap: 16px;
}

.kiosk-cart-bar-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.kiosk-cart-bar-badge {
  width: 36px;
  height: 36px;
  background: white;
  color: var(--kiosk-primary);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  font-weight: 800;
}

.kiosk-cart-bar-label {
  color: white;
  font-size: 18px;
  font-weight: 600;
}

.kiosk-cart-bar-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.kiosk-cart-bar-total {
  color: white;
  font-size: 20px;
  font-weight: 800;
}

.kiosk-cart-bar-arrow {
  color: rgba(255,255,255,0.7);
  font-size: 24px;
  font-weight: 300;
}

/* Feedback tactile */
.kiosk-touch-ripple {
  position: fixed;
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.15);
  transform: translate(-50%, -50%);
  pointer-events: none;
  z-index: 9999;
}

/* Transitions entre pages */
.slide-left-enter-active,
.slide-left-leave-active,
.slide-right-enter-active,
.slide-right-leave-active {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.slide-left-enter-from { transform: translateX(100%); opacity: 0; }
.slide-left-leave-to   { transform: translateX(-100%); opacity: 0; }
.slide-right-enter-from { transform: translateX(-100%); opacity: 0; }
.slide-right-leave-to   { transform: translateX(100%); opacity: 0; }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s ease; }
.slide-down-enter-from, .slide-down-leave-to { transform: translateX(-50%) translateY(120%); opacity: 0; }

.ripple-fade-enter-active, .ripple-fade-leave-active { transition: all 0.4s ease; }
.ripple-fade-enter-from { transform: translate(-50%, -50%) scale(0); opacity: 0.8; }
.ripple-fade-leave-to   { transform: translate(-50%, -50%) scale(3); opacity: 0; }

/* fade-scale for "Still Here?" modal */
.fade-scale-enter-active, .fade-scale-leave-active { transition: all 0.25s ease; }
.fade-scale-enter-from, .fade-scale-leave-to { opacity: 0; transform: scale(0.92); }

/* "Toujours là ?" modal */
.kiosk-still-here-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.75);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-still-here-modal {
  background: linear-gradient(160deg, #1a1a2e, #16213e);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 32px;
  padding: 3rem 2.5rem;
  text-align: center;
  max-width: 480px;
  width: 90%;
  box-shadow: 0 32px 80px rgba(0,0,0,0.6);
}

.kiosk-still-here-icon { font-size: 4rem; margin-bottom: 1rem; }

.kiosk-still-here-title {
  font-size: 2rem;
  font-weight: 800;
  color: #fff;
  margin: 0 0 0.5rem;
}

.kiosk-still-here-sub {
  font-size: 1.05rem;
  color: rgba(255,255,255,0.5);
  margin: 0 0 2rem;
}

.kiosk-still-here-btn {
  background: var(--kiosk-primary);
  color: #fff;
  border: none;
  border-radius: 18px;
  padding: 1rem 3rem;
  font-size: 1.2rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 8px 24px rgba(232,0,28,0.4);
  transition: transform 0.1s;
}
.kiosk-still-here-btn:active { transform: scale(0.96); }

/* Initialisation / branch loading overlay */
.kiosk-init-overlay {
  position: fixed; inset: 0; z-index: 9999;
  background: linear-gradient(160deg, #0f0f1a 0%, #1a1a2e 100%);
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 1.25rem; color: #fff;
}
.kiosk-init-spinner {
  width: 56px; height: 56px;
  border: 4px solid rgba(255,255,255,0.12);
  border-top-color: #e8001c;
  border-radius: 50%;
  animation: kiosk-spin 0.9s linear infinite;
}
@keyframes kiosk-spin { to { transform: rotate(360deg); } }
.kiosk-init-label { font-size: 1.1rem; color: rgba(255,255,255,0.6); }
.kiosk-init-error { background: linear-gradient(160deg, #1a0a0a 0%, #2a0d0d 100%); }
.kiosk-init-error-icon { font-size: 3.5rem; }
.kiosk-init-error-title { font-size: 1.4rem; font-weight: 700; margin: 0; }
.kiosk-init-error-sub { font-size: 0.95rem; color: rgba(255,255,255,0.55); margin: 0; text-align: center; max-width: 400px; }
.kiosk-init-retry-btn {
  background: #e8001c; color: #fff;
  border: none; border-radius: 50px;
  padding: 0.85rem 2.5rem; font-size: 1.05rem; font-weight: 700;
  cursor: pointer; transition: background 0.2s;
}
.kiosk-init-retry-btn:hover { background: #c0001a; }
</style>
