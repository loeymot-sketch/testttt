<template>
  <div
    class="kiosk-waiting"
    :class="{ ready: isReady, 'kiosk-ready-flash': _readyFlashActive }"
    data-testid="kiosk-waiting-root"
  >
    <!-- Fond animé -->
    <div class="kiosk-waiting-bg" />

    <!-- Offline order: queued locally, will sync when network returns -->
    <div v-if="isOfflineOrder" class="kiosk-waiting-content">
      <div class="kiosk-waiting-offline">
        <div class="kiosk-offline-icon">📡</div>
        <h1 class="kiosk-waiting-title">{{ $t('kiosk.offline_queue.title') }}</h1>
        <p class="kiosk-waiting-hint">
          {{ $t('kiosk.offline_queue.saved') }}<br>{{ $t('kiosk.offline_queue.will_send') }}
        </p>
        <div class="kiosk-offline-spinner"></div>
        <p class="kiosk-waiting-hint" style="font-size:14px;margin-top:8px">{{ $t('kiosk.offline_queue.activity') }}</p>
      </div>
    </div>

    <!-- Contenu principal -->
    <div v-else class="kiosk-waiting-content">
      <!-- [GAP-FIX-03] Rush banner — is_rush signal consumer (source: KioskMenuService::computeIsRush) -->
      <div
        v-if="isRush && !isReady"
        class="kiosk-rush-banner"
        role="status"
        aria-live="polite"
        data-testid="kiosk-rush-banner"
      >
        <span class="kiosk-rush-banner-icon" aria-hidden="true">🔥</span>
        <span class="kiosk-rush-banner-text">
          <strong>{{ $t('kiosk.rush.active_title') }}</strong>
          <span class="kiosk-rush-banner-subtitle">{{ $t('kiosk.rush.subtitle') }}</span>
        </span>
      </div>

      <!-- En préparation -->
      <transition name="fade-scale" mode="out-in">
        <div v-if="!isReady" key="preparing" class="kiosk-waiting-preparing">
          <!-- Animation cuisine -->
          <div class="kiosk-waiting-anim">
            <div class="kiosk-chef-hat">👨‍🍳</div>
            <div class="kiosk-wave-ring" v-for="n in 3" :key="n" :style="{ animationDelay: (n * 0.4) + 's' }" />
          </div>

          <h1 class="kiosk-waiting-title">{{ $t('kiosk.waiting_title') }}</h1>

          <!-- Numéro commande — gros, visible de loin -->
          <div class="kiosk-waiting-number-wrap">
            <span class="kiosk-waiting-number-label">{{ $t('kiosk.waiting_ui.number_label') }}</span>
            <div class="kiosk-waiting-number">{{ queueNumber }}</div>
          </div>

          <p class="kiosk-waiting-hint">{{ $t('kiosk.waiting_ui.preparing_hint') }}</p>

          <!-- [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] "Presque prête" (SSOT
               OrderTrackingService, ALMOST_READY_THRESHOLD=2) remplace position/temps
               une fois qu'il ne reste presque plus de commandes devant. -->
          <div v-if="almostReady" class="kiosk-waiting-almost-ready" data-testid="kiosk-almost-ready">
            {{ $t('kiosk.waiting_ui.almost_ready_banner') }}
          </div>
          <div v-else-if="positionAhead !== null || (waitLow !== null && waitHigh !== null)" class="kiosk-waiting-meta">
            <div v-if="positionAhead !== null" class="kiosk-waiting-meta-item" data-testid="kiosk-position-ahead">
              <span class="kiosk-waiting-meta-value">{{ positionAhead }}</span>
              <span class="kiosk-waiting-meta-label">{{ $t('kiosk.waiting_ui.orders_ahead_label') }}</span>
            </div>
            <div v-if="waitLow !== null && waitHigh !== null" class="kiosk-waiting-meta-item" data-testid="kiosk-wait-estimate">
              <span class="kiosk-waiting-meta-value">{{ waitLow }}-{{ waitHigh }} min</span>
              <span class="kiosk-waiting-meta-label">{{ $t('kiosk.waiting_ui.wait_estimate_label') }}</span>
            </div>
          </div>

          <!-- Barre de progression indéterminée -->
          <div class="kiosk-waiting-progress">
            <div class="kiosk-waiting-progress-bar" />
          </div>

          <!-- Suivi depuis le téléphone (QR vers la page publique /suivi/:token) -->
          <div v-if="trackingToken" class="kiosk-waiting-track" data-testid="kiosk-track-qr">
            <img
              class="kiosk-waiting-track-qr"
              :src="trackQrUrl"
              :alt="$t('kiosk.waiting_ui.track_qr_alt')"
              width="120" height="120"
            >
            <div class="kiosk-waiting-track-text">
              <strong>{{ $t('kiosk.waiting_ui.track_from_phone_title') }}</strong>
              <span>{{ $t('kiosk.waiting_ui.track_from_phone_hint') }}</span>
            </div>
          </div>
        </div>

        <!-- PRÊT -->
        <div v-else key="ready" class="kiosk-waiting-ready">
          <div class="kiosk-ready-icon">
            <div class="kiosk-ready-ring" />
            <div class="kiosk-ready-check">✓</div>
          </div>
          <h1 class="kiosk-ready-title">{{ $t('kiosk.order_ready_title') }}</h1>
          <div class="kiosk-waiting-number-wrap">
            <span class="kiosk-waiting-number-label">{{ $t('kiosk.waiting_ui.number_label') }}</span>
            <div class="kiosk-waiting-number">{{ queueNumber }}</div>
          </div>
          <p class="kiosk-ready-hint">{{ $t('kiosk.waiting_ui.ready_hint') }}</p>
        </div>
      </transition>
    </div>

    <!-- Footer (offline) -->
    <div v-if="isOfflineOrder" class="kiosk-waiting-footer">
      <button type="button" class="kiosk-waiting-new-order" @click="newOrder">
        {{ $t('kiosk.new_order') }}
      </button>
    </div>

    <!-- Footer -->
    <div v-else class="kiosk-waiting-footer">
      <template v-if="isReady">
        <button type="button" class="kiosk-waiting-new-order" @click="newOrder">
          {{ $t('kiosk.new_order') }}
        </button>
        <span class="kiosk-waiting-auto-reset">
          {{ $t('kiosk.auto_redirect', { n: autoResetSeconds }) }}
        </span>
      </template>
      <template v-else>
        <span class="kiosk-waiting-preparing-hint">
          {{ $t('kiosk.waiting_subtitle') }}
        </span>
        <!-- [Owner 2026-05-21] Home button always visible during preparation +
             auto-redirect 10s — owner instructed "normalement ça redirige
             après 10 secondes... bouton de retourner à l'accueil au bout de
             10 secondes automatique". Customer keeps their queue number on
             KDS/OSS regardless of which screen they're on. -->
        <button type="button" class="kiosk-waiting-new-order" @click="newOrder">
          {{ $t('kiosk.new_order') }}
        </button>
        <span class="kiosk-waiting-auto-reset" v-if="preparingAutoRedirectSeconds > 0">
          {{ $t('kiosk.auto_redirect', { n: preparingAutoRedirectSeconds }) }}
        </span>
        <!-- Allow cancellation during preparation (before kitchen starts) -->
        <button type="button"
          v-if="showCancelButton"
          class="kiosk-waiting-cancel-btn"
          @click="confirmCancel"
        >
          {{ $t('kiosk.waiting_screen.cancel_order_btn') }}
        </button>
      </template>
    </div>

    <!-- Banner connexion perdue -->
    <transition name="slide-down-banner">
      <div v-if="networkLost" class="kiosk-network-banner">
        <span class="kiosk-network-banner-icon">📡</span>
        <span>{{ $t('kiosk.waiting_screen.network_lost') }}</span>
      </div>
    </transition>

    <!-- [AUDIT-P1-C] Timeout banner: order stuck for 15 minutes -->
    <!-- [AUDIT-P47-BUG9] Click outside modal resumes polling so customer isn't stuck if they dismiss -->
    <transition name="fade-scale">
      <div v-if="timedOut" class="kiosk-timeout-overlay" @click.self="dismissTimeoutAndResume">
        <div class="kiosk-timeout-modal">
          <div class="kiosk-timeout-icon">⏱️</div>
          <h2>{{ $t('kiosk.waiting_screen.timeout_title') }}</h2>
          <p>{{ $t('kiosk.waiting_screen.timeout_body_1') }}</p>
          <p>{{ $t('kiosk.waiting_screen.timeout_body_2') }}</p>
          <button type="button" class="kiosk-timeout-btn" @click="newOrder">{{ $t('kiosk.waiting_screen.timeout_home') }}</button>
        </div>
      </div>
    </transition>

    <!-- Confirm cancel overlay -->
    <transition name="fade-scale">
      <div v-if="showCancelConfirm" class="kiosk-cancel-overlay" @click.self="showCancelConfirm = false">
        <div class="kiosk-cancel-modal">
          <div class="kiosk-cancel-icon">⚠️</div>
          <h2>{{ $t('kiosk.waiting_screen.cancel_modal_title') }}</h2>
          <p v-if="!cancelError">{{ $t('kiosk.waiting_screen.cancel_modal_body') }}</p>
          <p v-else class="kiosk-cancel-error-msg">{{ cancelError }}</p>
          <div class="kiosk-cancel-actions">
            <button type="button" v-if="!cancelError" class="kiosk-cancel-yes" :disabled="cancelLoading" @click="cancelOrder">
              <span v-if="!cancelLoading">{{ $t('kiosk.waiting_screen.cancel_yes') }}</span>
              <span v-else class="kiosk-spinner-sm"></span>
            </button>
            <button type="button" class="kiosk-cancel-no" @click="closeCancelModal">
              {{ cancelError ? $t('kiosk.waiting_screen.close') : $t('kiosk.waiting_screen.cancel_no') }}
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
import paymentStatusEnum from '../../../enums/modules/paymentStatusEnum';
import { onEvents } from '../../../services/eventContract';
import kioskHardware from '../../../services/kioskHardware';
import { buildIdempotencyHeaders } from '../../../helpers/idempotencyHeaders';
import ENV from '../../../config/env';

// [AUDIT-P1-C] Polling interval is always 15s — Echo provides real-time pushes.
// Timeout after 15 minutes if order never becomes ready (customer should contact staff).
// [HEAL B.3 2026-05-19] Intentional per-surface constant, NOT config-driven.
// Customer-facing screen: 15s balances UX freshness vs network noise. See
// config/broadcasting.php for the per-surface SoT note (RED-Z3 §B-6 closed).
const POLL_INTERVAL_MS   = 15000;
const AUTO_RESET_SECONDS = 20;
const TIMEOUT_SECONDS    = 900; // 15 minutes
// [Owner 2026-05-21] Preparing-state auto-redirect to idle. Customer keeps
// their queue number on KDS/OSS — leaving the waiting screen doesn't
// cancel anything. 10s = owner instruction.
const PREPARING_AUTO_REDIRECT_SECONDS = 10;
// Use shared enum — keeps in sync with PHP OrderStatus and KDS component
const STATUS_PREPARED  = orderStatusEnum.PREPARED;   // 8
const STATUS_DELIVERED = orderStatusEnum.DELIVERED;  // 13
const STATUS_PREPARING = orderStatusEnum.PREPARING;  // 7 — kitchen started, cancel no longer allowed
const STATUS_CANCELLED = orderStatusEnum.CANCELED;   // 16 — cancelled by admin/staff
const PAYMENT_PAID = paymentStatusEnum.PAID;
const PAYMENT_PENDING_COUNTER = paymentStatusEnum.PENDING_COUNTER;

export default {
  name: 'KioskWaitingComponent',

  inject: {
    showToast: { default: () => () => {} },
  },

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
      _eventSub: null,
      _pollInFlight: false, // [AUDIT-P2-G] prevent overlapping poll requests
      _readyFlashActive: false,
      // [Owner 2026-05-21] Countdown to auto-redirect home during preparing state.
      preparingAutoRedirectSeconds: PREPARING_AUTO_REDIRECT_SECONDS,
      preparingAutoRedirectTimer: null,
      // [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] Position file / fourchette temps
      // (même calcul SSOT que la page /suivi publique) + token pour le QR "suivez
      // votre commande depuis votre téléphone".
      trackingToken: null,
      positionAhead: null,
      almostReady: false,
      waitLow: null,
      waitHigh: null,
    };
  },
  computed: {
    trackQrUrl() {
      // <img src> ne passe pas par l'instance axios (baseURL configurée dans
      // shared/axios-setup.js) — même construction ENV.API_URL + '/api/...' ici.
      return this.trackingToken ? `${ENV.API_URL}/api/frontend/order/track-qr/${this.trackingToken}` : null;
    },
    // [GAP-FIX-03] Consume is_rush server-driven flag from kioskMenu Vuex store.
    // Backend signal: KioskMenuService::computeIsRush (checks config kiosk.rush_windows).
    // Vuex storage: kioskMenu.branchFlags.is_rush (mutation SET_BRANCH_FLAGS).
    // Banner shows on waiting screen post-confirmation so client renegotiates
    // expectation BEFORE picking up.
    isRush() {
      const flags = this.$store.getters['kioskMenu/kioskBranchFlags'];
      return !!(flags && flags.is_rush);
    },
  },
  mounted() {
    // If this is an offline-queued order, skip polling and show "syncing" state
    if (String(this.orderId).startsWith('offline_')) {
      this.isOfflineOrder = true;
      return;
    }
    this.startPolling();
    // [Owner 2026-05-21] Start the 10s preparing-state auto-redirect countdown.
    this.startPreparingAutoRedirect();
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
        this._eventSub = onEvents(branchId, [
          {
            broadcastAs: 'OrderCreated',
            handler: (event) => {
              const data = event.payload || {};
              // [AUDIT-P3] React to OrderCreated to confirm queue number immediately
              if (parseInt(data.order_id, 10) === parseInt(this.orderId, 10)) {
                if (data.queue_number) this.queueNumber = data.queue_number;
              }
            },
          },
          {
            broadcastAs: 'OrderStatusChanged',
            handler: (event) => {
              const data = event.payload || {};
              if (parseInt(data.order_id, 10) === parseInt(this.orderId, 10)) {
                this._doPoll();
              }
            },
          },
        ]);
        // [P13_LOG_HYGIENE] console.log(`[KioskWaiting] Echo subscribed to branch.${branchId}`);
      } catch (e) {
        console.warn('[KioskWaiting] Echo subscription failed:', e.message);
      }
    },

    _unsubscribeEcho() {
      const branchId = parseInt(this.$store.getters['kioskCart/branchId'] || 0);
      if (branchId <= 0) return;
      try {
        this._eventSub?.unsubscribe();
        // [P13_LOG_HYGIENE] console.log(`[KioskWaiting] Echo listeners removed from branch.${branchId}`);
      } catch (e) {
        console.warn('[KioskWaiting] Echo unsubscribe error:', e.message);
      }
      this._eventSub = null;
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

        if (data.queue_number) this.queueNumber = data.queue_number;

        // [T-C SUIVI-CLIENT 2026-08-16] `tracking` est un sibling top-level de `data`
        // (OrderDetailsResource::additional(), pas nested dedans) — voir
        // OrderController::trackingPayload(), même forme que /suivi côté client.
        const tracking = res?.data?.tracking;
        if (tracking) {
          if (tracking.tracking_token) this.trackingToken = tracking.tracking_token;
          this.positionAhead = tracking.position_ahead ?? null;
          this.almostReady = !!tracking.almost_ready;
          this.waitLow = tracking.wait_low ?? null;
          this.waitHigh = tracking.wait_high ?? null;
        }

        if (numericStatus === STATUS_PREPARED || numericStatus === STATUS_DELIVERED) {
          this.markReady();
        } else if (numericStatus === STATUS_CANCELLED) {
          // [SPLASH] Order was cancelled by admin/staff — redirect to idle with message
          this.stopAll();
          this.reset();
          this.$router.push({ name: 'kiosk.idle' });
        } else if (this.shouldRouteToConfirmation(data, numericStatus)) {
          await this.routeToConfirmation(data);
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

    shouldRouteToConfirmation(order, numericStatus) {
      if (!order || numericStatus === STATUS_CANCELLED) return false;
      if (numericStatus === STATUS_PREPARED || numericStatus === STATUS_DELIVERED) return false;
      if (numericStatus >= STATUS_PREPARING) return false;

      const paymentStatus = parseInt(order.payment_status, 10);
      return paymentStatus === PAYMENT_PAID
        || paymentStatus === PAYMENT_PENDING_COUNTER
        || order.payment_pending_counter === true;
    },

    async routeToConfirmation(order) {
      this.stopAll();
      const queueNumber = order.queue_number || this.queueNumber;
      if (queueNumber) this.queueNumber = queueNumber;
      this.$store.commit('kioskCart/SET_ORDER_REF', {
        orderId: order.id || this.orderId,
        queueNumber,
      });
      const total = order.total ?? this.$route.query.total ?? null;
      await this.$router.push({
        name: 'kiosk.confirmation',
        query: {
          ...(queueNumber ? { number: queueNumber } : {}),
          ...(total !== null && total !== undefined && total !== '' ? { total } : {}),
        },
      }).catch(() => {});
    },

    markReady() {
      clearInterval(this.pollTimer);
      // [F6 heal 2026-06-09] Cancel the preparing-state 10s auto-redirect (and the
      // elapsed/timeout timer) on PREPARED. Otherwise it keeps running and fires
      // newOrder() at the 10s mark, kicking the customer off the READY screen to
      // idle before the intended 20s ready auto-reset. The comment on
      // startPreparingAutoRedirect() claimed stopAll() cleared it on PREPARED, but
      // markReady() never called stopPreparingAutoRedirect().
      this.stopPreparingAutoRedirect();
      clearInterval(this.elapsedTimer);
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

    // [Owner 2026-05-21] Preparing-state auto-redirect: customer doesn't have
    // to stay on the waiting screen — their queue number is broadcast to KDS
    // and OSS. After 10s in preparing state we route back to idle so the
    // kiosk is ready for the next customer. Cleared by stopAll() when order
    // transitions to PREPARED (then startAutoReset handles ready-state).
    startPreparingAutoRedirect() {
      this.preparingAutoRedirectSeconds = PREPARING_AUTO_REDIRECT_SECONDS;
      this.preparingAutoRedirectTimer = setInterval(() => {
        this.preparingAutoRedirectSeconds--;
        if (this.preparingAutoRedirectSeconds <= 0) {
          this.stopAll();
          this.newOrder();
        }
      }, 1000);
    },

    stopPreparingAutoRedirect() {
      if (this.preparingAutoRedirectTimer) {
        clearInterval(this.preparingAutoRedirectTimer);
        this.preparingAutoRedirectTimer = null;
      }
    },

    async playReadySound() {
      try {
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) throw new Error('AudioContext unavailable');
        const ctx = new Ctor();
        if (ctx.state === 'suspended') {
          await ctx.resume().catch(() => {});
        }
        if (ctx.state !== 'running') throw new Error('AudioContext not running');

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
        setTimeout(() => {
          try { ctx.close(); } catch (_) {}
        }, 1200);
      } catch (_) {
        this.triggerReadyVisualFallback();
      }
    },

    triggerReadyVisualFallback() {
      this.showToast(this.$t('kiosk.waiting.ready_visual_fallback'), 'info', 4000);
      this._readyFlashActive = true;
      window.setTimeout(() => { this._readyFlashActive = false; }, 3000);
      try {
        kioskHardware.haptic('success');
      } catch (_) {}
    },

    startElapsedTimer() {
      clearInterval(this.elapsedTimer);
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
        // [AUDIT-F-004] Kiosk customer cancellation from waiting screen → 'customer_request'
        // (OrderCancelReason enum). Backend OrderStatusRequest 422s without whitelisted reason
        // when actor is kiosk machine token.
        const cancelPayload = {
          status: STATUS_CANCELLED,
          reason: 'customer_request',
        };
        await axios.post(`frontend/order/change-status/${this.orderId}`, cancelPayload, {
          headers: buildIdempotencyHeaders(cancelPayload),
        });
        // Success — clean up and return to idle
        this.showCancelConfirm = false;
        this.stopAll();
        this.reset();
        this.$router.push({ name: 'kiosk.idle' });
      } catch (err) {
        // API refused (e.g. kitchen already started PREPARING)
        const msg = err.response?.data?.message || this.$t('kiosk.waiting_screen.cancel_blocked');
        this.cancelError = msg;
      } finally {
        this.cancelLoading = false;
      }
    },

    stopAll() {
      clearInterval(this.pollTimer);
      clearInterval(this.countdownTimer);
      clearInterval(this.elapsedTimer);
      // [Owner 2026-05-21] Also clear preparing-state auto-redirect.
      this.stopPreparingAutoRedirect();
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
  background: var(--kiosk-page-bg, var(--kiosk-bg));
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: space-between;
  overflow: hidden;
  position: relative;
  transition: background 0.5s ease;
}

.kiosk-waiting.ready { background: var(--kiosk-page-bg, var(--kiosk-bg)); }

/* Fond animé */
.kiosk-waiting-bg {
  position: absolute;
  inset: 0;
  background: var(--kiosk-product-media-bg, transparent);
  animation: bgPulse 4s ease-in-out infinite;
}

.kiosk-waiting.ready .kiosk-waiting-bg {
  background: radial-gradient(ellipse at center, rgba(46,204,113,0.14) 0%, transparent 70%);
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
  border: 2px solid rgba(244, 80, 30,0.18);
  animation: waveExpand 2s ease-out infinite;
}

@keyframes waveExpand {
  0%   { transform: scale(0.8); opacity: 0.8; }
  100% { transform: scale(1.8); opacity: 0; }
}

.kiosk-waiting-title {
  font-size: clamp(32px, 4vw, 48px);
  font-weight: 900;
  color: var(--kiosk-text);
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
  font-size: 16px;
  font-weight: 900;
  color: var(--kiosk-text-muted);
  text-transform: uppercase;
  letter-spacing: 2px;
}

.kiosk-waiting-number {
  font-size: clamp(112px, 16vw, 180px);
  font-weight: 900;
  color: var(--kiosk-primary);
  line-height: 1;
  letter-spacing: -4px;
  text-shadow: 0 6px 24px rgba(244, 80, 30,0.12);
}

.kiosk-waiting.ready .kiosk-waiting-number {
  color: var(--kiosk-success);
  text-shadow: 0 6px 24px rgba(46,204,113,0.12);
}

.kiosk-waiting-hint, .kiosk-ready-hint {
  font-size: 19px;
  color: var(--kiosk-text-muted);
  margin: 0;
  max-width: 400px;
  line-height: 1.5;
}

/* [T-C SUIVI-CLIENT 2026-08-16] Presque prête / position+temps */
.kiosk-waiting-almost-ready {
  background: rgba(255, 184, 0, 0.12);
  border: 1px solid var(--kiosk-primary, #F4501E);
  border-radius: 16px;
  padding: 14px 22px;
  font-size: 17px;
  font-weight: 800;
  color: var(--kiosk-text);
  text-align: center;
}

.kiosk-waiting-meta {
  display: flex;
  gap: 16px;
}

.kiosk-waiting-meta-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  background: var(--kiosk-surface-alt);
  border-radius: 16px;
  padding: 12px 20px;
  min-width: 120px;
}

.kiosk-waiting-meta-value {
  font-size: 24px;
  font-weight: 900;
  color: var(--kiosk-text);
}

.kiosk-waiting-meta-label {
  font-size: 12px;
  color: var(--kiosk-text-muted);
  text-align: center;
}

/* Suivi téléphone (QR) */
.kiosk-waiting-track {
  display: flex;
  align-items: center;
  gap: 14px;
  background: var(--kiosk-surface-alt);
  border-radius: 18px;
  padding: 12px 18px;
  max-width: 380px;
}

.kiosk-waiting-track-qr {
  width: 76px;
  height: 76px;
  background: #fff;
  border-radius: 10px;
  padding: 4px;
  flex-shrink: 0;
}

.kiosk-waiting-track-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  text-align: left;
  font-size: 13px;
  color: var(--kiosk-text-muted);
}

.kiosk-waiting-track-text strong {
  font-size: 15px;
  color: var(--kiosk-text);
}

/* Barre progress */
.kiosk-waiting-progress {
  width: min(360px, 58vw);
  height: 8px;
  background: var(--kiosk-surface-alt);
  border-radius: 999px;
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
  font-size: clamp(42px, 6vw, 68px);
  font-weight: 900;
  color: var(--kiosk-success);
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
  min-height: 76px;
  padding: 18px 42px;
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  border: none;
  border-radius: 28px;
  font-size: 22px;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 6px 24px rgba(244, 80, 30,0.2);
  transition: all 0.15s ease;
}

.kiosk-waiting-new-order:active { transform: scale(0.97); }

.kiosk-waiting-auto-reset {
  font-size: 16px;
  color: var(--kiosk-text-muted);
  margin-top: 8px;
}

.kiosk-waiting-preparing-hint {
  font-size: 18px;
  color: var(--kiosk-text-muted);
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
  border: 3px solid var(--kiosk-border);
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
  background: var(--kiosk-overlay-modal);
  backdrop-filter: blur(6px);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
}
.kiosk-cancel-modal {
  background: var(--kiosk-surface);
  border: 1px solid var(--kiosk-border);
  border-radius: 22px;
  padding: 2.5rem 2rem;
  max-width: 440px;
  width: 90%;
  text-align: center;
  color: var(--kiosk-text);
}
.kiosk-cancel-icon  { font-size: 3rem; margin-bottom: 0.75rem; }
.kiosk-cancel-modal h2 { font-size: 1.5rem; font-weight: 700; margin: 0 0 0.5rem; }
.kiosk-cancel-modal p  { color: var(--kiosk-text-muted); font-size: 0.95rem; margin: 0 0 1.5rem; }
.kiosk-cancel-actions  { display: flex; gap: 1rem; }
.kiosk-cancel-yes {
  flex: 1;
  background: var(--kiosk-primary-soft);
  border: 1px solid var(--kiosk-border);
  border-radius: 14px;
  color: var(--kiosk-primary);
  padding: 0.9rem;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
}
.kiosk-cancel-no {
  flex: 1;
  background: var(--kiosk-surface-alt);
  border: 1px solid var(--kiosk-border);
  border-radius: 14px;
  color: var(--kiosk-text);
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

/* [GAP-FIX-03] Rush banner — visible during preparing state when chef backlog detected. */
.kiosk-rush-banner {
  position: absolute;
  top: 24px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 2;
  display: inline-flex;
  align-items: center;
  gap: 12px;
  padding: 12px 22px;
  background: rgba(244, 80, 30, 0.10);
  border: 1px solid rgba(244, 80, 30, 0.32);
  border-radius: 999px;
  max-width: 90vw;
  box-shadow: 0 4px 16px rgba(244, 80, 30, 0.08);
}
.kiosk-rush-banner-icon { font-size: 22px; }
.kiosk-rush-banner-text {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  color: var(--kiosk-text);
  font-size: 15px;
  line-height: 1.2;
  text-align: left;
}
.kiosk-rush-banner-text strong { font-weight: 800; }
.kiosk-rush-banner-subtitle {
  font-size: 13px;
  color: var(--kiosk-text-muted);
  font-weight: 500;
}

/* Network lost banner */
.kiosk-network-banner {
  position: fixed; top: 0; left: 0; right: 0; z-index: 200;
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  display: flex; align-items: center; justify-content: center; gap: 0.6rem;
  padding: 0.65rem 1rem;
  font-size: 0.95rem; font-weight: 600;
}
.kiosk-network-banner-icon { font-size: 1.2rem; }
.slide-down-banner-enter-active,
.slide-down-banner-leave-active { transition: transform 0.35s ease, opacity 0.35s ease; }
.slide-down-banner-enter-from,
.slide-down-banner-leave-to { transform: translateY(-100%); opacity: 0; }

/* Audio indisponible — flash visuel 3s (WCAG 2.3.3 reduced motion) */
.kiosk-waiting.kiosk-ready-flash {
  animation: kioskReadyFlash 3s ease-out 1;
}
@keyframes kioskReadyFlash {
  0% { box-shadow: inset 0 0 0 0 rgba(46, 204, 113, 0); }
  15% { box-shadow: inset 0 0 0 9999px rgba(46, 204, 113, 0.12); }
  100% { box-shadow: inset 0 0 0 0 rgba(46, 204, 113, 0); }
}
@media (prefers-reduced-motion: reduce) {
  .kiosk-waiting.kiosk-ready-flash {
    animation: none;
    box-shadow: inset 0 0 0 9999px rgba(46, 204, 113, 0.08);
  }
}
</style>
