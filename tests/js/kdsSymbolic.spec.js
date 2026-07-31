import { describe, expect, it } from 'vitest';

import {
    meatSymbol,
    sauceSymbol,
    cruditeSymbol,
    supportSymbol,
    symbolicMainLine,
    renderItemSymbolic,
    fritesSauceSymbol,
    buildSymbolic,
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
        // [OWNER 2026-07-31] Cayenne « Mixte » = hachée + poulet → les 2 lettres, poulet en tête « P K »
        // (avant : « K » seul). Parité stricte avec KitchenTicketSymbolicFormatter (PHP).
        expect(meatSymbol('Mixte (hachée + poulet)')).toBe('P K');
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

describe('couverture EXHAUSTIVE du vrai menu (parité avec le ticket imprimé)', () => {
    it('chaque viande/sauce/crudité/support du menu → symbole attendu', () => {
        const meats = { Mexicanos: 'Mex', 'Cordon Bleu': 'Cordon', 'Viande Hachée': 'K', Nuggets: 'Nug', Tenders: 'Tender', Fricadelle: 'Frec', 'Poulet mariné': 'P' };
        Object.entries(meats).forEach(([n, s]) => expect(meatSymbol(n)).toBe(s));
        const sauces = { Mayonnaise: 'MAY', Ketchup: 'KTP', Blanche: 'BL', Hannibal: 'HAN', 'Samouraï': 'SAM', 'Algérienne': 'ALG', Andalouse: 'AND', Curry: 'CURY', Barbecue: 'BBQ', Harissa: 'HAR', 'Fromagère maison': 'FRO', 'Spicy maison': 'SPI' };
        const seen = new Set();
        Object.entries(sauces).forEach(([n, s]) => { expect(sauceSymbol(n)).toBe(s); seen.add(s); });
        expect(seen.size).toBe(12); // 12 symboles distincts
        expect(cruditeSymbol('Salade')).toBe('S');
        expect(cruditeSymbol('Tomate')).toBe('T');
        expect(cruditeSymbol('Oignon')).toBe('O');
        expect(supportSymbol('Pain')).toBe('S');
        expect(supportSymbol('Galette')).toBe('G');
    });
});

describe('symbolicMainLine — owner examples', () => {
    it('sandwich galette / poulet / salade tomate oignon / samouraï → "G | SAN | P | STO | SAM"', () => {
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
        expect(symbolicMainLine(item)).toBe('G | SAN | P | STO | SAM');
    });

    it('tacos / viande hachée / mayonnaise (no size, no crudités) → "G | TAC | K | MAY"', () => {
        const item = {
            item_name: 'Tacos M',
            composition_snapshot: {
                lines: [
                    { attribute_name: 'Viande 1', variation_name: 'Viande Hachée' },
                    { attribute_name: 'Sauce', variation_name: 'Mayonnaise' },
                ],
            },
        };
        // [MEGA-BORNE 2026-07-22] Un tacos ne montre PAS la taille (le nombre de viandes porte l'info).
        expect(symbolicMainLine(item)).toBe('G | TAC | K | MAY');
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
        expect(symbolicMainLine(item)).toBe('SAN | SO | BL');
    });

    it('two meats (Tacos) are space-joined and the size is dropped', () => {
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
        // [MEGA-BORNE 2026-07-22] Plus de « L » : les 2 viandes (K P) portent l'info de taille.
        expect(symbolicMainLine(item)).toBe('G | TAC | K P | CURY');
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
        // The meat must still surface (P), not vanish. [MEGA-BORNE] tacos → no size.
        expect(symbolicMainLine(item)).toBe('G | TAC | P | MAY');
    });

    it('a drink renders just the product name (no slots)', () => {
        expect(symbolicMainLine({ item_name: 'Coca 33cl' })).toBe('COC');
    });
});

describe('renderItemSymbolic — line list for the KDS card', () => {
    it('emits symbolic-main, FRITES (partial formule), supplements, then allergen (owner order)', () => {
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
        // [CLUSTER-2 2026-07-11] role menu_frites = frites SEULES (+1,50), pas la formule
        // complète → « FRITES », pas « MENU » (sinon la cuisine sert tout = fuite revenu).
        expect(out.lines[1]).toMatchObject({ type: 'symbolic-menu', label: 'FRITES' });
        expect(out.lines[0]).toMatchObject({
            type: 'symbolic-main',
            qty: 2,
            label: 'G | TAC | K | SAM',
            hasAllergen: true,
        });
        expect(out.lines[2]).toMatchObject({ type: 'supplement', label: '⭐ Cheddar' });
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
        expect(out.lines[0].label).toBe('SAN | SO | MAY');
        const sup = out.lines.filter((l) => l.type === 'supplement').map((l) => l.label);
        expect(sup).toEqual(['⭐ Oignons frits']);
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

    it('[MULTIFRITES 2026-07-18] plusieurs sauces frites (gratuites) → toutes en symbole', () => {
        // Owner : « si le client a plusieurs sortes pour les frites, on pourra lui mettre ça ».
        // « Sauce frites : Ketchup, Mayonnaise » → « KTP MAY » (ordre de sélection préservé).
        expect(fritesSauceSymbol('Sauce frites : Ketchup, Mayonnaise')).toBe('KTP MAY');
        expect(fritesSauceSymbol('Menu (Frites + Boisson)\n↳ Sauce frites: Andalouse, Algérienne')).toBe('AND ALG');
        // Rétro-compat STRICTE : 1 seule sauce = symbole unique comme avant.
        expect(fritesSauceSymbol('Sauce frites : Algérienne')).toBe('ALG');
        expect(fritesSauceSymbol('Bien cuit svp')).toBe('');
    });

    it('[MULTIFRITES 2026-07-18] KDS : un menu à 2 sauces frites affiche « MENU : KTP MAY » (gratuit)', () => {
        const menuItem = {
            item_name: 'Menu (Frites + Boisson)',
            quantity: 1,
            instruction: 'Menu (Frites + Boisson)\n↳ Sauce frites: Ketchup, Mayonnaise',
            composition_snapshot: { lines: [] },
        };
        const out = renderItemSymbolic(menuItem);
        expect(out.lines[0]).toMatchObject({ type: 'symbolic-main', label: 'MENU : KTP MAY' });
        // GRATUIT : la sauce frites ne crée aucun supplément payant sur l'écran cuisine.
        expect(out.lines.filter((l) => l.type === 'supplement')).toHaveLength(0);
    });

    it('a real "Menu Enfant" product keeps its identity (NOT collapsed to MENU)', () => {
        // [SYNC-BORNE 2026-07-01] Burger vs Nuggets must be distinguishable on the KDS.
        const burger = renderItemSymbolic({ item_name: 'Menu Enfant Burger', quantity: 1, composition_snapshot: { lines: [] } });
        const nuggets = renderItemSymbolic({ item_name: 'Menu Enfant Nuggets', quantity: 1, composition_snapshot: { lines: [] } });
        expect(burger.lines[0].label).not.toBe('MENU');
        expect(nuggets.lines[0].label).not.toBe('MENU');
        expect(burger.lines[0].label).not.toBe(nuggets.lines[0].label);
        expect(burger.lines[0].label.toUpperCase()).toContain('BUR');
        expect(nuggets.lines[0].label.toUpperCase()).toContain('NUG');
    });

    it('[F-KITCHEN-BOL-BASE] distingue les bases de bol (BOL FRI vs BOL RIZ) pour le cuisinier', () => {
        // « Bol Frites » et « Bol Riz » n'ont pas de variation "base" → le nom porte la base.
        // Avant : les deux réduisaient à « BOL » → plat faux préparé.
        const frites = renderItemSymbolic({ item_name: 'Bol Frites', quantity: 1, composition_snapshot: { lines: [] } });
        const riz = renderItemSymbolic({ item_name: 'Bol Riz', quantity: 1, composition_snapshot: { lines: [] } });
        expect(frites.lines[0].label).not.toBe(riz.lines[0].label);
        expect(frites.lines[0].label.toUpperCase()).toContain('BOL FRI');
        expect(riz.lines[0].label.toUpperCase()).toContain('BOL RIZ');
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
        expect(supplements[0].label).toBe('⭐ Cheddar');
        expect(out.lines[0].label).toContain('S'); // Salade folded into crudités slot
    });
});

// [MEGA-BORNE Wave 1 2026-07-22 owner] (1) product sauces (included + extra) written TOGETHER in
// the Line-1 Sauce(s) slot, extra sauce no longer a "+ Sauce supplémentaire" line; (2) tacos drop
// the size. PHP twin: tests/Feature/Hardware/KitchenTicketTacosSauceTest.php (identical inputs).
describe('[MEGA-BORNE] product sauces on Line 1 + tacos without size', () => {
    it('the extra sauce (recovered from the instruction) joins the included sauce on Line 1 (FRO MAY)', () => {
        const item = {
            item_name: 'Cayenne',
            instruction: 'CAYENNE\nViandes : Poulet mariné Sauce : Fromagère, Mayonnaise',
            composition_snapshot: {
                lines: [
                    { attribute_name: 'Type de Pain', variation_name: 'Pain' },
                    { attribute_name: 'Sauce', variation_name: 'Fromagère' },
                    { attribute_name: 'Viande 1', variation_name: 'Poulet mariné' },
                ],
                extras: [{ extra_name: 'Sauce supplémentaire', unit_price: 0.5, quantity: 1 }],
            },
        };
        expect(symbolicMainLine(item)).toBe('S | CAY | P | FRO MAY');
        // the extra sauce is NOT a supplement line anymore (it moved into the Line-1 sauce slot)
        expect(buildSymbolic(item).supplements).toEqual([]);
    });

    it('a tacos drops its size and shows the meats (Tacos L → "G | TAC | K P | CURY")', () => {
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
        expect(symbolicMainLine(item)).toBe('G | TAC | K P | CURY');
    });

    it('retro-compat: an unrecoverable sauce name stays a generic "+ Sauce supplémentaire" line', () => {
        const item = {
            item_name: 'Tacos M',
            instruction: 'TACOS M', // no parsable sauce list → nothing to fold into Line 1
            composition_snapshot: {
                lines: [{ attribute_name: 'Sauce', variation_name: 'Algérienne' }],
                extras: [{ extra_name: 'Sauce supplémentaire', unit_price: 0.5, quantity: 1 }],
            },
        };
        expect(buildSymbolic(item).supplements).toEqual(['+ Sauce supplémentaire']);
    });

    it('the menu/frites sauce still lives on Line 2, not folded into the product sauce slot', () => {
        // A menu's frites sauce is a separate free channel (fritesSauceSymbol → Line 2), never a
        // product sauce. extraSauceNames must NOT capture "Sauce frites :".
        const item = {
            item_name: 'Menu (Frites + Boisson)',
            quantity: 1,
            instruction: 'Menu (Frites + Boisson)\n↳ Sauce frites: Andalouse',
            composition_snapshot: { lines: [] },
        };
        const out = renderItemSymbolic(item);
        expect(out.lines[0]).toMatchObject({ type: 'symbolic-main', label: 'MENU : AND' });
    });
});
