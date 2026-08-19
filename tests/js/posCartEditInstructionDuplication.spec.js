import { describe, it, expect } from 'vitest';

/**
 * [T-DOUBLON 2026-08-19 · GOAL owner] Rapport terrain du propriétaire :
 * « quand je modifie un article déjà au panier, le wizard s'ouvre bien et j'arrive
 *   à modifier, MAIS lors de l'impression du ticket et sur l'écran de cuisine
 *   ça écrit en double, deux fois la même chose ».
 *
 * REPRODUCTION EN DIRECT (navigateur réel, /admin/pos, 2026-08-19) — les chaînes
 * ci-dessous sont copiées telles quelles depuis `carts[0].instruction` de
 * l'instance Vue, avant puis après une modification (retrait de l'oignon) :
 *
 *   AVANT : "CAYENNE\nPain Viandes : Poulet mariné - Salade, Tomate, Oignon …"
 *   APRÈS : "CAYENNE\nPain Viandes : Poulet mariné - Salade, Tomate …
 *            [CAYENNE\nPain Viandes : Poulet mariné - Salade, Tomate, Oignon …]"
 *
 * MÉCANISME (vérifié par lecture de code, 3 maillons) :
 *   1. `public/js/pos-wizard.js:3981` (FROZEN) — à chaque validation le wizard
 *      ré-emballe sa variable « commentaire caissier » entre crochets :
 *          extraLines.push('[' + instructionText.trim() + ']');
 *   2. `ItemComponent.vue:1286` — à l'édition on lui renvoyait l'instruction
 *      COMPLÈTE de la ligne panier (nom produit + composition + [note]) dans le
 *      champ `instruction` du payload de restauration.
 *   3. `public/js/pos-wizard.js:5057` — le wizard charge ça dans `instructionText`.
 *   → la composition entière repart donc dans les crochets au tour suivant.
 *
 * POURQUOI ÇA RESSORT SUR LE TICKET ET LE KDS : les deux assainisseurs jumeaux
 * (`KitchenTicketSymbolicFormatter::cleanInstruction` PHP et
 * `helpers/kdsCustomization.js sanitizeKdsInstruction`) PRÉSERVENT volontairement
 * tout bloc `[...]` verbatim — garantie FOOD-SAFETY pour ne jamais tronquer une
 * note d'allergie multi-ligne. Ils suppriment la 1re copie (reconnue comme blob de
 * composition) mais impriment la 2e, crochetée. Il ne faut donc SURTOUT PAS
 * corriger là-bas : la source doit cesser d'émettre le doublon.
 *
 * AGGRAVATION : c'est cumulatif (2 éditions → imbrication), et le watcher de
 * troncature à 500 caractères peut couper le `]` fermant, laissant l'assainisseur
 * en mode « bloc ouvert » jusqu'à la fin.
 */
import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';
import { extractCashierNote } from '../../resources/js/helpers/posWizardInstruction';

const buildWizardRestorePayload = ItemComponent.methods.buildWizardRestorePayload;

/** Chaîne réelle produite par le wizard à l'ajout initial (aucune note caissier). */
const COMPO_SEULE = [
    'CAYENNE',
    'Pain Viandes : Poulet mariné - Salade, Tomate, Oignon Sauce : Algérienne',
    '+ Menu (Frites + Boisson) (+2,50 €)',
    '↳ Sauce frites: Mayonnaise',
    'BOISSON: Coca-Cola 33cl',
].join('\n');

/** Chaîne réelle observée APRÈS une modification — le doublon crocheté. */
const COMPO_DOUBLONNEE = [
    'CAYENNE',
    'Pain Viandes : Poulet mariné - Salade, Tomate Sauce : Algérienne',
    '+ Menu (Frites + Boisson) (+2,50 €)',
    '↳ Sauce frites: Mayonnaise',
    'BOISSON: Coca-Cola 33cl',
    '[CAYENNE',
    'Pain Viandes : Poulet mariné - Salade, Tomate, Oignon Sauce : Algérienne',
    '+ Menu (Frites + Boisson) (+2,50 €)',
    '↳ Sauce frites: Mayonnaise',
    'BOISSON: Coca-Cola 33cl]',
].join('\n');

function makeItem() {
    return {
        itemAttributes: [{ id: 1, name: 'Viande' }],
        variations: { 1: [{ id: 101, name: 'Poulet mariné' }] },
        extras: [{ id: 201, name: 'Salade', convert_price: 0 }],
    };
}

function makeCartLine(instruction) {
    return {
        name: 'Cayenne',
        instruction,
        quantity: 1,
        item_variations: [{ variation_name: 'Viande', name: 'Poulet mariné', quantity: 1 }],
        item_extras: [{ id: 201, name: 'Salade' }],
    };
}

describe('extractCashierNote — ne rend au wizard QUE la note libre du caissier', () => {
    it('composition pure (aucune note tapée) → rien à restaurer', () => {
        expect(extractCashierNote(COMPO_SEULE, 'Cayenne')).toBe('');
    });

    it('RÉGRESSION TERRAIN : une instruction déjà doublonnée ne réinjecte pas la compo', () => {
        expect(extractCashierNote(COMPO_DOUBLONNEE, 'Cayenne')).toBe('');
    });

    it('note libre du caissier → restaurée telle quelle, sans la composition', () => {
        expect(extractCashierNote(`${COMPO_SEULE}\n[sans oignons]`, 'Cayenne')).toBe('sans oignons');
    });

    it('note libre survivant à une ligne déjà corrompue (crochets imbriqués)', () => {
        const corrompu = `${COMPO_SEULE}\n[${COMPO_SEULE}\n[sans oignons]]`;
        expect(extractCashierNote(corrompu, 'Cayenne')).toBe('sans oignons');
    });

    it('produit SANS wizard (note tapée directement dans la modale) → préservée', () => {
        // Ici la 1re ligne n'est pas le nom du produit en majuscules : ce n'est pas
        // un blob wizard, c'est une vraie note. Ne jamais l'effacer.
        expect(extractCashierNote('Sonnez fort, interphone HS', 'Coca-Cola 33cl'))
            .toBe('Sonnez fort, interphone HS');
    });

    it('valeurs dégénérées', () => {
        expect(extractCashierNote('', 'Cayenne')).toBe('');
        expect(extractCashierNote(null, 'Cayenne')).toBe('');
        expect(extractCashierNote(undefined, 'Cayenne')).toBe('');
        expect(extractCashierNote(COMPO_SEULE, '')).toBe(COMPO_SEULE);
    });
});

describe('buildWizardRestorePayload — le wizard ne reçoit plus la composition', () => {
    it('composition pure → restore.instruction vide (sinon le wizard la re-crochète)', () => {
        const restore = buildWizardRestorePayload(makeCartLine(COMPO_SEULE), makeItem());
        expect(restore.instruction).toBe('');
    });

    it('RÉGRESSION TERRAIN : ligne déjà doublonnée → restore.instruction vide', () => {
        const restore = buildWizardRestorePayload(makeCartLine(COMPO_DOUBLONNEE), makeItem());
        expect(restore.instruction).toBe('');
        expect(restore.instruction).not.toContain('CAYENNE');
        expect(restore.instruction).not.toContain('BOISSON');
    });

    it('la note libre du caissier est bien conservée à travers l\'édition', () => {
        const restore = buildWizardRestorePayload(
            makeCartLine(`${COMPO_SEULE}\n[bien cuit svp]`),
            makeItem()
        );
        expect(restore.instruction).toBe('bien cuit svp');
    });
});
