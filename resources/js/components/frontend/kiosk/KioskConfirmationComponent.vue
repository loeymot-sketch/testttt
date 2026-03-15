<template>
  <div class="kiosk-confirmation">
    <div class="kiosk-confirmation-icon">
      <span class="kiosk-check-circle">✓</span>
    </div>
    
    <h2 class="kiosk-confirmation-title">Commande confirmée !</h2>
    
    <div class="kiosk-confirmation-details">
      <div class="kiosk-order-number">
        <span class="kiosk-label">Numéro de commande</span>
        <span class="kiosk-value">#{{ orderNumber }}</span>
      </div>
      
      <div class="kiosk-order-total" v-if="orderTotal">
        <span class="kiosk-label">Total</span>
        <span class="kiosk-value price">{{ formatPrice(orderTotal) }}</span>
      </div>
    </div>
    
    <div class="kiosk-confirmation-message">
      <p>Votre commande a été envoyée en cuisine.</p>
      <p>Veuillez vous présenter au comptoir avec votre numéro.</p>
    </div>
    
    <div class="kiosk-confirmation-timer">
      <p>Retour à l'accueil dans {{ countdown }} secondes...</p>
      <div class="kiosk-progress-bar-timer">
        <div class="kiosk-progress-fill" :style="{ width: progressWidth + '%' }"></div>
      </div>
    </div>
    
    <button @click="goHome" class="kiosk-btn-home">
      Retour à l'accueil →
    </button>
  </div>
</template>

<script>
export default {
  name: 'KioskConfirmationComponent',
  props: {
    orderNumber: { type: String, required: true },
    orderTotal: { type: Number, default: null }
  },
  emits: ['close'],
  data() {
    return {
      countdown: 30,
      progressWidth: 100,
      timer: null
    };
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
        
        if (this.countdown <= 0) {
          this.goHome();
        }
      }, 1000);
    },
    clearTimer() {
      if (this.timer) {
        clearInterval(this.timer);
        this.timer = null;
      }
    },
    formatPrice(price) {
      return new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'EUR'
      }).format(price || 0);
    },
    goHome() {
      this.clearTimer();
      this.$emit('close');
    }
  }
};
</script>

<style scoped>
.kiosk-confirmation {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 32px;
  background: linear-gradient(135deg, #43C6AC 0%, #191654 100%);
  color: white;
  text-align: center;
}

.kiosk-confirmation-icon {
  margin-bottom: 24px;
}

.kiosk-check-circle {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100px;
  height: 100px;
  background: white;
  border-radius: 50%;
  font-size: 48px;
  color: #43C6AC;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
  animation: scaleIn 0.5s ease-out;
}

@keyframes scaleIn {
  0% { transform: scale(0); opacity: 0; }
  50% { transform: scale(1.1); }
  100% { transform: scale(1); opacity: 1; }
}

.kiosk-confirmation-title {
  font-size: 32px;
  font-weight: 800;
  margin-bottom: 32px;
  text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.kiosk-confirmation-details {
  background: rgba(255, 255, 255, 0.15);
  border-radius: 20px;
  padding: 24px 32px;
  margin-bottom: 24px;
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.2);
}

.kiosk-order-number,
.kiosk-order-total {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.kiosk-order-number {
  margin-bottom: 16px;
  padding-bottom: 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.kiosk-label {
  font-size: 14px;
  opacity: 0.8;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.kiosk-value {
  font-size: 36px;
  font-weight: 800;
}

.kiosk-value.price {
  color: #FFD700;
}

.kiosk-confirmation-message {
  font-size: 18px;
  line-height: 1.6;
  margin-bottom: 32px;
  max-width: 400px;
}

.kiosk-confirmation-message p {
  margin: 8px 0;
}

.kiosk-confirmation-timer {
  width: 100%;
  max-width: 300px;
  margin-bottom: 24px;
}

.kiosk-confirmation-timer p {
  font-size: 14px;
  opacity: 0.8;
  margin-bottom: 8px;
}

.kiosk-progress-bar-timer {
  height: 6px;
  background: rgba(255, 255, 255, 0.3);
  border-radius: 3px;
  overflow: hidden;
}

.kiosk-progress-fill {
  height: 100%;
  background: white;
  border-radius: 3px;
  transition: width 1s linear;
}

.kiosk-btn-home {
  min-height: 64px;
  padding: 0 48px;
  font-size: 18px;
  font-weight: 700;
  color: #43C6AC;
  background: white;
  border: none;
  border-radius: 16px;
  cursor: pointer;
  touch-action: manipulation;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
  transition: all 0.2s ease;
}

.kiosk-btn-home:active {
  transform: scale(0.98);
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}
</style>
