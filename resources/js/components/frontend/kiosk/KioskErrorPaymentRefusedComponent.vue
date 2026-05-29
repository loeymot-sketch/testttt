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
            // [GOAL-2026-05-29 BTN-P1] This screen is a ROUTE (kiosk.error.payment-refused),
            // not a child of the (frozen) KioskAppComponent — the $emit reaches NO listener,
            // so the CTA was a dead button. Mirror cancelOrder()'s working router fallback:
            // re-attempt payment on the same (vuex-persisted) cart. Latent under Plan B
            // (route_all_to_counter never attempts TPE) — becomes live when TPE is wired.
            this.$router?.push({ name: 'kiosk.payment' }).catch(() => { this.retrying = false; });
            setTimeout(() => { this.retrying = false; }, 500);
        },
        payCounter() {
            this.logEvent('error_payment_switch_cash');
            this.$emit('pay-at-counter');
            // [GOAL-2026-05-29 BTN-P1] Router fallback (emit reaches no listener — see retryPayment).
            // Switch to the Plan-B counter cash-instruction flow. If the cash-instruction route
            // guard finds no order ref it safely redirects to idle (degraded, never broken).
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
