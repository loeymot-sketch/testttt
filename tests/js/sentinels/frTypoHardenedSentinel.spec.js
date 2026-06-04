import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * frTypoHardenedSentinel.spec.js — J2-HEAL-05 2026-05-24
 *
 * Locks fr.json against the introduction of typo variants of "Choisissez"
 * surfaced by Phase J-ADV-8 visual/UX hostile audit (UX-08).
 *
 * Audit verdict reconciliation:
 *   - J-ADV-8 UX-08 reported a customer-facing typo "Cholsissez 1 viande"
 *     visible on the kiosk wizard step 1 (Tacos viande) screenshot.
 *   - Source investigation (J2-HEAL-05) confirmed: resources/js/languages/
 *     fr.json line 1868 contains the canonical correct spelling
 *     "Choisissez 1 viande : touchez celle que vous voulez."
 *   - The same correctly-spelled string is present in bn.json:1549 and
 *     de.json:1549 (FR fallback copies).
 *   - The compiled bundles public/js/app.js, public/js/pos-app.js do NOT
 *     contain any occurrence of "Cholsissez".
 *   - The string "Cholsissez" exists in exactly one place on disk: the
 *     audit findings file itself.
 *   - The DOM render path KioskStepViandeComponent.vue:185 calls
 *     $t('kiosk.wizard.step.viande.instruction_one') → resolves to the
 *     correct string in fr.json.
 *
 * Root cause of the audit finding: visual misread of "Choi" as "Chol" in
 * the H7 W1-state3 JPEG at 1200x834 — the lowercase "i" body without a
 * clearly distinct dot can scan visually as "l" at this pixel density.
 * NO customer is seeing the string "Cholsissez" — they are seeing
 * "Choisissez" rendered in a font where lowercase i can be visually
 * confused at small sizes.
 *
 * Therefore J2-HEAL-05 ships NO source-text edit (there is nothing to
 * fix). This sentinel is honest defensive hardening: if a future change
 * EVER introduces "Cholsissez" or a known typo variant into fr.json, the
 * sentinel fails. The canonical "Choisissez" must remain present.
 *
 * @FK-ID J2-HEAL-05
 * @source Phase J-ADV-8 UX-08 P1 (false-positive reconciliation)
 */
describe('fr.json — typo regression lock (J2-HEAL-05 / J-ADV-8 UX-08)', () => {
    const frJsonPath = resolve(
        process.cwd(),
        'resources/js/languages/fr.json',
    );
    const fr = readFileSync(frJsonPath, 'utf8');

    it('does NOT contain the typo "Cholsissez" (J-ADV-8 UX-08 false-positive lock)', () => {
        expect(fr).not.toMatch(/Cholsissez/i);
    });

    it('does NOT contain known French typo variants of "Choisissez"', () => {
        // Documented variants the original audit listed as candidates to grep
        // (Cholsi*, Cholisez), plus the most common misspellings of the verb
        // "choisir" stem that customers might encounter in QA reviews.
        const knownTypos = [
            /Cholsi/i,
            /Cholisez/i,
            /Choississez/i, // doubled s
            /Choissisez/i,  // transposed
            /Choisisez/i,   // missing s
        ];
        for (const re of knownTypos) {
            expect(fr).not.toMatch(re);
        }
    });

    it('DOES contain the canonical "Choisissez" (positive presence check)', () => {
        // Confirms the correct spelling is present (so the sentinel can't
        // be satisfied by deleting the line entirely).
        expect(fr).toMatch(/Choisissez/);
    });

    it('DOES contain the canonical viande step instruction string', () => {
        // Bit-exact match on the canonical instruction_one value the audit
        // misread. Locks the spelling AND structural punctuation (the FR
        // narrow no-break space before ":" is part of the canonical form).
        expect(fr).toMatch(
            /"Choisissez 1 viande : touchez celle que vous voulez\."/,
        );
    });
});
