import { describe, it, expect } from 'vitest';

/**
 * [T-PANIER-COMPACT 2026-08-19 · GOAL owner] Rapport terrain du propriétaire :
 *
 *   « Pas besoin de scroller pour visualiser tout le panier. Au lieu d'écrire
 *     "salade tomate oignon", écris "STO". Pas besoin d'écrire chaque mot :
 *     au lieu de "Frites : sauce pour les frites : Mayo / Boissons : Coca-Cola",
 *     écris directement "Frites : Mayo" puis "Coca-Cola". »
 *
 * ÉTAT MESURÉ EN DIRECT (navigateur réel, /admin/pos, 2026-08-19) — un seul
 * sandwich menu occupait 196 px dans un corps de panier haut de 40 px :
 *
 *     Cayenne
 *     Viandes: Poulet mariné          ← nom de groupe redondant
 *     Pain: Pain                      ← tautologie pure
 *     Crudités: Salade, Tomate, Oignon← 4 mots là où 3 lettres suffisent
 *     Sauce: Algérienne               ← nom de groupe redondant
 *     + Menu (Frites + Boisson)
 *       ↳ Sauce frites: Mayonnaise
 *     (la BOISSON choisie n'apparaissait NULLE PART — voir plus bas)
 *
 * DÉFAUT DE SÉCURITÉ ASSOCIÉ, prouvé en direct : `pos_line_addons[0].menu_extras`
 * ne contient que la sauce frites. La boisson n'existe que dans `menu_restore
 * .boissonChoice` (un ID numérique) et dans la ligne « BOISSON: … » de
 * `instruction`. Le caissier ne pouvait donc PAS vérifier la boisson commandée
 * avant de la servir. `buildMenuExtras()` vit dans `public/js/pos-wizard.js`
 * (FROZEN §7) : on récupère donc la boisson depuis `instruction`, sans y toucher.
 *
 * RÈGLE DE SÛRETÉ NON NÉGOCIABLE : compacter ne doit JAMAIS faire disparaître un
 * RETRAIT (« Sans oignon ») ni une note client. Un plat mal préparé coûte plus
 * cher qu'une ligne de trop.
 */
import {
    compactCompositionSegments,
    compactBundledExtras,
    compactBundledName,
    drinkFromInstruction,
} from '../../resources/js/helpers/posCartCompactDisplay';

describe('compactBundledName — le libellé de formule ne répète pas son contenu', () => {
    it('cas réel : la parenthèse est retirée, le contenu étant détaillé en dessous', () => {
        expect(compactBundledName('Menu (Frites + Boisson)')).toBe('Menu');
    });

    it('un nom sans parenthèse finale est intact', () => {
        expect(compactBundledName('Menu enfant')).toBe('Menu enfant');
        expect(compactBundledName('Frites Seules')).toBe('Frites Seules');
    });

    it('jamais de libellé vide : une parenthèse seule est conservée', () => {
        expect(compactBundledName('(Frites + Boisson)')).toBe('(Frites + Boisson)');
        expect(compactBundledName(null)).toBe('');
    });
});

describe('compactCompositionSegments — la composition tient sur une ligne', () => {
    it('cas réel capturé en direct : 4 lignes → 3 segments', () => {
        const display = [
            'Viandes: Poulet mariné',
            'Pain: Pain',
            'Crudités: Salade, Tomate, Oignon',
            'Sauce: Algérienne',
        ].join('\n');

        expect(compactCompositionSegments(display)).toEqual([
            'Poulet mariné',
            'STO',
            'Algérienne',
        ]);
    });

    it('« Pain: Pain » est une tautologie et disparaît, « Pain: Galette » JAMAIS', () => {
        expect(compactCompositionSegments('Pain: Pain')).toEqual([]);
        expect(compactCompositionSegments('Pain: Galette')).toEqual(['Galette']);
    });

    it('SÛRETÉ : un retrait reste visible et en capitales', () => {
        const display = [
            'Viandes: Poulet mariné',
            'Sans: Oignon',
            'Crudités: Salade, Tomate',
            'Sauce: Algérienne',
        ].join('\n');

        const segments = compactCompositionSegments(display);
        expect(segments).toContain('SANS OIGNON');
        expect(segments).toEqual(['Poulet mariné', 'SANS OIGNON', 'ST', 'Algérienne']);
    });

    it('SÛRETÉ : « Sans oignons » listé comme crudité ne devient pas « O »', () => {
        // Piège réel documenté dans kdsSymbolic.js:109 — sans la garde de négation,
        // la table trouvait « oignon » dans le refus et la cuisine en mettait.
        expect(compactCompositionSegments('Crudités: Salade, Sans oignons')).toEqual(['S']);
    });

    it('les oignons CUITS gardent leur symbole distinct (O souligné)', () => {
        expect(compactCompositionSegments('Crudités: Salade, Oignons cuits')).toEqual(['SO̲']);
    });

    it('les crudités sortent dans l\'ordre canonique S-T-O quel que soit l\'ordre saisi', () => {
        expect(compactCompositionSegments('Crudités: Oignon, Salade, Tomate')).toEqual(['STO']);
    });

    it('un supplément payant reste identifiable par le préfixe +', () => {
        expect(compactCompositionSegments('Suppléments: Cheddar')).toEqual(['+Cheddar']);
    });

    it('un groupe inconnu conserve sa valeur (jamais de perte d\'information)', () => {
        expect(compactCompositionSegments('Cuisson: Bien cuit')).toEqual(['Bien cuit']);
    });

    it('valeurs dégénérées', () => {
        expect(compactCompositionSegments('')).toEqual([]);
        expect(compactCompositionSegments(null)).toEqual([]);
        expect(compactCompositionSegments('   \n  ')).toEqual([]);
    });
});

describe('drinkFromInstruction — récupère la boisson invisible du panier', () => {
    it('cas réel capturé en direct', () => {
        const instruction = [
            'CAYENNE',
            'Pain Viandes : Poulet mariné - Salade, Tomate, Oignon Sauce : Algérienne',
            '+ Menu (Frites + Boisson) (+2,50 €)',
            '↳ Sauce frites: Mayonnaise',
            'BOISSON: Coca-Cola 33cl',
        ].join('\n');

        expect(drinkFromInstruction(instruction)).toBe('Coca-Cola 33cl');
    });

    it('aucune boisson → chaîne vide', () => {
        expect(drinkFromInstruction('CAYENNE\nPain Viandes : Poulet mariné')).toBe('');
        expect(drinkFromInstruction(null)).toBe('');
    });
});

describe('compactBundledExtras — « Sauce frites: Mayo » puis la boisson', () => {
    it('cas réel : la sauce frites est allégée ET la boisson réapparaît', () => {
        const bundled = { name: 'Menu (Frites + Boisson)', menu_extras: ['Sauce frites: Mayonnaise'] };
        const parentInstruction = 'CAYENNE\n+ Menu (Frites + Boisson) (+2,50 €)\nBOISSON: Coca-Cola 33cl';

        expect(compactBundledExtras(bundled, parentInstruction)).toEqual([
            'Frites : Mayonnaise',
            'Coca-Cola 33cl',
        ]);
    });

    it('la boisson n\'est jamais ajoutée deux fois si elle est déjà listée', () => {
        const bundled = { name: 'Menu', menu_extras: ['Sauce frites: Mayonnaise', 'Coca-Cola 33cl'] };
        const parentInstruction = 'CAYENNE\nBOISSON: Coca-Cola 33cl';

        expect(compactBundledExtras(bundled, parentInstruction)).toEqual([
            'Frites : Mayonnaise',
            'Coca-Cola 33cl',
        ]);
    });

    it('menu_extras livré en objet indexé est accepté (tolérance défensive)', () => {
        // CORRECTION D'UNE PREMIÈRE LECTURE ERRONÉE (2026-08-19, red-team) : j'avais
        // d'abord noté ici avoir « observé en direct » `menu_extras` muter en objet
        // indexé après une édition. C'était FAUX — un artefact de la sérialisation
        // JSON d'un proxy réactif Vue par mon outil de sonde, pas une mutation des
        // données. Vérifié sur les trois chemins réels : `buildMenuExtras()`
        // (pos-wizard.js:4391) construit un vrai tableau, `readWizardBundledAddons`
        // le relit via JSON.parse en tableau, et `normPosLineAddon`
        // (store/modules/posCart.js:170) le force en tableau à chaque écriture.
        //
        // La tolérance est donc DÉFENSIVE, pas corrective — mais elle a sa valeur :
        // si une forme objet arrivait un jour (charge héritée, changement backend),
        // `posCart.js:170` la remplacerait silencieusement par `[]` — la sauce frites
        // DISPARAÎTRAIT du panier — et `posLineAddonsSignature` (l.146) produirait une
        // empreinte vide, faisant FUSIONNER deux lignes aux sauces différentes.
        // Ici, au moins, l'affichage tient.
        const bundled = { name: 'Menu', menu_extras: { 0: 'Sauce frites: Mayonnaise' } };
        expect(compactBundledExtras(bundled, '')).toEqual(['Frites : Mayonnaise']);
    });

    it('sans extras ni boisson → tableau vide', () => {
        expect(compactBundledExtras({ name: 'Menu' }, '')).toEqual([]);
        expect(compactBundledExtras(null, null)).toEqual([]);
    });
});
