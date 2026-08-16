import { describe, it, expect } from 'vitest';

/**
 * [T-A EDIT-PANIER 2026-08-16 · GOAL owner] Owner : « je n'arrive pas à modifier
 * un produit déjà ajouté au panier, il n'y a pas de bouton pour rouvrir le
 * wizard ». Vérifié en navigateur réel (POS /admin/pos, pos@lecayenne.fr) : le
 * mécanisme EXISTE (crayon ✎ + zone image, `editCartLine` → `openEditFromCart`
 * → shim `data-wizard-restore-selections` vers pos-wizard.js FROZEN) et
 * restaure correctement la viande (confirmé 2× en direct : "Poulet mariné"
 * réapparaît coché 1/1). Le crayon était en revanche minuscule (22px, fond
 * TRANSPARENT tant que non survolé — inutile au tactile) : agrandi et rendu
 * visible en permanence (resources/css/pos-v5.css).
 *
 * En testant la sauce, une INCOHÉRENCE a été observée en direct (le panier
 * stockait "Algérienne" après un clic visuel sur "Andalouse"), mais l'analyse
 * a montré qu'elle provient très probablement de la LIVRAISON synthétique des
 * clics de test (dispatch JS sur le wizard vanilla FROZEN, pas un clic
 * utilisateur authentique) plutôt que d'un vrai défaut de restauration — non
 * reproductible de façon fiable par automatisation sur ce composant. Cette
 * suite verrouille donc la LOGIQUE de restauration elle-même (statique, fiable,
 * indépendante des flakys du navigateur) sur les deux chemins réels :
 *   - `item_extras` (gabarits où la sauce est un extra, ex. "omelettes/snacking"
 *     cité dans le code) — bug RÉEL et confirmé par lecture de code : le
 *     catch-all `isFree` (garnitures gratuites : tomate, oignon…) passait AVANT
 *     le test `extraLower.includes('sauce')`, donc une sauce gratuite atterrissait
 *     dans `restore.garnitures`, jamais `restore.sauces`. Corrigé.
 *   - `item_variations` (le gabarit Tacos réel : attribut catalogue vérifié en
 *     base "Sauce (1ère Gratuite)", ItemVariation "Andalouse" id=14) — déjà
 *     correct par lecture de code, verrouillé ici en non-régression.
 */
import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';

const buildWizardRestorePayload = ItemComponent.methods.buildWizardRestorePayload;

function makeItem() {
    return {
        itemAttributes: [{ id: 1, name: 'Viande' }],
        variations: {
            1: [{ id: 101, name: 'Poulet mariné' }],
        },
        // [gabarits "omelettes/snacking" cités dans le code] Contrairement au Tacos
        // (sauce = variation, classée par nom d'ATTRIBUT), un extra n'a pas de groupe
        // parent — la classification `extraLower.includes('sauce')` exige donc que le
        // nom catalogue de l'extra lui-même porte "Sauce" (ex. "Sauce Andalouse").
        extras: [
            { id: 201, name: 'Sauce Andalouse', convert_price: 0 }, // 1ère sauce gratuite
            { id: 202, name: 'Sauce Algérienne', convert_price: 0 },
            { id: 210, name: 'Supplément fromage', convert_price: 1.5 },
            { id: 211, name: 'Tomate', convert_price: 0 },
        ],
    };
}

function makeCartLine(overrides = {}) {
    return {
        instruction: '',
        quantity: 1,
        item_variations: [{ variation_name: 'Viande', name: 'Poulet mariné', quantity: 1 }],
        item_extras: [{ id: 9001, name: 'Sauce Andalouse' }],
        ...overrides,
    };
}

describe('ItemComponent.buildWizardRestorePayload — sauce gratuite correctement restaurée', () => {
    it('une sauce gratuite (1ère, isFree=true) va dans restore.sauces, PAS restore.garnitures', () => {
        const restore = buildWizardRestorePayload(makeCartLine(), makeItem());

        expect(restore.sauces['s_201'], 'la sauce Andalouse (id 201) doit être marquée choisie').toBe(true);
        expect(restore.sauceOrder).toContain('s_201');
        expect(restore.sauceSingle, 'sauce unique doit pointer sur la VRAIE sauce choisie, pas une autre').toBe(201);
        expect(restore.garnitures['c_201'], 'la sauce ne doit JAMAIS finir classée comme garniture').toBeUndefined();
    });

    it('une vraie garniture gratuite (tomate) continue de tomber dans restore.garnitures', () => {
        const restore = buildWizardRestorePayload(
            makeCartLine({ item_extras: [{ id: 9001, name: 'Sauce Andalouse' }, { id: 9002, name: 'Tomate' }] }),
            makeItem(),
        );

        expect(restore.garnitures['c_211'], 'Tomate doit rester une garniture (non-régression)').toBe(true);
        expect(restore.sauces['c_211']).toBeUndefined();
    });

    it('une sauce PAYANTE (extra supplémentaire) continue de fonctionner comme avant', () => {
        const item = makeItem();
        item.extras.push({ id: 220, name: 'Sauce Barbecue', convert_price: 0.5 });
        const restore = buildWizardRestorePayload(
            makeCartLine({ item_extras: [{ id: 9001, name: 'Sauce Andalouse' }, { id: 9003, name: 'Sauce Barbecue' }] }),
            item,
        );

        expect(restore.sauces['s_201']).toBe(true);
        expect(restore.sauces['s_220'], 'la 2e sauce (payante) doit aussi être restaurée').toBe(true);
        expect(restore.sauceOrder).toEqual(['s_201', 's_220']);
    });

    it('un supplément non-sauce et non-gratuit continue de tomber dans restore.supplements', () => {
        const restore = buildWizardRestorePayload(
            makeCartLine({ item_extras: [{ id: 9001, name: 'Sauce Andalouse' }, { id: 9004, name: 'Supplément fromage' }] }),
            makeItem(),
        );

        expect(restore.supplements['p_210'], 'Supplément fromage (payant, non-sauce) reste un supplément').toBe(true);
    });
});

describe('ItemComponent.buildWizardRestorePayload — sauce via item_variations (gabarit Tacos réel)', () => {
    // Structure vérifiée en base locale (php artisan tinker, 2026-08-16) :
    // ItemAttribute id=5 name="Sauce (1ère Gratuite)" min_select=1 max_select=1 ;
    // ItemVariation id=14 name="Andalouse" item_attribute_id=5 convert_price=0.
    function makeTacosItem() {
        return {
            itemAttributes: [
                { id: 1, name: 'Viande 1' },
                { id: 5, name: 'Sauce (1ère Gratuite)' },
            ],
            variations: {
                1: [{ id: 43, name: 'Poulet mariné' }],
                5: [
                    { id: 14, name: 'Andalouse' },
                    { id: 13, name: 'Algérienne' },
                ],
            },
            extras: [],
        };
    }

    function makeTacosCartLine(sauceName) {
        return {
            instruction: '',
            quantity: 1,
            item_extras: [],
            item_variations: [
                { id: 43, item_attribute_id: 1, quantity: 1, variation_name: 'Viande 1', name: 'Poulet mariné' },
                { id: 14, item_attribute_id: 5, quantity: 1, variation_name: 'Sauce (1ère Gratuite)', name: sauceName },
            ],
        };
    }

    it('la sauce réellement choisie (Andalouse) est celle restaurée, pas la 1ère de la liste', () => {
        const restore = buildWizardRestorePayload(makeTacosCartLine('Andalouse'), makeTacosItem());

        expect(restore.sauces['s_14'], 'Andalouse (id 14, le vrai choix) doit être marqué').toBe(true);
        expect(restore.sauces['s_13'], 'Algérienne (id 13, PAS choisi) ne doit jamais être marqué').toBeUndefined();
        expect(restore.sauceSingle).toBe(14);
        expect(restore.viandes['v_43']).toBe(1);
    });

    it('si le client avait choisi Algérienne, restore pointe bien sur Algérienne (pas un défaut fixe)', () => {
        const restore = buildWizardRestorePayload(makeTacosCartLine('Algérienne'), makeTacosItem());

        expect(restore.sauces['s_13']).toBe(true);
        expect(restore.sauces['s_14']).toBeUndefined();
        expect(restore.sauceSingle).toBe(13);
    });
});

/**
 * [test-e2e fix A-001 round-1 2026-08-16] Adversarial supervisor finding, confirmed
 * by raw DOM text diff: a cart line explicitly excluding a default-included crudité
 * (customer says "no onion", picks "cooked onion" instead via mutual exclusivity)
 * shows correctly at add-time, but reopening the edit wizard and confirming with
 * ZERO changes (a pure round-trip) silently REINTRODUCED the excluded ingredient.
 *
 * Root cause: buildWizardRestorePayload only ever wrote restore.garnitures[key] = true
 * for extras PRESENT on the cart line — it never wrote `false` for the item's other
 * free/garniture-eligible extras that were explicitly NOT present (i.e. excluded).
 * pos-wizard.js restores selections via `Object.assign(selections, restored)`
 * (public/js/pos-wizard.js ~L5055), which REPLACES selections.garnitures WHOLESALE —
 * it does not merge key-by-key against the wizard's own name-aware defaults
 * (cruditeDefaultIncluded). A garniture key ABSENT from the restore payload reads as
 * `undefined !== false` downstream, so the frozen wizard's own defaults silently win
 * back anything the restore payload didn't explicitly override.
 */
describe('ItemComponent.buildWizardRestorePayload — exclusion garniture explicite survit au round-trip (A-001)', () => {
    function makeOnionItem() {
        return {
            itemAttributes: [],
            variations: {},
            extras: [
                { id: 301, name: 'Oignon', convert_price: 0 },        // cru — défaut inclus
                { id: 302, name: 'Oignons cuits', convert_price: 0 }, // cuit — opt-in, défaut exclu
                { id: 303, name: 'Salade', convert_price: 0 },
                { id: 304, name: 'Tomate', convert_price: 0 },
            ],
        };
    }

    function makeOnionCartLine(overrides = {}) {
        return {
            instruction: '',
            quantity: 1,
            item_variations: [],
            // Client a remplacé "Oignon" (cru, défaut inclus) par "Oignons cuits"
            // (opt-in, défaut exclu) via l'exclusivité mutuelle du wizard —
            // "Oignon" N'EST DONC PAS dans item_extras : exclusion EXPLICITE.
            item_extras: [
                { id: 9101, name: 'Salade' },
                { id: 9102, name: 'Tomate' },
                { id: 9103, name: 'Oignons cuits' },
            ],
            ...overrides,
        };
    }

    it('une crudité explicitement EXCLUE (oignon cru, remplacé par oignons cuits) est restaurée à false — PAS juste absente', () => {
        const restore = buildWizardRestorePayload(makeOnionCartLine(), makeOnionItem());

        expect(restore.garnitures['c_302'], 'Oignons cuits (réellement choisi) doit être true').toBe(true);
        expect(
            restore.garnitures['c_301'],
            'Oignon cru (explicitement exclu par le client) doit être false — un simple `undefined` ' +
            'laisserait le wizard le réintroduire silencieusement au reopen (Object.assign remplace ' +
            'tout le sous-objet garnitures, il ne fusionne pas clé par clé)',
        ).toBe(false);
        expect(restore.garnitures['c_303'], 'Salade (incluse, présente sur la ligne) reste true').toBe(true);
        expect(restore.garnitures['c_304'], 'Tomate (incluse, présente sur la ligne) reste true').toBe(true);
    });

    it('si TOUTES les crudités par défaut ont été retirées (item_extras vide), chacune est restaurée à false explicite', () => {
        const restore = buildWizardRestorePayload(makeOnionCartLine({ item_extras: [] }), makeOnionItem());

        expect(restore.garnitures['c_301']).toBe(false);
        expect(restore.garnitures['c_303']).toBe(false);
        expect(restore.garnitures['c_304']).toBe(false);
        // Oignons cuits (opt-in, défaut déjà exclu) doit aussi ressortir false explicite,
        // pas juste absent — même garantie pour tout le monde.
        expect(restore.garnitures['c_302']).toBe(false);
    });
});
