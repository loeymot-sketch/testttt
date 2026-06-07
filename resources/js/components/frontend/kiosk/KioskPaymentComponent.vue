<template>
  <div class="kiosk-payment" data-testid="kiosk-payment-root">
    <!-- [KIOSK-OFFLINE-PLANB-01 FIX] Dedicated offline-queued state. Takes
         precedence over the Plan-B counter-route screen (note `&& !offlineQueued`
         below) so a customer who paid cash while the kiosk was offline sees a
         clear "commande enregistrée hors-ligne — patientez / présentez-vous au
         comptoir" message instead of a cash-collect screen showing a blank "#—"
         (the order id only arrives later on sync). -->
    <div
      v-if="offlineQueued"
      class="kiosk-pay-offline-queued"
      role="status"
      aria-live="polite"
      data-testid="kiosk-payment-offline-queued"
    >
      <div class="kiosk-pay-offline-queued-icon" aria-hidden="true">
        <svg width="120" height="120" viewBox="0 0 120 120" fill="none">
          <circle cx="60" cy="60" r="58" stroke="#F4501E" stroke-width="3"/>
          <path d="M40 62l14 14 26-32" stroke="#F4501E" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <h1 class="kiosk-pay-offline-queued-title" data-testid="kiosk-payment-offline-queued-title">
        {{ $t('kiosk.pay_screen.offline_queued_title') }}
      </h1>
      <p class="kiosk-pay-offline-queued-sub" data-testid="kiosk-payment-offline-queued-sub">
        {{ $t('kiosk.pay_screen.offline_queued_sub') }}
      </p>
      <div class="kiosk-pay-offline-queued-total" data-testid="kiosk-payment-offline-queued-total">
        <span>{{ $t('kiosk.pay_screen.total_prefix') }}</span>
        <strong>{{ formatPrice(cartTotal) }}</strong>
      </div>
    </div>

    <!-- [SUPERVISOR WAVE C Z1 2026-05-28] Plan B: route ALL kiosk payments to counter.
         When true, hide method selection + auto-submit cash. Owner mandate Le Cayenne. -->
    <div
      v-if="paymentRouteAllToCounter && !offlineQueued"
      class="kiosk-pay-counter-route"
      role="status"
      aria-live="polite"
      data-testid="kiosk-payment-counter-route"
    >
      <div class="kiosk-pay-counter-icon" aria-hidden="true">
        <svg width="120" height="120" viewBox="0 0 120 120" fill="none">
          <circle cx="60" cy="60" r="58" stroke="#F4501E" stroke-width="3"/>
          <rect x="32" y="44" width="56" height="36" rx="4" fill="#FFE8DD" stroke="#F4501E" stroke-width="2"/>
          <rect x="40" y="52" width="40" height="6" fill="#F4501E" opacity="0.3"/>
          <circle cx="60" cy="70" r="6" fill="#F4501E"/>
        </svg>
      </div>
      <h1 class="kiosk-pay-counter-title" data-testid="kiosk-payment-counter-title">
        {{ $t('kiosk.pay_screen.counter_route_title') }}
      </h1>
      <p class="kiosk-pay-counter-sub" data-testid="kiosk-payment-counter-sub">
        {{ $t('kiosk.pay_screen.counter_route_sub') }}
      </p>
      <div class="kiosk-pay-counter-total" data-testid="kiosk-payment-counter-total">
        <span>{{ $t('kiosk.pay_screen.total_prefix') }}</span>
        <strong>{{ formatPrice(cartTotal) }}</strong>
      </div>
      <div v-if="submitting" class="kiosk-pay-counter-spinner" aria-hidden="true"></div>
      <p v-if="submitting" class="kiosk-pay-counter-processing">
        {{ $t('kiosk.pay_screen.counter_route_processing') }}
      </p>
      <div
        v-if="error"
        class="kiosk-pay-error"
        role="alert"
        data-testid="kiosk-payment-counter-error"
      >{{ error }}</div>
      <button
        v-if="!submitting && !submitted"
        type="button"
        class="kiosk-btn-confirm"
        @click="confirmCounterRoute"
        data-testid="kiosk-payment-counter-confirm"
      >
        <span>{{ $t('kiosk.pay_screen.counter_route_confirm_btn') }}</span>
      </button>
    </div>

    <!-- Header -->
    <div
      v-if="!paymentRouteAllToCounter"
      class="kiosk-pay-header">
      <button type="button"
        class="kiosk-pay-back"
        @click="$router.replace({ name: 'kiosk.cart' })"
        :disabled="submitting"
        :aria-label="$t('kiosk.back')"
        data-testid="kiosk-payment-back">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <div class="kiosk-pay-header-info">
        <h1 class="kiosk-pay-title" data-testid="kiosk-payment-title">{{ $t('kiosk.pay_screen.title') }}</h1>
        <p class="kiosk-pay-total-label" data-testid="kiosk-payment-total">{{ $t('kiosk.pay_screen.total_prefix') }} <strong>{{ formatPrice(cartTotal) }}</strong></p>
      </div>
    </div>
    <div
      v-if="networkOffline && !paymentRouteAllToCounter"
      class="kiosk-pay-offline-alert"
      role="status"
      aria-live="polite"
      data-testid="kiosk-payment-offline-alert"
    >
      {{ $t('kiosk.pay_screen.offline_alert') }}
    </div>

    <div
      v-if="!submitting && !submitted && !tpeWaiting && !paymentRouteAllToCounter"
      class="kiosk-pay-amount-card"
      role="status"
      aria-live="polite"
      data-testid="kiosk-payment-amount-card"
    >
      <span>{{ $t('kiosk.pay_screen.total_prefix') }}</span>
      <strong>{{ formatPrice(cartTotal) }}</strong>
    </div>

    <!-- Modes de paiement — grille borne (cartes, pas bandeaux pleine largeur) -->
    <div
      v-if="!submitting && !submitted && !paymentRouteAllToCounter"
      class="kiosk-pay-methods-outer"
      role="radiogroup"
      :aria-label="$t('kiosk.pay_screen.title')"
    >
      <div class="kiosk-pay-methods">
      <!-- CB -->
      <div
        class="kiosk-pay-method"
        :class="{ selected: method === 'card', disabled: isElectronicMethodBlocked('card') }"
        role="radio"
        :tabindex="isElectronicMethodBlocked('card') ? -1 : 0"
        :aria-checked="method === 'card'"
        :aria-disabled="isElectronicMethodBlocked('card') ? 'true' : 'false'"
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
        :class="{ selected: method === 'tr', disabled: isElectronicMethodBlocked('tr') }"
        role="radio"
        :tabindex="isElectronicMethodBlocked('tr') ? -1 : 0"
        :aria-checked="method === 'tr'"
        :aria-disabled="isElectronicMethodBlocked('tr') ? 'true' : 'false'"
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

    <!-- Écran API en cours (commande en création) — masqué pendant TPE (Lot 2.H) -->
    <div
      v-if="submitting && !tpeWaiting && !paymentRouteAllToCounter"
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
        <p class="kiosk-tpe-help" id="kiosk-tpe-stuck-help">{{ $t('kiosk.pay_screen.tpe_stuck_help') }}</p>
        <div class="kiosk-tpe-spinner" aria-hidden="true"></div>
        <button type="button"
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
    <div v-if="!submitting && !submitted && !tpeWaiting && !paymentRouteAllToCounter" class="kiosk-pay-confirm">
      <div
        v-if="error"
        class="kiosk-pay-error"
        role="alert"
        data-testid="kiosk-payment-error"
      >{{ error }}</div>
      <div class="kiosk-pay-confirm-inner">
      <button type="button"
        class="kiosk-btn-confirm"
        :disabled="!method || isElectronicMethodBlocked(method)"
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
import { KIOSK_HARDWARE } from '../../../config/kioskHardware';
// [PHASE-6.4] Analytics instrumentation (gated par consent, no-op si opt-out).
import kioskAnalytics from '../../../helpers/kioskAnalytics';
import { buildIdempotencyHeaders } from '../../../helpers/idempotencyHeaders';
// Kiosk Phase 9.1.8 — TTS sur erreurs de paiement (EAA 2025).
// Les malvoyants n'avaient aucun retour audio en cas de refus TPE → risque
// que le client ne réalise pas que la transaction a échoué.
import { useKioskSpeech } from '../../../composables/useKioskSpeech';
import { buildKioskOrderPayload } from '../../../store/modules/kioskCart';
import orderStatusEnum from '../../../enums/modules/orderStatusEnum';

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
      _lastQuote:    null,
      // [KIOSK-OFFLINE-PLANB-01 FIX] True once a cash order was queued offline
      // (no server id yet). Drives a dedicated "commande enregistrée hors-ligne"
      // screen instead of the standard cash-collect screen that would render a
      // blank order number "#—" (the id only arrives later on sync).
      offlineQueued: false,
      networkOffline: typeof navigator !== 'undefined' ? !navigator.onLine : false,
      // Kiosk Phase 9.1.11 — compteur d'échecs TPE.
      // Conformément à l'UX concurrence (McDonald's, Quick, Burger King),
      // on laisse l'utilisateur retenter UNE fois après un premier refus.
      // Au deuxième refus, on route vers l'écran d'erreur dédié qui propose
      // les 3 CTAs : retenter / payer au comptoir / annuler. Évite la boucle
      // "essaye, refusé, toast, essaye encore" frustrante et indéfinie.
      paymentFailureCount: 0,
    };
  },

  // Kiosk Phase 9.1.11 — seuil d'échecs au-delà duquel on redirige vers
  // l'écran d'erreur dédié. Exposé en constante d'instance pour faciliter
  // l'override en test (`wrapper.vm.$options.MAX_PAYMENT_FAILURES = ...`).
  MAX_PAYMENT_FAILURES: 2,
  // [KIOSK-OFFLINE-PLANB-01 FIX] Auto-return delay (ms) for the offline-queued
  // confirmation screen → back to kiosk.idle. Mirrors the bypassed
  // KioskCashInstructionComponent 45s auto-redirect. Component-option so tests
  // can shrink it (`wrapper.vm.$options.OFFLINE_QUEUED_RETURN_MS = ...`).
  OFFLINE_QUEUED_RETURN_MS: 45000,
  computed: {
    // [GAP-22-4] Also read orderType so it's passed to submitOrder
    ...mapGetters('kioskCart', ['total', 'branchId', 'orderType']),
    cartTotal() { return this._lastQuote?.total_ttc ?? this.total; },
    // [SUPERVISOR WAVE C Z1 2026-05-28] Plan B: route all kiosk payments to counter.
    // Read from window.foodkingConfig.kiosk.paymentRouteAllToCounter (config/kiosk.php +
    // master.blade.php injection). When true, KioskPaymentComponent hides method
    // selection UI and auto-submits with payment_method=1 (CASH_ON_DELIVERY).
    paymentRouteAllToCounter() {
      try {
        return !!(window?.foodkingConfig?.kiosk?.paymentRouteAllToCounter);
      } catch (_) {
        return false;
      }
    },
  },
  mounted() {
    // Kiosk Phase 9.1.8 — prépare le composable TTS (no-op si audio off ou
    // absence de Web Speech API sur le navigateur kiosk).
    try {
      this._kioskSpeech = useKioskSpeech({ store: this.$store });
    } catch (_) { this._kioskSpeech = null; }
    this.syncNetworkState();
    window.addEventListener('online', this.syncNetworkState);
    window.addEventListener('offline', this.syncNetworkState);
    // [AUDIT-F-008] Boot-time reconcile : récupère les transactions TPE
    // approuvées par hardware mais dont le confirm backend a échoué (network
    // blip / app crash post-TPE). Replay best-effort : aucune erreur ne doit
    // bloquer le rendu de l'écran paiement. Périodique toutes les 60s tant
    // que le composant est monté.
    try {
      this._reconcilePendingPayments();
    } catch (_) {}
    try {
      this._reconcileInterval = setInterval(() => {
        this._reconcilePendingPayments();
      }, 60000);
    } catch (_) { this._reconcileInterval = null; }
  },
  beforeUnmount() {
    this._lastOrder = null;
    window.removeEventListener('online', this.syncNetworkState);
    window.removeEventListener('offline', this.syncNetworkState);
    // Kiosk Phase 9.1.8 — stoppe le TTS si on quitte l'écran pendant la lecture.
    try { this._kioskSpeech?.stop(); } catch (_) {}
    // [AUDIT-F-008] Stoppe la boucle de reconcile périodique.
    if (this._reconcileInterval) {
      try { clearInterval(this._reconcileInterval); } catch (_) {}
      this._reconcileInterval = null;
    }
    // [KIOSK-OFFLINE-PLANB-01 FIX] Cancel the offline-queued auto-return timer
    // so it never fires a stray navigation after the screen is gone.
    if (this._offlineQueuedTimer) {
      try { clearTimeout(this._offlineQueuedTimer); } catch (_) {}
      this._offlineQueuedTimer = null;
    }
  },
  methods: {
    ...mapActions('kioskCart', ['submitOrder', 'reset']),

    syncNetworkState() {
      this.networkOffline = typeof navigator !== 'undefined' ? !navigator.onLine : false;
      if (this.networkOffline && this.isElectronicMethod(this.method)) {
        this.method = null;
      }
    },

    isElectronicMethod(method) {
      return method === 'card' || method === 'tr';
    },

    isElectronicMethodBlocked(method) {
      return this.networkOffline && this.isElectronicMethod(method);
    },

    offlinePaymentMessage() {
      return this.$t('kiosk.pay_screen.offline_short');
    },

    selectMethod(m) {
      if (this.isElectronicMethodBlocked(m)) {
        const msg = this.offlinePaymentMessage();
        this.method = null;
        this.error = msg;
        this.showToast(msg, 'warning', 4000);
        return;
      }

      this.method = m;
      this.error = null;
      // Kiosk Phase 9.1.11 — changer de mode réinitialise le compteur d'échec.
      // Motif : si un client re-sélectionne "Espèces" après un CB refusé,
      // on ne veut pas l'envoyer direct sur /error au premier problème cash.
      this.paymentFailureCount = 0;
      // [PHASE-6.4] Analytics : sélection d'un moyen de paiement (avant confirm).
      try { kioskAnalytics.track('payment_method_selected', { method: m }); } catch (_) {}
    },

    // [SUPERVISOR WAVE C Z1 2026-05-28] Plan B counter-route flow.
    // Force method='cash' (payment_method=CASH_ON_DELIVERY=1 backend mapping) and
    // reuse confirmPayment() pipeline. Order goes PENDING_COUNTER, cashier collects
    // at POS (espèces tiroir OR carte ticket+manual terminal). No TPE at kiosk.
    async confirmCounterRoute() {
      if (this.submitting) return;
      this.method = 'cash';
      this.error = null;
      // Delegate to existing confirmPayment which already routes 'cash' to
      // processCashPayment → kiosk.cash-instruction navigation.
      return this.confirmPayment();
    },

    async confirmPayment() {
      if (!this.method || this.submitting) return;
      if (this.isElectronicMethodBlocked(this.method)) {
        const msg = this.offlinePaymentMessage();
        this.error = msg;
        this.showToast(msg, 'warning', 4000);
        return;
      }

      this.submitting = true;
      this.error = null;

      try {
        const quote = await this.refreshQuote();
        // [PHASE-6.4] Analytics : démarrage du checkout (intent de payer).
        try { kioskAnalytics.track('checkout_started', { method: this.method, total_cents: Math.round(quote.total_ttc * 100) }); } catch (_) {}

        // Step 1 — Submit order to Laravel API
        // [GAP-22-4] Pass orderType (sur place=25 / à emporter=10) chosen by customer in cart
        const res = await this.submitOrder({ paymentMethod: this.method, orderType: this.orderType, quote });
        const orderId  = res?.data?.data?.id || res?.data?.id;
        const queueNum = res?.data?.data?.queue_number || res?.data?.queue_number;
        const isOfflineId = typeof orderId === 'string' && String(orderId).startsWith('offline_');
        if (isOfflineId && this.isElectronicMethod(this.method)) {
          throw new Error(this.offlinePaymentMessage());
        }

        // [AUDIT-52 / T06] SSOT paiement : total numérique serveur (`OrderDetailsResource.total` / POS `order_amount`).
        // Hors-ligne seulement : pas de total serveur → repli sur le panier local pour l’UX TPE.
        const rawTotal = res?.data?.data?.total ?? res?.data?.data?.order_amount;
        let total;
        if (isOfflineId) {
          total = this.cartTotal;
        } else {
          const n = rawTotal != null && rawTotal !== '' ? Number(rawTotal) : NaN;
          if (!Number.isFinite(n)) {
            throw new Error(this.$t('kiosk.pay_screen.invalid_order_response'));
          }
          total = Number.isFinite(Number(quote.total_ttc)) ? Number(quote.total_ttc) : n;
        }

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
        if (!orderId && !isOfflineId) {
          throw new Error(this.$t('kiosk.pay_screen.invalid_order_response'));
        }

        this._lastOrder = { id: orderId, queue_number: queueNum, total };

        // [Lot 2.H / F-13] Keep submitting=true through TPE/cash so the confirm
        // control cannot re-fire; clear only after payment path completes or in catch.
        const navTarget = this.method === 'cash' ? {
          name:  'kiosk.cash-instruction',
          query: { number: queueNum, total, timeout: 45 },
        } : {
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
          msg = this.$t('kiosk.payment.tpe_timeout_message');
        } else {
          msg = err?.response?.data?.errors
            ? Object.values(err.response.data.errors).flat().join(' ')
            : (err?.message || this.$t('kiosk.pay_screen.payment_error_generic'));
        }
        this.error = msg;
        this.showToast(msg, 'error', 6000);
        this.submitting = false;
        this.submitted = false;
        // Kiosk Phase 9.1.8 — annonce vocale de l'erreur (no-op si audio off).
        // On énonce un message court + clef i18n pour le fallback AR mp3 statique.
        try {
          this._kioskSpeech?.speak(
            this.$t('kiosk.pay_screen.speech_error', { msg }),
            { key: 'kiosk.pay_screen.speech_error' },
          ).catch(() => {});
        } catch (_) {}

        // Kiosk Phase 9.1.11 — au-delà de MAX_PAYMENT_FAILURES refus TPE
        // consécutifs, on route vers l'écran d'erreur dédié qui offre des
        // CTA clairs (retry / cash / cancel). On passe en query :
        //  - `code`     : code d'erreur TPE (pour le diag staff).
        //  - `order_id` : référence de la commande pending pour void.
        // Le compteur est remis à 0 quand l'utilisateur change de method ou
        // re-sélectionne : resetPaymentFailureCount() ci-dessous.
        this.paymentFailureCount += 1;
        if (this.paymentFailureCount >= this.$options.MAX_PAYMENT_FAILURES) {
          const code = err?.code || err?.response?.data?.code || 'declined';
          const orderId = this._lastOrder?.id ? String(this._lastOrder.id) : null;
          // Reset avant navigation pour ne pas empiler les seuils si l'utilisateur
          // revient (back) sur /payment après l'écran d'erreur.
          this.paymentFailureCount = 0;
          try {
            this.$router.push({
              name: 'kiosk.error.payment-refused',
              query: {
                code,
                ...(orderId ? { order_id: orderId } : {}),
              },
            });
          } catch (_) { /* navigation garde hors dispo (tests) → no-op */ }
        }
      }
    },

    async refreshQuote() {
      // [test-e2e/pos-kds-sync round-3 E-002 P0] silent-error visibility:
      // mirrors the kioskCart.quoteOrder gate — never POST to /frontend/order/quote
      // without a kiosk:order Sanctum bearer. Audit Wave E state 04 captured a 401
      // here during a hydration race; gating early prevents the silent failure.
      if (!this.$store.state.kioskCart?.kioskToken) {
        if (typeof console !== 'undefined' && typeof console.debug === 'function') {
          console.debug('[KioskPayment] refreshQuote skipped — kioskToken absent');
        }
        const error = new Error('KIOSK_QUOTE_NO_TOKEN');
        error.code = 'KIOSK_QUOTE_NO_TOKEN';
        throw error;
      }
      const payload = buildKioskOrderPayload(this.$store.state.kioskCart, {
        orderType: this.orderType,
        paymentMethod: this.method,
      });
      const res = await axios.post('frontend/order/quote', payload);
      const quote = res?.data?.data;
      if (!quote || quote.total_ttc === undefined || !quote.quote_token || !quote.signature) {
        throw new Error(this.$t('kiosk.pay_screen.invalid_order_response'));
      }
      this._lastQuote = quote;
      return quote;
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
      // [AUDIT-52-BUG7] Wrap dans un timeout global (TPE peut figer sur chip+PIN). SSOT: config/kioskHardware.js
      const { TPE_TIMEOUT_MS } = KIOSK_HARDWARE;
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
          // [AUDIT-F-004] Reason whitelist for kiosk-originated cancels (OrderCancelReason).
          // Mapping: TPE bridge declined / timed out → distinct codes for analytics; fallback
          // 'tpe_declined' covers generic refusal. Backend OrderStatusRequest 422s on missing
          // or non-whitelisted code when actor is kiosk machine token.
          const tpeReasonCode = (paymentResult?.error_code === 'timeout' ? 'tpe_timeout' : 'tpe_declined');
          const tpeVoidPayload = {
            status: orderStatusEnum.CANCELED,
            reason: tpeReasonCode,
          };
          axios.post(`frontend/order/change-status/${this._lastOrder.id}`, tpeVoidPayload, {
            headers: buildIdempotencyHeaders(tpeVoidPayload),
          })
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
        // [AUDIT-F-002] Echo amount_cents to backend so the controller can verify
        // that the TPE-approved amount matches order.total (±1 cent tolerance).
        // Without this, a compromised TPE could approve an arbitrary amount and
        // the backend would mark PAID without detecting the discrepancy.
        // The amount source is `paymentResult.amount_cents_approved` if the bridge
        // returned it (real TPE driver), else fallback on the locally computed
        // cart total (stub mode + legacy bridges that don't echo amount).
        const expectedCents = Math.round((this._lastOrder.total || this.cartTotal) * 100);
        const echoedCents = Number.isInteger(paymentResult.amount_cents_approved)
          ? paymentResult.amount_cents_approved
          : expectedCents;
        await this.confirmBackendPayment(this._lastOrder.id, {
          transaction_id: paymentResult.transaction_id,
          card_type:      paymentResult.card_type || 'CARD',
          payment_method: this.method === 'tr' ? 5 : 4,
          amount_cents:   echoedCents,
        });
      }

      await new Promise(r => setTimeout(r, 800));
      this.tpeWaiting = false;
      this.submitting = false;
      this.$router.push(navTarget);
    },

    /**
     * [PHASE-6.1] Invoque le TPE via kioskHardware.tpeCharge et normalise le
     * résultat au shape historique `{approved, transaction_id, card_type, error}`
     * attendu par processCardPayment. En dev (stub), retourne un stub synthétique.
     *
     * Contrat `tpeCharge(amountCents, method)` du service :
     *   → { ok: true, tx_ref, amount_cents_approved?, legacy?, data? } | { ok: false, error }
     *
     * [AUDIT-F-002] amount_cents_approved est l'écho strict du montant approuvé.
     * Le backend OrderController::paymentConfirm vérifie abs(amount_cents - order.total*100) ≤ 1.
     * Stub mode : echo strict de amountCents (mirroir). Bridges Electron prod : driver TPE
     * doit retourner amount_cents_approved depuis la trame ISO bancaire.
     *
     * Rétro-compat : si le bridge renvoie un shape legacy { status: 'approved', ... }
     * (vieux firmware Electron), runSafe encapsule déjà dans `data`.
     */
    async _invokeTpe(amountEuros, method = 'CB') {
      const amountCents = Math.round(Number(amountEuros) * 100);

      // [AUDIT-F-014] QA toggle (dev/staging only): force declined/timeout paths.
      // Production guard non-bypassable: `process.env.NODE_ENV` est remplacé au build par
      // webpack DefinePlugin (laravel-mix), donc la branche entière disparaît du bundle prod
      // (dead-code elimination). Aucun query param ne peut activer ce toggle en production.
      // Placé AVANT la branche stub afin que QA puisse aussi forcer un decline depuis une
      // borne staging avec bridge=true (utile pour tester la gestion d'erreur côté UI sans
      // pouvoir reproduire le decline avec un vrai TPE).
      if (process.env.NODE_ENV !== 'production' && typeof window !== 'undefined') {
        const force = new URLSearchParams(window.location.search).get('tpe_force');
        if (force === 'declined') {
          // [AUDIT-F-014] Mirror du contract bridge : approved:false avec error_code stable
          // pour que processCardPayment throw → KioskErrorPaymentRefusedComponent visible.
          // amount_cents_approved préservé (cross-contract F-002) même si jamais consommé
          // côté backend (pas de payment-confirm si !approved).
          return {
            approved: false,
            error: 'forced_decline_qa',
            error_code: 'QA_FORCE_DECLINED',
            transaction_id: null,
            amount_cents_approved: amountCents,
          };
        }
        if (force === 'timeout') {
          // [AUDIT-F-014] Throw TPE_TIMEOUT directement après court délai pour mimer
          // exactement le reject path du Promise.race global (TPE_TIMEOUT_MS=120s en prod
          // serait inutilisable en QA). Le catch upstream traite ce throw identiquement.
          await new Promise((r) => setTimeout(r, 500));
          throw new Error('TPE_TIMEOUT');
        }
      }

      // Pas de bridge réel → stub navigateur classique avec délai visuel.
      if (!kioskHardware.isKioskBridge()) {
        this.tpeMessage = this.$t('kiosk.pay_screen.tpe_browser_sim');
        await new Promise((r) => setTimeout(r, 2000));
        // [AUDIT-F-002] Stub echoes amountCents to honor backend echo verification contract.
        return {
          approved: true,
          transaction_id: `STUB-${Date.now()}`,
          card_type: 'VISA',
          amount_cents_approved: amountCents,
        };
      }
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
      // [AUDIT-F-002] amount_cents_approved : extracted from bridge response (real TPE
      // drivers must echo it from ISO bancaire trame). Fallback sur amountCents si absent
      // (rétro-compat firmware Electron legacy — but the backend will reject if mismatch).
      const echoedAmount = Number.isInteger(raw.amount_cents_approved)
        ? raw.amount_cents_approved
        : (Number.isInteger(result.amount_cents_approved) ? result.amount_cents_approved : amountCents);
      return {
        approved,
        transaction_id: raw.transaction_id || raw.tx_ref || result.tx_ref || null,
        card_type: raw.card_type || raw.cardType || 'CARD',
        error: !approved ? (raw.error || result.error || 'declined') : null,
        error_code: raw.error_code || result.error_code || null,
        amount_cents_approved: echoedAmount,
      };
    },

    async processCashPayment(navTarget) {
      // [B5b] Paiement espèces borne : aucune ouverture tiroir côté borne.
      // L'ordre part en cuisine mais reste PENDING_COUNTER jusqu'à encaissement POS.
      // On émet payment_completed ici même sans validation TPE (cf. KIOSK_ANALYTICS_EVENTS.md).
      try {
        kioskAnalytics.track('payment_completed', {
          method: 'cash',
          total_cents: Math.round((this._lastOrder?.total || this.cartTotal) * 100),
        });
      } catch (_) {}
      this.submitting = false;
      // [KIOSK-OFFLINE-PLANB-01 FIX] When the cash order was queued offline the
      // server hasn't assigned an id/queue number yet, so the standard
      // cash-instruction screen would show "#—". Instead, surface a dedicated
      // "commande enregistrée hors-ligne" state right here (it will be
      // transmitted to the counter on reconnection). This covers Plan-B
      // (forced cash) and any offline cash path — the real bug surface.
      if (String(this._lastOrder?.id || '').startsWith('offline_')) {
        this.offlineQueued = true;
        this.submitted = true;
        // RECOVERY: the global kiosk idle timer is disabled on `kiosk.payment`
        // (KioskAppComponent.startIdleTimer noTimerRoutes) and this screen has no
        // CTA — without an auto-return it would strand the next customer. Mirror
        // the 45s auto-redirect-to-idle of the cash-instruction screen we bypass.
        this._scheduleOfflineQueuedReturn();
        return;
      }
      this.$router.push(navTarget);
    },
    // [KIOSK-OFFLINE-PLANB-01 FIX] Auto-return to the kiosk idle screen after the
    // offline-queued confirmation so the self-service terminal recovers for the
    // next customer (cleared on unmount to avoid a stray navigation). The delay
    // mirrors KioskCashInstructionComponent.autoRedirectSeconds (45s) and is
    // exposed as a component option (this.$options.OFFLINE_QUEUED_RETURN_MS) for
    // test override, same pattern as MAX_PAYMENT_FAILURES.
    _scheduleOfflineQueuedReturn() {
      try { clearTimeout(this._offlineQueuedTimer); } catch (_) {}
      this._offlineQueuedTimer = setTimeout(() => {
        this.$router.push({ name: 'kiosk.idle' }).catch(() => {});
      }, this.$options.OFFLINE_QUEUED_RETURN_MS);
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
        // [AUDIT-F-004] Customer pressed Cancel on the TPE prompt → 'tpe_cancel_user'.
        const userCancelPayload = {
          status: orderStatusEnum.CANCELED,
          reason: 'tpe_cancel_user',
        };
        axios.post(`frontend/order/change-status/${this._lastOrder.id}`, userCancelPayload, {
          headers: buildIdempotencyHeaders(userCancelPayload),
        })
          .catch(e => console.warn('[KioskPayment] void on cancel failed:', e.message));
        this._lastOrder = null;
      }
    },

    async confirmBackendPayment(orderId, payload) {
      let lastError = null;
      // [round-5 fix E-NEW-001 2026-05-11] IdempotencyKeyMiddleware requires
      // X-Idempotency-Key on all POST mutations. Without it the middleware
      // throws 422 BEFORE the controller runs → kiosk customer never reaches
      // the confirmation page. The key MUST be stable across the 3-retry loop
      // so the backend's replay cache deduplicates correctly. Scope
      // (branch_id, user_id, hash(key)) is server-side; key just needs to be
      // unique per logical (order, transaction).
      const idempotencyKey = `kiosk-payment-confirm-${orderId}-${payload?.transaction_id ?? 'no-tx'}`;
      const requestConfig = { headers: { 'X-Idempotency-Key': idempotencyKey } };
      for (let attempt = 1; attempt <= 3; attempt++) {
        try {
          await axios.post(`frontend/order/${orderId}/payment-confirm`, payload, requestConfig);
          // [round-4 fix E-004 reopened 2026-05-10] If the first or second
          // attempt failed (transient 422 race with order state machine, or
          // 401 mid-rotation), Playwright captures the 4xx in network.json
          // but the UI stays silent because retry-success keeps the happy
          // path going. Without a DOM signal the reviewer protocol cat-6
          // selector flags it as silent_error. Mirror the kiosk auth-retry
          // bridge by surfacing a brief warning toast on recovered retry.
          if (attempt > 1) {
            try {
              this.showToast(this.$t('kiosk.pay_screen.payment_sync_retried'), 'warning', 2500);
            } catch (_) { /* showToast must never break the happy path */ }
          }
          return;
        } catch (error) {
          lastError = error;
          if (attempt < 3) {
            await new Promise((resolve) => setTimeout(resolve, attempt * 700));
          }
        }
      }
      console.warn('[KioskPayment] payment-confirm failed after retries:', lastError?.message);
      // [AUDIT-F-008] Persist TPE-approved transaction for boot-time reconcile.
      // Sans ça, un network blip ou crash backend post-TPE laisse l'order
      // PENDING orphelin alors que le client a payé.
      try {
        this._appendPendingReconcile({
          order_id:       orderId,
          transaction_id: payload.transaction_id,
          amount_cents:   payload.amount_cents,
          card_type:      payload.card_type,
          payment_method: payload.payment_method,
        });
      } catch (_) {}
      // Observability : log via kiosk-event (whitelisted type 'sync_failed' avec
      // subtype dédié — évite d'avoir à étendre KioskEventController::ALLOWED_TYPES).
      try {
        window.axios?.post('frontend/kiosk-event', {
          type: 'sync_failed',
          subtype: 'payment_confirm_retry_exhausted',
          order_ref: String(orderId),
          details: `tx=${payload.transaction_id}`,
        }).catch(() => {});
      } catch (_) {}
      throw new Error(this.$t('kiosk.pay_screen.payment_sync_failed'));
    },

    // [AUDIT-F-008] localStorage helpers for reconcile queue.
    // Contrat : aucun PAN, aucune info bancaire — uniquement transaction_id +
    // amount_cents + label card_type + payment_method (gateway integer).
    _readPendingReconcile() {
      try {
        const raw = window?.localStorage?.getItem('pending_payment_confirms');
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
      } catch (_) {
        return [];
      }
    },
    _writePendingReconcile(list) {
      try {
        // Borne dure 50 entries (anti-explosion localStorage).
        const trimmed = Array.isArray(list) ? list.slice(0, 50) : [];
        window?.localStorage?.setItem('pending_payment_confirms', JSON.stringify(trimmed));
      } catch (_) {}
    },
    _appendPendingReconcile(entry) {
      const list = this._readPendingReconcile();
      list.push({ ...entry, attempted_at: new Date().toISOString() });
      this._writePendingReconcile(list);
    },
    _isPendingReconcileExpired(entry) {
      // Borne 30 min — au-delà alert ops, pas de retry indéfini.
      try {
        return Date.now() - new Date(entry.attempted_at).getTime() > 30 * 60 * 1000;
      } catch (_) {
        return true;
      }
    },
    async _reconcilePendingPayments() {
      const list = this._readPendingReconcile();
      if (list.length === 0) return;

      const fresh = list.filter((e) => !this._isPendingReconcileExpired(e));
      const expired = list.filter((e) => this._isPendingReconcileExpired(e));

      if (expired.length > 0) {
        // Alert ops — au-delà 30 min, transaction probablement perdue.
        try {
          window.axios?.post('frontend/kiosk-event', {
            type: 'sync_failed',
            subtype: 'payment_reconcile_expired',
            details: 'expired_count=' + expired.length,
          }).catch(() => {});
        } catch (_) {}
      }

      if (fresh.length === 0) {
        this._writePendingReconcile([]);
        return;
      }

      try {
        const entries = fresh.map((e) => ({
          order_id:       e.order_id,
          transaction_id: e.transaction_id,
          amount_cents:   e.amount_cents,
          card_type:      e.card_type,
          payment_method: e.payment_method,
        }));
        const response = await axios.post('frontend/payment/reconcile-pending', { entries });
        const results = response?.data?.data || [];
        const reconciledTxs = results
          .filter((r) => r.status === 'reconciled' || r.status === 'already_paid')
          .map((r) => r.transaction_id);

        // Garde uniquement les fresh non reconciled — drop les expired définitivement.
        const remaining = fresh.filter((e) => !reconciledTxs.includes(e.transaction_id));
        this._writePendingReconcile(remaining);
      } catch (_) {
        // Réseau / backend KO → on garde le localStorage pour le prochain tick.
        // Drop les expired malgré tout (alert déjà émis ci-dessus).
        if (expired.length > 0) {
          this._writePendingReconcile(fresh);
        }
      }
    },

    // formatPrice() provided by kioskPriceMixin
  },
};
</script>

<style scoped>
.kiosk-payment {
  width: 100vw;
  height: 100vh;
  background: var(--kiosk-page-bg, var(--kiosk-bg));
  display: flex;
  flex-direction: column;
  overflow: hidden;
  color: var(--kiosk-text);
}

/* [SUPERVISOR WAVE C Z1 2026-05-28] Plan B counter-route screen */
.kiosk-pay-counter-route {
  width: 100vw;
  height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 24px;
  padding: 40px;
  background: var(--kiosk-page-bg, #FFFFFF);
  color: var(--kiosk-text, #0F0F0F);
  text-align: center;
}
.kiosk-pay-counter-icon {
  margin-bottom: 8px;
}
.kiosk-pay-counter-title {
  font-size: clamp(36px, 5vw, 56px);
  font-weight: 900;
  margin: 0;
  color: var(--kiosk-text, #0F0F0F);
  text-transform: uppercase;
  letter-spacing: 0.02em;
}
.kiosk-pay-counter-sub {
  font-size: clamp(22px, 2.8vw, 32px);
  font-weight: 700;
  color: var(--kiosk-primary, #F4501E);
  margin: 0;
  max-width: 720px;
}
.kiosk-pay-counter-total {
  margin: 16px auto 0;
  width: min(640px, calc(100vw - 64px));
  min-height: 140px;
  border-radius: 28px;
  background: linear-gradient(135deg, var(--kiosk-primary, #F4501E), var(--kiosk-primary-dark, #D7263D));
  color: #FFFFFF;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  box-shadow: var(--kiosk-shadow-cta, 0 12px 28px rgba(0,0,0,0.18));
}
.kiosk-pay-counter-total span {
  font-size: 16px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  opacity: 0.9;
}
.kiosk-pay-counter-total strong {
  font-size: clamp(48px, 8vw, 80px);
  font-weight: 900;
  line-height: 0.92;
}
/* [KIOSK-OFFLINE-PLANB-01 FIX] Offline-queued state — mirrors the counter-route
   layout so the offline cash screen reads as a deliberate confirmation, not a
   broken cash-collect screen with a blank "#—". */
.kiosk-pay-offline-queued {
  width: 100vw;
  height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 24px;
  padding: 40px;
  background: var(--kiosk-page-bg, #FFFFFF);
  color: var(--kiosk-text, #0F0F0F);
  text-align: center;
}
.kiosk-pay-offline-queued-icon {
  margin-bottom: 8px;
}
.kiosk-pay-offline-queued-title {
  font-size: clamp(36px, 5vw, 56px);
  font-weight: 900;
  margin: 0;
  color: var(--kiosk-text, #0F0F0F);
  text-transform: uppercase;
  letter-spacing: 0.02em;
}
.kiosk-pay-offline-queued-sub {
  font-size: clamp(20px, 2.6vw, 30px);
  font-weight: 600;
  color: var(--kiosk-primary, #F4501E);
  margin: 0;
  max-width: 760px;
  line-height: 1.3;
}
.kiosk-pay-offline-queued-total {
  margin: 16px auto 0;
  width: min(640px, calc(100vw - 64px));
  min-height: 140px;
  border-radius: 28px;
  background: linear-gradient(135deg, var(--kiosk-primary, #F4501E), var(--kiosk-primary-dark, #D7263D));
  color: #FFFFFF;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  box-shadow: var(--kiosk-shadow-cta, 0 12px 28px rgba(0,0,0,0.18));
}
.kiosk-pay-offline-queued-total span {
  font-size: 16px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  opacity: 0.9;
}
.kiosk-pay-offline-queued-total strong {
  font-size: clamp(48px, 8vw, 80px);
  font-weight: 900;
  line-height: 0.92;
}
.kiosk-pay-counter-spinner {
  width: 64px;
  height: 64px;
  border: 5px solid #FFE8DD;
  border-top-color: #F4501E;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-top: 8px;
}
.kiosk-pay-counter-processing {
  font-size: 18px;
  color: var(--kiosk-text-muted, #5A5A5A);
  margin: 0;
}

/* Header — thème clair : texte foncé lisible */
.kiosk-pay-header {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 26px 34px 22px;
  background: var(--kiosk-surface);
  border-bottom: 1px solid var(--kiosk-border);
  box-shadow: var(--kiosk-shadow-sticky);
  flex-shrink: 0;
}

.kiosk-pay-back {
  width: 60px;
  height: 60px;
  border-radius: 18px;
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
  font-size: clamp(30px, 4vw, 44px);
  font-weight: 900;
  color: var(--kiosk-text);
  margin: 0 0 4px;
  text-transform: uppercase;
}

.kiosk-pay-total-label {
  font-size: 16px;
  color: var(--kiosk-text-muted);
  margin: 0;
}

.kiosk-pay-total-label strong { color: var(--kiosk-text); font-size: 18px; }

.kiosk-pay-amount-card {
  margin: 28px auto 0;
  width: min(720px, calc(100vw - 64px));
  min-height: 168px;
  border-radius: 34px;
  background: linear-gradient(135deg, var(--kiosk-primary), var(--kiosk-primary-dark));
  color: var(--kiosk-text-on-red);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  box-shadow: var(--kiosk-shadow-cta);
  text-align: center;
}

.kiosk-pay-amount-card span {
  font-size: 18px;
  font-weight: 900;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  opacity: 0.86;
}

.kiosk-pay-amount-card strong {
  font-size: clamp(56px, 9vw, 96px);
  font-weight: 900;
  line-height: 0.92;
  letter-spacing: 0;
}

/* Grille méthodes — cartes centrées, pas bandeaux edge-to-edge */
.kiosk-pay-methods-outer {
  flex: 1;
  overflow-y: auto;
  padding: 28px 32px 20px;
  scrollbar-width: none;
  display: flex;
  justify-content: center;
  align-items: flex-start;
}

.kiosk-pay-methods-outer::-webkit-scrollbar { display: none; }

.kiosk-pay-methods {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
  gap: 22px;
  width: 100%;
  max-width: 1000px;
  align-content: start;
}

.kiosk-pay-method {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 26px 28px;
  min-height: 138px;
  background: var(--kiosk-surface);
  border-radius: 28px;
  border: 2px solid var(--kiosk-border);
  box-shadow: var(--kiosk-shadow-card);
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
}

.kiosk-pay-method:active { transform: scale(0.99); }

.kiosk-pay-method.disabled {
  cursor: not-allowed;
  opacity: 0.5;
  transform: none;
}

.kiosk-pay-method.selected {
  border-color: var(--kiosk-primary);
  background: var(--kiosk-surface);
  box-shadow: 0 0 0 2px var(--kiosk-primary), var(--kiosk-shadow-lift);
}

.kiosk-pay-method-icon {
  width: 86px;
  height: 86px;
  border-radius: 50%;
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
  font-size: 25px;
  font-weight: 900;
  color: var(--kiosk-text);
  margin: 0 0 4px;
}

.kiosk-pay-method-info p {
  font-size: 15px;
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
  border-block-start-color: transparent;
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

.kiosk-pay-offline-alert {
  margin: 0 20px 12px;
  border: 1px solid rgba(215, 38, 61, 0.28);
  border-radius: 12px;
  background: rgba(215, 38, 61, 0.08);
  color: #8f1022;
  font-size: 14px;
  font-weight: 700;
  line-height: 1.35;
  padding: 12px 14px;
  text-align: center;
}

/* Confirmer — largeur max centrée (borne) */
.kiosk-pay-confirm {
  padding: 20px 32px 34px;
  flex-shrink: 0;
}

.kiosk-pay-confirm-inner {
  display: flex;
  justify-content: center;
  width: 100%;
}

.kiosk-btn-confirm {
  width: 100%;
  max-width: 680px;
  min-height: 92px;
  height: auto;
  padding: 20px 32px;
  /* [iter15-mega-fix C-012 round-7 2026-05-10] Pin the Confirmer CTA to the
     Cayenne brand-red token directly with a solid fallback (#F4501E) instead
     of relying on the resolved `--kiosk-primary` chain. With the default
     theme now being light (C-011), `--kiosk-primary` = #F4501E ; we still
     keep the var() so dark-mode operators get the correct brand red. */
  background: var(--kiosk-primary, #F4501E);
  color: var(--kiosk-text-on-red, #FFFFFF);
  border: none;
  border-radius: 30px;
  font-size: 26px;
  font-weight: 900;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  box-shadow: var(--kiosk-shadow-cta);
  transition: all 0.15s ease;
}

.kiosk-btn-confirm:disabled {
  /* [iter15-mega-fix C-012 round-7 2026-05-10] Raise opacity from 0.4 to
     0.65 so disabled state stays readable (WCAG AA against white kiosk bg).
     Add an explicit dark-grey overlay so the disabled button no longer reads
     as "pale pink" against the soft Cayenne bg. */
  opacity: 0.65;
  background: #C9C9C9;
  color: #4A4A4A;
  cursor: not-allowed;
  box-shadow: none;
}

.kiosk-btn-confirm:not(:disabled):active {
  transform: scale(0.98);
  box-shadow: var(--kiosk-shadow-card);
}

/* TPE terminal waiting overlay — V3.4 owner gate (2026-05-10) :
   ramené en light mode pour cohérence avec rest of kiosk flow. Le focus
   haptique sur CB reste assuré par l'animation pulse + spinner Cayenne. */
.kiosk-tpe-overlay {
  position: fixed; inset: 0; z-index: 100;
  background: #FFFFFF;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 1.5rem; text-align: center; padding: 2rem;
  color: #0F0F0F;
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
  background: #FFE8DD;
  border: 2px solid #F4501E;
  border-radius: 50%;
  width: 100px; height: 100px;
  display: flex; align-items: center; justify-content: center;
  color: #F4501E;
}
.kiosk-tpe-title {
  font-size: 1.8rem; font-weight: 800; color: #0F0F0F; margin: 0;
}
.kiosk-tpe-help {
  margin: 0.5rem 0 0;
  font-size: 0.95rem;
  line-height: 1.35;
  color: #5A5A5A;
  max-width: 22rem;
  text-align: center;
}
.kiosk-tpe-sub {
  font-size: 1rem; color: #5A5A5A; margin: 0; max-width: 340px;
}
.kiosk-tpe-spinner {
  width: 64px; height: 64px;
  border: 5px solid #FFE8DD;
  border-top-color: #F4501E;
  border-radius: 50%;
  animation: tpe-spin 0.8s linear infinite;
}
@keyframes tpe-spin { to { transform: rotate(360deg); } }

.kiosk-tpe-cancel {
  margin-top: 8px;
  padding: 14px 40px;
  background: #FFFFFF;
  border: 1.5px solid #E5E5E5;
  border-radius: 14px;
  color: #5A5A5A;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
}
.kiosk-tpe-cancel:hover { background: #FFE8DD; color: #F4501E; border-color: #F4501E; }

/* Focus visible WCAG 2.4.7 — méthodes paiement navigables au clavier */
.kiosk-pay-method:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, var(--kiosk-primary));
  outline-offset: 3px;
}

</style>
