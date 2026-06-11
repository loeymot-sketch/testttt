/**
 * [UIUX-W4 K8 2026-06-11] — Promo appliquée : placeholders perdus.
 *
 * KioskCartComponent.vue:316 passe `{ code, amount }` à
 * `$t('kiosk.promo.applied', …)` mais la chaîne FR était « Code promo
 * appliqué » sans placeholder → le client borne ne voyait ni le code
 * appliqué ni le montant de la remise.
 *
 * Contrat : fr + en portent {code} et {amount}, et vue-i18n interpole
 * réellement les deux valeurs.
 */
import { describe, it, expect } from 'vitest';
import { createI18n } from 'vue-i18n';
import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';

describe('kiosk.promo.applied placeholders (K8)', () => {
    it('fr + en define the key with {code} and {amount}', () => {
        for (const [code, messages] of Object.entries({ fr, en })) {
            const msg = messages.kiosk?.promo?.applied;
            expect(msg, `${code} kiosk.promo.applied`).toBeTruthy();
            expect(msg, `${code} {code} placeholder`).toContain('{code}');
            expect(msg, `${code} {amount} placeholder`).toContain('{amount}');
        }
        expect(en.kiosk.promo.applied).not.toBe(fr.kiosk.promo.applied);
    });

    it('interpolates code + amount through vue-i18n (fr)', () => {
        const i18n = createI18n({
            legacy: false,
            locale: 'fr',
            missingWarn: false,
            fallbackWarn: false,
            messages: { fr },
        });
        const out = i18n.global.t('kiosk.promo.applied', { code: 'CAYENNE10', amount: '2,00 €' });
        expect(out).toContain('CAYENNE10');
        expect(out).toContain('2,00 €');
    });
});
