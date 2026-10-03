import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

import { resumeTechnique, compoAffichee, resumeComposition } from '../../resources/js/support/compositionCommande.js';
import { apercuTechnique } from '../../resources/js/helpers/apercuTechniqueCommande.js';

// [GOAL REMARQUES 2026-10-03 · T-3.4 R-009] Propriétaire, 24/08 : « faudrait pas juste voir le total […]
// mettre même les noms de produits que il y a avec les mots techniques » ; 02/09 : « on va toujours ce
// qu'il y a dedans en mode technique avec le nom de produits ainsi que l'heure de commande ».
// Le suivi et le tiroir écrivaient la composition en toutes lettres ; la file « À encaisser » de l'écran
// principal ne montrait que le N° et le prix. Les mots techniques sont ceux de la cuisine.

const LIGNE = {
    item_name: 'Cayenne',
    options: [
        { label: 'Pain', value: 'Pain' },
        { label: 'Viande 1', value: 'Poulet mariné' },
        { label: 'Crudités', value: 'Salade' },
        { label: 'Crudités', value: 'Tomate' },
        { label: 'Crudités', value: 'Oignon' },
        { label: 'Sauce (1ère Gratuite)', value: 'Algérienne' },
    ],
    extras: [{ name: 'Cheddar' }],
    addons: [{ name: 'Menu (Frites + Boisson)' }],
};

describe('suivi / tiroir : composition en mots techniques (R-009)', () => {
    it('crudités regroupées (STO), sauce et pain en symboles cuisine', () => {
        const t = resumeTechnique(LIGNE);
        expect(t).toContain('STO');
        expect(t).toContain('ALG');
        expect(t).not.toMatch(/Salade|Tomate|Algérienne/);
        expect(t).toContain('+Cheddar');
    });

    it('la carte (compoAffichee) affiche la forme technique', () => {
        expect(compoAffichee(LIGNE).texte).toBe(resumeTechnique(LIGNE));
    });

    it('le détail intégral (« Voir tout ») reste en toutes lettres', () => {
        expect(resumeComposition(LIGNE)).toContain('Algérienne');
    });

    it('une valeur sans symbole connu reste lisible (jamais effacée)', () => {
        expect(resumeTechnique({ options: [{ label: 'Cuisson', value: 'Bien cuit' }] })).toContain('Bien cuit');
    });
});

describe('file « À encaisser » : aperçu technique des produits (R-009)', () => {
    const commande = {
        order_items: [
            {
                id: 1, item_name: 'Cayenne', quantity: 2,
                instruction: 'CAYENNE\nPain Viandes : Poulet mariné - Salade, Tomate, Oignon Sauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)\n↳ Sauce frites: Mayonnaise',
                composition_snapshot: {
                    lines: [
                        { attribute_name: 'Pain', variation_name: 'Pain' },
                        { attribute_name: 'Viande 1', variation_name: 'Poulet mariné' },
                        { attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Algérienne' },
                    ],
                    extras: [{ extra_name: 'Salade', unit_price: 0, quantity: 1 }, { extra_name: 'Tomate', unit_price: 0, quantity: 1 }, { extra_name: 'Oignon', unit_price: 0, quantity: 1 }],
                    addons: [],
                },
            },
            { id: 2, item_name: 'Menu (Frites + Boisson)', quantity: 2, instruction: 'Sauce frites: Mayonnaise', composition_snapshot: { lines: [], extras: [], addons: [] } },
            { id: 3, item_name: 'Coca-Cola 33cl', quantity: 1, instruction: '', composition_snapshot: { lines: [], extras: [], addons: [] } },
        ],
    };

    it('montre les produits en symboles cuisine, quantités comprises, formule repliée', () => {
        const a = apercuTechnique(commande);
        expect(a.texte).toMatch(/^2× /);
        expect(a.texte).toContain('ALG');
        expect(a.texte).toContain('MENU');
        expect(a.texte).toContain('Coca-Cola 33cl');
        expect(a.texte).not.toContain('Menu (Frites');
    });

    it('trop long : coupé sur un séparateur et la suite ANNONCÉE (+N), jamais tronqué en silence', () => {
        const a = apercuTechnique(commande, 20);
        expect(a.restants).toBeGreaterThan(0);
        expect(a.texte.length).toBeLessThanOrEqual(20);
    });

    it('commande sans ligne : aperçu vide, pas d\'erreur', () => {
        expect(apercuTechnique({}).texte).toBe('');
    });

    it('la file de la caisse affiche cet aperçu sous le numéro', () => {
        const src = fs.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/pos/PosComponent.vue'), 'utf8');
        const ligne = src.slice(src.indexOf('v-for="o in kioskCashOrders.slice(0, 4)"'), src.indexOf('pos-shortcut-encaisser-'));
        expect(ligne).toContain('pos-shortcut-apercu-');
        expect(ligne).toMatch(/apercuCommande\(o\)\.texte/);
        expect(src).toMatch(/import \{ apercuTechnique \} from '\.\.\/\.\.\/\.\.\/helpers\/apercuTechniqueCommande'/);
    });
});
