import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import { formatPhoneFr } from '../../resources/js/helpers/formatPhoneFr';

/**
 * [W-REM T-R2.3 Q-8/D-B3-03 2026-06-12] Téléphones FR.
 *
 * Finding D-B3-03 : concat naïve `country_code + phone` → "+330600000003"
 * (garde le 0 national → international invalide) et incohérences (numéros
 * sans préfixe). Heal : helper partagé `formatPhoneFr` — FR rendu national
 * groupé par 2 ("06 00 00 00 03"), non-FR rendu international propre
 * (indicatif + espace, 0 national retiré).
 *
 * Périmètre R2 (CENTRAL) : administrators, chefs, employees, waiters,
 * deliveryBoys, customers, creditBalanceReport, onlineOrders.
 * PosOrderShowComponent = voie caisse → exclu (documenté rapport R2).
 */

const REPO_ROOT = path.resolve(__dirname, '../..');

describe('formatPhoneFr unit contract', () => {
    it('renders the D-B3-03 case "+33" + "0600000003" as national grouped', () => {
        expect(formatPhoneFr('+33', '0600000003')).toBe('06 00 00 00 03');
    });

    it('renders 9-digit international remainder with the national 0 restored', () => {
        expect(formatPhoneFr('+33', '600000003')).toBe('06 00 00 00 03');
    });

    it('renders a bare national number without prefix ("0603025505")', () => {
        expect(formatPhoneFr('', '0603025505')).toBe('06 03 02 55 05');
    });

    it('renders +33-prefixed phone value itself correctly', () => {
        expect(formatPhoneFr('', '+330600000003')).toBe('06 00 00 00 03');
    });

    it('non-FR country codes render international with space, national 0 dropped', () => {
        expect(formatPhoneFr('+32', '0471234567')).toBe('+32 471234567');
    });

    it('empty/missing phone renders empty string (no "+33undefined")', () => {
        expect(formatPhoneFr('+33', '')).toBe('');
        expect(formatPhoneFr('+33', null)).toBe('');
        expect(formatPhoneFr(undefined, undefined)).toBe('');
    });

    it('never glues the country code to a 0-leading national number', () => {
        const out = formatPhoneFr('+33', '0600000003');
        expect(out).not.toContain('+330');
    });
});

describe('no naive country_code+phone concat left in the R2 CENTRAL scope', () => {
    const SCOPE_DIRS = [
        'resources/js/components/admin/administrators',
        'resources/js/components/admin/chefs',
        'resources/js/components/admin/employees',
        'resources/js/components/admin/waiters',
        'resources/js/components/admin/deliveryBoys',
        'resources/js/components/admin/customers',
        'resources/js/components/admin/creditBalanceReport',
        'resources/js/components/admin/onlineOrders',
    ];

    it('scope files render phones via formatPhoneFr', () => {
        const offenders = [];
        for (const dir of SCOPE_DIRS) {
            const abs = path.join(REPO_ROOT, dir);
            if (!fs.existsSync(abs)) continue;
            for (const entry of fs.readdirSync(abs)) {
                if (!entry.endsWith('.vue')) continue;
                const src = fs.readFileSync(path.join(abs, entry), 'utf-8');
                if (src.includes(".country_code || '') +")) {
                    offenders.push(`${dir}/${entry}`);
                }
            }
        }
        expect(offenders).toEqual([]);
    });
});
