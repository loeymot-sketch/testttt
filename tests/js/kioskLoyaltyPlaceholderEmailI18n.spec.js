/**
 * [UIUX-W4 K2 2026-06-11] — Écran blanc inscription fidélité borne.
 *
 * `kiosk.loyalty_screen.placeholder_email` contenait un `@` NON échappé
 * (« vous@exemple.fr »). En vue-i18n v9, `@` introduit un message lié
 * (linked format) → le compilateur de message jette une SyntaxError au
 * premier `$t()` → le render de KioskLoyaltyComponent crashe → écran
 * blanc total sur la borne.
 *
 * Fix : échapper le littéral via `{'@'}` dans les 5 locales
 * (resources/js/languages/{fr,en,ar,de,bn}.json).
 *
 * Ce spec compile réellement la clé via vue-i18n pour chaque locale :
 * RED avant le fix (throw), GREEN après (rend une adresse avec « @ »).
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { createI18n } from 'vue-i18n';
import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';
import ar from '../../resources/js/languages/ar.json';
import de from '../../resources/js/languages/de.json';
import bn from '../../resources/js/languages/bn.json';

const LOCALES = { fr, en, ar, de, bn };

describe('kiosk.loyalty_screen.placeholder_email compiles in every locale (K2)', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    Object.entries(LOCALES).forEach(([code, messages]) => {
        it(`compiles + renders a literal "@" without compiler error in ${code}`, () => {
            // Le compilateur de message vue-i18n émet sa SyntaxError via
            // console.error/warn (dev build) au lieu de throw — capturer.
            const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

            const i18n = createI18n({
                legacy: false,
                locale: code,
                fallbackLocale: 'fr',
                missingWarn: false,
                fallbackWarn: false,
                messages: { [code]: messages },
            });

            let out;
            expect(() => {
                out = i18n.global.t('kiosk.loyalty_screen.placeholder_email');
            }).not.toThrow();

            const diagnostics = [...errSpy.mock.calls, ...warnSpy.mock.calls]
                .map((args) => args.map(String).join(' '))
                .filter((line) => /SyntaxError|Message compilation error|Invalid linked format/i.test(line));
            expect(diagnostics).toEqual([]);

            expect(typeof out).toBe('string');
            expect(out).toContain('@');
            // Le fix utilise {'@'} — le rendu ne doit PAS laisser fuiter
            // la syntaxe d'échappement brute.
            expect(out).not.toContain("{'@'}");
        });
    });
});
