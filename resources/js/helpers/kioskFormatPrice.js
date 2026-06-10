/**
 * [PHASE-38] Centralized price formatter for kiosk components.
 *
 * Uses the branch currency and locale from the Vuex globalState store,
 * so all kiosk screens display prices consistently and follow the
 * restaurant's configured currency (not hardcoded fr-FR / EUR).
 *
 * Usage:
 *   import { formatKioskPrice } from '@/helpers/kioskFormatPrice';
 *   formatKioskPrice(12.5, store)  // → "12,50 €"
 *
 * Or use the Vue mixin (kioskPriceMixin) for component-level access:
 *   this.formatPrice(12.5)
 */

/**
 * Format a price value using the configured currency and locale.
 *
 * @param {number|string} value - The price to format
 * @param {object} options - Optional overrides
 * @param {string} options.locale - BCP 47 locale (e.g. 'fr-FR', 'en-US', 'ar-SA')
 * @param {string} options.currency - ISO 4217 currency code (e.g. 'EUR', 'USD')
 * @param {string} options.currencySymbol - Direct symbol override (e.g. '€', '$')
 * @param {string} options.position - 'left' or 'right' (from settings)
 * @param {number} options.digits - Decimal places (from settings, default 2)
 * @returns {string} Formatted price string
 */
export function formatKioskPrice(value, options = {}) {
    const num = parseFloat(value) || 0;
    const digits = options.digits ?? 2;
    const locale = options.locale || 'fr-FR';
    const currency = options.currency || 'EUR';

    // If we have a direct currency symbol (from settings), use manual formatting
    if (options.currencySymbol) {
        const formatted = num.toFixed(digits).replace('.', ',');
        if (options.position === 'right') {
            return `${formatted} ${options.currencySymbol}`;
        }
        return `${options.currencySymbol}${formatted}`;
    }

    // Use Intl for proper locale-aware formatting
    try {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: currency,
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        }).format(num);
    } catch (_) {
        // Fallback if locale/currency is invalid
        return `${num.toFixed(digits)} ${currency}`;
    }
}

/**
 * Extract price formatting options from Vuex globalState.lists
 * @param {object} lists - globalState.lists from Vuex store
 * @returns {object} Options for formatKioskPrice
 */
export function getPriceOptionsFromStore(lists) {
    if (!lists) return {};
    return {
        currencySymbol: lists.site_default_currency_symbol || '€',
        position: normalizeCurrencyPosition(lists.site_currency_position),
        digits: parseInt(lists.site_digit_after_decimal_point, 10) || 2,
    };
}

/**
 * [F-BV-01 / ultra-audit 2026-06-10] settings.site_currency_position is the
 * NUMERIC CurrencyPosition enum (LEFT=5, RIGHT=10 — app/Enums/
 * CurrencyPosition.php), but formatKioskPrice compares position to the
 * STRING 'right' (10 === 'right' was always false) → the whole kiosk fell
 * back to symbol-before "€1,50". Normalize numeric/string enum values to the
 * 'left'/'right' strings the formatter expects; FR default = 'right'.
 * @param {number|string|undefined} raw - raw settings value (5, 10, '5', '10', 'left', 'right')
 * @returns {'left'|'right'}
 */
export function normalizeCurrencyPosition(raw) {
    const numeric = parseInt(raw, 10);
    if (numeric === 5) return 'left';
    if (numeric === 10) return 'right';
    if (typeof raw === 'string' && raw.toLowerCase() === 'left') return 'left';
    return 'right';
}

/**
 * Vue mixin to add formatPrice() to any kiosk component.
 * Reads currency config from globalState.lists automatically.
 *
 * Usage in component:
 *   import { kioskPriceMixin } from '@/helpers/kioskFormatPrice';
 *   mixins: [kioskPriceMixin]
 *   // Then use: this.formatPrice(12.5) or {{ formatPrice(item.price) }}
 */
export const kioskPriceMixin = {
    methods: {
        formatPrice(value) {
            const store = this?.$?.appContext?.config?.globalProperties?.$store || null;
            const lists = store?.state?.globalState?.lists;
            const options = getPriceOptionsFromStore(lists);
            return formatKioskPrice(value, options);
        },
    },
};

export default formatKioskPrice;
