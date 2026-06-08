<template>
  <KioskErrorLayoutComponent
    variant="payment"
    icon="❌"
    :title="$t('kiosk.error.payment_refused.title')"
    :subtitle="$t('kiosk.error.payment_refused.subtitle')"
    :hint="$t('kiosk.error.payment_refused.hint')"
  >
    <template #actions>
      <KsButton
        variant="primary"
        size="lg"
        full-width
        :loading="retrying"
        data-testid="kiosk-error-payment-cta-retry"
        @click="retryPayment"
      >
        {{ $t('kiosk.error.payment_refused.cta_retry') }}
      </KsButton>
      <KsButton
        variant="secondary"
        size="lg"
        full-width
        data-testid="kiosk-error-payment-cta-counter"
        @click="payCounter"
      >
        {{ $t('kiosk.error.payment_refused.cta_pay_counter') }}
      </KsButton>
      <KsButton
        variant="danger"
        size="md"
        full-width
        data-testid="kiosk-error-payment-cta-cancel"
        @click="cancelOrder"
      >
        {{ $t('kiosk.error.payment_refused.cta_cancel') }}
      </KsButton>
    </template>
    <template #footer>
      <span v-if="errorCode">code : {{ errorCode }}</span>
    </template>
  </KioskErrorLayoutComponent>
</template>

<script>
import KioskErrorLayoutComponent from './KioskErrorLayoutComponent.vue';
import { trackKioskErrorEvent } from '../../../helpers/kioskAnalytics';

/**
 * KioskErrorPaymentRefusedComponent — Kiosk Design V1 Phase 3.5
 *
 * Le TPE a refusé définitivement la transaction. 3 CTAs :
 *  - Retry : relancer le paiement sur le même panier (sans recreate order).
 *  - Pay counter : basculer vers CashInstruction (paiement en espèces).
 *  - Cancel : abandon panier → retour idle.
 *
 * Props `errorCode` pour affichage diagnostic staff (ex. TPE_TIMEOUT).
 */
export default {
    name: 'KioskErrorPaymentRefusedComponent',
    components: { KioskErrorLayoutComponent },
    props: {
        errorCode: { type: String, default: null },
        orderId: { type: [String, Number], default: null },
    },
    emits: ['retry', 'pay-at-counter', 'cancel-order'],
    data() { return { retrying: false }; },
    mounted() {
        this.logEvent('error_shown', {
            context: {
                error_code: this.errorCode,
                order_id: this.orderId,
            },
        });
    },
    methods: {
        async retryPayment() {
            this.retrying = true;
            this.logEvent('error_payment_retry');
            this.$emit('retry');
            // [FP-01/KIOSK-5] Self-contained fallback. The frozen parent
            // (KioskAppComponent) does NOT bind @retry, so the emit alone is inert
            // and the borne stays stuck on the refusal screen. Navigate back to the
            // payment screen so the customer can re-attempt (requireCart passes — the
            // cart is intact after a decline). Mirrors the sibling error screens'
            // self-contained $router fallback (cancelOrder already does this).
            setTimeout(() => {
                this.retrying = false;
                this.$router?.push({ name: 'kiosk.payment' }).catch(() => {});
            }, 500);
        },
        payCounter() {
            this.logEvent('error_payment_switch_cash');
            this.$emit('pay-at-counter');
            // [FP-01/KIOSK-5] @pay-at-counter is not bound by the frozen parent →
            // route to the cash/counter instruction screen ourselves so the button
            // actually switches the customer to paying at the counter.
            this.$router?.push({ name: 'kiosk.cash-instruction' }).catch(() => {});
        },
        cancelOrder() {
            this.logEvent('error_payment_cancel');
            this.$emit('cancel-order');
            this.$router?.push({ name: 'kiosk.idle' }).catch(() => {});
        },
        logEvent(type, meta = {}) {
            trackKioskErrorEvent(type, 'payment_refused', meta);
        },
    },
};
</script>
