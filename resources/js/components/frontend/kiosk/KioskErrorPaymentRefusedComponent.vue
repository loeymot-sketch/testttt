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
import axios from 'axios';
import KioskErrorLayoutComponent from './KioskErrorLayoutComponent.vue';

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
            subtype: 'payment_refused',
            context: {
                error_code: this.errorCode,
                order_id: this.orderId,
            },
        });
    },
    methods: {
        async retryPayment() {
            this.retrying = true;
            this.logEvent('error_payment_retry', { subtype: 'payment_refused' });
            this.$emit('retry');
            setTimeout(() => { this.retrying = false; }, 500);
        },
        payCounter() {
            this.logEvent('error_payment_switch_cash', { subtype: 'payment_refused' });
            this.$emit('pay-at-counter');
        },
        cancelOrder() {
            this.logEvent('error_payment_cancel', { subtype: 'payment_refused' });
            this.$emit('cancel-order');
            this.$router?.push({ name: 'kiosk.idle' }).catch(() => {});
        },
        logEvent(type, meta = {}) {
            axios.post('/frontend/kiosk/event', { type, ...meta }).catch(() => {});
        },
    },
};
</script>
