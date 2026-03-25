<template>
  <div class="kiosk-confirmation">
    <!-- Animated success checkmark -->
    <div class="kiosk-confirmation-anim">
      <svg class="kiosk-check-svg" viewBox="0 0 120 120" fill="none">
        <circle cx="60" cy="60" r="54" stroke="rgba(255,255,255,0.1)" stroke-width="6"/>
        <circle
          cx="60" cy="60" r="54"
          stroke="#2ECC71"
          stroke-width="6"
          stroke-linecap="round"
          stroke-dasharray="339"
          stroke-dashoffset="339"
          class="kiosk-ring-fill"
        />
        <path
          d="M36 60l18 18 30-30"
          stroke="#2ECC71"
          stroke-width="7"
          stroke-linecap="round"
          stroke-linejoin="round"
          class="kiosk-check-path"
        />
      </svg>
    </div>

    <h1 class="kiosk-confirmation-title">Commande confirmée !</h1>

    <div class="kiosk-confirmation-card">
      <div class="kiosk-confirmation-row">
        <span class="kiosk-confirmation-label">Numéro de commande</span>
        <span class="kiosk-confirmation-number">#{{ displayNumber }}</span>
      </div>
      <div v-if="displayTotal" class="kiosk-confirmation-row">
        <span class="kiosk-confirmation-label">Total payé</span>
        <span class="kiosk-confirmation-price">{{ formatPrice(displayTotal) }}</span>
      </div>
    </div>

    <div class="kiosk-confirmation-message">
      <p>Votre commande a été envoyée en cuisine.</p>
      <p>Présentez-vous au comptoir avec votre numéro.</p>
    </div>

    <!-- Progress timer -->
    <div class="kiosk-confirmation-timer">
      <span class="kiosk-timer-label">Retour automatique dans {{ countdown }}s</span>
      <div class="kiosk-timer-bar">
        <div class="kiosk-timer-fill" :style="{ width: progressWidth + '%' }"></div>
      </div>
    </div>

    <button class="kiosk-btn-print" @click="printReceipt">
      🖨️ Imprimer le ticket
    </button>

    <button class="kiosk-btn-home" @click="goHome">
      Nouvelle commande →
    </button>
  </div>

  <!-- Receipt zone (only visible when printing) -->
  <div id="kiosk-print-receipt" class="kiosk-receipt-zone">
    <div class="receipt-header">
      <p class="receipt-restaurant">{{ restaurantName }}</p>
      <p class="receipt-date">{{ receiptDate }}</p>
    </div>
    <div class="receipt-divider">- - - - - - - - - - - - - - - - - -</div>
    <div class="receipt-queue">
      <span>NUMÉRO</span>
      <span class="receipt-queue-number">#{{ displayNumber }}</span>
    </div>
    <div class="receipt-divider">- - - - - - - - - - - - - - - - - -</div>
    <div v-for="item in receiptItems" :key="item.item_id" class="receipt-line">
      <span>{{ item.quantity }}x {{ item.name }}</span>
      <span>{{ formatPrice((parseFloat(item.convert_price) + (item.item_variation_total||0) + (item.item_extra_total||0)) * item.quantity) }}</span>
    </div>
    <div class="receipt-divider">- - - - - - - - - - - - - - - - - -</div>
    <div v-if="receiptDiscount > 0" class="receipt-line receipt-discount">
      <span>Réduction fidélité</span>
      <span>-{{ formatPrice(receiptDiscount) }}</span>
    </div>
    <div class="receipt-line receipt-total">
      <span>TOTAL</span>
      <span>{{ formatPrice(displayTotal || 0) }}</span>
    </div>
    <div class="receipt-divider">- - - - - - - - - - - - - - - - - -</div>
    <p class="receipt-footer">Merci de votre visite !</p>
    <p class="receipt-footer">Présentez ce ticket au comptoir.</p>
  </div>
</template>

<script>
export default {
  name: 'KioskConfirmationComponent',
  props: {
    // Populated from route.query by kioskRoutes.js
    orderNumber: { type: String, default: '' },
    orderTotal:  { type: Number, default: null },
  },
  emits: ['close'],
  data() {
    return {
      countdown:     30,
      progressWidth: 100,
      timer:         null,
    };
  },
  computed: {
    displayNumber() {
      if (this.orderNumber) return this.orderNumber;
      return this.$store.state.kioskCart?.queueNumber || '—';
    },
    displayTotal() {
      if (this.orderTotal != null) return this.orderTotal;
      return this.$store.getters['kioskCart/total'] || null;
    },
    receiptItems() {
      return this.$store.state.kioskCart?.items || [];
    },
    receiptDiscount() {
      return this.$store.state.kioskCart?.loyaltyDiscount || 0;
    },
    restaurantName() {
      return this.$store.state.globalState?.settings?.company_name || 'FoodKing';
    },
    receiptDate() {
      return new Date().toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
    },
  },
  mounted() {
    this.startTimer();
  },
  beforeUnmount() {
    this.clearTimer();
  },
  methods: {
    startTimer() {
      this.timer = setInterval(() => {
        this.countdown--;
        this.progressWidth = (this.countdown / 30) * 100;
        if (this.countdown <= 0) this.goHome();
      }, 1000);
    },

    clearTimer() {
      if (this.timer) { clearInterval(this.timer); this.timer = null; }
    },

    printReceipt() {
      const el = document.getElementById('kiosk-print-receipt');
      if (!el) return;
      const win = window.open('', '_blank', 'width=320,height=600');
      win.document.write(`
        <html><head><title>Ticket</title>
        <style>
          body{font-family:monospace;width:300px;margin:0 auto;padding:12px;font-size:14px}
          .receipt-header{text-align:center;margin-bottom:6px}
          .receipt-restaurant{font-weight:bold;font-size:16px;margin:0}
          .receipt-date{margin:2px 0;color:#555}
          .receipt-queue{text-align:center;margin:8px 0}
          .receipt-queue-number{display:block;font-size:40px;font-weight:900;letter-spacing:2px}
          .receipt-line{display:flex;justify-content:space-between;margin:2px 0}
          .receipt-total{font-weight:bold;font-size:15px;margin-top:4px}
          .receipt-discount{color:#e53935}
          .receipt-divider{text-align:center;color:#bbb;margin:4px 0}
          .receipt-footer{text-align:center;color:#555;margin:2px 0;font-size:12px}
        </style></head><body>
        ${el.innerHTML}
        </body></html>`);
      win.document.close();
      win.focus();
      setTimeout(() => { win.print(); win.close(); }, 300);
    },

    goHome() {
      this.clearTimer();
      this.$emit('close');
      this.$store.dispatch('kioskCart/reset');
      this.$router.push({ name: 'kiosk.idle' });
    },

    formatPrice(price) {
      return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(price || 0);
    },
  },
};
</script>

<style scoped>
.kiosk-confirmation {
  min-height: 100vh;
  background: linear-gradient(160deg, #0f0f1a 0%, #1a1a2e 60%, #16213e 100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2.5rem 2rem;
  text-align: center;
  color: #fff;
  gap: 1.5rem;
}

/* Animated SVG ring + check */
.kiosk-confirmation-anim {
  position: relative;
  width: 140px;
  height: 140px;
}

.kiosk-check-svg {
  width: 140px;
  height: 140px;
  overflow: visible;
}

.kiosk-ring-fill {
  animation: drawRing 0.8s ease-out 0.1s forwards;
}

.kiosk-check-path {
  stroke-dasharray: 80;
  stroke-dashoffset: 80;
  animation: drawCheck 0.4s ease-out 0.9s forwards;
}

@keyframes drawRing {
  to { stroke-dashoffset: 0; }
}
@keyframes drawCheck {
  to { stroke-dashoffset: 0; }
}

/* Title */
.kiosk-confirmation-title {
  font-size: 2.2rem;
  font-weight: 900;
  letter-spacing: -0.01em;
  margin: 0;
  animation: fadeUp 0.5s ease-out 0.8s both;
}

/* Detail card */
.kiosk-confirmation-card {
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 24px;
  padding: 1.5rem 2.5rem;
  width: 100%;
  max-width: 400px;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  animation: fadeUp 0.5s ease-out 1s both;
}

.kiosk-confirmation-row {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.kiosk-confirmation-label {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: rgba(255,255,255,0.4);
}

.kiosk-confirmation-number {
  font-size: 3rem;
  font-weight: 900;
  color: #fff;
  line-height: 1;
}

.kiosk-confirmation-price {
  font-size: 1.8rem;
  font-weight: 800;
  color: #FFD700;
}

/* Message */
.kiosk-confirmation-message {
  font-size: 1rem;
  color: rgba(255,255,255,0.5);
  line-height: 1.7;
  animation: fadeUp 0.5s ease-out 1.1s both;
}
.kiosk-confirmation-message p { margin: 0; }

/* Timer */
.kiosk-confirmation-timer {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: 100%;
  max-width: 320px;
  animation: fadeUp 0.5s ease-out 1.2s both;
}

.kiosk-timer-label {
  font-size: 0.85rem;
  color: rgba(255,255,255,0.35);
}

.kiosk-timer-bar {
  height: 5px;
  background: rgba(255,255,255,0.1);
  border-radius: 3px;
  overflow: hidden;
}

.kiosk-timer-fill {
  height: 100%;
  background: linear-gradient(90deg, #2ECC71, #00b894);
  border-radius: 3px;
  transition: width 1s linear;
}

/* CTA */
.kiosk-btn-home {
  background: linear-gradient(135deg, #E8001C, #C0001A);
  color: #fff;
  border: none;
  border-radius: 20px;
  padding: 1.1rem 3rem;
  font-size: 1.15rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 8px 28px rgba(232,0,28,0.4);
  transition: transform 0.1s, box-shadow 0.1s;
  animation: fadeUp 0.5s ease-out 1.3s both;
}
.kiosk-btn-home:active {
  transform: scale(0.96);
  box-shadow: 0 4px 14px rgba(232,0,28,0.3);
}

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: translateY(0); }
}

.kiosk-btn-print {
  background: rgba(255,255,255,0.08);
  border: 1.5px solid rgba(255,255,255,0.2);
  color: rgba(255,255,255,0.65);
  border-radius: 50px;
  font-size: 1rem;
  padding: 0.75rem 2rem;
  cursor: pointer;
  transition: all 0.2s;
  font-family: inherit;
}
.kiosk-btn-print:hover { background: rgba(255,255,255,0.14); color: #fff; }

/* Receipt zone — hidden on screen, only rendered in print popup */
.kiosk-receipt-zone { display: none; }
</style>
