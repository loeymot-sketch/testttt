import { describe, expect, it } from 'vitest';

import {
    extraSauceNames,
    renderItemSymbolic,
    sauceSymbol,
} from '../../resources/js/helpers/kdsSymbolic.js';

// [GOAL CAISSE/CUISINE #4 2026-10-02] Jumeau JS de
// tests/Unit/Hardware/KitchenTicketNomsDesSaucesToutesCategoriesTest.php.
//
// « Avec deux sauces (ou plus), l'écran affiche « sauce supplémentaire » sans le nom. » CAUSE
// RACINE : la caisse écrit « Sauce : A, B, C Supplément : Cheddar (+€0.90) » — une ESPACE, pas
// une virgule, avant « Supplément : ». Le découpage s'arrêtait au premier « : » et jetait
// « C Supplément : Cheddar » EN ENTIER : la dernière sauce disparaissait dès qu'un supplément
// suivait, et le libellé générique restait seul à l'écran. Les anciens tests utilisaient une
// virgule : ils passaient pendant que la production échouait.

const CATEGORIES = {
    Sandwichs: 'Cayenne',
    Galette: 'Galette Cayenne',
    Burgers: 'Cheese Burger',
    Tacos: 'Tacos M',
    Bols: 'Bol Frites',
    Frites: 'Petite Frites',
    'Menu enfant': 'Menu Enfant Chicken Burger',
};

const makeItem = (name, first, extraQty, instruction, sealedProduct = null, withCheddar = true) => {
    const extras = [{ extra_name: 'Sauce supplémentaire', unit_price: 0.5, line_total: 0.5 * extraQty, quantity: extraQty }];
    if (withCheddar) extras.push({ extra_name: 'Cheddar', unit_price: 0.9, line_total: 0.9, quantity: 1 });
    const snap = {
        lines: [{ attribute_name: 'Sauce (1ère Gratuite)', variation_name: first }],
        extras,
        addons: [],
    };
    if (sealedProduct) snap.sauce_destinations = { product: sealedProduct, fries: [] };
    return { item_name: name, quantity: 1, instruction, composition_snapshot: snap };
};

const texte = (res) => res.lines.map((l) => l.label).join(' | ');

const toutesNommees = (res, sauces, ctx) => {
    const supps = res.lines.filter((l) => l.type === 'supplement');
    for (const l of supps) {
        expect(l.label.toLowerCase(), `${ctx} : générique affiché → ${texte(res)}`).not.toContain('sauce supplémentaire');
    }
    for (const s of sauces) {
        expect(texte(res), `${ctx} : « ${s} » absente → ${texte(res)}`).toContain(sauceSymbol(s));
    }
};

describe.each(Object.entries(CATEGORIES))('noms des sauces en plus — %s', (_cat, produit) => {
    it('caisse : 3 sauces puis un supplément sur la même ligne (forme RÉELLE, espace)', () => {
        const instr = `${produit.toUpperCase()}\nPain Viandes : Poulet mariné - Salade, Tomate Sauce : Algérienne, Andalouse, Harissa Supplément : Cheddar (+€0.90)`;
        expect(extraSauceNames(instr)).toEqual(['Andalouse', 'Harissa']);
        toutesNommees(renderItemSymbolic(makeItem(produit, 'Algérienne', 2, instr)), ['Algérienne', 'Andalouse', 'Harissa'], produit);
    });

    it('caisse : 2 sauces puis un supplément', () => {
        const instr = `${produit.toUpperCase()}\nPain Sauce : Mayonnaise, Samouraï Supplément : Cheddar (+€0.90)`;
        expect(extraSauceNames(instr)).toEqual(['Samouraï']);
        toutesNommees(renderItemSymbolic(makeItem(produit, 'Mayonnaise', 1, instr)), ['Mayonnaise', 'Samouraï'], produit);
    });

    it('snapshot scellé tronqué : la relecture plus complète de l\'instruction l\'emporte', () => {
        const instr = `${produit.toUpperCase()}\nSauce : Barbecue, Curry Supplément : Cheddar (+€0.90)`;
        toutesNommees(renderItemSymbolic(makeItem(produit, 'Barbecue', 1, instr, ['Curry'])), ['Barbecue', 'Curry'], produit);
    });

    it('borne : « Sauces en plus : … »', () => {
        const instr = 'Sauces en plus : Andalouse, Harissa. Supplément : Cheddar (+0,90 €)';
        expect(extraSauceNames(instr)).toEqual(['Andalouse', 'Harissa']);
        toutesNommees(renderItemSymbolic(makeItem(produit, 'Algérienne', 2, instr)), ['Algérienne', 'Andalouse', 'Harissa'], produit);
    });

    it('borne en arabe : « صلصات إضافية: … » reconnu', () => {
        const instr = 'صلصات إضافية: Andalouse, Harissa';
        expect(extraSauceNames(instr)).toEqual(['Andalouse', 'Harissa']);
        toutesNommees(renderItemSymbolic(makeItem(produit, 'Algérienne', 2, instr, null, false)), ['Andalouse', 'Harissa'], produit);
    });
});

// [GOAL REMARQUES 2026-10-03 · T-1.3 R-070] Les 13 sauces VENDUES SEULES (catégorie « Sauces
// supplémentaires », noms réels de la table items #245-#257) s'affichaient toutes « SAU ».
// Jumeau : KitchenTicketNomsDesSaucesToutesCategoriesTest::saucesVenduesSeules().
const SAUCES_SEULES = {
    'Sauce Ketchup': 'SAUCE KETCHUP',
    'Sauce Mayonnaise': 'SAUCE MAYONNAISE',
    'Sauce Blanche': 'SAUCE BLANCHE',
    'Sauce Algérienne': 'SAUCE ALGERIENNE',
    'Sauce Samouraï': 'SAUCE SAMOURAI',
    'Sauce Andalouse': 'SAUCE ANDALOUSE',
    'Sauce Américaine': 'SAUCE AMERICAINE',
    'Sauce Barbecue': 'SAUCE BARBECUE',
    'Sauce Curry': 'SAUCE CURRY',
    'Sauce Harissa': 'SAUCE HARISSA',
    'Sauce Hannibal': 'SAUCE HANNIBAL',
    'Sauce Fromagère maison': 'SAUCE FROMAGERE MAISON',
    'Sauce Spicy maison': 'SAUCE SPICY MAISON',
};

describe('sauce vendue seule : son nom, jamais « SAU » (R-070)', () => {
    it.each(Object.entries(SAUCES_SEULES))('%s → %s', (nom, attendu) => {
        const res = renderItemSymbolic({ item_name: nom, quantity: 1, instruction: '', composition_snapshot: { lines: [], extras: [], addons: [] } });
        expect(res.lines.find((l) => l.type === 'symbolic-main').label).toBe(attendu);
    });

    it('contre-épreuve : un produit qui ne COMMENCE pas par « Sauce » garde son code court', () => {
        const res = renderItemSymbolic({ item_name: 'Bol Frites', quantity: 1, instruction: '', composition_snapshot: { lines: [], extras: [], addons: [] } });
        expect(res.lines.find((l) => l.type === 'symbolic-main').label).not.toContain('SAUCE');
    });
});

describe('contre-épreuves', () => {
    it('sans supplément, le cas qui marchait déjà ne régresse pas', () => {
        expect(extraSauceNames('Sauce : Algérienne, Andalouse, Harissa')).toEqual(['Andalouse', 'Harissa']);
    });
    it('le prix à virgule et un supplément collant ne fabriquent pas de fausse sauce', () => {
        expect(extraSauceNames('Sauce : Mayonnaise, Samouraï Supplément : Œuf (+0,90 €), Olives (+0,90 €)')).toEqual(['Samouraï']);
    });
});
