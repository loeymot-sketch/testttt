/**
 * [visual-round-3 P3 fix 2026-07-07] Shared payment-method label mapper for the
 * admin / table / frontend receipt + order-detail Vue surfaces.
 *
 * Root-cause (P3, Round 3): the raw backend `transaction.payment_method` slug —
 * uppercased by TransactionResource / SimpleOrderResource (e.g. "COUNTER_CASH"
 * from PaymentService::counterPaymentMethodLabel, or gateway slugs like "CREDIT")
 * — was rendered verbatim on 8 receipt/detail components. The Round-2 fix only
 * covered the LIST surfaces (TransactionListComponent). This extracts the exact
 * same FR mapping into one shared helper so every surface renders a human FR
 * label ("Espèces (Caisse)", "Carte", …), never a machine slug, and the mapping
 * lives in a single place instead of being duplicated per component.
 *
 * `t` is the vue-i18n translator (this.$t) injected by the caller so the pure
 * function stays framework-free and unit-testable.
 *
 * @param {string|null|undefined} raw - Backend payment-method slug (any case).
 * @param {(key: string) => string} t - Translator (this.$t).
 * @returns {string} Human FR label.
 */
export function paymentMethodLabel(raw, t) {
    if (raw === null || raw === undefined || raw === '') {
        return '—';
    }
    const key = String(raw).toUpperCase();
    const caisse = t('label.caisse');
    const map = {
        // Counter-collect (borne encaissée en caisse) — "(Caisse)" qualifier.
        COUNTER_CASH: `${t('label.cash')} (${caisse})`,
        COUNTER_CARD: `${t('label.card')} (${caisse})`,
        COUNTER_MOBILE_BANKING: `${t('label.mobile_banking')} (${caisse})`,
        COUNTER_TICKET_RESTAURANT: `${t('label.ticket_restaurant')} (${caisse})`,
        COUNTER_OTHER: `${t('label.other')} (${caisse})`,
        // Direct gateway / POS methods.
        CASH: t('label.cash'),
        CARD: t('label.card'),
        CREDIT: t('label.card'),
        TICKET_RESTAURANT: t('label.ticket_restaurant'),
        MOBILE_BANKING: t('label.mobile_banking'),
        CASH_ON_DELIVERY: t('label.cash_on_delivery'),
        // Multi-tender aggregate → the codebase's established FR term
        // (label.split_payment = "Multi-paiement"), used across the POS split UI.
        SPLIT: t('label.split_payment'),
        MIXED: t('label.split_payment'),
    };
    if (map[key]) {
        return map[key];
    }
    // Humanise any other gateway slug: STRIPE -> Stripe, MY_GATEWAY -> My Gateway.
    return key.toLowerCase().replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Vue mixin: adds `this.paymentMethodLabel(raw)` to any component, wiring the
 * component's own `this.$t` into the pure helper.
 *
 * Usage:
 *   import { paymentMethodLabelMixin } from '@/helpers/paymentMethodLabel';
 *   mixins: [paymentMethodLabelMixin],
 *   // template: {{ paymentMethodLabel(order.transaction.payment_method) }}
 */
export const paymentMethodLabelMixin = {
    methods: {
        paymentMethodLabel(raw) {
            return paymentMethodLabel(raw, (k) => this.$t(k));
        },
    },
};

export default paymentMethodLabel;
