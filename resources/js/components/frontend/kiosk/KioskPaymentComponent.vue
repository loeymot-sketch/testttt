<template>
  <div class="kiosk-payment">
    <!-- Header -->
    <div class="kiosk-pay-header">
      <button class="kiosk-pay-back" @click="$router.push({ name: 'kiosk.cart' })" :disabled="submitting">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
          <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <div class="kiosk-pay-header-info">
        <h1 class="kiosk-pay-title">Choisissez votre paiement</h1>
        <p class="kiosk-pay-total-label">Total à régler : <strong>{{ formatPrice(cartTotal) }}</strong></p>
      </div>
    </div>

    <!-- Modes de paiement -->
    <div v-if="!submitting && !submitted" class="kiosk-pay-methods">
      <!-- CB -->
      <div class="kiosk-pay-method" :class="{ selected: method === 'card' }" @click="selectMethod('card')">
        <div class="kiosk-pay-method-icon card">
          <svg width="52" height="52" viewBox="0 0 52 52" fill="none">
            <rect x="4" y="12" width="44" height="30" rx="6" fill="white" fill-opacity="0.12" stroke="white" stroke-opacity="0.3" stroke-width="1.5"/>
            <rect x="4" y="20" width="44" height="8" fill="white" fill-opacity="0.2"/>
            <rect x="10" y="32" width="12" height="4" rx="2" fill="white" fill-opacity="0.5"/>
            <rect x="26" y="32" width="8" height="4" rx="2" fill="white" fill-opacity="0.5"/>
          </svg>
        </div>
        <div class="kiosk-pay-method-info">
          <h3>Carte bancaire</h3>
          <p>Visa · Mastercard · CB</p>
        </div>
        <div class="kiosk-pay-method-check" v-if="method === 'card'">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M5 12l5 5 9-10" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
      </div>

      <!-- Espèces -->
      <div class="kiosk-pay-method" :class="{ selected: method === 'cash' }" @click="selectMethod('cash')">
        <div class="kiosk-pay-method-icon cash">
          <svg width="52" height="52" viewBox="0 0 52 52" fill="none">
            <rect x="4" y="14" width="44" height="26" rx="6" fill="white" fill-opacity="0.12" stroke="white" stroke-opacity="0.3" stroke-width="1.5"/>
            <circle cx="26" cy="27" r="8" stroke="white" stroke-opacity="0.5" stroke-width="1.5"/>
            <text x="26" y="32" text-anchor="middle" font-size="12" fill="white" fill-opacity="0.8" font-weight="bold">€</text>
          </svg>
        </div>
        <div class="kiosk-pay-method-info">
          <h3>Espèces</h3>
          <p>Paiement à la caisse</p>
        </div>
        <div class="kiosk-pay-method-check" v-if="method === 'cash'">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M5 12l5 5 9-10" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
      </div>

      <!-- Ticket Restaurant -->
      <div class="kiosk-pay-method" :class="{ selected: method === 'tr' }" @click="selectMethod('tr')">
        <div class="kiosk-pay-method-icon tr">
          <svg width="52" height="52" viewBox="0 0 52 52" fill="none">
            <rect x="4" y="12" width="44" height="28" rx="6" fill="white" fill-opacity="0.12" stroke="white" stroke-opacity="0.3" stroke-width="1.5"/>
            <path d="M14 22h24M14 28h16" stroke="white" stroke-opacity="0.6" stroke-width="2" stroke-linecap="round"/>
            <path d="M36 28l4 4" stroke="white" stroke-opacity="0.6" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <div class="kiosk-pay-method-info">
          <h3>Ticket Restaurant</h3>
          <p>Edenred · Swile · Sodexo</p>
        </div>
        <div class="kiosk-pay-method-check" v-if="method === 'tr'">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M5 12l5 5 9-10" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
      </div>
    </div>

    <!-- Écran API en cours (commande en création) -->
    <div v-if="submitting" class="kiosk-pay-processing">
      <div class="kiosk-pay-processing-ring">
        <div class="kiosk-pay-processing-ring-inner" />
      </div>
      <h2>Envoi de la commande…</h2>
      <p>Veuillez patienter</p>
    </div>

    <!-- Écran TPE : attente terminal physique (carte ou TR) -->
    <transition name="fade-scale">
      <div v-if="tpeWaiting" class="kiosk-tpe-overlay">
        <div class="kiosk-tpe-card-anim">
          <div class="kiosk-tpe-ring" v-for="n in 3" :key="n" :style="{ animationDelay: (n * 0.5) + 's' }" />
          <div class="kiosk-tpe-card-icon">
            <svg v-if="method === 'card'" width="72" height="72" viewBox="0 0 72 72" fill="none">
              <rect x="6" y="16" width="60" height="40" rx="8" fill="white" fill-opacity="0.1" stroke="white" stroke-opacity="0.5" stroke-width="2"/>
              <rect x="6" y="28" width="60" height="10" fill="white" fill-opacity="0.15"/>
              <rect x="14" y="44" width="16" height="5" rx="2.5" fill="white" fill-opacity="0.5"/>
              <rect x="34" y="44" width="10" height="5" rx="2.5" fill="white" fill-opacity="0.5"/>
            </svg>
            <span v-else style="font-size:4rem">🎫</span>
          </div>
        </div>
        <h2 class="kiosk-tpe-title">{{ tpeMessage }}</h2>
        <p class="kiosk-tpe-sub">Suivez les instructions sur le terminal de paiement</p>
        <div class="kiosk-tpe-progress">
          <div class="kiosk-tpe-progress-bar" :style="{ width: tpeProgressPct + '%' }" />
        </div>
        <p class="kiosk-tpe-hint">Redirection automatique dans {{ tpeCountdown }}s</p>
      </div>
    </transition>

    <!-- Bouton confirmer -->
    <div v-if="!submitting && !submitted && !tpeWaiting" class="kiosk-pay-confirm">
      <div v-if="error" class="kiosk-pay-error">{{ error }}</div>
      <button
        class="kiosk-btn-confirm"
        :disabled="!method"
        @click="confirmPayment"
      >
        <span>Confirmer — {{ formatPrice(cartTotal) }}</span>
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
          <path d="M6 14h16M16 8l6 6-6 6" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
    </div>
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';

const TPE_COUNTDOWN_SECONDS = 5;

const TPE_MESSAGES = {
  card: 'Insérez votre carte sur le terminal',
  tr: 'Présentez votre titre-restaurant',
};

export default {
  name: 'KioskPaymentComponent',
  data() {
    return {
      method: null,
      submitting: false,
      submitted: false,
      error: null,
      // TPE waiting screen (card / ticket restaurant)
      tpeWaiting: false,
      tpeCountdown: TPE_COUNTDOWN_SECONDS,
      tpeProgressPct: 0,
      _tpeTimer: null,
      _pendingNav: null,
    };
  },
  computed: {
    ...mapGetters('kioskCart', ['total', 'branchId']),
    cartTotal() { return this.total; },
    tpeMessage() { return TPE_MESSAGES[this.method] || 'Terminal en cours…'; },
  },
  beforeUnmount() {
    clearInterval(this._tpeTimer);
  },
  methods: {
    ...mapActions('kioskCart', ['submitOrder', 'reset']),

    selectMethod(m) { this.method = m; this.error = null; },

    async confirmPayment() {
      if (!this.method) return;
      this.submitting = true;
      this.error = null;

      try {
        const res = await this.submitOrder({ paymentMethod: this.method });
        const orderId  = res?.data?.data?.id || res?.data?.id;
        const queueNum = res?.data?.data?.queue_number || res?.data?.queue_number;
        const total    = res?.data?.data?.order_amount || this.cartTotal;
        const navTarget = {
          name: 'kiosk.waiting',
          params: { orderId: String(orderId) },
          query: { queue: queueNum, total },
        };

        this.submitted = true;
        this.submitting = false;

        if (this.method === 'card' || this.method === 'tr') {
          // Show TPE waiting screen with countdown before proceeding to kitchen waiting
          this._pendingNav = navTarget;
          this.startTpeCountdown();
        } else {
          // Cash: go directly to waiting
          this.$router.push(navTarget);
        }
      } catch (err) {
        const msg = err?.response?.data?.errors
          ? Object.values(err.response.data.errors).flat().join(' ')
          : 'Une erreur est survenue. Veuillez réessayer.';
        this.error = msg;
        this.submitting = false;
      }
    },

    startTpeCountdown() {
      this.tpeWaiting = true;
      this.tpeCountdown = TPE_COUNTDOWN_SECONDS;
      this.tpeProgressPct = 0;
      const step = 100 / (TPE_COUNTDOWN_SECONDS * 10); // update every 100ms
      this._tpeTimer = setInterval(() => {
        this.tpeProgressPct = Math.min(100, this.tpeProgressPct + step);
        this.tpeCountdown = Math.max(0, TPE_COUNTDOWN_SECONDS - Math.floor((this.tpeProgressPct / 100) * TPE_COUNTDOWN_SECONDS));
        if (this.tpeProgressPct >= 100) {
          clearInterval(this._tpeTimer);
          this.tpeWaiting = false;
          this.$router.push(this._pendingNav);
        }
      }, 100);
    },

    formatPrice(price) {
      return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(price || 0);
    },
  },
};
</script>

<style scoped>
.kiosk-payment {
  width: 100vw;
  height: 100vh;
  background: var(--kiosk-dark);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* Header */
.kiosk-pay-header {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 24px 32px 20px;
  background: var(--kiosk-dark-2);
  border-bottom: 1px solid rgba(255,255,255,0.07);
  flex-shrink: 0;
}

.kiosk-pay-back {
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

.kiosk-pay-back:active { background: rgba(255,255,255,0.12); transform: scale(0.95); }
.kiosk-pay-back:disabled { opacity: 0.4; cursor: not-allowed; }

.kiosk-pay-header-info { flex: 1; }

.kiosk-pay-title {
  font-size: 26px;
  font-weight: 800;
  color: white;
  margin: 0 0 4px;
}

.kiosk-pay-total-label {
  font-size: 16px;
  color: rgba(255,255,255,0.5);
  margin: 0;
}

.kiosk-pay-total-label strong { color: white; font-size: 18px; }

/* Méthodes */
.kiosk-pay-methods {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 28px 32px;
  overflow-y: auto;
  scrollbar-width: none;
}

.kiosk-pay-methods::-webkit-scrollbar { display: none; }

.kiosk-pay-method {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 24px 28px;
  background: var(--kiosk-dark-2);
  border-radius: 20px;
  border: 2px solid rgba(255,255,255,0.07);
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
}

.kiosk-pay-method:active { transform: scale(0.99); }

.kiosk-pay-method.selected {
  border-color: var(--kiosk-primary);
  background: rgba(232,0,28,0.08);
  box-shadow: 0 0 0 1px rgba(232,0,28,0.2), 0 8px 32px rgba(232,0,28,0.15);
}

.kiosk-pay-method-icon {
  width: 72px;
  height: 72px;
  border-radius: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.kiosk-pay-method-icon.card   { background: linear-gradient(135deg, #1a3a6b, #2563EB); }
.kiosk-pay-method-icon.cash   { background: linear-gradient(135deg, #0a4a20, #16a34a); }
.kiosk-pay-method-icon.tr     { background: linear-gradient(135deg, #7a2000, #ea580c); }

.kiosk-pay-method-info { flex: 1; }

.kiosk-pay-method-info h3 {
  font-size: 22px;
  font-weight: 700;
  color: white;
  margin: 0 0 4px;
}

.kiosk-pay-method-info p {
  font-size: 14px;
  color: rgba(255,255,255,0.4);
  margin: 0;
}

.kiosk-pay-method-check {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--kiosk-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  animation: popIn 0.2s cubic-bezier(0.34,1.56,0.64,1);
}

@keyframes popIn {
  from { transform: scale(0); opacity: 0; }
  to   { transform: scale(1); opacity: 1; }
}

/* Processing */
.kiosk-pay-processing {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 24px;
  padding: 40px;
  text-align: center;
}

.kiosk-pay-processing-ring {
  width: 120px;
  height: 120px;
  border-radius: 50%;
  border: 4px solid rgba(232,0,28,0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  animation: spin 1.5s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.kiosk-pay-processing-ring-inner {
  width: 96px;
  height: 96px;
  border-radius: 50%;
  background: rgba(232,0,28,0.1);
  border: 4px solid var(--kiosk-primary);
  border-top-color: transparent;
  animation: spin 0.8s linear infinite reverse;
}

.kiosk-pay-processing h2 {
  font-size: 28px;
  font-weight: 800;
  color: white;
  margin: 0;
}

.kiosk-pay-processing p {
  font-size: 16px;
  color: rgba(255,255,255,0.5);
  margin: 0;
}

/* Erreur */
.kiosk-pay-error {
  background: rgba(232,0,28,0.15);
  border: 1px solid rgba(232,0,28,0.3);
  color: #ff6b6b;
  padding: 14px 20px;
  border-radius: 12px;
  font-size: 15px;
  text-align: center;
  margin-bottom: 8px;
}

/* Confirmer */
.kiosk-pay-confirm {
  padding: 20px 32px 32px;
  flex-shrink: 0;
}

.kiosk-btn-confirm {
  width: 100%;
  height: 80px;
  background: var(--kiosk-primary);
  color: white;
  border: none;
  border-radius: 20px;
  font-size: 22px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 32px;
  box-shadow: 0 8px 32px rgba(232,0,28,0.4);
  transition: all 0.15s ease;
}

.kiosk-btn-confirm:disabled {
  opacity: 0.4;
  cursor: not-allowed;
  box-shadow: none;
}

.kiosk-btn-confirm:not(:disabled):active {
  transform: scale(0.98);
  box-shadow: 0 4px 16px rgba(232,0,28,0.3);
}

/* TPE terminal waiting overlay */
.kiosk-tpe-overlay {
  position: fixed; inset: 0; z-index: 100;
  background: linear-gradient(160deg, #0a0a1a 0%, #121228 100%);
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 1.5rem; text-align: center; padding: 2rem;
}
.kiosk-tpe-card-anim {
  position: relative;
  width: 160px; height: 160px;
  display: flex; align-items: center; justify-content: center;
}
.kiosk-tpe-ring {
  position: absolute; inset: 0;
  border: 3px solid rgba(232, 0, 28, 0.4);
  border-radius: 50%;
  animation: tpe-pulse 1.8s ease-out infinite;
}
@keyframes tpe-pulse {
  0% { transform: scale(0.6); opacity: 0.9; }
  100% { transform: scale(1.4); opacity: 0; }
}
.kiosk-tpe-card-icon {
  position: relative; z-index: 2;
  background: rgba(255,255,255,0.06);
  border: 2px solid rgba(255,255,255,0.15);
  border-radius: 50%;
  width: 100px; height: 100px;
  display: flex; align-items: center; justify-content: center;
}
.kiosk-tpe-title {
  font-size: 1.8rem; font-weight: 800; color: #fff; margin: 0;
}
.kiosk-tpe-sub {
  font-size: 1rem; color: rgba(255,255,255,0.5); margin: 0; max-width: 340px;
}
.kiosk-tpe-progress {
  width: 280px; height: 6px;
  background: rgba(255,255,255,0.1);
  border-radius: 3px; overflow: hidden;
}
.kiosk-tpe-progress-bar {
  height: 100%; background: #e8001c;
  border-radius: 3px;
  transition: width 0.1s linear;
}
.kiosk-tpe-hint {
  font-size: 0.85rem; color: rgba(255,255,255,0.3); margin: 0;
}
</style>
