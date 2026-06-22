import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-BLUE-2026-05-08-B2-A11Y-P3 | @source HEAL-B mission B2 A11Y P3
 * @reason
 *   LoginComponent.vue avait `autocomplete="current-password"` sur le password
 *   (post RED-R1) mais l'input email n'avait aucun attribut autocomplete. Les
 *   gestionnaires de mots de passe (1Password, Bitwarden, browser built-in)
 *   ont besoin de la paire {email, current-password} pour proposer le remplissage
 *   automatique. Sans autocomplete sur l'email, l'UX login est dégradée.
 *
 * Sentinel ferme :
 *   - l'input email (id="formEmail") porte autocomplete="email"
 *   - l'input password garde autocomplete="current-password" (anti-régression R1)
 */
describe('LoginComponent — autocomplete a11y (B2 A11Y P3)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/frontend/auth/LoginComponent.vue'),
        'utf8',
    );

    it('email input declares autocomplete="email"', () => {
        // Find the input tag bearing id="formEmail" and assert it carries autocomplete="email".
        const emailInputMatch = source.match(/<input\b[^>]*id=["']formEmail["'][^>]*>/);
        expect(emailInputMatch).not.toBeNull();
        expect(emailInputMatch[0]).toMatch(/autocomplete=["']email["']/);
    });

    it('password input keeps autocomplete="current-password" (anti-régression R1)', () => {
        const pwdInputMatch = source.match(/<input\b[^>]*id=["']formPassword["'][^>]*>/);
        expect(pwdInputMatch).not.toBeNull();
        expect(pwdInputMatch[0]).toMatch(/autocomplete=["']current-password["']/);
    });
});
