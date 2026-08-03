<template>
  <KioskErrorLayoutComponent
    variant="product"
    icon="🚫"
    :title="$t('kiosk.error.product_removed.title')"
    :subtitle="productName ? `${$t('kiosk.error.product_removed.subtitle')} — ${productName}` : $t('kiosk.error.product_removed.subtitle')"
    :hint="$t('kiosk.error.product_removed.hint')"
  >
    <template #actions>
      <KsButton
        variant="primary"
        size="lg"
        full-width
        data-testid="kiosk-error-product-cta-menu"
        @click="backToMenu"
      >
        {{ $t('kiosk.error.product_removed.cta_back_menu') }}
      </KsButton>
      <KsButton
        variant="secondary"
        size="lg"
        full-width
        data-testid="kiosk-error-product-cta-home"
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
 * KioskErrorProductRemovedComponent — Kiosk Design V1 Phase 3.4
 *
 * Un item a été supprimé de la carte pendant le wizard (409 Conflict au
 * moment du POST /order). On propose de revenir à la carte ou à l'accueil.
 */
export default {
    name: 'KioskErrorProductRemovedComponent',
    components: { KioskErrorLayoutComponent },
    props: {
        productName: { type: String, default: null },
        itemId: { type: [String, Number], default: null },
    },
    emits: ['back-to-menu', 'back-home'],
    mounted() {
        this.logEvent('error_shown', {
            context: this.itemId ? { item_id: Number(this.itemId) } : null,
        });
    },
    methods: {
        backToMenu() {
            this.logEvent('error_back_to_menu');
            this.$emit('back-to-menu');
            this.$router?.push({ name: 'kiosk.categories' }).catch(() => {});
        },
        backHome() {
            this.logEvent('error_back_home');
            this.$emit('back-home');
            this.$router?.push({ name: 'kiosk.idle' }).catch(() => {});
        },
        logEvent(type, meta = {}) {
            trackKioskErrorEvent(type, 'product_removed', meta);
        },
    },
};
</script>
