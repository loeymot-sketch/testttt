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
        return { retrying: false };
    },
    mounted() {
        this.logEvent('error_shown');
    },
    methods: {
        async retry() {
            this.retrying = true;
            try {
                this.logEvent('error_retry');
                this.$emit('retry');
            } finally {
                // Laisse 500ms de feedback visuel avant de relâcher.
                setTimeout(() => { this.retrying = false; }, 500);
            }
        },
        callStaff() {
            this.logEvent('error_call_staff');
            this.$emit('call-staff');
        },
        logEvent(type, meta = {}) {
            trackKioskErrorEvent(type, 'network', meta);
        },
    },
};
</script>
