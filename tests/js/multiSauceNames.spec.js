import { describe, expect, it } from 'vitest';

import {
    extraSauceNames,
    extraDisplayName,
    renderItemSymbolic,
} from '../../resources/js/helpers/kdsSymbolic.js';

// [MULTISAUCE 2026-07-18] Le 2e+ choix de sauce est véhiculé par un ItemExtra
// GÉNÉRIQUE « Sauce supplémentaire » (prix seul, aucun nom). Son nom réel ne survit
// que dans le texte libre `instruction`. Ces tests (jumeau JS du PHP
// KitchenTicketSymbolicFormatterTest) prouvent que le NOM de chaque sauce en plus est
// récupéré + affiché sur l'écran KDS, à l'identique de l'écran de paiement.

describe('extraSauceNames — récupération depuis l\'instruction', () => {
    it('caisse : "Sauce : <1ère>, <en plus…>" → extras = tout sauf la 1ère (gratuite)', () => {
        expect(extraSauceNames('TACOS M\nViandes : Poulet - Salade Sauce : Algérienne, Andalouse'))
            .toEqual(['Andalouse']);
        expect(extraSauceNames('Sauce : Algérienne, Andalouse, Blanche'))
            .toEqual(['Andalouse', 'Blanche']);
    });
    it('borne/web : "Sauces en plus : <extras>" (mono-ligne jointe par ". ") → extras seuls', () => {
        expect(extraSauceNames('Pain : Pain. Viandes : Poulet. Sauces en plus : Andalouse. Menu : complet'))
            .toEqual(['Andalouse']);
        expect(extraSauceNames('Extra sauces: Andalouse, Blanche'))
            .toEqual(['Andalouse', 'Blanche']);
    });
    it('ignore la sauce frites (dip gratuit, autre canal) et le non-parsable', () => {
        expect(extraSauceNames('↳ Sauce frites: Andalouse')).toEqual([]);
        expect(extraSauceNames('Bien cuit svp')).toEqual([]);
        expect(extraSauceNames('')).toEqual([]);
        expect(extraSauceNames('Sauce : Algérienne')).toEqual([]); // une seule sauce
    });
});

describe('extraDisplayName — nomme l\'extra générique, laisse les autres intacts', () => {
    it('nomme « Sauce supplémentaire »', () => {
        expect(extraDisplayName('Sauce supplémentaire', 'Sauce : Algérienne, Andalouse'))
            .toBe('Sauce supplémentaire : Andalouse');
    });
    it('rétro-compat : sans nom parsable → générique inchangé', () => {
        expect(extraDisplayName('Sauce supplémentaire', 'TACOS M')).toBe('Sauce supplémentaire');
        expect(extraDisplayName('Sauce supplémentaire', null)).toBe('Sauce supplémentaire');
    });
    it('laisse les extras déjà nommés intacts', () => {
        expect(extraDisplayName('Cheddar', 'Sauce : Algérienne, Andalouse')).toBe('Cheddar');
        expect(extraDisplayName('Viande supplémentaire', 'Sauce : X, Y')).toBe('Viande supplémentaire');
    });
});

// [MEGA-BORNE 2026-07-22 owner] La 2e sauce (produit) ne s'affiche plus comme LIGNE supplément :
// elle remonte en SYMBOLE dans le slot Sauce(s) de la ligne 1 (à côté de la 1ère incluse). Le NOM
// complet reste sur le TICKET CLIENT (fiscal). PHP twin: KitchenTicketTacosSauceTest.
describe('renderItemSymbolic — la 2e sauce (produit) remonte en ligne 1', () => {
    const makeItem = (instruction) => ({
        item_name: 'Tacos M',
        quantity: 1,
        instruction,
        composition_snapshot: {
            lines: [
                { attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Algérienne' },
                { attribute_name: 'Viande 1', variation_name: 'Poulet mariné' },
            ],
            extras: [
                { extra_name: 'Salade', unit_price: 0, line_total: 0, quantity: 1 },
                { extra_name: 'Sauce supplémentaire', unit_price: 0.5, line_total: 0.5, quantity: 1 },
            ],
            addons: [],
        },
    });

    it('caisse #5727 : ALG (incluse) + AND (en plus) ensemble en ligne 1, plus de supplément sauce', () => {
        const res = renderItemSymbolic(makeItem('TACOS M\nViandes : Poulet mariné - Salade, Tomate, Oignon Sauce : Algérienne, Andalouse'));
        expect(res.lines[0].type).toBe('symbolic-main');
        expect(res.lines[0].label).toContain('ALG AND'); // 1ère (ALG) + en plus (AND) côte à côte
        expect(res.lines.filter((l) => l.type === 'supplement')).toHaveLength(0);
    });

    it('borne : la sauce en plus (AND) remonte en ligne 1 à côté de la 1ère (ALG)', () => {
        const res = renderItemSymbolic(makeItem('TACOS M. Viandes : Poulet mariné. Sauces en plus : Andalouse.'));
        expect(res.lines[0].label).toContain('ALG AND');
        expect(res.lines.filter((l) => l.type === 'supplement')).toHaveLength(0);
    });

    it('rétro-compat : sans nom parsable → « Sauce supplémentaire » générique reste en supplément', () => {
        const res = renderItemSymbolic(makeItem('TACOS M'));
        const supp = res.lines.find((l) => l.type === 'supplement');
        expect(supp).toBeTruthy();
        expect(supp.label).toContain('Sauce suppl');
        expect(supp.label).not.toContain(' : ');
    });
});
