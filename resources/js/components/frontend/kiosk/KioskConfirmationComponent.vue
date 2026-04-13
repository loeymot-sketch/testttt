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

    <h1 class="kiosk-confirmation-title">{{ $t('kiosk.confirmation.title') }}</h1>

    <div class="kiosk-confirmation-card">
      <div class="kiosk-confirmation-row">
        <span class="kiosk-confirmation-label">{{ $t('kiosk.confirmation.order_number') }}</span>
        <span class="kiosk-confirmation-number">#{{ displayNumber }}</span>
      </div>
      <!-- [AUDIT-P2-B] Check null/undefined explicitly so total=0 is shown correctly -->
      <div v-if="displayTotal !== null && displayTotal !== undefined" class="kiosk-confirmation-row">
        <span class="kiosk-confirmation-label">{{ $t('kiosk.confirmation.total_paid') }}</span>
        <span class="kiosk-confirmation-price">{{ formatPrice(displayTotal) }}</span>
      </div>
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

    <button
      class="kiosk-btn-print"
      :class="{ 'is-printing': printStatus === 'printing', 'is-done': printStatus === 'done', 'is-error': printStatus === 'error' }"
      @click="printReceipt"
      :disabled="printStatus === 'printing'"
    >
      <span v-if="printStatus === 'printing'">⏳ {{ $t('kiosk.confirmation.printing') }}</span>
      <span v-else-if="printStatus === 'done'">✅ {{ $t('kiosk.confirmation.printed') }}</span>
      <span v-else-if="printStatus === 'error'">❌ {{ $t('kiosk.confirmation.print_error') }}</span>
      <span v-else>🖨️ {{ $t('kiosk.confirmation.print_button') }}</span>
    </button>

    <button class="kiosk-btn-home" @click="goHome">
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
import { printReceipt as escPosPrint, buildReceiptData } from '../../../helpers/kioskPrinter';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { sanitizeKioskCustomerFacingText } from '../../../helpers/kioskDisplayText';

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
      return lists?.company_name || lists?.site_name || 'FoodKing';
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
    this._snapshotItems   = JSON.parse(JSON.stringify(items));
    this._snapshotDiscount = state?.loyaltyDiscount || 0;
    // [KIOSK-17] Use item.total (always present after ADD_ITEM fix) for accuracy
    this._snapshotSubtotal = items.reduce((sum, item) => sum + (parseFloat(item.total) || 0), 0);
    const methodMap = {
      card: this.$t('kiosk.card'),
      cash: this.$t('kiosk.cash'),
      tr: this.$t('kiosk.pay_screen.tr_title'),
    };
    const rawMethod = state?.paymentMethod;
    this._snapshotPayment = methodMap[rawMethod] || rawMethod || '';
    // [GAP-35-7] Snapshot loyalty customer name and order total for points display
    const loyaltyCustomer = state?.loyaltyCustomer;
    this._snapshotLoyaltyName = loyaltyCustomer?.name || loyaltyCustomer?.first_name || null;
    // Use orderTotal prop first, then compute from items
    this._snapshotOrderTotal = this.orderTotal != null
      ? this.orderTotal
      : Math.max(0, this._snapshotSubtotal - this._snapshotDiscount);

    // Reset cart immediately — confirmation screen owns the data via snapshot
    this.$store.dispatch('kioskCart/reset');

    this.startTimer();

    // [SPLASH] Auto-print receipt on confirmation (non-blocking)
    // Splash always prints automatically — user can reprint manually if needed
    this.$nextTick(() => {
      this.printReceipt();
    });
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
          setTimeout(() => { this.printStatus = null; }, 3000);
        } else {
          this.printStatus = 'done';
          setTimeout(() => { this.printStatus = null; }, 2000);
        }
      } catch (_) {
        this.printStatus = 'error';
        setTimeout(() => { this.printStatus = null; }, 3000);
      } finally {
        if (this.countdown > 0 && !this.timer) {
          this.startTimer();
        }
      }
    },

    goHome() {
      this.clearTimer();
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
  background: linear-gradient(90deg, #2ECC71, #00b894);
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
</style>
