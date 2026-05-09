<template>
  <KioskErrorLayoutComponent
    variant="menu"
    icon="🍽️"
    :title="$t('kiosk.error.menu_unavailable.title')"
    :subtitle="$t('kiosk.error.menu_unavailable.subtitle')"
    :hint="$t('kiosk.error.menu_unavailable.hint')"
  >
    <template #actions>
      <KsButton
        variant="primary"
        size="lg"
        full-width
        :loading="retrying"
        data-testid="kiosk-error-menu-cta-retry"
        @click="retry"
      >
        {{ $t('kiosk.error.retry') }}
      </KsButton>
      <KsButton
        variant="secondary"
        size="lg"
        full-width
        data-testid="kiosk-error-menu-cta-home"
        @click="backHome"
      >
        {{ $t('kiosk.error.back_home') }}
      </KsButton>
    </template>
  </KioskErrorLayoutComponent>
</template>

<script>
import KioskErrorLayoutComponent from './KioskErrorLayoutComponent.vue';
import { trackKioskErrorEvent } from '../../../helpers/kioskAnalytics';

/**
 * KioskErrorMenuUnavailableComponent — Kiosk Design V1 Phase 3.3
 *
 * `GET /api/frontend/menu` renvoie 503 ou menu vide. Le client ne peut pas
 * commander — on propose retry + retour idle. Event observabilité envoyé.
 */
export default {
    name: 'KioskErrorMenuUnavailableComponent',
    components: { KioskErrorLayoutComponent },
    emits: ['retry', 'back-home'],
    data() { return { retrying: false }; },
    mounted() {
        this.logEvent('error_shown');
    },
    methods: {
        async retry() {
            this.retrying = true;
            this.logEvent('error_retry');
            this.$emit('retry');
            setTimeout(() => { this.retrying = false; }, 500);
        },
        backHome() {
            this.logEvent('error_back_home');
            this.$emit('back-home');
            this.$router?.push({ name: 'kiosk.idle' }).catch(() => {});
        },
        logEvent(type, meta = {}) {
            trackKioskErrorEvent(type, 'menu_unavailable', meta);
        },
    },
};
</script>
