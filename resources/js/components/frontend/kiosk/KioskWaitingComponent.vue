<template>
  <div class="kiosk-waiting" :class="{ ready: isReady }">
    <!-- Fond animé -->
    <div class="kiosk-waiting-bg" />

    <!-- Offline order: queued locally, will sync when network returns -->
    <div v-if="isOfflineOrder" class="kiosk-waiting-content">
      <div class="kiosk-waiting-offline">
        <div class="kiosk-offline-icon">📡</div>
        <h1 class="kiosk-waiting-title">Commande enregistrée</h1>
        <p class="kiosk-waiting-hint">Votre commande a été sauvegardée localement.<br>Elle sera transmise dès que la connexion sera rétablie.</p>
        <div class="kiosk-offline-spinner"></div>
        <p class="kiosk-waiting-hint" style="font-size:14px;margin-top:8px">Synchronisation en cours…</p>
      </div>
    </div>

    <!-- Contenu principal -->
    <div v-else class="kiosk-waiting-content">
      <!-- En préparation -->
      <transition name="fade-scale" mode="out-in">
        <div v-if="!isReady" key="preparing" class="kiosk-waiting-preparing">
          <!-- Animation cuisine -->
          <div class="kiosk-waiting-anim">
            <div class="kiosk-chef-hat">👨‍🍳</div>
            <div class="kiosk-wave-ring" v-for="n in 3" :key="n" :style="{ animationDelay: (n * 0.4) + 's' }" />
          </div>

          <h1 class="kiosk-waiting-title">Votre commande est en préparation</h1>

          <!-- Numéro commande — gros, visible de loin -->
          <div class="kiosk-waiting-number-wrap">
            <span class="kiosk-waiting-number-label">Numéro</span>
            <div class="kiosk-waiting-number">{{ queueNumber }}</div>
          </div>

          <p class="kiosk-waiting-hint">Présentez-vous au comptoir quand votre numéro est appelé</p>

          <!-- Barre de progression indéterminée -->
          <div class="kiosk-waiting-progress">
            <div class="kiosk-waiting-progress-bar" />
          </div>
        </div>

        <!-- PRÊT -->
        <div v-else key="ready" class="kiosk-waiting-ready">
          <div class="kiosk-ready-icon">
            <div class="kiosk-ready-ring" />
            <div class="kiosk-ready-check">✓</div>
          </div>
          <h1 class="kiosk-ready-title">Votre commande est prête !</h1>
          <div class="kiosk-waiting-number-wrap">
            <span class="kiosk-waiting-number-label">Numéro</span>
            <div class="kiosk-waiting-number">{{ queueNumber }}</div>
          </div>
          <p class="kiosk-ready-hint">Venez récupérer votre commande au comptoir 🙌</p>
        </div>
      </transition>
    </div>

    <!-- Footer (offline) -->
    <div v-if="isOfflineOrder" class="kiosk-waiting-footer">
      <button class="kiosk-waiting-new-order" @click="newOrder">
        Nouvelle commande →
      </button>
    </div>

    <!-- Footer -->
    <div v-else class="kiosk-waiting-footer">
      <template v-if="isReady">
        <button class="kiosk-waiting-new-order" @click="newOrder">
          Nouvelle commande →
        </button>
        <span class="kiosk-waiting-auto-reset">
          Retour automatique dans {{ autoResetSeconds }}s
        </span>
      </template>
      <template v-else>
        <span class="kiosk-waiting-preparing-hint">
          Merci de patienter…
        </span>
        <!-- Allow cancellation during preparation (before kitchen starts) -->
        <button
          v-if="showCancelButton"
          class="kiosk-waiting-cancel-btn"
          @click="confirmCancel"
        >
          Annuler ma commande
        </button>
      </template>
    </div>

    <!-- Banner connexion perdue -->
    <transition name="slide-down-banner">
      <div v-if="networkLost" class="kiosk-network-banner">
        <span class="kiosk-network-banner-icon">📡</span>
        <span>Connexion perdue — Reconnexion en cours…</span>
      </div>
    </transition>

    <!-- [AUDIT-P1-C] Timeout banner: order stuck for 15 minutes -->
    <!-- [AUDIT-P47-BUG9] Click outside modal resumes polling so customer isn't stuck if they dismiss -->
    <transition name="fade-scale">
      <div v-if="timedOut" class="kiosk-timeout-overlay" @click.self="dismissTimeoutAndResume">
        <div class="kiosk-timeout-modal">
          <div class="kiosk-timeout-icon">⏱️</div>
          <h2>Attente prolongée</h2>
          <p>Votre commande met plus de temps que prévu.</p>
          <p>Merci de vous adresser au personnel.</p>
          <button class="kiosk-timeout-btn" @click="newOrder">Retour à l'accueil</button>
        </div>
      </div>
    </transition>

    <!-- Confirm cancel overlay -->
    <transition name="fade-scale">
      <div v-if="showCancelConfirm" class="kiosk-cancel-overlay" @click.self="showCancelConfirm = false">
        <div class="kiosk-cancel-modal">
          <div class="kiosk-cancel-icon">⚠️</div>
          <h2>Annuler la commande ?</h2>
          <p v-if="!cancelError">Voulez-vous vraiment annuler votre commande ?</p>
          <p v-else class="kiosk-cancel-error-msg">{{ cancelError }}</p>
          <div class="kiosk-cancel-actions">
            <button v-if="!cancelError" class="kiosk-cancel-yes" :disabled="cancelLoading" @click="cancelOrder">
              <span v-if="!cancelLoading">Oui, annuler</span>
              <span v-else class="kiosk-spinner-sm"></span>
            </button>
            <button class="kiosk-cancel-no" @click="closeCancelModal">
              {{ cancelError ? 'Fermer' : 'Non, continuer' }}
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script>
import { mapActions } from 'vuex';
import axios from 'axios';
import orderStatusEnum from '../../../enums/modules/orderStatusEnum';

// [AUDIT-P1-C] Polling interval is always 15s — Echo provides real-time pushes.
// Timeout after 15 minutes if order never becomes ready (customer should contact staff).
const POLL_INTERVAL_MS   = 15000;
const AUTO_RESET_SECONDS = 20;
const TIMEOUT_SECONDS    = 900; // 15 minutes
// Use shared enum — keeps in sync with PHP OrderStatus and KDS component
const STATUS_PREPARED  = orderStatusEnum.PREPARED;   // 8
const STATUS_DELIVERED = orderStatusEnum.DELIVERED;  // 13
const STATUS_PREPARING = orderStatusEnum.PREPARING;  // 7 — kitchen started, cancel no longer allowed
const STATUS_CANCELLED = orderStatusEnum.CANCELED;   // 16 — cancelled by admin/staff

export default {
  name: 'KioskWaitingComponent',
  props: {
    orderId: { type: [String, Number], required: true },
  },
  data() {
    return {
      queueNumber: this.$route.query.queue || '—',
      isReady: false,
      isOfflineOrder: false,
      pollTimer: null,
      countdownTimer: null,
      autoResetSeconds: AUTO_RESET_SECONDS,
      elapsedSeconds: 0,
      elapsedTimer: null,
      showCancelButton: false,
      showCancelConfirm: false,
      cancelError: null,
      cancelLoading: false,
      pollFailCount: 0,
      networkLost: false,
      timedOut: false, // [AUDIT-P1-C] true after 15 min timeout
      _echoChannel: null,
      _pollInFlight: false, // [AUDIT-P2-G] prevent overlapping poll requests
    };
  },
  mounted() {
    // If this is an offline-queued order, skip polling and show "syncing" state
    if (String(this.orderId).startsWith('offline_')) {
      this.isOfflineOrder = true;
      return;
    }
    this.startPolling();
    this._subscribeEcho();
    this.startElapsedTimer();
  },
  beforeUnmount() {
    this.stopAll();
    this._unsubscribeEcho();
  },
  methods: {
    ...mapActions('kioskCart', ['fetchOrderStatus', 'reset']),

    // Subscribe to branch Echo channel for sub-second order status push.
    // Falls back gracefully to polling if Echo/Soketi is unavailable.
    _subscribeEcho() {
      if (!window.Echo) return;
      const branchId = parseInt(this.$store.getters['kioskCart/branchId'] || 0);
      if (branchId <= 0) return;
      // [FIX-53-1] Always unsubscribe first to prevent duplicate listeners on re-mount
      this._unsubscribeEcho();
      try {
        const channelName = `branch.${branchId}`;
        this._echoChannel = window.Echo.private(channelName);
        // Store listener references for proper cleanup (same pattern as KDS/OSS Phase 51/52 fix)
        this._echoListenerCreated = (data) => {
          // [AUDIT-P3] React to OrderCreated to confirm queue number immediately
          if (parseInt(data.order_id) === parseInt(this.orderId)) {
            if (data.queue_number) this.queueNumber = data.queue_number;
          }
        };
        this._echoListenerStatus = (data) => {
          if (parseInt(data.order_id) === parseInt(this.orderId)) {
            this._doPoll();
          }
        };
        this._echoChannel
          .listen('.OrderCreated', this._echoListenerCreated)
          .listen('.OrderStatusChanged', this._echoListenerStatus);
        console.log(`[KioskWaiting] Echo subscribed to ${channelName}`);
      } catch (e) {
        console.warn('[KioskWaiting] Echo subscription failed:', e.message);
      }
    },

    _unsubscribeEcho() {
      if (!window.Echo) return;
      const branchId = parseInt(this.$store.getters['kioskCart/branchId'] || 0);
      if (branchId <= 0) return;
      const channelName = `branch.${branchId}`;
      try {
        // [FIX-53-1] Properly stop listening before leaving channel to prevent memory leak
        if (this._echoChannel) {
          if (this._echoListenerCreated) this._echoChannel.stopListening('.OrderCreated');
          if (this._echoListenerStatus)  this._echoChannel.stopListening('.OrderStatusChanged');
        }
        window.Echo.leave(channelName);
        console.log(`[KioskWaiting] Echo unsubscribed from ${channelName}`);
      } catch (e) {
        console.warn('[KioskWaiting] Echo unsubscribe error:', e.message);
      }
      this._echoChannel = null;
      this._echoListenerCreated = null;
      this._echoListenerStatus = null;
    },

    startPolling() {
      // [AUDIT-P50-BUG7] Guard: do not start polling if orderId is missing or invalid
      const oid = this.orderId;
      if (!oid || oid === 'undefined' || oid === 'null' || String(oid).trim() === '') {
        console.warn('[KioskWaiting] Polling skipped — invalid orderId:', oid);
        return;
      }
      // Poll immediately once, then on interval (fallback when Echo unavailable)
      this._doPoll();
      this.pollTimer = setInterval(() => this._doPoll(), POLL_INTERVAL_MS);
    },

    async _doPoll() {
      if (this.isReady) return;
      // [AUDIT-P2-G] Guard against overlapping poll requests (Echo trigger + interval collision)
      if (this._pollInFlight) return;
      this._pollInFlight = true;
      try {
        const res = await this.fetchOrderStatus(this.orderId);
        const data = res?.data?.data || res?.data || {};
        const numericStatus = parseInt(data.status ?? data.order_status ?? -1, 10);

        if (numericStatus === STATUS_PREPARED || numericStatus === STATUS_DELIVERED) {
          if (data.queue_number) this.queueNumber = data.queue_number;
          this.markReady();
        } else if (numericStatus === STATUS_CANCELLED) {
          // [SPLASH] Order was cancelled by admin/staff — redirect to idle with message
          this.stopAll();
          this.reset();
          this.$router.push({ name: 'kiosk.idle' });
        } else if (numericStatus >= STATUS_PREPARING) {
          // Kitchen started — hide cancel button (API will refuse anyway)
          this.showCancelButton = false;
        }

        // Success — reset failure counter
        this.pollFailCount = 0;
        this.networkLost = false;
      } catch (_) {
        this.pollFailCount += 1;
        // Show network banner after 3 consecutive failures (~15s)
        if (this.pollFailCount >= 3) {
          this.networkLost = true;
        }
      } finally {
        this._pollInFlight = false;
      }
    },

    markReady() {
      clearInterval(this.pollTimer);
      this.isReady = true;
      this.playReadySound();
      this.startAutoReset();
    },

    startAutoReset() {
      this.autoResetSeconds = AUTO_RESET_SECONDS;
      this.countdownTimer = setInterval(() => {
        this.autoResetSeconds--;
        if (this.autoResetSeconds <= 0) this.newOrder();
      }, 1000);
    },

    playReadySound() {
      try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        [523, 659, 784].forEach((freq, i) => {
          const osc = ctx.createOscillator();
          const gain = ctx.createGain();
          osc.connect(gain);
          gain.connect(ctx.destination);
          osc.frequency.value = freq;
          osc.type = 'sine';
          gain.gain.setValueAtTime(0.3, ctx.currentTime + i * 0.18);
          gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + i * 0.18 + 0.4);
          osc.start(ctx.currentTime + i * 0.18);
          osc.stop(ctx.currentTime + i * 0.18 + 0.5);
        });
      } catch (_) {}
    },

    startElapsedTimer() {
      // Show cancel button after 30s — _doPoll() hides it if kitchen already started (PREPARING+)
      // [AUDIT-P1-C] Timeout after 15 minutes — customer should contact staff
      this.elapsedTimer = setInterval(() => {
        this.elapsedSeconds++;
        if (this.elapsedSeconds === 30 && !this.isReady) {
          this.showCancelButton = true;
        }
        if (this.elapsedSeconds >= TIMEOUT_SECONDS && !this.isReady) {
          this.stopAll();
          this.timedOut = true;
        }
      }, 1000);
    },

    confirmCancel() {
      this.cancelError = null;
      this.showCancelConfirm = true;
    },

    closeCancelModal() {
      this.showCancelConfirm = false;
      this.cancelError = null;
      this.cancelLoading = false;
    },

    async cancelOrder() {
      this.cancelLoading = true;
      this.cancelError = null;
      try {
        await axios.post(`frontend/order/change-status/${this.orderId}`, { status: 16 });
        // Success — clean up and return to idle
        this.showCancelConfirm = false;
        this.stopAll();
        this.reset();
        this.$router.push({ name: 'kiosk.idle' });
      } catch (err) {
        // API refused (e.g. kitchen already started PREPARING)
        const msg = err.response?.data?.message || 'Impossible d\'annuler : la préparation a déjà commencé.';
        this.cancelError = msg;
      } finally {
        this.cancelLoading = false;
      }
    },

    stopAll() {
      clearInterval(this.pollTimer);
      clearInterval(this.countdownTimer);
      clearInterval(this.elapsedTimer);
    },

    // [AUDIT-P47-BUG9] Dismiss timeout overlay and resume polling (customer may want to keep waiting)
    // [AUDIT-P48-BUG2] Reset elapsedSeconds so the 15-minute timeout doesn't re-fire immediately.
    dismissTimeoutAndResume() {
      this.timedOut = false;
      this.elapsedSeconds = 0; // CRITICAL: reset counter so timeout can countdown again
      clearInterval(this.pollTimer);
      clearInterval(this.elapsedTimer);
      this.startPolling(); // immediate poll + 15s interval
      this.startElapsedTimer(); // resume elapsed timer (cancel btn after 30s, timeout after 15min)
    },

    newOrder() {
      this.stopAll();
      this.reset();
      this.$router.push({ name: 'kiosk.idle' });
    },
  },
};
</script>

<style scoped>
.kiosk-waiting {
  width: 100vw;
  height: 100vh;
  background: #f7f7f8;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: space-between;
  overflow: hidden;
  position: relative;
  transition: background 0.5s ease;
}

.kiosk-waiting.ready { background: #f6fbf7; }

/* Fond animé */
.kiosk-waiting-bg {
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse at center, rgba(232,0,28,0.05) 0%, transparent 70%);
  animation: bgPulse 4s ease-in-out infinite;
}

.kiosk-waiting.ready .kiosk-waiting-bg {
  background: radial-gradient(ellipse at center, rgba(46,204,113,0.10) 0%, transparent 70%);
}

@keyframes bgPulse { 0%,100% { opacity: 0.5; } 50% { opacity: 1; } }

/* Contenu */
.kiosk-waiting-content {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1;
  padding: 40px;
  width: 100%;
}

/* Préparation */
.kiosk-waiting-preparing,
.kiosk-waiting-ready {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 28px;
  text-align: center;
  width: 100%;
}

/* Animation chef */
.kiosk-waiting-anim {
  position: relative;
  width: 120px;
  height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-chef-hat {
  font-size: 64px;
  z-index: 2;
  animation: chefBounce 1.5s ease-in-out infinite;
}

@keyframes chefBounce {
  0%,100% { transform: translateY(0) rotate(-5deg); }
  50%      { transform: translateY(-8px) rotate(5deg); }
}

.kiosk-wave-ring {
  position: absolute;
  inset: -10px;
  border-radius: 50%;
  border: 2px solid rgba(232,0,28,0.18);
  animation: waveExpand 2s ease-out infinite;
}

@keyframes waveExpand {
  0%   { transform: scale(0.8); opacity: 0.8; }
  100% { transform: scale(1.8); opacity: 0; }
}

.kiosk-waiting-title {
  font-size: 28px;
  font-weight: 800;
  color: #1f1f1f;
  margin: 0;
  max-width: 500px;
  line-height: 1.3;
}

/* Numéro commande */
.kiosk-waiting-number-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.kiosk-waiting-number-label {
  font-size: 14px;
  font-weight: 600;
  color: #999;
  text-transform: uppercase;
  letter-spacing: 2px;
}

.kiosk-waiting-number {
  font-size: 100px;
  font-weight: 900;
  color: #e8001c;
  line-height: 1;
  letter-spacing: -4px;
  text-shadow: 0 6px 24px rgba(232,0,28,0.12);
}

.kiosk-waiting.ready .kiosk-waiting-number {
  color: #2ECC71;
  text-shadow: 0 6px 24px rgba(46,204,113,0.12);
}

.kiosk-waiting-hint, .kiosk-ready-hint {
  font-size: 17px;
  color: #777;
  margin: 0;
  max-width: 400px;
  line-height: 1.5;
}

/* Barre progress */
.kiosk-waiting-progress {
  width: 240px;
  height: 4px;
  background: #ececec;
  border-radius: 2px;
  overflow: hidden;
}

.kiosk-waiting-progress-bar {
  height: 100%;
  background: var(--kiosk-primary);
  border-radius: 2px;
  animation: progressSlide 2s ease-in-out infinite;
}

@keyframes progressSlide {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(300%); }
}

/* Écran PRÊT */
.kiosk-ready-icon {
  position: relative;
  width: 120px;
  height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-ready-ring {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  border: 3px solid rgba(46,204,113,0.22);
  animation: readyRing 1.5s ease-out infinite;
}

@keyframes readyRing { to { transform: scale(2); opacity: 0; } }

.kiosk-ready-check {
  width: 96px;
  height: 96px;
  border-radius: 50%;
  background: var(--kiosk-success);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 48px;
  color: white;
  font-weight: 900;
  box-shadow: 0 0 60px rgba(46,204,113,0.5);
  animation: popIn 0.4s cubic-bezier(0.34,1.56,0.64,1);
}

@keyframes popIn {
  from { transform: scale(0); opacity: 0; }
  to   { transform: scale(1); opacity: 1; }
}

.kiosk-ready-title {
  font-size: 36px;
  font-weight: 900;
  color: #2ECC71;
  margin: 0;
  animation: fadeInUp 0.5s ease;
}

@keyframes fadeInUp {
  from { transform: translateY(20px); opacity: 0; }
  to   { transform: translateY(0); opacity: 1; }
}

/* Footer */
.kiosk-waiting-footer {
  padding: 20px 32px 40px;
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  z-index: 1;
}

.kiosk-waiting-new-order {
  padding: 16px 38px;
  background: #e8001c;
  color: white;
  border: none;
  border-radius: 14px;
  font-size: 18px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 6px 24px rgba(232,0,28,0.2);
  transition: all 0.15s ease;
}

.kiosk-waiting-new-order:active { transform: scale(0.97); }

.kiosk-waiting-auto-reset {
  font-size: 14px;
  color: #999;
  margin-top: 8px;
}

.kiosk-waiting-preparing-hint {
  font-size: 16px;
  color: #999;
  font-style: italic;
}

/* Transitions */
.fade-scale-enter-active, .fade-scale-leave-active { transition: all 0.4s ease; }
.fade-scale-enter-from { opacity: 0; transform: scale(0.95); }
.fade-scale-leave-to   { opacity: 0; transform: scale(1.05); }

/* Offline order state */
.kiosk-waiting-offline {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 20px;
  text-align: center;
}

.kiosk-offline-icon {
  font-size: 72px;
  animation: chefBounce 2s ease-in-out infinite;
}

.kiosk-offline-spinner {
  width: 48px;
  height: 48px;
  border: 3px solid #ececec;
  border-top-color: var(--kiosk-primary);
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* Cancel button */
.kiosk-waiting-cancel-btn {
  margin-top: 12px;
  background: none;
  border: 1px solid rgba(255,100,100,0.4);
  border-radius: 12px;
  color: rgba(255,100,100,0.7);
  padding: 10px 24px;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s;
  display: block;
}
.kiosk-waiting-cancel-btn:hover { border-color: rgba(255,100,100,0.7); color: #ff6464; }

/* Cancel confirm overlay */
.kiosk-cancel-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.4);
  backdrop-filter: blur(6px);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
}
.kiosk-cancel-modal {
  background: white;
  border: 1px solid #f0d5d9;
  border-radius: 22px;
  padding: 2.5rem 2rem;
  max-width: 440px;
  width: 90%;
  text-align: center;
  color: #1f1f1f;
}
.kiosk-cancel-icon  { font-size: 3rem; margin-bottom: 0.75rem; }
.kiosk-cancel-modal h2 { font-size: 1.5rem; font-weight: 700; margin: 0 0 0.5rem; }
.kiosk-cancel-modal p  { color: #777; font-size: 0.95rem; margin: 0 0 1.5rem; }
.kiosk-cancel-actions  { display: flex; gap: 1rem; }
.kiosk-cancel-yes {
  flex: 1;
  background: #fff3f5;
  border: 1px solid #f0b8c2;
  border-radius: 14px;
  color: #d7263d;
  padding: 0.9rem;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
}
.kiosk-cancel-no {
  flex: 1;
  background: #f7f7f8;
  border: 1px solid #e4e4e4;
  border-radius: 14px;
  color: #444;
  padding: 0.9rem;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
}
.kiosk-cancel-yes:active { background: rgba(220,38,38,0.4); }
.kiosk-cancel-no:active  { background: rgba(255,255,255,0.15); }

.kiosk-cancel-error-msg {
  color: #ff6b6b;
  font-size: 1rem;
  margin: 0.5rem 0;
}
.kiosk-spinner-sm {
  display: inline-block;
  width: 18px; height: 18px;
  border: 2.5px solid rgba(215,38,61,0.15);
  border-top-color: #d7263d;
  border-radius: 50%;
  animation: spin-sm 0.7s linear infinite;
  vertical-align: middle;
}
@keyframes spin-sm { to { transform: rotate(360deg); } }

/* Network lost banner */
.kiosk-network-banner {
  position: fixed; top: 0; left: 0; right: 0; z-index: 200;
  background: #e8001c;
  color: #fff;
  display: flex; align-items: center; justify-content: center; gap: 0.6rem;
  padding: 0.65rem 1rem;
  font-size: 0.95rem; font-weight: 600;
}
.kiosk-network-banner-icon { font-size: 1.2rem; }
.slide-down-banner-enter-active,
.slide-down-banner-leave-active { transition: transform 0.35s ease, opacity 0.35s ease; }
.slide-down-banner-enter-from,
.slide-down-banner-leave-to { transform: translateY(-100%); opacity: 0; }
</style>
