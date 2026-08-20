import { describe, expect, it } from 'vitest';

import { displayItemName, symbolicMainLine } from '../../resources/js/helpers/kdsSymbolic.js';

/**
 * [UBER TITRE ENTIER 2026-08-20 · owner] « chaque fois ça donne ART ! article non mappé !! alors
 * vaut mieux l'afficher entièrement ! au lieu de distraire l'équipe ».
 *
 * Une ligne Uber que la carte n'a pas reconnue est ancrée sur l'article technique
 * « Article Uber (non mappé) ». Le KDS lit `item_name` → produitCode() → « ART », un code qui ne
 * désigne RIEN : le cuisinier ne sait pas quoi préparer, et il perd du temps à chercher.
 *
 * Le titre du ticket doit donc s'écrire EN ENTIER à la place du code — et LUI SEUL. Le reste de
 * la ligne reste en symboles, consigne owner : « faut juste les mots technique comme la caisse ».
 *
 * Jumeau STRICT du PHP KitchenTicketSymbolicFormatter::mainLine() — écran et papier doivent dire
 * le même mot, sinon on retombe sur l'écart aperçu↔cuisine de 2026-08-12.
 */
describe('KDS — ligne Uber non reconnue', () => {
    const PLACEHOLDER = 'Article Uber (non mappé)';

    it('écrit le titre du ticket EN ENTIER au lieu du code « ART »', () => {
        const ligne = symbolicMainLine({
            item_name: PLACEHOLDER,
            composition_snapshot: {
                uber_unmapped: true,
                uber_title: 'Tacos Menu M 1 viande',
                lines: [],
                extras: [],
                addons: [],
            },
        });

        expect(ligne).toContain('TACOS MENU M 1 VIANDE');
        expect(ligne).not.toContain('ART');
    });

    it('garde les options en SYMBOLES — jamais les mots « sauce », « crudité », « boisson »', () => {
        const ligne = symbolicMainLine({
            item_name: PLACEHOLDER,
            composition_snapshot: {
                uber_unmapped: true,
                uber_title: 'Produit Inconnu',
                lines: [
                    { attribute_name: 'Viande 1', variation_name: 'Poulet' },
                    { attribute_name: 'Sauce 1', variation_name: 'Algérienne' },
                ],
                // Prix ZÉRO → crudité gratuite, repliée dans le slot crudités (« ST »).
                extras: [
                    { extra_name: 'Salade', quantity: 1, unit_price: 0, line_total: 0 },
                    { extra_name: 'Tomate', quantity: 1, unit_price: 0, line_total: 0 },
                ],
                addons: [],
            },
        });

        expect(ligne).toContain('PRODUIT INCONNU');
        for (const mot of ['SAUCE', 'CRUDIT', 'BOISSON', 'VIANDE']) {
            expect(ligne).not.toContain(mot);
        }
        // Les codes techniques de la caisse, eux, sont bien là.
        expect(ligne).toContain('P');   // Poulet
        expect(ligne).toContain('ALG'); // Algérienne
        expect(ligne).toContain('ST');  // Salade + Tomate
    });

    it('pose le support G quand le titre du ticket dit « tacos » (le nom du bouche-trou, lui, ne le dit pas)', () => {
        const ligne = symbolicMainLine({
            item_name: PLACEHOLDER,
            composition_snapshot: {
                uber_unmapped: true,
                uber_title: 'Tacos Inconnu',
                lines: [],
                extras: [],
                addons: [],
            },
        });

        expect(ligne.startsWith('G | ')).toBe(true);
    });

    it('lit aussi le champ dédié du tableau items (KDSOrderItemsResource n’expose pas le snapshot entier)', () => {
        const ligne = symbolicMainLine({
            item_name: PLACEHOLDER,
            uber_unmapped_title: 'Produit Qui N Existe Pas',
            item_variations: [],
            item_extras: [],
        });

        expect(ligne).toContain('PRODUIT QUI N EXISTE PAS');
        expect(ligne).not.toContain('ART');
    });

    it('ne touche PAS une ligne reconnue : elle garde le code court de la caisse', () => {
        const ligne = symbolicMainLine({
            item_name: 'Tacos M',
            composition_snapshot: {
                uber_unmapped: false,
                uber_title: 'Menu Tacos M',
                lines: [{ attribute_name: 'Viande 1', variation_name: 'Poulet' }],
                extras: [],
                addons: [],
            },
        });

        expect(ligne).toBe('G | TAC | P');
    });

    // Le tableau des items du KDS écrit le nom du produit EN TOUTES LETTRES (<h5>) : c'est là que
    // l'owner lisait littéralement « article non mappé ».
    it('le tableau des items n’écrit plus « Article Uber (non mappé) »', () => {
        const item = { item_name: PLACEHOLDER, uber_unmapped_title: 'Tacos Menu M 1 viande' };

        expect(displayItemName(item)).toBe('Tacos Menu M 1 viande');
        expect(displayItemName(item)).not.toContain('non mappé');
    });

    it('displayItemName laisse tout produit ordinaire intact', () => {
        expect(displayItemName({ item_name: 'Galette Cayenne' })).toBe('Galette Cayenne');
        expect(displayItemName({})).toBe('');
    });

    it('ne se déclenche jamais sur une commande ordinaire (aucun champ Uber)', () => {
        const ligne = symbolicMainLine({
            item_name: 'Tacos M',
            composition_snapshot: { lines: [], extras: [], addons: [] },
        });

        expect(ligne).toBe('G | TAC');
    });
});
