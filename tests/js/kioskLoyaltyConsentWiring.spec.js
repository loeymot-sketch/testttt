/**
 * Kiosk Phase 9.1.10 — Regression guard on the consent modal event contract.
 *
 * Le fichier source de `KsConsentModal` émet `accepted` / `declined` (past
 * tense) via `emits: [...]`. Avant ce fix, `KioskLoyaltyComponent` écoutait
 * `@accept` / `@decline` (infinitif), donc :
 *  - `onConsentAccept` n'était jamais appelé → `submitRegister` ne terminait
 *    pas → la modale semblait "accepter" mais aucun POST /loyalty/register
 *    n'était émis (RGPD opt-in broken).
 *  - Symétrie : `onConsentDecline` n'était jamais appelé → la modale restait
 *    ouverte sur décline, bloquant l'utilisateur.
 *
 * Ce test fige le contrat :
 *  1. KsConsentModal.vue déclare dans `emits` : `accepted` + `declined`.
 *  2. KioskLoyaltyComponent.vue écoute `@accepted` + `@declined` (pas
 *     l'infinitif) sur <KsConsentModal>.
 *  3. KioskLoyaltyComponent.vue N'a PAS de listener `@accept` / `@decline`
 *     sur un `<KsConsentModal>` (garde contre un revert muet).
 */
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const readSfc = (rel) =>
    fs.readFileSync(path.join(__dirname, '..', '..', rel), 'utf8');

describe('Kiosk Phase 9.1.10 — loyalty consent event wiring', () => {
    it('KsConsentModal.vue déclare emits: accepted + declined', () => {
        const src = readSfc(
            'resources/js/components/frontend/kiosk/ds/KsConsentModal.vue',
        );
        // `emits: [...]` contient `'accepted'` ET `'declined'`.
        expect(src).toMatch(/emits:\s*\[[^\]]*['"]accepted['"][^\]]*\]/);
        expect(src).toMatch(/emits:\s*\[[^\]]*['"]declined['"][^\]]*\]/);
    });

    it('KioskLoyaltyComponent écoute @accepted / @declined (past tense)', () => {
        const src = readSfc(
            'resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue',
        );
        expect(src).toMatch(/<KsConsentModal[\s\S]*?@accepted=/);
        expect(src).toMatch(/<KsConsentModal[\s\S]*?@declined=/);
    });

    it('KioskLoyaltyComponent n\'écoute PAS @accept / @decline (infinitif)', () => {
        const src = readSfc(
            'resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue',
        );
        // On se limite à la zone <template> autour de <KsConsentModal>.
        // Cherche explicitement une liaison template shorthand `@accept=` ou
        // `@decline=` (pas un commentaire/doc).
        const tpl = src.split('</template>')[0];
        expect(tpl).not.toMatch(/@accept=\s*["']/);
        expect(tpl).not.toMatch(/@decline=\s*["']/);
    });
});
