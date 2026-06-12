/**
 * [W-REM T-R2.0 Q-7 2026-06-12] Typed coupon discount renderer.
 *
 * Finding DB5-05: the REMISE column rendered `coupon.flat_discount` raw
 * ("12.00" — US decimal point, no unit) while the adjacent column already
 * distinguishes Fixe/Pourcentage. A fixed discount and a percentage
 * discount are different beasts — the cell must carry the unit:
 *   - taxTypeEnum.FIXED (5)      → "12,00 €" (shared FR EUR formatter)
 *   - taxTypeEnum.PERCENTAGE (10)→ "12 %"   (FR number + NBSP + %)
 *
 * Kept as a pure module (no Vue dependency) so the Vitest contract
 * (couponDiscountTyped.spec.js) exercises the exact production logic.
 */
import { formatPrice } from '../../../helpers/formatPrice';
import taxTypeEnum from '../../../enums/modules/taxTypeEnum';

/**
 * Format a coupon discount according to its type.
 *
 * @param {number|string} value - Raw numeric discount (CouponResource `discount`).
 * @param {number} type - taxTypeEnum.FIXED (5) | taxTypeEnum.PERCENTAGE (10).
 * @returns {string} "12,00 €" or "12 %".
 */
export function formatTypedDiscount(value, type) {
    if (Number(type) === taxTypeEnum.PERCENTAGE) {
        const num = Number.parseFloat(value);
        const safe = Number.isFinite(num) ? num : 0;
        let rendered;
        try {
            rendered = new Intl.NumberFormat('fr-FR', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2,
            }).format(safe);
        } catch (_e) {
            rendered = String(safe).replace('.', ',');
        }
        // French typography: non-breaking space before the percent sign.
        return rendered + ' %';
    }

    // FIXED (and the CouponResource null→5 default): FR EUR money.
    return formatPrice(value);
}

export default formatTypedDiscount;
