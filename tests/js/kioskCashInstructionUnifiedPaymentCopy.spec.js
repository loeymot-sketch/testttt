/**
 * [dispute-r1 C-ADV-06 + E-ADV-6 2026-06-12] — copy « Paiement en espèces
 * uniquement à la caisse. » FACTUELLEMENT FAUSSE.
 * -----------------------------------------------------------------------------
 * Round-1 adversarial : la caisse encaisse la MÊME commande borne en
 * Espèces / Terminal (manuel) SumUp / Mobile / Ticket restaurant
 * (E21-01-modal.png, mandat owner « encaissement UNIFIÉ » 2026-06-05).
 * Un client sans espèces pouvait renoncer alors que sa carte est acceptée
 * (vente perdue). Clef : kiosk.cash_instruction.help, rendue
 * inconditionnellement par KioskCashInstructionComponent.
 *
 * Invariant : la copy mentionne les modes réellement acceptés au comptoir et
 * ne contient plus « uniquement »/« only » — dans TOUTES les locales (bn/de
 * étaient des miroirs FR périmés du même texte faux).
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const locales = ['fr', 'en', 'bn', 'de'].map((code) => [
    code,
    JSON.parse(readFileSync(resolve(process.cwd(), `resources/js/languages/${code}.json`), 'utf-8')),
]);

describe('[C-ADV-06/E-ADV-6] cash_instruction.help = encaissement unifié', () => {
    it.each(locales)('%s : plus de « espèces uniquement »', (code, messages) => {
        const help = messages?.kiosk?.cash_instruction?.help || '';
        expect(help.toLowerCase()).not.toContain('uniquement');
        expect(help.toLowerCase()).not.toContain('only');
    });

    it('FR : mentionne espèces + carte + ticket restaurant', () => {
        const fr = locales.find(([c]) => c === 'fr')[1];
        const help = fr.kiosk.cash_instruction.help;
        expect(help).toMatch(/espèces/i);
        expect(help).toMatch(/carte/i);
        expect(help).toMatch(/ticket restaurant/i);
    });

    it('EN : parité (cash + card + meal voucher)', () => {
        const en = locales.find(([c]) => c === 'en')[1];
        const help = en.kiosk.cash_instruction.help;
        expect(help).toMatch(/cash/i);
        expect(help).toMatch(/card/i);
        expect(help).toMatch(/voucher/i);
    });
});
