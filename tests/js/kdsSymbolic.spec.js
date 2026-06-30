import { describe, expect, it } from 'vitest';

import {
    meatSymbol,
    sauceSymbol,
    cruditeSymbol,
    supportSymbol,
    symbolicMainLine,
    renderItemSymbolic,
} from '../../resources/js/helpers/kdsSymbolic.js';

// [KITCHEN-SYMBOLS 2026-06-28] Owner spec — the kitchen screen (KDS) and the
// printed kitchen ticket must show a SHORT symbolic composition so the cook
// reads it instantly. Line 1 = [Support] | [Produit] | [Taille] | [Viande(s)]
// | [Crudités] | [Sauce(s)], empty slots omitted, separated by " | ".
// These tests pin the owner's exact symbol table + examples.

describe('symbol mappers — owner table', () => {
    it('maps meats', () => {
        expect(meatSymbol('Viande Hachée')).toBe('K');
        expect(meatSymbol('Steak')).toBe('K');
        expect(meatSymbol('Poulet mariné')).toBe('P');
        expect(meatSymbol('Tenders')).toBe('Tender');
        expect(meatSymbol('Nuggets')).toBe('Nug');
        expect(meatSymbol('Mexicanos')).toBe('Mex');
        expect(meatSymbol('Fricadelle')).toBe('Frec');
        expect(meatSymbol('Cordon Bleu')).toBe('Cordon');
    });
    it('maps sauces', () => {
        expect(sauceSymbol('Mayonnaise')).toBe('MAY');
        expect(sauceSymbol('Samouraï')).toBe('SAM');
        expect(sauceSymbol('Hannibal')).toBe('HAN');
        expect(sauceSymbol('Curry')).toBe('CURY');
        expect(sauceSymbol('Andalouse')).toBe('AND');
        expect(sauceSymbol('Blanche')).toBe('BL');
        expect(sauceSymbol('Ketchup')).toBe('KTP');
        expect(sauceSymbol('Sauce Burger')).toBe('Burg');
    });
    it('falls back to a 3-letter uppercase code for unlisted sauces', () => {
        expect(sauceSymbol('Algérienne')).toBe('ALG');
        expect(sauceSymbol('Harissa')).toBe('HAR');
    });
    it('maps crudités', () => {
        expect(cruditeSymbol('Salade')).toBe('S');
        expect(cruditeSymbol('Tomate')).toBe('T');
        expect(cruditeSymbol('Oignon')).toBe('O');
        expect(cruditeSymbol('Cheddar')).toBe(''); // not a crudité
    });
    it('maps support', () => {
        expect(supportSymbol('Galette')).toBe('G');
        expect(supportSymbol('Pain')).toBe('S');
    });
});

describe('symbolicMainLine — owner examples', () => {
    it('sandwich galette / poulet / salade tomate oignon / samouraï → "G | SANDWICH | P | STO | SAM"', () => {
        const item = {
            item_name: 'Sandwich',
            composition_snapshot: {
                lines: [
                    { attribute_name: 'Pain', variation_name: 'Galette' },
                    { attribute_name: 'Viande 1', variation_name: 'Poulet mariné' },
                    { attribute_name: 'Sauce', variation_name: 'Samouraï' },
                ],
                extras: [
                    { extra_name: 'Salade' },
                    { extra_name: 'Tomate' },
                    { extra_name: 'Oignon' },
                ],
            },
        };
        expect(symbolicMainLine(item)).toBe('G | SANDWICH | P | STO | SAM');
    });

    it('tacos M / viande hachée / mayonnaise (no crudités) → "G | TACOS | M | K | MAY"', () => {
        const item = {
            item_name: 'Tacos M',
            composition_snapshot: {
                lines: [
                    { attribute_name: 'Viande 1', variation_name: 'Viande Hachée' },
                    { attribute_name: 'Sauce', variation_name: 'Mayonnaise' },
                ],
            },
        };
        expect(symbolicMainLine(item)).toBe('G | TACOS | M | K | MAY');
    });

    it('crudités are concatenated in canonical S,T,O order regardless of input order', () => {
        const item = {
            item_name: 'Sandwich',
            composition_snapshot: {
                lines: [{ attribute_name: 'Sauce', variation_name: 'Blanche' }],
                extras: [
                    { extra_name: 'Oignon' },
                    { extra_name: 'Salade' },
                ],
            },
        };
        // support omitted (no pain choice, not tacos), no taille, no viande
        expect(symbolicMainLine(item)).toBe('SANDWICH | SO | BL');
    });

    it('two meats (Tacos L) are space-joined', () => {
        const item = {
            item_name: 'Tacos L',
            composition_snapshot: {
                lines: [
                    { attribute_name: 'Viande 1', variation_name: 'Viande Hachée' },
                    { attribute_name: 'Viande 2', variation_name: 'Poulet mariné' },
                    { attribute_name: 'Sauce', variation_name: 'Curry' },
                ],
            },
        };
        expect(symbolicMainLine(item)).toBe('G | TACOS | L | K P | CURY');
    });

    it('never drops a meat when attribute_name is null (malformed snapshot)', () => {
        const item = {
            item_name: 'Tacos M',
            composition_snapshot: {
                lines: [
                    { attribute_name: null, variation_name: 'Poulet mariné' },
                    { attribute_name: 'Sauce', variation_name: 'Mayonnaise' },
                ],
            },
        };
        // The meat must still surface (P), not vanish.
        expect(symbolicMainLine(item)).toBe('G | TACOS | M | P | MAY');
    });

    it('a drink renders just the product name (no slots)', () => {
        expect(symbolicMainLine({ item_name: 'Coca 33cl' })).toBe('COCA 33CL');
    });
});

describe('renderItemSymbolic — line list for the KDS card', () => {
    it('emits symbolic-main, MENU, supplements, then allergen (owner order)', () => {
        const item = {
            item_name: 'Tacos M',
            quantity: 2,
            composition_snapshot: {
                lines: [
                    { attribute_name: 'Viande 1', variation_name: 'Viande Hachée' },
                    { attribute_name: 'Sauce', variation_name: 'Samouraï' },
                ],
                extras: [{ extra_name: 'Cheddar' }],
                addons: [{ addon_name: 'Frites Moyennes', role: 'menu_frites' }],
            },
            allergens_snapshot: ['gluten'],
        };
        const out = renderItemSymbolic(item);
        const types = out.lines.map((l) => l.type);
        expect(types).toEqual([
            'symbolic-main',
            'symbolic-menu',
            'supplement',
            'allergen',
        ]);
        // Menu enrichi de la sauce frites en symbole quand dispo (ici pas d'instruction → MENU).
        expect(out.lines[1]).toMatchObject({ type: 'symbolic-menu', label: 'MENU' });
        expect(out.lines[0]).toMatchObject({
            type: 'symbolic-main',
            qty: 2,
            label: 'G | TACOS | M | K | SAM',
            hasAllergen: true,
        });
        expect(out.lines[2]).toMatchObject({ type: 'supplement', label: '+ Cheddar' });
        expect(out.hasAllergen).toBe(true);
    });

    it('a lone frites add-on (no formule) emits a "F" line', () => {
        const item = {
            item_name: 'Sandwich',
            quantity: 1,
            composition_snapshot: {
                lines: [{ attribute_name: 'Viande 1', variation_name: 'Poulet mariné' }],
                addons: [{ addon_name: 'Frites Moyennes', role: null }],
            },
        };
        const menuLine = renderItemSymbolic(item).lines.find((l) => l.type === 'symbolic-menu');
        expect(menuLine).toMatchObject({ type: 'symbolic-menu', label: 'F' });
    });

    it('a paid extra named like a crudité (Oignons frits 0,90) is a supplement, not folded', () => {
        const item = {
            item_name: 'Sandwich',
            quantity: 1,
            composition_snapshot: {
                lines: [{ attribute_name: 'Sauce', variation_name: 'Mayonnaise' }],
                extras: [
                    { extra_name: 'Salade', unit_price: 0 },
                    { extra_name: 'Oignon', unit_price: 0 },
                    { extra_name: 'Oignons frits', unit_price: 0.9 },
                ],
            },
        };
        const out = renderItemSymbolic(item);
        // crudités = SO (free only); Oignons frits stays a paid supplement line.
        expect(out.lines[0].label).toBe('SANDWICH | SO | MAY');
        const sup = out.lines.filter((l) => l.type === 'supplement').map((l) => l.label);
        expect(sup).toEqual(['+ Oignons frits']);
    });

    it('a menu/formule item renders just "MENU : <sauce frites symbol>" (no price, no verbose)', () => {
        const menuItem = {
            item_name: 'Menu (Frites + Boisson)',
            quantity: 1,
            instruction: 'Menu (Frites + Boisson)\n↳ Sauce frites: Andalouse',
            composition_snapshot: { lines: [] },
        };
        const out = renderItemSymbolic(menuItem);
        expect(out.lines[0]).toMatchObject({ type: 'symbolic-main', label: 'MENU : AND' });
        // no supplement / no extra verbose line
        expect(out.lines.filter((l) => l.type === 'supplement')).toHaveLength(0);
    });

    it('crudité extras never leak into the supplement lines', () => {
        const item = {
            item_name: 'Sandwich',
            quantity: 1,
            composition_snapshot: {
                lines: [{ attribute_name: 'Viande 1', variation_name: 'Poulet mariné' }],
                extras: [{ extra_name: 'Salade' }, { extra_name: 'Cheddar' }],
            },
        };
        const out = renderItemSymbolic(item);
        const supplements = out.lines.filter((l) => l.type === 'supplement');
        expect(supplements).toHaveLength(1);
        expect(supplements[0].label).toBe('+ Cheddar');
        expect(out.lines[0].label).toContain('S'); // Salade folded into crudités slot
    });
});
