import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

/**
 * [W-REM T-R2.0 Q-7 2026-06-12] Coupons — colonne REMISE typée.
 *
 * Finding DB5-05 (micro-audit loyalty-validation 2026-06-12):
 *   /admin/coupons colonne REMISE rendait `coupon.flat_discount` brut
 *   ("12.00" — point décimal US, aucune unité) alors que la colonne voisine
 *   TYPE DE REMISE distingue Fixe/Pourcentage. Heal: rendu typé par
 *   `discount_type` — taxTypeEnum.FIXED (5) → "12,00 €" (formatter FR
 *   partagé), taxTypeEnum.PERCENTAGE (10) → "12 %" (nombre FR + NBSP + %).
 */

const REPO_ROOT = path.resolve(__dirname, '../..');
const LIST_PATH = path.join(
    REPO_ROOT,
    'resources/js/components/admin/coupons/CouponListComponent.vue'
);
const listSrc = fs.readFileSync(LIST_PATH, 'utf-8');

describe('coupon REMISE typed formatting helper (Q-7)', async () => {
    const { formatTypedDiscount } = await import(
        '../../resources/js/components/admin/coupons/couponDiscountFormat.js'
    );

    it('renders FIXED (5) as FR EUR "12,00 €"', () => {
        const out = formatTypedDiscount('12.00', 5);
        expect(out).toContain('12,00');
        expect(out).toContain('€');
        expect(out).not.toContain('12.00');
    });

    it('renders PERCENTAGE (10) as "12 %" (no decimals when integral)', () => {
        const out = formatTypedDiscount(12, 10);
        expect(out.replace(/ | /g, ' ')).toBe('12 %');
    });

    it('renders fractional percentage the FR way ("12,5 %")', () => {
        const out = formatTypedDiscount(12.5, 10);
        expect(out.replace(/ | /g, ' ')).toBe('12,5 %');
    });

    it('null discount_type defaults to FIXED (CouponResource ships 5 for null)', () => {
        const out = formatTypedDiscount(8, 5);
        expect(out).toContain('8,00');
        expect(out).toContain('€');
    });

    it('never renders NaN for garbage input', () => {
        expect(formatTypedDiscount(undefined, 10)).not.toContain('NaN');
        expect(formatTypedDiscount(undefined, 5)).not.toContain('NaN');
    });
});

describe('CouponListComponent REMISE column uses the typed renderer (Q-7)', () => {
    it('no raw {{ coupon.flat_discount }} interpolation left', () => {
        expect(listSrc).not.toMatch(/\{\{\s*coupon\.flat_discount\s*\}\}/);
    });

    it('REMISE cell delegates to formatTypedDiscount with discount_type', () => {
        expect(listSrc).toMatch(/formatTypedDiscount\(\s*coupon\.discount\s*,\s*coupon\.discount_type\s*\)/);
        expect(listSrc).toContain("couponDiscountFormat");
    });
});

describe('Q-7 companion — catalog KPI 1280px wrap sentinel (already healed A-009/NEW-R4-04)', () => {
    // Live-verified 2026-06-12 on :8770 @1280x900: "INDISPONIBLES" wraps
    // hyphenated (clientH 40, scrollW == clientW, no clipping). The refuter's
    // truncation repro was on a branch lacking this CSS. Lock the heal so a
    // style refactor cannot silently reintroduce mid-word clipping.
    it('ItemListComponent metric <small> CSS keeps the wrap heal', () => {
        const itemSrc = fs.readFileSync(
            path.join(REPO_ROOT, 'resources/js/components/admin/items/ItemListComponent.vue'),
            'utf-8'
        );
        const smallBlock = itemSrc.match(
            /\.catalog-control-plane__metric small \{[\s\S]*?\}/
        );
        expect(smallBlock).not.toBeNull();
        expect(smallBlock[0]).toContain('white-space: normal');
        expect(smallBlock[0]).toContain('overflow-wrap: break-word');
        expect(smallBlock[0]).toContain('hyphens: auto');
    });
});
