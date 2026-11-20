/**
 * [CONSIGNES 2026-11-04 · décision propriétaire « Garder toute consigne »] Écran cuisine : une consigne tapée en
 * capitales (« PEU DE SAUCE ») n'est plus jetée ; seul l'écho du nom du produit l'est. Même table que le banc PHP
 * (tests/Unit/KitchenConsignesMajusculesTest.php) : les deux jumeaux restent alignés.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { sanitizeKdsInstruction, estEchoDuProduit } from '../../resources/js/helpers/kdsCustomization.js';

const { cas } = JSON.parse(readFileSync(resolve(__dirname, '../fixtures/consignes-majuscules-2026-11-04.json'), 'utf8'));

describe('Écran cuisine — consignes en MAJUSCULES', () => {
    for (const c of cas) {
        it(`« ${c.ligne} » sur ${c.produit} → ${c.garde ? 'GARDÉE' : 'retirée (écho)'}`, () => {
            expect(estEchoDuProduit(c.ligne, c.produit)).toBe(!c.garde);
            const sortie = sanitizeKdsInstruction(c.ligne, c.produit);
            if (c.garde) expect(sortie).toContain(c.ligne);
            else expect(sortie).toBe('');
        });
    }
});
