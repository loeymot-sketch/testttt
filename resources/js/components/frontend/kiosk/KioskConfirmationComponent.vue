<template>
  <div class="kiosk-confirmation" role="status" aria-live="polite" data-testid="kiosk-confirmation-root">
    <!-- Animated success checkmark (décoratif) -->
    <div class="kiosk-confirmation-anim" aria-hidden="true">
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

    <h1 class="kiosk-confirmation-title" data-testid="kiosk-confirmation-title">
      {{ $t('kiosk.confirmation.title') }}
    </h1>

    <div class="kiosk-confirmation-card" data-testid="kiosk-confirmation-card">
      <div class="kiosk-confirmation-row">
        <span class="kiosk-confirmation-label">{{ $t('kiosk.confirmation.order_number') }}</span>
        <span class="kiosk-confirmation-number" data-testid="kiosk-confirmation-number">#{{ displayNumber }}</span>
      </div>
      <!-- [AUDIT-P2-B] Check null/undefined explicitly so total=0 is shown correctly -->
      <div v-if="displayTotal !== null && displayTotal !== undefined" class="kiosk-confirmation-row">
        <span class="kiosk-confirmation-label">{{ $t('kiosk.confirmation.total_paid') }}</span>
        <span class="kiosk-confirmation-price" data-testid="kiosk-confirmation-total">{{ formatPrice(displayTotal) }}</span>
      </div>
      <!-- [QW-5] Mode de paiement (label SSOT déjà traduit côté snapshot) -->
      <div v-if="paymentMethodLabel" class="kiosk-confirmation-row" data-testid="kiosk-confirmation-payment-row">
        <span class="kiosk-confirmation-label">{{ $t('kiosk.confirmation.payment_method') }}</span>
        <span class="kiosk-confirmation-meta">
          {{ paymentMethodLabel }}
          <span v-if="paymentMethodIcon" aria-hidden="true">{{ paymentMethodIcon }}</span>
        </span>
      </div>
      <!-- [QW-5] ETA cuisine (fenêtre 5..baseMin+3) -->
      <div class="kiosk-confirmation-row" data-testid="kiosk-confirmation-eta-row">
        <span class="kiosk-confirmation-label">{{ $t('kiosk.confirmation.estimated_ready') }}</span>
        <span class="kiosk-confirmation-meta">{{ estimatedReadyTime }}</span>
      </div>
    </div>

    <div v-if="printFailed" class="kiosk-printer-fallback">
      <p class="kiosk-printer-fallback-label">{{ $t('kiosk.confirmation.print_failed') || 'Impression indisponible — notez votre numéro :' }}</p>
      <div class="kiosk-printer-fallback-number">#{{ displayNumber }}</div>
      <p class="kiosk-printer-fallback-hint">{{ $t('kiosk.confirmation.print_failed_hint') || 'Présentez ce numéro au comptoir.' }}</p>
    </div>

    <div class="kiosk-confirmation-message">
      <p>{{ $t('kiosk.confirmation.message_kitchen') }}</p>
      <p>{{ $t('kiosk.confirmation.message_counter') }}</p>
    </div>

    <!-- [GAP-35-7] Points fidélité gagnés — style Splash -->
    <!-- [QW-3] aria-hidden sur l'étoile décorative -->
    <!-- [QW-6] Affichage du solde total cumulé (gagnés + balance) -->
    <transition name="fade-up">
      <div v-if="pointsEarned > 0 && loyaltyCustomerName" class="kiosk-confirmation-points">
        <div class="kiosk-points-icon" aria-hidden="true">⭐</div>
        <div class="kiosk-points-text">
          <span class="kiosk-points-name">{{ loyaltyCustomerName }},</span>
          <span class="kiosk-points-value">{{ $t('kiosk.confirmation.loyalty_points', { n: pointsEarned }) }}</span>
          <span
            v-if="totalLoyaltyPoints > pointsEarned"
            class="kiosk-points-balance"
            data-testid="kiosk-confirmation-points-balance"
          >
            {{ $t('kiosk.confirmation.total_balance', { points: totalLoyaltyPoints, eur: pointsValueEur }) }}
          </span>
        </div>
      </div>
    </transition>

    <!-- Progress timer -->
    <div class="kiosk-confirmation-timer">
      <span class="kiosk-timer-label">{{ $t('kiosk.confirmation.auto_return', { n: countdown }) }}</span>
      <div class="kiosk-timer-bar">
        <div class="kiosk-timer-fill" :style="{ width: progressWidth + '%' }"></div>
      </div>
    </div>

    <!-- [M-3] 5-star CSAT inline (post-commande) — silent fail, optimistic UI -->
    <div
      v-if="ratingOrderId && !ratingSubmitted"
      class="kiosk-confirmation-csat"
      data-testid="kiosk-confirmation-csat"
    >
      <div class="kiosk-csat-prompt">{{ $t('kiosk.confirmation.csat_prompt') }}</div>
      <div
        class="kiosk-csat-stars"
        role="radiogroup"
        :aria-label="$t('kiosk.confirmation.csat_aria')"
      >
        <button
          v-for="n in 5"
          :key="n"
          type="button"
          class="kiosk-csat-star"
          :class="{ active: n <= hoverRating || n <= rating }"
          @click="submitRating(n)"
          @mouseenter="hoverRating = n"
          @mouseleave="hoverRating = 0"
          @focus="hoverRating = n"
          @blur="hoverRating = 0"
          role="radio"
          :aria-checked="n === rating"
          :aria-label="$t('kiosk.confirmation.csat_n_stars', { n })"
          :data-testid="`kiosk-confirmation-csat-star-${n}`"
        >
          <span aria-hidden="true">★</span>
        </button>
      </div>
    </div>
    <div
      v-else-if="ratingSubmitted"
      class="kiosk-confirmation-csat-thanks"
      data-testid="kiosk-confirmation-csat-thanks"
    >
      {{ $t('kiosk.confirmation.csat_thanks') }} <span aria-hidden="true">✓</span>
    </div>

    <button
      class="kiosk-btn-print"
      :class="{ 'is-printing': printStatus === 'printing', 'is-done': printStatus === 'done', 'is-error': printStatus === 'error' }"
      @click="printReceipt"
      :disabled="printStatus === 'printing'"
      :aria-busy="printStatus === 'printing'"
      data-testid="kiosk-confirmation-cta-print"
    >
      <!-- [QW-3] aria-hidden sur emojis purement décoratifs -->
      <span v-if="printStatus === 'printing'">
        <span aria-hidden="true">⏳</span> {{ $t('kiosk.confirmation.printing') }}
      </span>
      <span v-else-if="printStatus === 'done'">
        <span aria-hidden="true">✅</span> {{ $t('kiosk.confirmation.printed') }}
      </span>
      <span v-else-if="printStatus === 'error'">
        <span aria-hidden="true">❌</span> {{ $t('kiosk.confirmation.print_error') }}
      </span>
      <span v-else>
        <span aria-hidden="true">🖨️</span> {{ $t('kiosk.confirmation.print_button') }}
      </span>
    </button>

    <button class="kiosk-btn-home" @click="goHome" data-testid="kiosk-confirmation-cta-home">
      {{ $t('kiosk.confirmation.new_order') }} →
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
      <span>{{ $t('kiosk.confirmation.receipt_number') }}</span>
      <span class="receipt-queue-number">#{{ displayNumber }}</span>
    </div>
    <div class="receipt-divider">- - - - - - - - - - - - - - - - - -</div>
    <!-- [AUDIT-P2-C] Use index in key to prevent duplicate keys when same item_id appears multiple times -->
    <div v-for="(item, index) in receiptItems" :key="item.item_id + '_' + index" class="receipt-line">
      <span>{{ item.quantity }}x {{ sanitizeItemName(item.name) }}</span>
      <span>{{ formatPrice(item.total) }}</span>
    </div>
    <div class="receipt-divider">- - - - - - - - - - - - - - - - - -</div>
    <div v-if="receiptDiscount > 0" class="receipt-line receipt-discount">
      <span>{{ $t('kiosk.confirmation.receipt_discount') }}</span>
      <span>-{{ formatPrice(receiptDiscount) }}</span>
    </div>
    <div class="receipt-line receipt-total">
      <span>{{ $t('kiosk.confirmation.receipt_total') }}</span>
      <span>{{ formatPrice(displayTotal || 0) }}</span>
    </div>
    <template v-if="pointsEarned > 0 && loyaltyCustomerName">
      <div class="receipt-divider">- - - - - - - - - - - - - - - - - -</div>
      <p class="receipt-footer receipt-loyalty">{{ $t('kiosk.confirmation.receipt_loyalty', { n: pointsEarned }) }}</p>
      <p class="receipt-footer">{{ loyaltyCustomerName }}</p>
    </template>
    <div class="receipt-divider">- - - - - - - - - - - - - - - - - -</div>
    <p class="receipt-footer">{{ $t('kiosk.confirmation.receipt_thanks') }}</p>
    <p class="receipt-footer">{{ $t('kiosk.confirmation.receipt_present') }}</p>
  </div>
</template>

<script>
import axios from 'axios';
import { printReceipt as escPosPrint, buildReceiptData, reportPrinterFailure } from '../../../helpers/kioskPrinter';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { sanitizeKioskCustomerFacingText } from '../../../helpers/kioskDisplayText';
// Kiosk Phase 9.1.12 — snapshot localStorage pour survie F5 du ticket.
import {
  saveKioskReceiptSnapshot,
  readKioskReceiptSnapshot,
  clearKioskReceiptSnapshot,
} from '../../../helpers/kioskReceiptPersistence';

// Wrapper tolérant (ne lève jamais dans les tests jsdom sans localStorage).
function clearKioskReceiptSnapshotSafe() {
  try { clearKioskReceiptSnapshot(); } catch (_) {}
}
// Kiosk Phase 9.1.8 — TTS sur l'écran de confirmation.
// Énoncé du numéro de commande + total pour les malvoyants (EAA 2025).
// Le composable no-op si `kioskSettings.audio` est off — aucun effet de bord.
import { useKioskSpeech } from '../../../composables/useKioskSpeech';

export default {
  name: 'KioskConfirmationComponent',
  mixins: [kioskPriceMixin],
  props: {
    // Populated from route.query by kioskRoutes.js
    orderNumber: { type: String, default: '' },
    orderTotal:  { type: Number, default: null },
  },
  data() {
    return {
      countdown:      30,
      progressWidth:  100,
      timer:          null,
      printStatus:    null,  // null | 'printing' | 'done' | 'error'
      printFailed:    false,
      // Snapshot cart data at mount time — captured before cart reset
      _snapshotItems:        null,
      _snapshotDiscount:     null,
      _snapshotSubtotal:     null,
      _snapshotPayment:      null,
      // [GAP-35-7] Snapshot loyalty customer before cart reset
      _snapshotLoyaltyName:  null,
      _snapshotOrderTotal:   null,
      // [QW-5] Raw key (cash|card|tr) needed for icon mapping (the
      // existing _snapshotPayment is the translated label).
      _snapshotPaymentKey:   null,
      // [QW-6] Pre-existing loyalty balance captured before cart reset.
      _snapshotLoyaltyBalance: 0,
      // [M-3] CSAT inline state
      rating:           0,
      hoverRating:      0,
      ratingSubmitted:  false,
      // [M-3] DB id (numeric PK) snapshotted from kioskCart.orderRef.
      // Distinct from `orderNumber` (which is the queue display string).
      ratingOrderId:    null,
    };
  },
  computed: {
    // [GAP-35-7] Points earned = floor(total * rate) — same formula as AwardLoyaltyPointsOnDelivery
    pointsEarned() {
      const total = this._snapshotOrderTotal || 0;
      const lists = this.$store.state.globalState?.lists;
      const rate = parseInt(lists?.loyalty_points_per_euro, 10) || 0;
      if (total <= 0 || rate <= 0) return 0;
      return Math.floor(total * rate);
    },
    loyaltyCustomerName() {
      return this._snapshotLoyaltyName || null;
    },
    displayNumber() {
      if (this.orderNumber) return this.orderNumber;
      return this.$store.state.kioskCart?.queueNumber || '—';
    },
    displayTotal() {
      if (this.orderTotal != null) return this.orderTotal;
      return this.$store.getters['kioskCart/total'] || null;
    },
    receiptItems() {
      // Use snapshot if available (after cart reset)
      return this._snapshotItems ?? this.$store.state.kioskCart?.items ?? [];
    },
    receiptDiscount() {
      return this._snapshotDiscount ?? this.$store.state.kioskCart?.loyaltyDiscount ?? 0;
    },
    receiptSubtotal() {
      const items = this.receiptItems;
      // [KIOSK-17] Use item.total (always present after ADD_ITEM fix) for accuracy
      return this._snapshotSubtotal ?? items.reduce((sum, item) => sum + (parseFloat(item.total) || 0), 0);
    },
    receiptPaymentMethod() {
      if (this._snapshotPayment !== null) return this._snapshotPayment;
      const map = {
        card: this.$t('kiosk.card'),
        cash: this.$t('kiosk.cash'),
        tr: this.$t('kiosk.pay_screen.tr_title'),
      };
      const method = this.$store.state.kioskCart?.paymentMethod;
      return map[method] || method || '';
    },
    restaurantName() {
      // globalState stores data in state.lists (not state.settings)
      const lists = this.$store.state.globalState?.lists;
      return lists?.company_name || lists?.site_name || 'FoodKing';
    },
    receiptDate() {
      const locale = this.$i18n?.locale || 'fr';
      const browserLocale = locale === 'ar' ? 'ar-SA' : locale === 'en' ? 'en-GB' : 'fr-FR';
      return new Date().toLocaleString(browserLocale, { dateStyle: 'short', timeStyle: 'short' });
    },
    // [QW-5] Reuse _snapshotPayment which is already the translated label
    // (set in mounted() from the same map). Falls back to '' so the row hides.
    paymentMethodLabel() {
      return this._snapshotPayment || '';
    },
    // [QW-5] Icon keyed on the raw cart paymentMethod string (cash|card|tr).
    paymentMethodIcon() {
      const map = { card: '💳', cash: '💶', tr: '🎫' };
      return map[this._snapshotPaymentKey] || '';
    },
    // [QW-5] ETA fenêtre 5..baseMin+3, basée sur Settings.preparation_time.
    estimatedReadyTime() {
      const lists = this.$store.state.globalState?.lists;
      const baseMin = parseInt(
        lists?.order_setup_food_preparation_time
          ?? lists?.preparation_time
          ?? 5,
        10,
      ) || 5;
      const min = Math.max(1, baseMin);
      return this.$t('kiosk.confirmation.minutes_range', { min, max: min + 3 });
    },
    // [QW-6] Solde total cumulé (gagnés + balance pré-existant).
    totalLoyaltyPoints() {
      const earned = this.pointsEarned || 0;
      const before = this._snapshotLoyaltyBalance || 0;
      return earned + before;
    },
    // [QW-6] Conversion points → euros via le même rate SSOT que pointsEarned.
    pointsValueEur() {
      const lists = this.$store.state.globalState?.lists;
      const rate = parseInt(lists?.loyalty_points_per_euro, 10) || 0;
      if (rate <= 0) return '0.00';
      return (this.totalLoyaltyPoints / rate).toFixed(2);
    },
  },
  mounted() {
    // Snapshot cart data BEFORE resetting, so receipt can still be printed
    const state = this.$store.state.kioskCart;
    const items = state?.items || [];

    // Kiosk Phase 9.1.12 — si le panier est déjà vide (reload F5 sur
    // /confirmation après un premier mount), on tente de réhydrater depuis
    // le snapshot localStorage. Cela couvre le cas "le client appuie sur F5
    // juste après la confirmation et perd son ticket" — la persistance
    // localStorage recharge items/total/queueNumber pour afficher le ticket.
    // SSOT : ces données ne sont JAMAIS utilisées pour refaire un paiement.
    const cartIsEmpty = !Array.isArray(items) || items.length === 0;
    const snapshot = cartIsEmpty ? readKioskReceiptSnapshot() : null;

    this._snapshotItems   = cartIsEmpty && snapshot
      ? JSON.parse(JSON.stringify(snapshot.items || []))
      : JSON.parse(JSON.stringify(items));
    this._snapshotDiscount = cartIsEmpty && snapshot
      ? (snapshot.discount || 0)
      : (state?.loyaltyDiscount || 0);
    // [KIOSK-17] Use item.total (always present after ADD_ITEM fix) for accuracy
    this._snapshotSubtotal = cartIsEmpty && snapshot
      ? (snapshot.subtotal ?? (snapshot.items || []).reduce((s, it) => s + (parseFloat(it.total) || 0), 0))
      : items.reduce((sum, item) => sum + (parseFloat(item.total) || 0), 0);
    const methodMap = {
      card: this.$t('kiosk.card'),
      cash: this.$t('kiosk.cash'),
      tr: this.$t('kiosk.pay_screen.tr_title'),
    };
    if (cartIsEmpty && snapshot) {
      // Le snapshot stocke déjà le libellé traduit à l'instant où il a
      // été posé (locale au paiement) → on ne re-traduit pas pour rester
      // cohérent avec le ticket imprimé.
      this._snapshotPayment = snapshot.paymentMethod || '';
      // [QW-5] La clé brute (cash|card|tr) survit aussi pour l'icône.
      this._snapshotPaymentKey = snapshot.paymentMethodKey || null;
    } else {
      const rawMethod = state?.paymentMethod;
      this._snapshotPayment = methodMap[rawMethod] || rawMethod || '';
      this._snapshotPaymentKey = rawMethod || null;
    }
    // [GAP-35-7] Snapshot loyalty customer name and order total for points display
    // [QW-6] Capture balance pré-existant pour totalLoyaltyPoints (gagnés + solde).
    if (cartIsEmpty && snapshot) {
      this._snapshotLoyaltyName = snapshot.loyaltyCustomerName || null;
      this._snapshotOrderTotal = Number.isFinite(snapshot.total) ? snapshot.total : 0;
      this._snapshotLoyaltyBalance = Number.isFinite(snapshot.loyaltyBalance) ? snapshot.loyaltyBalance : 0;
    } else {
      const loyaltyCustomer = state?.loyaltyCustomer;
      this._snapshotLoyaltyName = loyaltyCustomer?.name || loyaltyCustomer?.first_name || null;
      // Use orderTotal prop first, then compute from items
      this._snapshotOrderTotal = this.orderTotal != null
        ? this.orderTotal
        : Math.max(0, this._snapshotSubtotal - this._snapshotDiscount);
      // Le payload backend renvoie selon le cas `loyalty_balance` ou
      // `loyalty_points` ; on tolère les deux + 0 par défaut.
      this._snapshotLoyaltyBalance = parseInt(
        loyaltyCustomer?.loyalty_balance
          ?? loyaltyCustomer?.loyalty_points
          ?? loyaltyCustomer?.points
          ?? 0,
        10,
      ) || 0;
    }

    // [M-3] Snapshot l'ID DB numérique avant le reset du panier — distinct
    // du `orderNumber` (queue display string) qui ne sert qu'à l'affichage.
    // Source : `kioskCart.SET_ORDER_REF` posé par submitOrder dans le store.
    this.ratingOrderId = state?.orderRef
      ? parseInt(state.orderRef, 10) || null
      : null;

    // Reset cart immediately — confirmation screen owns the data via snapshot
    this.$store.dispatch('kioskCart/reset');

    // Kiosk Phase 9.1.12 — persiste le reçu APRES avoir capturé le snapshot
    // in-memory (et avant reset, idéalement — mais le dispatch synchrone
    // ne recharge pas les items, donc ok ici). Aucun PII n'est persisté
    // (email/phone exclus), seul le prénom loyalty déjà imprimé sur le
    // ticket papier est stocké.
    try {
      saveKioskReceiptSnapshot({
        orderId: this.orderNumber || state?.lastOrderId || null,
        queueNumber: this.displayNumber || state?.queueNumber || null,
        total: this._snapshotOrderTotal,
        discount: this._snapshotDiscount,
        subtotal: this._snapshotSubtotal,
        items: this._snapshotItems,
        paymentMethod: this._snapshotPayment,
        // [QW-5] Persiste la clé brute pour l'icône à la reload F5.
        paymentMethodKey: this._snapshotPaymentKey,
        loyaltyCustomerName: this._snapshotLoyaltyName,
        // [QW-6] Persiste le solde pré-existant pour totalLoyaltyPoints.
        loyaltyBalance: this._snapshotLoyaltyBalance,
        pointsEarned: this.pointsEarned || 0,
        restaurantName: this.restaurantName,
      });
    } catch (_) { /* localStorage peut être indisponible → no-op */ }

    this.startTimer();

    // [SPLASH] Auto-print receipt on confirmation (non-blocking)
    // Splash always prints automatically — user can reprint manually if needed
    this.$nextTick(() => {
      this.printReceipt();
    });

    // Kiosk Phase 9.1.8 — annonce vocale de la confirmation (audio only if
    // user opted in via a11y toggles). No-op si audio=off, et l'appel est
    // placé après `$nextTick` pour respecter la règle autoplay Chrome
    // (lancé en réponse à la navigation utilisateur sur /confirmation).
    try {
      this._kioskSpeech = useKioskSpeech({ store: this.$store });
      const text = this.$t('kiosk.confirmation.speech_summary', {
        number: String(this.displayNumber || '').replace(/[^0-9A-Za-z]/g, ''),
        total: this.formatPrice(this.displayTotal || 0),
      });
      if (text) {
        this._kioskSpeech.speak(text, { key: 'kiosk.confirmation.speech_summary' }).catch(() => {});
      }
    } catch (_) { /* tolérant à l'absence d'API Web Speech */ }
  },
  beforeUnmount() {
    this.clearTimer();
    // Kiosk Phase 9.1.8 — stoppe proprement le TTS si l'utilisateur quitte
    // l'écran avant la fin de la lecture (sinon fuite d'utterance sur idle).
    try { this._kioskSpeech?.stop(); } catch (_) {}
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

    async printReceipt() {
      if (this.printStatus === 'printing') return;
      this.clearTimer();
      this.printStatus = 'printing';

      const receiptData = buildReceiptData({
        restaurantName: this.restaurantName,
        queueNumber:    this.displayNumber,
        cartItems:      this.receiptItems,
        subtotal:       this.receiptSubtotal,
        discount:       this.receiptDiscount,
        total:          this.displayTotal || 0,
        paymentMethod:  this.receiptPaymentMethod,
        loyaltyPointsEarned: this.pointsEarned,
        loyaltyCustomerName: this.loyaltyCustomerName || '',
        thankYou: this.$t('kiosk.confirmation.receipt_thanks'),
        labels: {
          queueNumberTitle: this.$t('kiosk.confirmation.receipt_number'),
          subtotal: this.$t('kiosk.subtotal'),
          discount: this.$t('kiosk.confirmation.receipt_discount'),
          total: this.$t('kiosk.confirmation.receipt_total'),
          payment: this.$t('label.payment_method'),
          loyalty: this.$t('kiosk.loyalty_card'),
          seeYouSoon: this.$t('message.please_come_again'),
        },
      });

      try {
        const result = await escPosPrint(receiptData, 'kiosk-print-receipt');

        if (result.method === 'none') {
          this.printStatus = 'error';
          this.printFailed = true;
          reportPrinterFailure(this.displayNumber, result.error || 'no print method');
        } else {
          this.printStatus = 'done';
          this.printFailed = false;
          setTimeout(() => { this.printStatus = null; }, 2000);
        }
      } catch (err) {
        this.printStatus = 'error';
        this.printFailed = true;
        reportPrinterFailure(this.displayNumber, err?.message || 'exception');
      } finally {
        if (this.countdown > 0 && !this.timer) {
          this.startTimer();
        }
      }
    },

    goHome() {
      this.clearTimer();
      // Kiosk Phase 9.1.12 — retour idle = fin de session visible. On purge
      // le snapshot localStorage (le client n'a plus besoin de son ticket
      // à la borne) pour ne pas polluer la prochaine commande.
      try {
        // require-style pour garder le chunk kiosk léger — import déjà fait
        // en haut du fichier; utiliser la fonction importée directement.
        clearKioskReceiptSnapshotSafe();
      } catch (_) { /* noop */ }
      // Router child — parent does not handle @close; must navigate here.
      this.$router.push({ name: 'kiosk.idle' }).catch(() => {});
    },

    sanitizeItemName(name) {
      return sanitizeKioskCustomerFacingText(name || '');
    },

    // [M-3] CSAT 5-star inline submit — silent fail (network/down), optimistic UI.
    // Endpoint: POST /api/frontend/order/{orderId}/rating (auth:sanctum + throttle 10/min).
    async submitRating(n) {
      if (this.ratingSubmitted) return;
      const stars = parseInt(n, 10);
      if (!Number.isFinite(stars) || stars < 1 || stars > 5) return;
      this.rating = stars;
      // Optimistic flip — réseau coupé / endpoint en erreur ne doit pas
      // bloquer le client en bas du parcours (CSAT n'est pas critique).
      const orderId = this.ratingOrderId;
      if (!orderId) {
        this.ratingSubmitted = true;
        return;
      }
      try {
        // axios.defaults.baseURL est `/api` (cf. resources/js/app.js).
        await axios.post(`frontend/order/${orderId}/rating`, {
          rating: stars,
          source: 'kiosk',
        });
      } catch (e) {
        // eslint-disable-next-line no-console
        console.warn('[CSAT] submit failed (silent)', e?.message || e);
      } finally {
        this.ratingSubmitted = true;
      }
    },

    // formatPrice() provided by kioskPriceMixin
  },
};
</script>

<style scoped>
.kiosk-confirmation {
  min-height: 100vh;
  background: #f7f7f8;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2.5rem 2rem;
  text-align: center;
  color: #1f1f1f;
  gap: 1.25rem;
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
  background: white;
  border: 1px solid #ececec;
  border-radius: 20px;
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
  color: #999;
}

.kiosk-confirmation-number {
  font-size: 3rem;
  font-weight: 900;
  color: #1f1f1f;
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
  color: #777;
  line-height: 1.7;
  animation: fadeUp 0.5s ease-out 1.1s both;
}
.kiosk-confirmation-message p { margin: 0; }

/* [GAP-35-7] Loyalty points earned banner */
.kiosk-confirmation-points {
  display: flex;
  align-items: center;
  gap: 12px;
  background: linear-gradient(135deg, rgba(255, 193, 7, 0.12), rgba(255, 152, 0, 0.08));
  border: 1px solid rgba(255, 193, 7, 0.24);
  border-radius: 16px;
  padding: 14px 20px;
  max-width: 360px;
  width: 100%;
  animation: fadeUp 0.5s ease-out 1.15s both;
}
.kiosk-points-icon {
  font-size: 1.8rem;
  flex-shrink: 0;
  animation: pulse 1.5s ease-in-out infinite;
}
.kiosk-points-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.kiosk-points-name {
  font-size: 0.85rem;
  color: #8a6b17;
}
.kiosk-points-value {
  font-size: 1rem;
  color: #FFD54F;
}
.kiosk-points-value strong {
  color: #FFB300;
  font-weight: 700;
}
/* Fade-up transition for v-if */
.fade-up-enter-active { transition: all 0.4s ease-out; }
.fade-up-enter-from { opacity: 0; transform: translateY(12px); }

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
  color: #999;
}

.kiosk-timer-bar {
  height: 5px;
  background: #ececec;
  border-radius: 3px;
  overflow: hidden;
}

.kiosk-timer-fill {
  height: 100%;
  /* [QW-5] Timer = countdown ≠ validation success — couleur muted pour
     ne pas reproduire le vert de la coche de confirmation. */
  background: var(--kiosk-text-muted, #5A5A5A);
  border-radius: 3px;
  transition: width 1s linear;
}

/* CTA */
.kiosk-btn-home {
  background: linear-gradient(135deg, #E8001C, #C0001A);
  color: #fff;
  border: none;
  border-radius: 14px;
  padding: 1rem 2.4rem;
  font-size: 1.05rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 8px 24px rgba(232,0,28,0.18);
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
  background: white;
  border: 1.5px solid #e4e4e4;
  color: #666;
  border-radius: 50px;
  font-size: 1rem;
  padding: 0.75rem 2rem;
  cursor: pointer;
  transition: all 0.2s;
  font-family: inherit;
}
.kiosk-btn-print:hover:not(:disabled) { background: #f7f7f8; color: #1f1f1f; }
.kiosk-btn-print:disabled { opacity: 0.6; cursor: default; }
.kiosk-btn-print.is-done { border-color: rgba(46,204,113,0.5); color: #2ecc71; }
.kiosk-btn-print.is-error { border-color: rgba(232,0,28,0.5); color: #ff6b7a; }

/* [QW-5] Payment / ETA meta rows — variantes visuelles plus discrètes que
   le total pour ne pas concurrencer le numéro de commande comme info clé. */
.kiosk-confirmation-meta {
  font-size: 1rem;
  font-weight: 600;
  color: #1f1f1f;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

/* [QW-6] Solde total — ligne secondaire sous "+N points". */
.kiosk-points-balance {
  font-size: 0.78rem;
  color: #8a6b17;
  margin-top: 2px;
}

/* [M-3] CSAT 5-star inline */
.kiosk-confirmation-csat {
  margin: 0.5rem auto 0.25rem;
  text-align: center;
  animation: fadeUp 0.5s ease-out 1.25s both;
}
.kiosk-csat-prompt {
  font-size: 0.95rem;
  color: var(--kiosk-text-muted, #5A5A5A);
  margin-bottom: 8px;
}
.kiosk-csat-stars {
  display: inline-flex;
  gap: 8px;
}
.kiosk-csat-star {
  background: transparent;
  border: none;
  font-size: 36px;
  line-height: 1;
  color: rgba(0, 0, 0, 0.2);
  cursor: pointer;
  padding: 4px 6px;
  transition: color 0.15s ease, transform 0.15s ease;
  font-family: inherit;
  /* Tap target ≥ 44px (WCAG 2.5.5 + EAA) */
  min-width: 44px;
  min-height: 44px;
}
.kiosk-csat-star:hover,
.kiosk-csat-star:focus-visible { transform: scale(1.15); outline: none; }
.kiosk-csat-star:focus-visible { box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.45); border-radius: 8px; }
.kiosk-csat-star.active { color: var(--kiosk-warning, #F59E0B); }
.kiosk-confirmation-csat-thanks {
  color: var(--kiosk-success, #1B8A3A);
  font-weight: 700;
  margin: 0.75rem auto 0.25rem;
  animation: fadeUp 0.4s ease-out;
}

/* Receipt zone — visible only when printing via window.print() */
.kiosk-receipt-zone { display: none; }

@media print {
  body > * { display: none !important; }
  #kiosk-print-receipt {
    display: block !important;
    font-family: monospace;
    width: 300px;
    margin: 0 auto;
    padding: 12px;
    font-size: 14px;
    color: #000;
  }
}
.kiosk-printer-fallback {
  background: #fef3cd;
  border: 2px solid #f59e0b;
  border-radius: 12px;
  padding: 20px;
  margin: 16px 0;
  text-align: center;
}
.kiosk-printer-fallback-label {
  font-size: 1.1rem;
  color: #92400e;
  margin-bottom: 8px;
}
.kiosk-printer-fallback-number {
  font-size: 3.5rem;
  font-weight: 800;
  color: #1f2937;
  line-height: 1.2;
  margin: 8px 0;
}
.kiosk-printer-fallback-hint {
  font-size: 1rem;
  color: #78350f;
}
</style>
