import { describe, expect, it, vi } from 'vitest';

vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({
    default: { name: 'PaymentComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({
    default: { name: 'CreateCustomerAddressComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({
    default: { name: 'CustomerAddressCreateComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));

import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';

function createVm() {
    const data = ItemComponent.data.call({});
    const vm = {
        ...data,
        item: null,
        $t: (key) => key,
        $store: { dispatch: vi.fn(() => Promise.resolve()), getters: { 'frontendSetting/lists': {} } },
        $refs: {
            itemVariationModal: { dataset: {}, setAttribute: vi.fn(), removeAttribute: vi.fn(), classList: { add: vi.fn(), remove: vi.fn() } },
            itemInfoModal: { classList: { add: vi.fn(), remove: vi.fn() } },
        },
    };
    Object.entries(ItemComponent.methods).forEach(([name, fn]) => { vm[name] = fn.bind(vm); });
    return vm;
}

/**
 * [P0-01 · RAPPORT_DEV_CAISSE_2026-09-24 — REPRODUIT PAR L'ÉCRAN le 2026-09-30]
 *
 * « Tacos XL : 3 viandes incluses … À la réouverture "Modifier", la modal recharge une
 * seule viande. » Un premier correctif (2026-09-26) avait traité la priorité
 * `attribute_name` ; le défaut RESTAIT, pour une autre raison, mesurée au navigateur :
 * modale rechargée = 1 tuile allumée sur 3, tout en affichant « 3/3 incluses ».
 *
 * CAUSE RACINE, prouvée en base (Tacos XL, item 234) : le backend attribue un id de
 * variation DIFFÉRENT par attribut pour le MÊME nom —
 *     Mexicanos     : 777 (Viande 1) · 784 (Viande 2) · 791 (Viande 3)
 *     Cordon Bleu   : 778           · 785           · 792
 *     Viande Hachée : 779           · 786           · 793
 * Une ligne à 3 viandes enregistre donc un id pris dans TROIS attributs (777/785/793).
 *
 * Or `public/js/pos-wizard.js` (ZONE GELÉE) dédoublonne ses tuiles PAR NOM et ne garde
 * que l'id du PREMIER attribut : ses tuiles sont `v_777`, `v_778`, `v_779`, et il lit le
 * compte sous CETTE clé seulement. `v_785` / `v_793` ne correspondaient à aucune tuile :
 * deux viandes sur trois disparaissaient de l'écran, en silence, alors que le compteur
 * — qui somme toutes les clés — affichait toujours 3/3. Le caissier pouvait valider une
 * composition amputée.
 *
 * Correctif (hors zone gelée) : `idViandeCanonique` normalise la clé vers l'id du premier
 * attribut viande portant ce nom, dans l'ordre exact de déduplication du wizard.
 */
const itemTacosXL = () => ({
    id: 234,
    name: 'Tacos XL',
    itemAttributes: [
        { id: 61, name: 'Viande 1' },
        { id: 62, name: 'Viande 2' },
        { id: 63, name: 'Viande 3' },
        { id: 70, name: 'Sauce' },
    ],
    variations: {
        61: [{ id: 777, name: 'Mexicanos' }, { id: 778, name: 'Cordon Bleu' }, { id: 779, name: 'Viande Hachée' }],
        62: [{ id: 784, name: 'Mexicanos' }, { id: 785, name: 'Cordon Bleu' }, { id: 786, name: 'Viande Hachée' }],
        63: [{ id: 791, name: 'Mexicanos' }, { id: 792, name: 'Cordon Bleu' }, { id: 793, name: 'Viande Hachée' }],
        70: [{ id: 900, name: 'Ketchup' }, { id: 901, name: 'Mayonnaise' }],
    },
    extras: [],
    addons: [],
});

/** Ligne de panier telle que le wizard l'enregistre : un id par ATTRIBUT. */
const ligneTroisViandes = () => ({
    item_id: 234,
    name: 'Tacos XL',
    quantity: 1,
    convert_price: 10.9,
    item_variations: [
        { attribute_name: 'Viande 1', variation_name: 'Mexicanos', id: 777, quantity: 1 },
        { attribute_name: 'Viande 2', variation_name: 'Cordon Bleu', id: 785, quantity: 1 },
        { attribute_name: 'Viande 3', variation_name: 'Viande Hachée', id: 793, quantity: 1 },
    ],
    item_extras: [],
});

describe('caisse — « Modifier » restaure les viandes sous la clé que le wizard affiche', () => {
    it('les trois viandes sont restaurées sous les ids du PREMIER attribut', () => {
        const vm = createVm();
        const restore = vm.buildWizardRestorePayload(ligneTroisViandes(), itemTacosXL());

        expect(Object.keys(restore.viandes).sort()).toEqual(['v_777', 'v_778', 'v_779']);
        expect(restore.viandes.v_777).toBe(1);
        expect(restore.viandes.v_778).toBe(1);
        expect(restore.viandes.v_779).toBe(1);
    });

    it('AUCUNE clé ne porte l\'id d\'un attribut secondaire — c\'était le défaut', () => {
        const vm = createVm();
        const restore = vm.buildWizardRestorePayload(ligneTroisViandes(), itemTacosXL());

        for (const idSecondaire of ['v_784', 'v_785', 'v_786', 'v_791', 'v_792', 'v_793']) {
            expect(
                restore.viandes[idSecondaire],
                `RÉGRESSION P0-01 : ${idSecondaire} ne correspond à AUCUNE tuile du wizard `
                + '(tuiles dédoublonnées par nom sur le premier attribut) — la viande disparaîtrait de l\'écran.'
            ).toBeUndefined();
        }
    });

    it('le TOTAL de viandes est conservé : trois viandes restent trois viandes', () => {
        const vm = createVm();
        const restore = vm.buildWizardRestorePayload(ligneTroisViandes(), itemTacosXL());
        const total = Object.values(restore.viandes).reduce((s, n) => s + n, 0);
        expect(total).toBe(3);
    });

    it('deux fois la même viande s\'additionnent sous une seule clé', () => {
        const vm = createVm();
        const ligne = ligneTroisViandes();
        ligne.item_variations = [
            { attribute_name: 'Viande 1', variation_name: 'Mexicanos', id: 777, quantity: 1 },
            { attribute_name: 'Viande 2', variation_name: 'Mexicanos', id: 784, quantity: 1 },
        ];
        const restore = vm.buildWizardRestorePayload(ligne, itemTacosXL());
        expect(restore.viandes).toEqual({ v_777: 2 });
    });

    it('idViandeCanonique retombe sur l\'id d\'origine si le nom est introuvable', () => {
        const vm = createVm();
        // Repli explicite : mieux vaut l'ancien comportement qu'une clé inventée.
        expect(vm.idViandeCanonique(itemTacosXL(), 'Viande Inconnue', 4242)).toBe(4242);
        expect(vm.idViandeCanonique(null, 'Mexicanos', 777)).toBe(777);
    });

    it('la correspondance ignore accents et casse', () => {
        const vm = createVm();
        expect(vm.idViandeCanonique(itemTacosXL(), 'VIANDE HACHEE', 793)).toBe(779);
    });

    it('les sauces ne sont pas touchées par cette normalisation', () => {
        const vm = createVm();
        const ligne = ligneTroisViandes();
        ligne.item_variations.push({ attribute_name: 'Sauce', variation_name: 'Ketchup', id: 900, quantity: 1 });
        const restore = vm.buildWizardRestorePayload(ligne, itemTacosXL());
        expect(restore.sauces.s_900).toBe(true);
        expect(restore.sauceOrder).toContain('s_900');
    });
});
