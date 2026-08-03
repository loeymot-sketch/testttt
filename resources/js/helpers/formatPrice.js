/**
 * [WT-D-R1-F4 2026-05-20] Shared price formatter — single canonical EUR
 * renderer for the admin POS + tracker + detail + receipts surfaces.
 *
 * Root-cause of WT-D-R1-03 (currency rendering inconsistency):
 *   - Tracker        (Intl.NumberFormat fr-FR EUR)         → "19,00 €"  (FR + space, ok)
 *   - Orders list    ({{ order.total_amount_price }} raw)  → "19.00"    (no €, US decimal)
 *   - Detail page    ({{ order.total_currency_price }})    → "19.00€"   (glued, no space)
 *
 * Each surface picked a different backend projection of the same `orders.total`
 * column. The strings were correct for their respective `AppLibrary` helpers
 * (`flatAmountFormat` strips the symbol, `currencyAmountFormat` glues it), but
 * the user-visible result was three different layouts for the same value.
 *
 * Heal: every admin currency render goes through `formatPrice(value)` returning
 * "19,00 €" (Intl.NumberFormat fr-FR EUR with NBSP between number and symbol,
 * as French typography expects). The kiosk surfaces keep their own
 * `kioskFormatPrice` mixin (it reads currency from the Vuex globalState lists
 * so customer-facing surfaces follow branch settings rather than hardcoded
 * EUR). Admin is FR EUR only by product decision (Le Cayenne is a French
 * single-restaurant deployment in V1).
 *
 * Numeric integrity contract:
 *   formatPrice(undefined)  → "0,00 €"
 *   formatPrice(null)       → "0,00 €"
 *   formatPrice('')         → "0,00 €"
 *   formatPrice(0)          → "0,00 €"
 *   formatPrice(19)         → "19,00 €"
 *   formatPrice('19.00')    → "19,00 €"   (flatAmountFormat string parses cleanly)
 *   formatPrice('19,00 €')  → "0,00 €"    (Intl never round-trips a formatted string;
 *                                          callers must pass numeric input)
 */

/**
 * Format a numeric value as FR EUR currency ("19,00 €").
 *
 * @param {number|string} value - Numeric value or numeric-looking string.
 * @returns {string} Formatted price.
 */
export function formatPrice(value) {
    const num = Number.parseFloat(value);
    const safe = Number.isFinite(num) ? num : 0;
    try {
        return new Intl.NumberFormat('fr-FR', {
            style: 'currency',
            currency: 'EUR',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(safe);
    } catch (_e) {
        // Locale unavailable (very old runtime) — fall back to manual FR layout.
        return safe.toFixed(2).replace('.', ',') + ' €';
    }
}

/**
 * Vue mixin: adds `this.formatPrice(value)` to any admin component.
 *
 * Usage:
 *   import { adminPriceMixin } from '@/helpers/formatPrice';
 *   mixins: [adminPriceMixin],
 *   // template: {{ formatPrice(order.total) }}
 */
export const adminPriceMixin = {
    methods: {
        formatPrice,
    },
};

export default formatPrice;
