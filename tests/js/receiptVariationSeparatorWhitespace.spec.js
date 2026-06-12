import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * [DISPUTE-R1 A-RED-7 2026-06-12] — P2 ticket : « Viande 1: Poulet mariné
 * ,Sauce (1ère Gratuite): Algérienne » — espace AVANT la virgule.
 *
 * Root cause : dans ReceiptComponent.vue, le séparateur `<span>, </span>`
 * était sur sa PROPRE ligne de template après `{{ variation.name }}` — le
 * mode whitespace 'condense' de Vue transforme le retour-ligne en espace
 * rendu → « nom , » au lieu de « nom, ». Dupliqué sur le ticket cuisine
 * (séparateur « · ») et sur les blocs extras des deux tickets.
 *
 * Contrat : l'interpolation du nom et le span séparateur doivent être
 * ADJACENTS (aucun whitespace entre eux) sur les 4 occurrences
 * (variations client, extras client, variations cuisine, extras cuisine).
 */

const src = readFileSync(
  resolve(process.cwd(), 'resources/js/components/admin/pos/ReceiptComponent.vue'),
  'utf8',
);

describe('ReceiptComponent — séparateurs de listes collés au nom (A-RED-7)', () => {
  it('variation.name est immédiatement suivi du span séparateur (aucun retour-ligne)', () => {
    // Toute occurrence de {{ variation.name }} suivie d'un séparateur doit
    // être collée : `{{ variation.name }}<span`.
    const detached = src.match(/\{\{\s*variation\.name\s*\}\}\s*\n\s*<span/g);
    expect(detached).toBeNull();
    // Et il existe bien des occurrences collées (le séparateur n'a pas disparu).
    const attached = src.match(/\{\{\s*variation\.name\s*\}\}<span/g) || [];
    expect(attached.length).toBeGreaterThanOrEqual(2);
  });

  it('extra.name est immédiatement suivi du span séparateur (aucun retour-ligne)', () => {
    const detached = src.match(/\{\{\s*extra\.name\s*\}\}\s*\n\s*<span/g);
    expect(detached).toBeNull();
    const attached = src.match(/\{\{\s*extra\.name\s*\}\}<span/g) || [];
    expect(attached.length).toBeGreaterThanOrEqual(2);
  });

  it('le séparateur client reste « , » et le séparateur cuisine reste « · »', () => {
    expect(src).toMatch(/<span v-if="index \+ 1 < receiptVariationsFor\(item\)\.length">, <\/span>/);
    expect(src).toMatch(/<span v-if="index \+ 1 < receiptVariationsFor\(item\)\.length"> · <\/span>/);
  });
});
