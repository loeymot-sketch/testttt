import { describe, it, expect } from 'vitest';

/**
 * [T-KDS-MENU-DOUBLON 2026-08-19 · GOAL owner, arbitrage owner « fusionner »]
 *
 * Observé en direct sur l'écran cuisine (/kds), commande réelle N°A0032 :
 *
 *     1× S | CAY | P | ST | ALG
 *         [FRITES : MAY]              ← la sauce frites, sur la ligne du sandwich
 *         · BOISSON: Coca-Cola 33cl
 *     1× MENU : MAY                   ← LA MÊME sauce frites, une 2e fois
 *     1× Coca-Cola 33cl               ← celle-ci est une VRAIE 2e boisson commandée à part
 *
 * Une formule est enregistrée DEUX FOIS en base — vérifié sur la commande 6598 :
 *   · order_item #5978 « Cayenne », dont l'`instruction` contient
 *     « + Menu (Frites + Boisson) (+2,50 €) / ↳ Sauce frites: Mayonnaise » ;
 *   · order_item #5979 « Menu (Frites + Boisson) » à 2,50 € — la contrepartie
 *     COMPTABLE de la formule, indispensable au prix.
 *
 * La 2e ligne doit exister en base (c'est elle qui porte les 2,50 €) mais elle
 * n'apporte RIEN au cuisinier : son contenu est déjà décrit sous son parent.
 * On la replie donc à l'AFFICHAGE seulement — aucune écriture, aucun impact
 * sur le prix, la TVA ou la chaîne fiscale.
 *
 * `order_items` n'a AUCUNE colonne de lien parent (schéma vérifié), et
 * `composition_snapshot.addons` du parent est vide. Le seul signal fiable
 * disponible est donc le « + <nom de la formule> » que le wizard écrit lui-même
 * dans l'instruction du parent.
 *
 * RÈGLE DE SÛRETÉ : on ne replie QUE ce qu'un parent revendique explicitement, et
 * seulement à hauteur de la quantité revendiquée. Une formule commandée SEULE
 * n'est jamais masquée — sinon la cuisine ne la préparerait pas.
 */
import { collapseBundledAddonItems } from '../../resources/js/helpers/kdsBundledAddons';

/** Instruction réelle capturée sur la commande 6598 (order_item #5978). */
const INSTRUCTION_PARENT = [
    'CAYENNE',
    'Pain Viandes : Poulet mariné - Salade, Tomate Sauce : Algérienne',
    '+ Menu (Frites + Boisson) (+2,50 €)',
    '↳ Sauce frites: Mayonnaise',
    'BOISSON: Coca-Cola 33cl',
].join('\n');

const sandwich = (over = {}) => ({
    id: 5978, item_name: 'Cayenne', quantity: 1, instruction: INSTRUCTION_PARENT, ...over,
});
const formule = (over = {}) => ({
    id: 5979, item_name: 'Menu (Frites + Boisson)', quantity: 1,
    instruction: 'Sauce frites: Mayonnaise', ...over,
});
const boisson = (over = {}) => ({
    id: 5980, item_name: 'Coca-Cola 33cl', quantity: 1, instruction: 'COCA-COLA 33CL', ...over,
});

const noms = (rows) => rows.map((r) => `${r.quantity}× ${r.item_name}`);

describe('collapseBundledAddonItems — la formule n\'est plus écrite deux fois', () => {
    it('cas réel commande 6598 : la ligne formule disparaît, le reste est intact', () => {
        const out = collapseBundledAddonItems([sandwich(), formule(), boisson()]);

        expect(noms(out)).toEqual(['1× Cayenne', '1× Coca-Cola 33cl']);
    });

    it('SÛRETÉ : une formule commandée SEULE reste affichée', () => {
        // Aucun parent ne la revendique → le cuisinier doit la voir, sinon il ne
        // prépare pas les frites.
        const out = collapseBundledAddonItems([formule(), boisson()]);

        expect(noms(out)).toEqual(['1× Menu (Frites + Boisson)', '1× Coca-Cola 33cl']);
    });

    it('deux sandwichs en menu : la ligne formule de quantité 2 disparaît', () => {
        const out = collapseBundledAddonItems([
            sandwich({ quantity: 2 }),
            formule({ quantity: 2 }),
        ]);

        expect(noms(out)).toEqual(['2× Cayenne']);
    });

    it('SÛRETÉ : 1 menu attaché + 1 menu seul → il en reste UN d\'affiché', () => {
        // Le parent n'en revendique qu'un ; l'autre a bien été commandé à part.
        const out = collapseBundledAddonItems([
            sandwich({ quantity: 1 }),
            formule({ quantity: 2 }),
        ]);

        expect(noms(out)).toEqual(['1× Cayenne', '1× Menu (Frites + Boisson)']);
    });

    it('la revendication tolère la casse et les accents', () => {
        const out = collapseBundledAddonItems([
            sandwich({ instruction: 'CAYENNE\n+ MENU (FRITES + BOISSON) (+2,50 €)' }),
            formule(),
        ]);

        expect(noms(out)).toEqual(['1× Cayenne']);
    });

    it('un « + » qui n\'est pas une formule ne masque rien', () => {
        // « + Cheddar » est un supplément décrit sur la ligne du parent ; il
        // n'existe pas comme article séparé. Rien ne doit disparaître.
        const out = collapseBundledAddonItems([
            sandwich({ instruction: 'CAYENNE\n+ Cheddar (+1,00 €)' }),
            boisson(),
        ]);

        expect(noms(out)).toEqual(['1× Cayenne', '1× Coca-Cola 33cl']);
    });

    it('une ligne ne peut jamais se replier elle-même', () => {
        // Garde-fou : si une formule portait dans SA propre instruction un
        // « + <son propre nom> », elle ne doit pas s'auto-supprimer.
        const out = collapseBundledAddonItems([
            formule({ instruction: '+ Menu (Frites + Boisson) (+2,50 €)' }),
        ]);

        expect(noms(out)).toEqual(['1× Menu (Frites + Boisson)']);
    });

    /**
     * [RED-TEAM 2026-08-19] SÛRETÉ — la note libre du caissier ne doit JAMAIS pouvoir
     * faire disparaître une ligne de la cuisine.
     *
     * La note est un `<textarea>` : elle peut contenir des retours à la ligne. Le wizard
     * l'écrit EN DERNIER, entre crochets. Avant correctif, une note dont une ligne
     * commençait par « + » produisait une revendication fantôme : la ligne « Frites »
     * réellement commandée à côté disparaissait du ticket ET de l'écran cuisine — tout en
     * restant facturée. Le client payait des frites que personne ne préparait.
     */
    it('SÛRETÉ : une note libre contenant « + Frites » ne masque pas les vraies frites', () => {
        const sandwichAvecNote = sandwich({
            instruction: `${INSTRUCTION_PARENT}\n[+ Frites\nMerci]`,
        });
        const frites = { id: 6001, item_name: 'Frites', quantity: 1, instruction: 'FRITES' };

        const out = collapseBundledAddonItems([sandwichAvecNote, frites]);

        expect(noms(out)).toEqual(['1× Cayenne', '1× Frites']);
    });

    it('la revendication légitime fonctionne toujours malgré une note libre', () => {
        const sandwichAvecNote = sandwich({
            instruction: `${INSTRUCTION_PARENT}\n[sans oignons]`,
        });

        const out = collapseBundledAddonItems([sandwichAvecNote, formule()]);

        expect(noms(out)).toEqual(['1× Cayenne']);
    });

    it('valeurs dégénérées', () => {
        expect(collapseBundledAddonItems([])).toEqual([]);
        expect(collapseBundledAddonItems(null)).toEqual([]);
        expect(collapseBundledAddonItems(undefined)).toEqual([]);
        const sansInstruction = [{ id: 1, item_name: 'Frites', quantity: 1 }];
        expect(noms(collapseBundledAddonItems(sansInstruction))).toEqual(['1× Frites']);
    });

    it('les objets rendus ne sont pas les objets d\'origine mutés', () => {
        const src = [sandwich({ quantity: 1 }), formule({ quantity: 2 })];
        const out = collapseBundledAddonItems(src);

        expect(src[1].quantity, 'la source ne doit pas être mutée').toBe(2);
        expect(out[1].quantity).toBe(1);
    });
});
