/**
 * [UIUX-W4 K6 2026-06-11] — Loyalty borne : 429 brut + a11y boutons.
 *
 *  - Le throttle Laravel renvoyait « Too Many Attempts. » (EN brut) affiché
 *    tel quel au client borne → message FR dédié sur status 429 (vérif code
 *    ET inscription).
 *  - Bouton retour SVG-only et touche « del » du numpad sans nom accessible
 *    → aria-label FR.
 *
 * Vérification source-level (pattern kioskIdleWarningEvent.spec.js — le
 * composant est trop couplé store/axios pour un mount léger) + clés i18n
 * présentes dans les 5 locales.
 */
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';
import ar from '../../resources/js/languages/ar.json';
import de from '../../resources/js/languages/de.json';
import bn from '../../resources/js/languages/bn.json';

const SRC = fs.readFileSync(
    path.join(
        __dirname,
        '../../resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue',
    ),
    'utf8',
);

describe('Kiosk loyalty 429 + a11y (K6)', () => {
    it('maps HTTP 429 to the FR too_many_attempts message (lookup + register)', () => {
        const matches = SRC.match(/status === 429/g) || [];
        expect(matches.length).toBeGreaterThanOrEqual(2);
        expect(SRC).toContain("$t('kiosk.loyalty_screen.too_many_attempts')");
    });

    it('gives the SVG-only back button an accessible FR name', () => {
        expect(SRC).toMatch(/kiosk-back-btn"[^>]*:aria-label="\$t\('kiosk\.loyalty_screen\.back'\)"/);
    });

    it('gives the numpad "del" key an accessible FR name', () => {
        expect(SRC).toContain("key === 'del' ? $t('kiosk.loyalty_screen.numpad_del_aria') : null");
    });

    it('defines the new keys in the 5 locales', () => {
        for (const [code, messages] of Object.entries({ fr, en, ar, de, bn })) {
            expect(messages.kiosk?.loyalty_screen?.too_many_attempts, `${code} too_many_attempts`).toBeTruthy();
            expect(messages.kiosk?.loyalty_screen?.numpad_del_aria, `${code} numpad_del_aria`).toBeTruthy();
        }
        expect(fr.kiosk.loyalty_screen.too_many_attempts).toMatch(/Trop de tentatives/);
        expect(en.kiosk.loyalty_screen.too_many_attempts).not.toBe(
            fr.kiosk.loyalty_screen.too_many_attempts,
        );
    });
});
