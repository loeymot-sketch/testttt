import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-BLUE-2026-05-08-B5-UX-P1 | @source HEAL-B mission B5 UX-FLOW P1
 * @reason
 *   Le POS NF525 doit tourner en français garanti. Avant le fix, detectLocale()
 *   suivait navigator.language sans contexte de surface — un navigateur configuré
 *   en EN faisait basculer la caisse en EN (cf. RED-R1 CS1 : aria-label "Add Customer"
 *   alors que les placeholders restaient FR). On force `fr` quand le pathname
 *   commence par `/admin` (POS, KDS, dashboard, etc.).
 *
 * Sentinel ferme :
 *   - le helper isAdminPath() existe dans i18n.js et match /^\/admin/
 *   - detectLocale() consomme bien isAdminPath() avant le fallback navigator.language
 *   - le retour pour les surfaces admin est `'fr'` (string littérale, pas DEFAULT_LOCALE)
 */
describe('i18n — FR forcée sur surfaces admin (B5 UX-FLOW P1)', () => {
    const i18nSource = readFileSync(resolve(process.cwd(), 'resources/js/i18n.js'), 'utf8');

    it('declares an isAdminPath() helper that matches /^\\/admin/', () => {
        expect(i18nSource).toMatch(/function\s+isAdminPath\s*\(/);
        expect(i18nSource).toMatch(/\/\^\\\/admin\//);
    });

    it('detectLocale() invokes isAdminPath() before reading navigator.language', () => {
        const detectMatch = i18nSource.match(/function\s+detectLocale\s*\([^)]*\)\s*\{([\s\S]*?)\n\}/);
        expect(detectMatch).not.toBeNull();
        const body = detectMatch[1];

        const adminIdx = body.indexOf('isAdminPath');
        const navIdx   = body.indexOf('navigator.language');
        expect(adminIdx).toBeGreaterThan(-1);
        expect(navIdx).toBeGreaterThan(-1);
        expect(adminIdx).toBeLessThan(navIdx);
    });

    it('detectLocale() returns the literal string "fr" for admin surfaces (NF525)', () => {
        // Match the admin branch and assert the literal return value.
        const detectMatch = i18nSource.match(/function\s+detectLocale\s*\([^)]*\)\s*\{([\s\S]*?)\n\}/);
        const body = detectMatch[1];
        // Look for `if (isAdminPath()) { return 'fr'; }` (any whitespace, single or double quotes).
        expect(body).toMatch(/isAdminPath\s*\(\s*\)\s*\)\s*\{\s*return\s*['"]fr['"]\s*;?\s*\}/);
    });
});
