<template>
  <KioskErrorLayoutComponent
    variant="network"
    icon="📡"
    :title="$t('kiosk.error.network.title')"
    :subtitle="$t('kiosk.error.network.subtitle')"
    :hint="$t('kiosk.error.network.hint')"
  >
    <template #actions>
      <KsButton
        variant="primary"
        size="lg"
        full-width
        :loading="retrying"
        data-testid="kiosk-error-network-cta-retry"
        @click="retry"
      >
        {{ $t('kiosk.error.retry') }}
      </KsButton>
      <KsButton
        variant="secondary"
        size="lg"
        full-width
        data-testid="kiosk-error-network-cta-staff"
        @click="callStaff"
      >
        {{ $t('kiosk.error.call_staff') }}
      </KsButton>
      <p
        v-if="staffCalled"
        class="kiosk-error-network-ack"
        role="status"
        data-testid="kiosk-error-network-staff-ack"
      >
        {{ $t('kiosk.error.network.staff_ack') }}
      </p>
    </template>
  </KioskErrorLayoutComponent>
</template>

<script>
import KioskErrorLayoutComponent from './KioskErrorLayoutComponent.vue';
import { trackKioskErrorEvent } from '../../../helpers/kioskAnalytics';

/**
 * KioskErrorNetworkComponent — Kiosk Design V1 Phase 3.2
 *
 * Écran erreur réseau : heartbeat backend indisponible. Remonte un event
 * d'observabilité + propose retry + call staff. Pas de reset panier auto.
 */
export default {
    name: 'KioskErrorNetworkComponent',
    components: { KioskErrorLayoutComponent },
    emits: ['retry', 'call-staff'],
    data() {
        return { retrying: false, staffCalled: false };
    },
    mounted() {
        this.logEvent('error_shown');
    },
    methods: {
        async retry() {
            this.retrying = true;
            this.logEvent('error_retry');
            this.$emit('retry');
            // [FP-01] Self-contained reconnect. The frozen parent (KioskAppComponent) does
            // NOT bind @retry, so the emit alone is inert and the borne stays stuck on the
            // error screen. Reloading re-bootstraps the kiosk SPA and re-attempts the backend
            // heartbeat — a real retry, mirroring the sibling error screens' self-contained
            // $router fallback. The 600ms delay lets the button's loading state show first.
            setTimeout(() => { window.location.reload(); }, 600);
        },
        callStaff() {
            this.logEvent('error_call_staff');
            this.$emit('call-staff');
            // [FP-01] Visible acknowledgement. There is no staff-call backend in V1 (the
            // event above is logged to observability); the frozen parent does not bind
            // @call-staff, so without this the button gave the customer zero feedback.
            this.staffCalled = true;
        },
        logEvent(type, meta = {}) {
            trackKioskErrorEvent(type, 'network', meta);
        },
    },
};
</script>
