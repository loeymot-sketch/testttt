<template>
  <div class="kiosk-payment" data-testid="kiosk-payment-root">
    <!-- Header -->
    <div class="kiosk-pay-header">
      <button
        class="kiosk-pay-back"
        type="button"
        @click="$router.replace({ name: 'kiosk.cart' })"
        :disabled="submitting"
        :aria-label="$t('kiosk.back')"
        data-testid="kiosk-payment-back"
      >
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <div class="kiosk-pay-header-info">
        <h1 class="kiosk-pay-title" data-testid="kiosk-payment-title">{{ $t('kiosk.pay_screen.title') }}</h1>
        <p class="kiosk-pay-total-label" data-testid="kiosk-payment-total">{{ $t('kiosk.pay_screen.total_prefix') }} <strong>{{ formatPrice(cartTotal) }}</strong></p>
      </div>
    </div>

    <!-- Modes de paiement — grille borne (cartes, pas bandeaux pleine largeur) -->
    <div
      v-if="!submitting && !submitted"
      class="kiosk-pay-methods-outer"
      role="radiogroup"
      :aria-label="$t('kiosk.pay_screen.title')"
    >
      <div class="kiosk-pay-methods">
      <!-- CB -->
      <div
        class="kiosk-pay-method"
        :class="{ selected: method === 'card' }"
        role="radio"
        tabindex="0"
        :aria-checked="method === 'card'"
        data-testid="kiosk-payment-method-card"
        @click="selectMethod('card')"
        @keydown.enter.prevent="selectMethod('card')"
        @keydown.space.prevent="selectMethod('card')"
      >
        <div class="kiosk-pay-method-icon card">
          <svg width="52" height="52" viewBox="0 0 52 52" fill="none">
            <rect x="4" y="12" width="44" height="30" rx="6" fill="white" fill-opacity="0.12" stroke="white" stroke-opacity="0.3" stroke-width="1.5"/>
            <rect x="4" y="20" width="44" height="8" fill="white" fill-opacity="0.2"/>
            <rect x="10" y="32" width="12" height="4" rx="2" fill="white" fill-opacity="0.5"/>
            <rect x="26" y="32" width="8" height="4" rx="2" fill="white" fill-opacity="0.5"/>
          </svg>
        </div>
        <div class="kiosk-pay-method-info">
          <h3>{{ $t('kiosk.pay_screen.card_title') }}</h3>
          <p>{{ $t('kiosk.pay_screen.card_sub') }}</p>
        </div>
        <div class="kiosk-pay-method-check" v-if="method === 'card'">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M5 12l5 5 9-10" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
      </div>

      <!-- Espèces -->
      <div
        class="kiosk-pay-method"
        :class="{ selected: method === 'cash' }"
        role="radio"
        tabindex="0"
        :aria-checked="method === 'cash'"
        data-testid="kiosk-payment-method-cash"
        @click="selectMethod('cash')"
        @keydown.enter.prevent="selectMethod('cash')"
        @keydown.space.prevent="selectMethod('cash')"
      >
        <div class="kiosk-pay-method-icon cash">
          <svg width="52" height="52" viewBox="0 0 52 52" fill="none">
            <rect x="4" y="14" width="44" height="26" rx="6" fill="white" fill-opacity="0.12" stroke="white" stroke-opacity="0.3" stroke-width="1.5"/>
            <circle cx="26" cy="27" r="8" stroke="white" stroke-opacity="0.5" stroke-width="1.5"/>
            <text x="26" y="32" text-anchor="middle" font-size="12" fill="white" fill-opacity="0.8" font-weight="bold">€</text>
          </svg>
        </div>
        <div class="kiosk-pay-method-info">
          <h3>{{ $t('kiosk.pay_screen.cash_title') }}</h3>
          <p>{{ $t('kiosk.pay_screen.cash_sub') }}</p>
        </div>
        <div class="kiosk-pay-method-check" v-if="method === 'cash'">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M5 12l5 5 9-10" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
      </div>

      <!-- Ticket Restaurant -->
      <div
        class="kiosk-pay-method"
        :class="{ selected: method === 'tr' }"
        role="radio"
        tabindex="0"
        :aria-checked="method === 'tr'"
        data-testid="kiosk-payment-method-tr"
        @click="selectMethod('tr')"
        @keydown.enter.prevent="selectMethod('tr')"
        @keydown.space.prevent="selectMethod('tr')"
      >
        <div class="kiosk-pay-method-icon tr">
          <svg width="52" height="52" viewBox="0 0 52 52" fill="none">
            <rect x="4" y="12" width="44" height="28" rx="6" fill="white" fill-opacity="0.12" stroke="white" stroke-opacity="0.3" stroke-width="1.5"/>
            <path d="M14 22h24M14 28h16" stroke="white" stroke-opacity="0.6" stroke-width="2" stroke-linecap="round"/>
            <path d="M36 28l4 4" stroke="white" stroke-opacity="0.6" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <div class="kiosk-pay-method-info">
          <h3>{{ $t('kiosk.pay_screen.tr_title') }}</h3>
          <p>{{ $t('kiosk.pay_screen.tr_sub') }}</p>
        </div>
        <div class="kiosk-pay-method-check" v-if="method === 'tr'">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M5 12l5 5 9-10" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
      </div>
      </div>
    </div>

    <!-- Écran API en cours (commande en création) -->
    <div
      v-if="submitting"
      class="kiosk-pay-processing"
      role="status"
      aria-live="polite"
      data-testid="kiosk-payment-processing"
    >
      <div class="kiosk-pay-processing-ring" aria-hidden="true">
        <div class="kiosk-pay-processing-ring-inner" />
      </div>
      <h2>{{ $t('kiosk.pay_screen.processing_title') }}</h2>
      <p>{{ $t('kiosk.pay_screen.processing_sub') }}</p>
    </div>

    <!-- Écran TPE : attente terminal physique (carte ou TR) -->
    <transition name="fade-scale">
      <div
        v-if="tpeWaiting"
        class="kiosk-tpe-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="kiosk-tpe-title"
        data-testid="kiosk-payment-tpe-overlay"
      >
        <div class="kiosk-tpe-card-anim" aria-hidden="true">
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
        <h2 id="kiosk-tpe-title" class="kiosk-tpe-title" aria-live="polite">{{ tpeMessage }}</h2>
        <p class="kiosk-tpe-sub">{{ $t('kiosk.pay_screen.tpe_follow') }}</p>
        <div class="kiosk-tpe-spinner" aria-hidden="true"></div>
        <button
          v-if="tpeCanCancel"
          class="kiosk-tpe-cancel"
          @click="cancelCardPayment"
          data-testid="kiosk-payment-tpe-cancel"
        >
          {{ $t('kiosk.pay_screen.cancel_payment') }}
        </button>
      </div>
    </transition>

    <!-- Bouton confirmer -->
    <div v-if="!submitting && !submitted && !tpeWaiting" class="kiosk-pay-confirm">
      <div
        v-if="error"
        class="kiosk-pay-error"
        role="alert"
        data-testid="kiosk-payment-error"
      >{{ error }}</div>
      <div class="kiosk-pay-confirm-inner">
      <button
        class="kiosk-btn-confirm"
        :disabled="!method"
        @click="confirmPayment"
        :aria-label="$t('kiosk.pay_screen.confirm', { amount: formatPrice(cartTotal) })"
        data-testid="kiosk-payment-confirm"
      >
        <span>{{ $t('kiosk.pay_screen.confirm', { amount: formatPrice(cartTotal) }) }}</span>
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true">
          <path d="M6 14h16M16 8l6 6-6 6" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      </div>
    </div>

  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';
import axios from 'axios';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
// [PHASE-6.1] Unified hardware wrapper — remplace les appels window.borne.* directs
//             par le contrat {ok, error?} + reporting automatique des erreurs hardware.
import kioskHardware from '../../../services/kioskHardware';
// [PHASE-6.4] Analytics instrumentation (gated par consent, no-op si opt-out).
import kioskAnalytics from '../../../helpers/kioskAnalytics';

export default {
  name: 'KioskPaymentComponent',
  mixins: [kioskPriceMixin],

  inject: {
    showToast: { default: () => () => {} },
  },

  data() {
    return {
      method:        null,
      submitting:    false,
      submitted:     false,
      error:         null,
      tpeWaiting:    false,
      tpeMessage:    '',
      tpeCanCancel:  false,
      _lastOrder:    null,
    };
  },
  computed: {
    // [GAP-22-4] Also read orderType so it's passed to submitOrder
    ...mapGetters('kioskCart', ['total', 'branchId', 'orderType']),
    cartTotal() { return this.total; },
  },
  beforeUnmount() {
    this._lastOrder = null;
  },
  methods: {
    ...mapActions('kioskCart', ['submitOrder', 'reset']),

    selectMethod(m) {
      this.method = m;
      this.error = null;
      // [PHASE-6.4] Analytics : sélection d'un moyen de paiement (avant confirm).
      try { kioskAnalytics.track('payment_method_selected', { method: m }); } catch (_) {}
    },

    async confirmPayment() {
      if (!this.method || this.submitting) return;
      this.submitting = true;
      this.error = null;
      // [PHASE-6.4] Analytics : démarrage du checkout (intent de payer).
      try { kioskAnalytics.track('checkout_started', { method: this.method, total_cents: Math.round(this.cartTotal * 100) }); } catch (_) {}

      try {
        // Step 1 — Submit order to Laravel API
        // [GAP-22-4] Pass orderType (sur place=25 / à emporter=10) chosen by customer in cart
        const res = await this.submitOrder({ paymentMethod: this.method, orderType: this.orderType });
        const orderId  = res?.data?.data?.id || res?.data?.id;
        const queueNum = res?.data?.data?.queue_number || res?.data?.queue_number;
        // [AUDIT-52-BUG5] FrontendOrder (kiosk) uses column 'total', NOT 'order_amount' (POS-only column).
        // Fallback chain: server total → POS order_amount (never set for kiosk) → client cart total.
        // Using cartTotal as final fallback only — TPE must always charge the server-validated amount.
        const total    = res?.data?.data?.total ?? res?.data?.data?.order_amount ?? this.cartTotal;

        // [AUDIT-P2] Check if loyalty discount was silently dropped server-side.
        // This happens when points were consumed by another order between the loyalty check
        // and the order commit (race condition). The order still succeeds but without the discount.
        const loyaltyWasRequested = this.$store.state.kioskCart?.loyaltyDiscount > 0;
        const loyaltyApplied = res?.data?.loyalty_applied;
        if (loyaltyWasRequested && loyaltyApplied === false) {
          this.showToast(this.$t('kiosk.pay_screen.loyalty_not_applied_toast'), 'warning', 6000);
        }

        // [AUDIT-P0] Guard: if the API response is malformed and orderId is missing,
        // do NOT navigate to /waiting/undefined — show a clear error instead.
        // This prevents an infinite poll loop on GET frontend/order/undefined.
        // [AUDIT-P48-BUG3] Clearer logic: throw if no orderId AND it's not an offline queued order.
        const isOfflineId = typeof orderId === 'string' && orderId.startsWith('offline_');
        if (!orderId && !isOfflineId) {
          throw new Error(this.$t('kiosk.pay_screen.invalid_order_response'));
        }

        this._lastOrder = { id: orderId, queue_number: queueNum, total };

        this.submitting = false;

        const navTarget = {
          name:   'kiosk.waiting',
          params: { orderId: String(orderId) },
          query:  { queue: queueNum, total },
        };

        // Step 2 — Payment processing
        if (this.method === 'card' || this.method === 'tr') {
          await this.processCardPayment(navTarget);
        } else {
          await this.processCashPayment(navTarget);
        }

      } catch (err) {
        this.tpeWaiting = false;
        this.tpeCanCancel = false;
        // [AUDIT-52-BUG7] Specific user-friendly message for TPE timeout
        let msg;
        if (err?.message === 'TPE_TIMEOUT') {
          msg = this.$t('kiosk.pay_screen.tpe_timeout');
        } else {
          msg = err?.response?.data?.errors
            ? Object.values(err.response.data.errors).flat().join(' ')
            : (err?.message || this.$t('kiosk.pay_screen.payment_error_generic'));
        }
        this.error = msg;
        this.showToast(msg, 'error', 6000);
        this.submitting = false;
        this.submitted = false;
      }
    },

    async processCardPayment(navTarget) {
      this.tpeWaiting = true;
      const tpeKey =
        this.method === 'card'
          ? 'tpe_card'
          : this.method === 'tr'
            ? 'tpe_tr'
            : 'tpe_default';
      this.tpeMessage = this.$t(`kiosk.pay_screen.${tpeKey}`);
      this.tpeCanCancel = true;

      // [PHASE-6.1] Passage par kioskHardware — stub auto en navigateur (dev/tests),
      // contrat {ok, error?} uniforme, auto-report vers /frontend/kiosk-event en cas de throw.
      // [AUDIT-52-BUG7] Wrap dans un timeout global de 120s (TPE peut figer sur chip+PIN).
      const TPE_TIMEOUT_MS = 120_000;
      const amountEuros = this._lastOrder.total || this.cartTotal;
      const tpeMethod = this.method === 'tr' ? 'TR' : 'CB';
      const paymentResult = await Promise.race([
        this._invokeTpe(amountEuros, tpeMethod),
        new Promise((_, reject) => setTimeout(() => reject(new Error('TPE_TIMEOUT')), TPE_TIMEOUT_MS)),
      ]);

      this.tpeCanCancel = false;

      if (!paymentResult.approved) {
        this.tpeWaiting = false;
        // [PHASE-6.4] Analytics : échec paiement (code normalisé, jamais de PII).
        try {
          kioskAnalytics.track('payment_failed', {
            method: this.method,
            reason_code: paymentResult.error_code || 'declined',
            total_cents: Math.round((this._lastOrder.total || this.cartTotal) * 100),
          });
        } catch (_) {}
        // [AUDIT-P1] Void the server-side order when TPE declines/cancels.
        // Without this, a PENDING order stays in DB forever (orphan order).
        // We fire-and-forget: if the void fails, staff can cancel manually from admin.
        if (this._lastOrder?.id && !String(this._lastOrder.id).startsWith('offline_')) {
          axios.post(`frontend/order/change-status/${this._lastOrder.id}`, { status: 16 })
            .catch(e => console.warn('[KioskPayment] void order failed:', e.message));
        }
        throw new Error(paymentResult.error || this.$t('kiosk.pay_screen.payment_declined'));
      }

      this.tpeMessage = this.$t('kiosk.pay_screen.tpe_accepted');

      // [PHASE-6.4] Analytics : paiement validé au TPE (avant confirm API).
      try {
        kioskAnalytics.track('payment_completed', {
          method: this.method,
          total_cents: Math.round((this._lastOrder.total || this.cartTotal) * 100),
        });
      } catch (_) {}

      // Step 3 — Confirm payment on backend (stores transaction_id)
      if (this._lastOrder?.id && paymentResult.transaction_id) {
        await this.confirmBackendPayment(this._lastOrder.id, {
          transaction_id: paymentResult.transaction_id,
          card_type:      paymentResult.card_type || 'CARD',
          payment_method: this.method === 'tr' ? 5 : 4,
        });
      }

      await new Promise(r => setTimeout(r, 800));
      this.tpeWaiting = false;
      this.$router.push(navTarget);
    },

    /**
     * [PHASE-6.1] Invoque le TPE via kioskHardware.tpeCharge et normalise le
     * résultat au shape historique `{approved, transaction_id, card_type, error}`
     * attendu par processCardPayment. En dev (stub), retourne un stub synthétique.
     *
     * Contrat `tpeCharge(amountCents, method)` du service :
     *   → { ok: true, tx_ref, legacy?, data? } | { ok: false, error }
     *
     * Rétro-compat : si le bridge renvoie un shape legacy { status: 'approved', ... }
     * (vieux firmware Electron), runSafe encapsule déjà dans `data`.
     */
    async _invokeTpe(amountEuros, method = 'CB') {
      // Pas de bridge réel → stub navigateur classique avec délai visuel.
      if (!kioskHardware.isKioskBridge()) {
        this.tpeMessage = this.$t('kiosk.pay_screen.tpe_browser_sim');
        await new Promise((r) => setTimeout(r, 2000));
        return { approved: true, transaction_id: `STUB-${Date.now()}`, card_type: 'VISA' };
      }
      const amountCents = Math.round(Number(amountEuros) * 100);
      const result = await kioskHardware.tpeCharge(amountCents, method);
      if (!result?.ok) {
        return {
          approved: false,
          error: result?.error || 'tpe_unknown_error',
          error_code: result?.error_code || null,
        };
      }
      // Le bridge peut renvoyer soit un shape direct `{tx_ref}`, soit une capsule
      // `{data: {status: 'approved', transaction_id, card_type, ...}}` (legacy).
      const raw = result.data || result;
      const approved =
        result.ok !== false &&
        (raw.status === 'approved' || raw.approved === true || !!raw.transaction_id || !!raw.tx_ref);
      return {
        approved,
        transaction_id: raw.transaction_id || raw.tx_ref || result.tx_ref || null,
        card_type: raw.card_type || raw.cardType || 'CARD',
        error: !approved ? (raw.error || result.error || 'declined') : null,
        error_code: raw.error_code || result.error_code || null,
      };
    },

    async processCashPayment(navTarget) {
      // [PHASE-6.1] Passage par kioskHardware.openDrawer — le service gère
      // bridge absent (no-op stub) + report auto d'erreur via hardware_event.
      if (kioskHardware.isKioskBridge()) {
        const drawerResult = await kioskHardware.openDrawer();
        if (!drawerResult.ok) {
          console.warn('[KioskPayment] Cash drawer failed:', drawerResult.error);
          this._reportDrawerFailure(drawerResult.error || 'no success');
        }
      }
      // [PHASE-6.4] Paiement cash : l'ordre est transmis, le cash est encaissé par le staff.
      // On émet payment_completed ici même sans validation TPE (cf. KIOSK_ANALYTICS_EVENTS.md).
      try {
        kioskAnalytics.track('payment_completed', {
          method: 'cash',
          total_cents: Math.round((this._lastOrder?.total || this.cartTotal) * 100),
        });
      } catch (_) {}
      this.$router.push(navTarget);
    },
    _reportDrawerFailure(errorMsg) {
      // [PHASE-6.1] Conservé : reporte un event "cash_drawer_failure" dédié
      // (séparé du hardware_event générique car utilisé par dashboards ops).
      try {
        window.axios?.post('frontend/kiosk-event', {
          type: 'cash_drawer_failure',
          details: `error=${errorMsg || 'unknown'}`,
        }).catch(() => {});
      } catch (_) {}
    },

    async cancelCardPayment() {
      // [PHASE-6.1] cancelPayment via kioskHardware — no-op silencieux si bridge absent.
      if (kioskHardware.isKioskBridge()) {
        await kioskHardware.cancelPayment().catch(() => {});
      }
      this.tpeWaiting = false;
      this.tpeCanCancel = false;
      this.submitted = false;
      this.submitting = false;
      this.error = this.$t('kiosk.pay_screen.payment_cancelled');
      this.showToast(this.$t('kiosk.pay_screen.payment_cancelled_toast'), 'warning', 2500);
      // [PHASE-6.4] Analytics : abandon explicite utilisateur au TPE.
      try {
        kioskAnalytics.track('order_cancelled', {
          method: this.method,
          stage: 'tpe_cancel',
        });
      } catch (_) {}
      // [AUDIT-P1] Void the server order created before TPE — prevents orphan PENDING orders.
      if (this._lastOrder?.id && !String(this._lastOrder.id).startsWith('offline_')) {
        axios.post(`frontend/order/change-status/${this._lastOrder.id}`, { status: 16 })
          .catch(e => console.warn('[KioskPayment] void on cancel failed:', e.message));
        this._lastOrder = null;
      }
    },

    async confirmBackendPayment(orderId, payload) {
      let lastError = null;
      for (let attempt = 1; attempt <= 3; attempt++) {
        try {
          await axios.post(`frontend/order/${orderId}/payment-confirm`, payload);
          return;
        } catch (error) {
          lastError = error;
          if (attempt < 3) {
            await new Promise((resolve) => setTimeout(resolve, attempt * 700));
          }
        }
      }
      console.warn('[KioskPayment] payment-confirm failed after retries:', lastError?.message);
      throw new Error(this.$t('kiosk.pay_screen.payment_sync_failed'));
    },

    // formatPrice() provided by kioskPriceMixin
  },
};
</script>

<style scoped>
.kiosk-payment {
  width: 100vw;
  height: 100vh;
  background: var(--kiosk-bg);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  color: var(--kiosk-text);
}

/* Header — thème clair : texte foncé lisible */
.kiosk-pay-header {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 24px 32px 20px;
  background: var(--kiosk-surface);
  border-bottom: 1px solid var(--kiosk-border);
  flex-shrink: 0;
}

.kiosk-pay-back {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  border: 1.5px solid var(--kiosk-border);
  background: var(--kiosk-bg);
  color: var(--kiosk-text);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
  transition: all 0.15s ease;
}

.kiosk-pay-back:active { background: var(--kiosk-surface-alt); transform: scale(0.95); }
.kiosk-pay-back:disabled { opacity: 0.4; cursor: not-allowed; }

.kiosk-pay-header-info { flex: 1; }

.kiosk-pay-title {
  font-size: 26px;
  font-weight: 800;
  color: var(--kiosk-text);
  margin: 0 0 4px;
}

.kiosk-pay-total-label {
  font-size: 16px;
  color: var(--kiosk-text-muted);
  margin: 0;
}

.kiosk-pay-total-label strong { color: var(--kiosk-text); font-size: 18px; }

/* Grille méthodes — cartes centrées, pas bandeaux edge-to-edge */
.kiosk-pay-methods-outer {
  flex: 1;
  overflow-y: auto;
  padding: 28px 32px;
  scrollbar-width: none;
  display: flex;
  justify-content: center;
  align-items: flex-start;
}

.kiosk-pay-methods-outer::-webkit-scrollbar { display: none; }

.kiosk-pay-methods {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
  gap: 20px;
  width: 100%;
  max-width: 1000px;
  align-content: start;
}

.kiosk-pay-method {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 24px 28px;
  min-height: 120px;
  background: var(--kiosk-bg);
  border-radius: 20px;
  border: 2px solid var(--kiosk-border);
  box-shadow: var(--kiosk-shadow-card);
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
}

.kiosk-pay-method:active { transform: scale(0.99); }

.kiosk-pay-method.selected {
  border-color: var(--kiosk-primary);
  background: var(--kiosk-primary-soft);
  box-shadow: 0 0 0 2px var(--kiosk-primary), var(--kiosk-shadow-lift);
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

/* Icônes métier payment : gradient brand spécifique carte/cash/TR.
   Couleurs conservées hors tokens car elles encodent la sémantique de moyen de
   paiement (bleu CB / vert cash / orange TR), reconnues internationalement. */
.kiosk-pay-method-icon.card   { background: linear-gradient(135deg, #1a3a6b, var(--kiosk-info, #2563EB)); }
.kiosk-pay-method-icon.cash   { background: linear-gradient(135deg, #0a4a20, var(--kiosk-success, #16a34a)); }
.kiosk-pay-method-icon.tr     { background: linear-gradient(135deg, #7a2000, #ea580c); }

.kiosk-pay-method-info { flex: 1; min-width: 0; }

.kiosk-pay-method-info h3 {
  font-size: 22px;
  font-weight: 700;
  color: var(--kiosk-text);
  margin: 0 0 4px;
}

.kiosk-pay-method-info p {
  font-size: 14px;
  color: var(--kiosk-text-muted);
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
  border: 4px solid var(--kiosk-primary-soft);
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
  background: var(--kiosk-primary-soft);
  border: 4px solid var(--kiosk-primary);
  border-top-color: transparent;
  animation: spin 0.8s linear infinite reverse;
}

.kiosk-pay-processing h2 {
  font-size: 28px;
  font-weight: 800;
  color: var(--kiosk-text);
  margin: 0;
}

.kiosk-pay-processing p {
  font-size: 16px;
  color: var(--kiosk-text-muted);
  margin: 0;
}

/* Erreur */
.kiosk-pay-error {
  background: var(--kiosk-primary-soft);
  border: 1px solid var(--kiosk-primary);
  color: var(--kiosk-error);
  padding: 14px 20px;
  border-radius: 12px;
  font-size: 15px;
  text-align: center;
  margin-bottom: 8px;
}

/* Confirmer — largeur max centrée (borne) */
.kiosk-pay-confirm {
  padding: 20px 32px 32px;
  flex-shrink: 0;
}

.kiosk-pay-confirm-inner {
  display: flex;
  justify-content: center;
  width: 100%;
}

.kiosk-btn-confirm {
  width: 100%;
  max-width: 480px;
  min-height: 80px;
  height: auto;
  padding: 20px 32px;
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  border: none;
  border-radius: 20px;
  font-size: 22px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  box-shadow: var(--kiosk-shadow-cta);
  transition: all 0.15s ease;
}

.kiosk-btn-confirm:disabled {
  opacity: 0.4;
  cursor: not-allowed;
  box-shadow: none;
}

.kiosk-btn-confirm:not(:disabled):active {
  transform: scale(0.98);
  box-shadow: var(--kiosk-shadow-card);
}

/* TPE terminal waiting overlay — fond sombre volontaire (focus haptique sur CB).
   Les teintes sombres #0a0a1a/#121228 sont des neutres hors palette brand
   (pas de token dédié overlay) ; si besoin AAA, overrides dans tokens-aaa.css. */
.kiosk-tpe-overlay {
  position: fixed; inset: 0; z-index: 100;
  background: linear-gradient(160deg, #0a0a1a 0%, #121228 100%);
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 1.5rem; text-align: center; padding: 2rem;
  color: var(--kiosk-text-on-red, #fff);
}
.kiosk-tpe-card-anim {
  position: relative;
  width: 160px; height: 160px;
  display: flex; align-items: center; justify-content: center;
}
.kiosk-tpe-ring {
  position: absolute; inset: 0;
  border: 3px solid var(--kiosk-primary);
  border-radius: 50%;
  opacity: 0.4;
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
  font-size: 1.8rem; font-weight: 800; color: var(--kiosk-text-on-red, #fff); margin: 0;
}
.kiosk-tpe-sub {
  font-size: 1rem; color: rgba(255,255,255,0.5); margin: 0; max-width: 340px;
}
.kiosk-tpe-spinner {
  width: 64px; height: 64px;
  border: 5px solid rgba(255,255,255,0.1);
  border-top-color: var(--kiosk-primary);
  border-radius: 50%;
  animation: tpe-spin 0.8s linear infinite;
}
@keyframes tpe-spin { to { transform: rotate(360deg); } }

.kiosk-tpe-cancel {
  margin-top: 8px;
  padding: 14px 40px;
  background: rgba(255,255,255,0.08);
  border: 1.5px solid rgba(255,255,255,0.2);
  border-radius: 14px;
  color: rgba(255,255,255,0.7);
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
}
.kiosk-tpe-cancel:hover { background: rgba(255,255,255,0.14); color: var(--kiosk-text-on-red, #fff); }

/* Focus visible WCAG 2.4.7 — méthodes paiement navigables au clavier */
.kiosk-pay-method:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, var(--kiosk-primary));
  outline-offset: 3px;
}

</style>
