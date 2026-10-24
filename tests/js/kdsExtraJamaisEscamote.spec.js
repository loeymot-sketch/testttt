import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import KdsOrderLine from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue';
import { renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';

/**
 * [FIX-1 2026-08-25 · P0 cuisine] UN EXTRA FACTURÉ NE DISPARAÎT JAMAIS DE L'ÉCRAN V2.
 *
 * Le correctif du 2026-08-24 (35c53efca) a réparé le gabarit KDS HÉRITÉ
 * (`kdsExtraDisplayName` : repli « Supplément », ne rend jamais vide). Le gabarit
 * de PRODUCTION est la V2 symbolique, et elle avait DEUX trous distincts, tous deux
 * silencieux — aucune ligne, aucun marqueur, juste du blanc sous la carte :
 *
 *   TROU A — `kdsSymbolic.js:459` : `const name = extraName(e); if (!name) continue;`
 *            Un extra dont l'entrée ne porte AUCUN champ de nom (forme brute
 *            `{"id":269,"quantity":1}`) était purement et simplement sauté.
 *   TROU B — `renderItemSymbolic()` : la branche « conteneur de menu »
 *            (`isMenuItem`) retournait tôt, avec la boisson et la note, mais SANS
 *            AUCUN supplément — un cheddar facturé sur une ligne « Menu (Frites +
 *            Boisson) » n'atteignait pas le cuisinier.
 *
 * POURQUOI PERSONNE NE L'AVAIT VU : le test de garde cherchait le motif hérité
 * « Extras: , , , ». La V2 n'émet aucun libellé « Extras: », donc aucune virgule
 * vide à trouver — chercher l'ABSENCE d'un symptôme ne prouve rien. Ce spec
 * vérifie donc une PRÉSENCE, et il la vérifie sur le DOM réellement peint par
 * <KdsOrderLine>, pas seulement sur le tableau de lignes.
 */

const stubs = { mocks: { $t: (k) => k } };

/** Texte RÉELLEMENT peint par la carte cuisine, ligne par ligne. */
function texteRendu(item) {
    return renderItemSymbolic(item).lines.map((line) =>
        mount(KdsOrderLine, { props: { line }, global: stubs }).text(),
    );
}

function lignesSupplement(item) {
    return renderItemSymbolic(item).lines.filter((l) => l.type === 'supplement');
}

describe('KDS V2 — un extra facturé est toujours visible par le cuisinier', () => {
    it('TROU A : un extra sans AUCUN champ de nom est ANNONCÉ, pas escamoté', () => {
        // Forme réellement présente en base sur la colonne brute `item_extras`
        // (ex. ligne #5904 : [{"id":269,"quantity":1}, …]) — servie dès que
        // l'instantané NF525 ne porte pas d'extras (KDSOrderItemsResource:81-88).
        const item = {
            item_name: 'Cayenne',
            quantity: 1,
            item_extras: [{ id: 269, quantity: 1, unit_price: 0.9, line_total: 0.9 }],
        };

        const supps = lignesSupplement(item);
        expect(supps).toHaveLength(1);
        expect(supps[0].label).toContain('Supplément');

        // Et le cuisinier le VOIT : la ligne est peinte, marquée ⭐ comme les autres.
        expect(texteRendu(item).join(' | ')).toMatch(/Supplément/);
    });

    it('TROU A : la QUANTITÉ d\'un extra anonyme survit (2 cheddars ≠ 1)', () => {
        const supps = lignesSupplement({
            item_name: 'Cayenne',
            quantity: 1,
            composition_snapshot: {
                lines: [],
                addons: [],
                extras: [{ extra_id: 269, quantity: 2, unit_price: 0.9, line_total: 1.8 }],
            },
        });
        expect(supps).toHaveLength(1);
        expect(supps[0].label).toMatch(/×\s?2/);
    });

    it('TROU A : un extra NOMMÉ et un extra ANONYME coexistent — deux lignes, aucune perdue', () => {
        const supps = lignesSupplement({
            item_name: 'Cayenne',
            quantity: 1,
            composition_snapshot: {
                lines: [],
                addons: [],
                extras: [
                    { extra_id: 53, extra_name: 'Cheddar', quantity: 1, unit_price: 0.9, line_total: 0.9 },
                    { extra_id: 269, quantity: 1, unit_price: 0.9, line_total: 0.9 },
                ],
            },
        });
        expect(supps).toHaveLength(2);
        expect(supps.map((l) => l.label).join(' ')).toContain('Cheddar');
        expect(supps.map((l) => l.label).join(' ')).toContain('Supplément');
    });

    it('TROU B : un supplément payé sur un CONTENEUR DE MENU atteint la cuisine', () => {
        // Constat E-002 du superviseur : carte « 1 × MENU » + boisson + note,
        // et le cheddar facturé nulle part — ~150 px de blanc à sa place.
        const item = {
            item_name: 'Menu (Frites + Boisson)',
            quantity: 1,
            composition_snapshot: {
                lines: [],
                addons: [{ role: 'menu_boisson', addon_name: 'Coca-Cola 33cl', quantity: 1 }],
                extras: [{ extra_id: 53, extra_name: 'Cheddar', quantity: 2, unit_price: 0.9, line_total: 1.8 }],
            },
        };

        const rendu = texteRendu(item).join(' | ');
        expect(rendu).toMatch(/MENU/);
        expect(rendu).toMatch(/Coca-Cola 33cl/);
        expect(rendu).toMatch(/Cheddar/); // ← ce que la V2 perdait
    });

    it('TROU B : sur un conteneur de menu, l\'extra ANONYME est aussi annoncé', () => {
        const rendu = texteRendu({
            item_name: 'Formule Midi',
            quantity: 1,
            item_extras: [{ id: 269, quantity: 1, unit_price: 0.9, line_total: 0.9 }],
        }).join(' | ');
        expect(rendu).toMatch(/Supplément/);
    });

    it('NON-RÉGRESSION : une garniture GRATUITE nommée reste repliée en symbole, pas en supplément', () => {
        const item = {
            item_name: 'Cayenne',
            quantity: 1,
            composition_snapshot: {
                lines: [],
                addons: [],
                extras: [
                    { extra_id: 49, extra_name: 'Salade', quantity: 1, unit_price: 0, line_total: 0 },
                    { extra_id: 50, extra_name: 'Tomate', quantity: 1, unit_price: 0, line_total: 0 },
                ],
            },
        };
        expect(lignesSupplement(item)).toHaveLength(0);
        expect(texteRendu(item)[0]).toContain('ST');
    });
});
