<template>
  <div
    v-if="(promos || []).length > 0"
    class="kiosk-promo-carousel"
    role="region"
    :aria-label="$t('kiosk.promo.aria_label') || 'Offres en cours'"
    data-testid="kiosk-promo-carousel"
  >
    <div
      class="kiosk-promo-track"
      :class="{ 'kiosk-promo-track--animated': (promos || []).length > 1 && !reducedMotion }"
      :aria-live="(promos || []).length > 1 ? 'polite' : 'off'"
    >
      <article
        v-for="promo in promos"
        :key="promo.id"
        class="kiosk-promo-card"
        :data-testid="`kiosk-promo-card-${promo.id}`"
      >
        <div class="kiosk-promo-card__label">
          {{ $t('kiosk.promo.badge_label') || 'Offre' }}
        </div>
        <div class="kiosk-promo-card__value">
          <span class="kiosk-promo-card__amount">{{ formatPromoValue(promo) }}</span>
        </div>
        <div v-if="promo.min_cart" class="kiosk-promo-card__min">
          {{ $t('kiosk.promo.min_cart', { value: formatPrice(promo.min_cart) }) }}
        </div>
        <div class="kiosk-promo-card__code">
          <span class="kiosk-promo-card__code-label">
            {{ $t('kiosk.promo.code_label') || 'Code' }}
          </span>
          <span class="kiosk-promo-card__code-value" :aria-label="$t('kiosk.promo.code_aria', { code: promo.code })">
            {{ promo.code }}
          </span>
        </div>
      </article>
    </div>
  </div>
</template>

<script>
/**
 * KioskPromoCarouselComponent — Bandeau des offres actives kiosk.
 *
 * Source : `$store.getters['kioskMenu/kioskPromos']` (alimenté par
 * `GET /api/frontend/menu → promos[]`).
 *
 * Invariants :
 *  - Aucun calcul de prix client : on n'affiche que les métadonnées brutes
 *    exposées par le serveur. L'application effective se fait via
 *    `POST /api/frontend/promo/validate` au panier (SSOT).
 *  - Rotation via animation CSS — respectée par `prefers-reduced-motion` et
 *    le toggle `data-kiosk-reduced-motion` (a11y WCAG 2.3.3).
 *  - Zéro PII dans l'ARIA (le code promo est public, utilisable par tous).
 */
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';

export default {
    name: 'KioskPromoCarouselComponent',
    mixins: [kioskPriceMixin],
    computed: {
        promos() {
            // Lecture défensive : le module kioskMenu peut être absent
            // (tests isolés) ou le getter non enregistré.
            try {
                const v = this.$store?.getters?.['kioskMenu/kioskPromos'];
                return Array.isArray(v) ? v : [];
            } catch (_) {
                return [];
            }
        },
        reducedMotion() {
            return !!this.$store?.getters?.['kioskSettings/reducedMotion'];
        },
    },
    methods: {
        formatPromoValue(promo) {
            if (!promo) return '';
            if (promo.type === 'percent') {
                const n = parseFloat(promo.value || 0);
                return `-${Number.isInteger(n) ? n : n.toFixed(0)}%`;
            }
            if (promo.type === 'amount') {
                return `-${this.formatPrice(promo.value)}`;
            }
            return '';
        },
    },
};
</script>

<style scoped>
.kiosk-promo-carousel {
    width: 100%;
    overflow: hidden;
    background: linear-gradient(90deg, var(--kiosk-primary-soft, #FFF0F2), var(--kiosk-surface-alt, #F7F3EC));
    border-top: 2px solid var(--kiosk-primary, #E8001C);
    border-bottom: 2px solid var(--kiosk-primary, #E8001C);
    padding: var(--kiosk-space-3, 12px) 0;
}

.kiosk-promo-track {
    display: flex;
    gap: var(--kiosk-space-4, 16px);
    padding: 0 var(--kiosk-space-4, 16px);
    align-items: stretch;
}

.kiosk-promo-track--animated {
    animation: kiosk-promo-marquee 26s linear infinite;
}

@keyframes kiosk-promo-marquee {
    from { transform: translateX(0); }
    to   { transform: translateX(-33%); }
}

@media (prefers-reduced-motion: reduce) {
    .kiosk-promo-track--animated { animation: none; }
}
:global([data-kiosk-reduced-motion="true"]) .kiosk-promo-track--animated {
    animation: none;
}

.kiosk-promo-card {
    background: var(--kiosk-surface, #FFFFFF);
    border: 2px solid var(--kiosk-primary, #E8001C);
    border-radius: var(--kiosk-radius-3, 14px);
    padding: var(--kiosk-space-3, 12px) var(--kiosk-space-4, 16px);
    min-width: 280px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    color: var(--kiosk-text, #1A1A1A);
    box-shadow: var(--kiosk-shadow-2, 0 6px 18px rgba(0,0,0,.10));
}

.kiosk-promo-card__label {
    font-size: calc(12px * var(--kiosk-text-scale, 1));
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--kiosk-primary, #E8001C);
}

.kiosk-promo-card__value {
    font-family: var(--kiosk-font-display, inherit);
    font-weight: 900;
    font-size: calc(32px * var(--kiosk-text-scale, 1));
    line-height: 1;
    color: var(--kiosk-primary-dark, #B8000F);
}

.kiosk-promo-card__min {
    font-size: calc(13px * var(--kiosk-text-scale, 1));
    color: var(--kiosk-text-muted, #5A5A5A);
}

.kiosk-promo-card__code {
    display: flex;
    align-items: baseline;
    gap: 6px;
    margin-top: 4px;
}

.kiosk-promo-card__code-label {
    font-size: calc(11px * var(--kiosk-text-scale, 1));
    color: var(--kiosk-text-muted, #5A5A5A);
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.kiosk-promo-card__code-value {
    font-family: var(--kiosk-font-mono, monospace);
    font-size: calc(18px * var(--kiosk-text-scale, 1));
    font-weight: 800;
    padding: 2px 8px;
    background: var(--kiosk-surface-alt, #F7F3EC);
    border-radius: var(--kiosk-radius-1, 6px);
    letter-spacing: 0.08em;
}
</style>
