<template>
  <div
    :class="[
      'ks-priceline',
      `ks-priceline--${size}`,
      { 'ks-priceline--total': emphasis, 'ks-priceline--neg': signedDelta < 0 }
    ]"
  >
    <span class="ks-priceline__label">
      <slot>{{ label }}</slot>
    </span>
    <span class="ks-priceline__value" aria-live="polite">
      <slot name="value">
        <template v-if="isDelta">{{ formattedDelta }}</template>
        <template v-else>{{ formattedValue }}</template>
      </slot>
    </span>
  </div>
</template>

<script>
/**
 * KsPriceLine — Ligne prix (récap, panier, totaux).
 *
 * Rappel SSOT : la borne n'affiche que des prix reçus du backend
 * (FrontendOrderService / /pricing/preview / /promo/validate).
 * Aucune multiplication / total calculé côté Vue hors preview server-side.
 *
 * Props :
 *  - label      : libellé (ou slot default)
 *  - price      : montant à afficher (number, EUR)
 *  - delta      : si défini, rend un delta signé (ex: +1,00 € pour extras)
 *  - currency   : symbole (default €)
 *  - locale     : fr-FR par défaut (Intl.NumberFormat)
 *  - size       : sm | md | lg (lg = total gras)
 *  - emphasis   : booléen — style "Total" (label plus gros, valeur rouge)
 *
 * Format FR : "1 234,56 €" (séparateur insécable, virgule décimale)
 */
export default {
    name: 'KsPriceLine',
    props: {
        label: { type: String, default: null },
        price: { type: Number, default: null },
        delta: { type: Number, default: null },
        currency: { type: String, default: '€' },
        locale: { type: String, default: 'fr-FR' },
        size: {
            type: String,
            default: 'md',
            validator: (v) => ['sm', 'md', 'lg'].includes(v),
        },
        emphasis: { type: Boolean, default: false },
    },
    computed: {
        isDelta() { return this.delta != null; },
        signedDelta() { return this.delta == null ? 0 : this.delta; },
        formattedValue() {
            return this.formatMoney(this.price ?? 0);
        },
        formattedDelta() {
            const amount = this.delta ?? 0;
            if (amount === 0) return this.formatMoney(0);
            const sign = amount > 0 ? '+' : '−';
            return `${sign} ${this.formatMoney(Math.abs(amount))}`;
        },
    },
    methods: {
        formatMoney(value) {
            if (value == null || Number.isNaN(Number(value))) return '—';
            try {
                const body = new Intl.NumberFormat(this.locale, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                }).format(Number(value));
                return `${body}\u00A0${this.currency}`;
            } catch (_) {
                return `${Number(value).toFixed(2).replace('.', ',')}\u00A0${this.currency}`;
            }
        },
    },
};
</script>

<style scoped>
.ks-priceline {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--kiosk-space-4);
    width: 100%;
    color: var(--kiosk-text);
    font-family: inherit;
}

.ks-priceline__label {
    color: var(--kiosk-text-muted);
    font-weight: var(--kiosk-font-weight-medium);
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ks-priceline__value {
    color: var(--kiosk-text);
    font-weight: var(--kiosk-font-weight-bold);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

/* ---------- Sizes ---------- */
.ks-priceline--sm .ks-priceline__label,
.ks-priceline--sm .ks-priceline__value {
    font-size: calc(var(--kiosk-font-size-caption) * var(--kiosk-text-scale));
}
.ks-priceline--md .ks-priceline__label,
.ks-priceline--md .ks-priceline__value {
    font-size: calc(var(--kiosk-font-size-body) * var(--kiosk-text-scale));
}
.ks-priceline--lg .ks-priceline__label {
    font-size: calc(var(--kiosk-font-size-subtitle) * var(--kiosk-text-scale));
}
.ks-priceline--lg .ks-priceline__value {
    font-size: calc(var(--kiosk-font-size-title) * var(--kiosk-text-scale));
}

/* ---------- Emphasis (total) ---------- */
.ks-priceline--total .ks-priceline__label {
    color: var(--kiosk-text);
    font-weight: var(--kiosk-font-weight-bold);
    text-transform: uppercase;
    letter-spacing: var(--kiosk-letter-spacing-wide);
}
.ks-priceline--total .ks-priceline__value {
    color: var(--kiosk-primary);
    font-weight: var(--kiosk-font-weight-black);
}

/* ---------- Negative delta (rabais, remise) ---------- */
.ks-priceline--neg .ks-priceline__value {
    color: var(--kiosk-success);
}
</style>
