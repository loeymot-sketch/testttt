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
    </div>

    <div v-if="printFailed" class="kiosk-printer-fallback">
      <p class="kiosk-printer-fallback-label">{{ $t('kiosk.confirmation.print_failed') }}</p>
      <div class="kiosk-printer-fallback-number">#{{ displayNumber }}</div>
      <p class="kiosk-printer-fallback-hint">{{ $t('kiosk.confirmation.print_failed_hint') }}</p>
    </div>

    <div class="kiosk-confirmation-message">
      <p>{{ $t('kiosk.confirmation.message_kitchen') }}</p>
      <p>{{ $t('kiosk.confirmation.message_counter') }}</p>
    </div>

    <!-- [GAP-35-7] Points fidélité gagnés — style Splash -->
    <transition name="fade-up">
      <div v-if="pointsEarned > 0 && loyaltyCustomerName" class="kiosk-confirmation-points">
        <div class="kiosk-points-icon">⭐</div>
        <div class="kiosk-points-text">
          <span class="kiosk-points-name">{{ loyaltyCustomerName }},</span>
          <span class="kiosk-points-value">{{ $t('kiosk.confirmation.loyalty_points', { n: pointsEarned }) }}</span>
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

    <button type="button"
      class="kiosk-btn-print"
      :class="{ 'is-printing': printStatus === 'printing', 'is-done': printStatus === 'done', 'is-error': printStatus === 'error' }"
      @click="printReceipt"
      :disabled="printStatus === 'printing'"
      :aria-busy="printStatus === 'printing'"
      data-testid="kiosk-confirmation-cta-print"
    >
      <span v-if="printStatus === 'printing'">⏳ {{ $t('kiosk.confirmation.printing') }}</span>
      <span v-else-if="printStatus === 'done'">✅ {{ $t('kiosk.confirmation.printed') }}</span>
      <span v-else-if="printStatus === 'error'">❌ {{ $t('kiosk.confirmation.print_error') }}</span>
      <span v-else>🖨️ {{ $t('kiosk.confirmation.print_button') }}</span>
    </button>

    <button type="button" class="kiosk-btn-home" @click="goHome" data-testid="kiosk-confirmation-cta-home">
      {{ $t('kiosk.confirmation.new_order') }} →
    </button>
  </div>

  <!-- Receipt zone (hidden unless print dialog / print-failed fallback) -->
  <div
    id="kiosk-print-receipt"
    class="kiosk-receipt-zone"
    :data-print-failed="printFailed"
    data-testid="kiosk-print-receipt"
    :role="printFailed ? 'status' : undefined"
    :aria-live="printFailed ? 'polite' : undefined"
  >
    <template v-if="printFailed">
      <h2 class="kiosk-fallback-receipt-title">{{ $t('kiosk.confirmation.fallback_receipt_title') }}</h2>
      <p class="kiosk-fallback-receipt-help">{{ $t('kiosk.confirmation.fallback_receipt_help') }}</p>
    </template>
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
import { printReceipt as escPosPrint, buildReceiptData, reportPrinterFailure } from '../../../helpers/kioskPrinter';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { sanitizeKioskCustomerFacingText } from '../../../helpers/kioskDisplayText';
import kioskHardware from '../../../services/kioskHardware';
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

function confirmationAutoReturnSeconds() {
  const configured = Number(window.foodkingConfig?.kioskConfirmationAutoReturnSeconds ?? 30);
  return Number.isFinite(configured) && configured > 0 ? Math.floor(configured) : 30;
}
// Kiosk Phase 9.1.8 — TTS sur l'écran de confirmation.
// Énoncé du numéro de commande + total pour les malvoyants (EAA 2025).
// Le composable no-op si `kioskSettings.audio` est off — aucun effet de bord.
import { useKioskSpeech } from '../../../composables/useKioskSpeech';

export default {
  name: 'KioskConfirmationComponent',
  mixins: [kioskPriceMixin],
  // [iter15-mega-fix Vue-warn-cluster round-7 2026-05-10]
  // KioskAppComponent's <router-view> binds shell-level listeners
  // (add-to-cart / go-to-cart / start-order / reset-kiosk) on every routed
  // child. This component renders a fragment (two sibling root elements:
  // `.kiosk-confirmation` + `#kiosk-print-receipt`), so Vue 3 cannot auto-
  // inherit those listeners and warns:
  //   "Extraneous non-emits event listeners (addToCart, goToCart, startOrder,
  //    resetKiosk) were passed to component but could not be automatically
  //    inherited because component renders fragment or text root nodes."
  // Declaring them in `emits` tells Vue these events are intentionally not
  // emitted from this confirmation screen (the cart shell drives them
  // elsewhere) and silences the warning without weakening the contract.
  emits: ['add-to-cart', 'go-to-cart', 'start-order', 'reset-kiosk'],
  props: {
    // Populated from route.query by kioskRoutes.js
    orderNumber: { type: String, default: '' },
    orderTotal:  { type: Number, default: null },
  },
  data() {
    const autoReturnSeconds = confirmationAutoReturnSeconds();
    return {
      autoReturnSeconds,
      countdown:      autoReturnSeconds,
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
      return lists?.company_name || lists?.site_name || 'Le Cayenne';
    },
    receiptDate() {
      const locale = this.$i18n?.locale || 'fr';
      const browserLocale = locale === 'ar' ? 'ar-SA' : locale === 'en' ? 'en-GB' : 'fr-FR';
      return new Date().toLocaleString(browserLocale, { dateStyle: 'short', timeStyle: 'short' });
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
    } else {
      const rawMethod = state?.paymentMethod;
      this._snapshotPayment = methodMap[rawMethod] || rawMethod || '';
    }
    // [GAP-35-7] Snapshot loyalty customer name and order total for points display
    if (cartIsEmpty && snapshot) {
      this._snapshotLoyaltyName = snapshot.loyaltyCustomerName || null;
      this._snapshotOrderTotal = Number.isFinite(snapshot.total) ? snapshot.total : 0;
    } else {
      const loyaltyCustomer = state?.loyaltyCustomer;
      this._snapshotLoyaltyName = loyaltyCustomer?.name || loyaltyCustomer?.first_name || null;
      // Use orderTotal prop first, then compute from items
      this._snapshotOrderTotal = this.orderTotal != null
        ? this.orderTotal
        : Math.max(0, this._snapshotSubtotal - this._snapshotDiscount);
    }

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
        loyaltyCustomerName: this._snapshotLoyaltyName,
        pointsEarned: this.pointsEarned || 0,
        restaurantName: this.restaurantName,
      });
    } catch (_) { /* localStorage peut être indisponible → no-op */ }

    this.startTimer();

    // Auto-print only on the real kiosk bridge. Browser window.print() can
    // suspend timers in dev/simulated payment and leave the kiosk on this page.
    this.$nextTick(() => {
      if (kioskHardware.isKioskBridge()) {
        this.printReceipt();
      }
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
      this.clearTimer();
      this.countdown = this.autoReturnSeconds;
      this.progressWidth = 100;
      this.timer = setInterval(() => {
        this.countdown--;
        this.progressWidth = (this.countdown / this.autoReturnSeconds) * 100;
        if (this.countdown <= 0) this.goHome();
      }, 1000);
    },

    clearTimer() {
      if (this.timer) { clearInterval(this.timer); this.timer = null; }
    },

    async printReceipt() {
      if (this.printStatus === 'printing') return;
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
      this.$store.dispatch('kioskCart/reset');
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

    // formatPrice() provided by kioskPriceMixin
  },
};
</script>

<style scoped>
.kiosk-confirmation {
  min-height: 100vh;
  background: var(--kiosk-page-bg, var(--kiosk-bg));
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2.5rem 2rem;
  text-align: center;
  color: var(--kiosk-text);
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
  font-size: clamp(2.4rem, 6vw, 4.8rem);
  font-weight: 900;
  letter-spacing: -0.01em;
  margin: 0;
  animation: fadeUp 0.5s ease-out 0.8s both;
}

/* Detail card */
.kiosk-confirmation-card {
  background: var(--kiosk-surface);
  border: 1.5px solid var(--kiosk-border);
  border-radius: 30px;
  padding: 1.8rem 2.8rem;
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
  color: var(--kiosk-text-muted);
}

.kiosk-confirmation-number {
  font-size: 4rem;
  font-weight: 900;
  color: var(--kiosk-primary);
  line-height: 1;
}

.kiosk-confirmation-price {
  font-size: 1.8rem;
  font-weight: 800;
  color: var(--kiosk-warning);
}

/* Message */
.kiosk-confirmation-message {
  font-size: 1rem;
  color: var(--kiosk-text-muted);
  line-height: 1.7;
  animation: fadeUp 0.5s ease-out 1.1s both;
}
.kiosk-confirmation-message p { margin: 0; }

/* [GAP-35-7] Loyalty points earned banner */
.kiosk-confirmation-points {
  display: flex;
  align-items: center;
  gap: 12px;
  background: rgba(245, 158, 11, 0.12);
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
  color: var(--kiosk-text-muted);
}
.kiosk-points-value {
  font-size: 1rem;
  color: var(--kiosk-warning);
}
.kiosk-points-value strong {
  color: var(--kiosk-warning);
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
  color: var(--kiosk-text-muted);
}

.kiosk-timer-bar {
  height: 5px;
  background: var(--kiosk-surface-alt);
  border-radius: 3px;
  overflow: hidden;
}

.kiosk-timer-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--kiosk-success), #00b894);
  border-radius: 3px;
  transition: width 1s linear;
}

/* CTA */
.kiosk-btn-home {
  background: linear-gradient(135deg, var(--kiosk-primary), var(--kiosk-primary-dark));
  color: var(--kiosk-text-on-red);
  border: none;
  border-radius: 28px;
  padding: 1.15rem 2.8rem;
  font-size: 1.25rem;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 8px 24px rgba(244, 80, 30, 0.22);
  transition: transform 0.1s, box-shadow 0.1s;
  animation: fadeUp 0.5s ease-out 1.3s both;
}
.kiosk-btn-home:active {
  transform: scale(0.96);
  box-shadow: 0 4px 14px rgba(244, 80, 30, 0.34);
}

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: translateY(0); }
}

.kiosk-btn-print {
  background: var(--kiosk-surface);
  border: 1.5px solid var(--kiosk-border);
  color: var(--kiosk-text-muted);
  border-radius: 50px;
  font-size: 1rem;
  padding: 0.75rem 2rem;
  cursor: pointer;
  transition: all 0.2s;
  font-family: inherit;
}
.kiosk-btn-print:hover:not(:disabled) { background: var(--kiosk-surface-alt); color: var(--kiosk-text); }
.kiosk-btn-print:disabled { opacity: 0.6; cursor: default; }
.kiosk-btn-print.is-done { border-color: rgba(46,204,113,0.5); color: var(--kiosk-success); }
.kiosk-btn-print.is-error { border-color: rgba(194, 30, 47, 0.5); color: var(--kiosk-error); }

/* Receipt zone — visible when printing or fallback after print failure */
.kiosk-receipt-zone { display: none; }

.kiosk-receipt-zone[data-print-failed="true"] {
  display: block !important;
  max-width: 500px;
  max-height: 80vh;
  margin: 1.25rem auto;
  padding: 1.25rem 1.5rem 1.5rem;
  background: #fff;
  border: 1px solid #e2e2e4;
  border-radius: 16px;
  box-shadow: 0 10px 32px rgba(0, 0, 0, 0.08);
  text-align: center;
  color: #1f1f1f;
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}

.kiosk-fallback-receipt-title {
  font-size: 1.35rem;
  font-weight: 800;
  margin: 0 0 0.75rem;
}
.kiosk-fallback-receipt-help {
  font-size: 0.95rem;
  color: #555;
  margin: 0 0 1rem;
  line-height: 1.45;
}

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
