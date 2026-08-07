import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { meatPortionsForItem, renderCuisson } from '../../resources/js/helpers/kdsSymbolic.js';

// [PARITÉ CUISSON PHP↔JS 2026-08-07] Jumeau JS du verrou de non-dérive.
// Consomme LE MÊME fichier golden que CuissonPhpJsParityFixtureTest.php
// (tests/fixtures/cuisson/parity_cases.json). Si l'écran (ce moteur JS) diverge du
// ticket/stock (moteur PHP), l'un des deux rougit. Un changement de règle = 1 seul endroit.
const FIXTURE = join(dirname(fileURLToPath(import.meta.url)), '../fixtures/cuisson/parity_cases.json');
const cases = JSON.parse(readFileSync(FIXTURE, 'utf8'));

// Miroir EXACT de kdsCuisson.spec.js::snap ET de MeatPortionCalculatorTest::snap.
const snap = (viandes, extras = []) => ({
    lines: [
        ...viandes.map((v, i) => ({ attribute_name: `Viande ${i + 1}`, variation_name: v })),
        { attribute_name: 'Sauce 1', variation_name: 'Algérienne' },
    ],
    extras,
});

const buildItem = (c) => ({
    item_name: c.item,
    quantity: c.quantity ?? 1,
    instruction: c.instruction ?? '',
    composition_snapshot: snap(c.viandes ?? [], c.extras ?? []),
});

describe('parité cuisson PHP↔JS — même fixture golden', () => {
    it.each(cases.map((c) => [c.desc || c.item, c]))('%s', (_desc, c) => {
        const r = meatPortionsForItem(buildItem(c));
        const txt = renderCuisson(r.pieces, r.inconnu ? (c.quantity ?? 1) : 0);
        expect(txt).toBe(c.expected);
    });
});
