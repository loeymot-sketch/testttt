/**
 * [VG-2026-10-21 M3-001 · P0] Une ligne Tacos M de la caisse ne porte QUE les viandes choisies
 * par le caissier.
 *
 * Constat (base locale, commandes 8023 et 8056) : un Tacos M vendu avec UNE viande (Poulet
 * mariné) scellait aussi « Viande 3 = Cordon Bleu » — jamais commandée, mais lue en cuisine,
 * au suivi et sur le ticket client. Le prix restait juste ; la composition scellée était fausse.
 *
 * Chemin établi :
 *  1. `item_attributes.id=3` (« Viande 3 ») est à `min_select=1` en base depuis le 29/09
 *     (EnsureTacosXl3ViandesCommand), alors que le profil publié dit `viande_3` min 0 ;
 *  2. `ItemComponent.initializeDefaultSelections()` pré-remplissait la 1ʳᵉ variation de tout
 *     attribut à choix unique et `min_select > 0` → « Viande 3 = Cordon Bleu » d'office ;
 *  3. le wizard caisse (GELÉ, `public/js/pos-wizard.js` syncAndSubmit §4c) n'ÉCRIT que les
 *     viandes choisies, dans les <select> de pont, dans l'ordre (1 viande → 1ᵉʳ select) ; il
 *     n'efface jamais les autres. Le pré-remplissage survivait donc jusqu'au panier.
 *  Même mécanique à la modification / duplication d'une ligne : les viandes d'avant restaient
 *  dans `temp` et toute viande retirée dans le wizard repartait quand même.
 *
 * Ce banc MONTE le vrai composant, ouvre le produit comme un clic de tuile, reproduit à
 * l'identique l'écriture du wizard gelé dans les <select> de pont, déclenche
 * `wizard:add-to-cart` et lit la ligne réellement envoyée au panier.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        requestHandler: () => '',
        currencyFormat: (v) => String(v),
        textShortener: (text) => text,
        onlyNumber: () => true,
        modalHide: () => {},
    },
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { success: () => {}, error: () => {}, info: () => {}, warning: () => {} },
}));

import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';

const VIANDES = [
    'Poulet mariné', 'Cordon Bleu', 'Kefta', 'Mexicanos', 'Tenders', 'Nuggets', 'Steak',
];

/** Une variation par viande et par emplacement — ids distincts par attribut, comme en base. */
function viandesSous(attributeId, baseId) {
    return VIANDES.map((name, i) => ({
        id: baseId + i,
        item_attribute_id: attributeId,
        name,
        convert_price: 0,
        currency_price: '0.00 €',
        price: 0,
        status: 5,
        is_available: true,
    }));
}

/** Tacos M tel que `item/details?surface=pos` le sert sur la base locale (2026-10-22). */
function tacosM() {
    return {
        id: 26,
        name: 'Tacos M',
        description: 'Galette de blé, 1 viande au choix, frites maison et sauce.',
        caution: '',
        thumb: '',
        offer: [],
        convert_price: 6.9,
        currency_price: '6.90 €',
        is_available: true,
        itemAttributes: [
            { id: 1, name: 'Viande 1', min_select: 1, max_select: 1, allow_repeat: false },
            { id: 2, name: 'Viande 2', min_select: 0, max_select: 1, allow_repeat: false },
            // Valeur RÉELLE en base depuis le 29/09 — la cause du P0.
            { id: 3, name: 'Viande 3', min_select: 1, max_select: 1, allow_repeat: false },
            { id: 5, name: 'Sauce (1ère Gratuite)', min_select: 1, max_select: 1, allow_repeat: false },
        ],
        variations: {
            1: viandesSous(1, 43),
            2: viandesSous(2, 1175),
            3: viandesSous(3, 1185),
            5: [{ id: 317, item_attribute_id: 5, name: 'Barbecue', convert_price: 0, currency_price: '0.00 €', price: 0, status: 5, is_available: true }],
        },
        extras: [],
        addons: [],
        composer_profile: {
            steps: [
                { step_key: 'viande', source_type: 'item_attribute', source_ref: '1', min_select: 1, max_select: 4 },
                { step_key: 'viande_2', source_type: 'item_attribute', source_ref: '2', min_select: 0, max_select: 4 },
                { step_key: 'viande_3', source_type: 'item_attribute', source_ref: '3', min_select: 0, max_select: 4 },
                { step_key: 'sauce', source_type: 'item_attribute', source_ref: '5', min_select: 1, max_select: 2 },
            ],
        },
    };
}

function monter(detail) {
    const envois = [];
    const store = {
        getters: { 'frontendSetting/lists': {}, 'auth/authBranchId': 1 },
        state: {},
        commit: vi.fn(),
        dispatch: vi.fn((action, payload) => {
            if (action === 'item/details') return Promise.resolve({ data: { data: JSON.parse(JSON.stringify(detail)) } });
            if (action === 'posCart/addOptimistic') return Promise.resolve(1);
            if (action === 'posCart/lists' || action === 'posCart/replaceCartLine') {
                envois.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
            }
            return Promise.resolve();
        }),
    };
    const wrapper = mount(ItemComponent, {
        attachTo: document.body,
        props: { items: {} },
        global: {
            mocks: { $t: (k) => k, $store: store },
            stubs: { Swiper: true, SwiperSlide: true },
        },
    });
    return { wrapper, envois };
}

/**
 * Réplique FIDÈLE de `public/js/pos-wizard.js` syncAndSubmit §4c (zone gelée, non modifiée) :
 * les <select> dont le bloc mentionne « viande », remplis DANS L'ORDRE avec les viandes choisies.
 */
function wizardEcritLesViandes(modal, nomsChoisis) {
    const norm = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    const selects = Array.from(modal.querySelectorAll('select')).filter((sel) => {
        const scope = sel.closest('.row, .col, .form-group, .mb-2, .mb-3') || sel.parentElement;
        const txt = norm(scope ? scope.textContent : (sel.name || ''));
        return txt.includes('viande') || txt.includes('meat');
    });
    nomsChoisis.forEach((nom, idx) => {
        const target = selects[idx];
        if (!target) return;
        const match = Array.from(target.options).find((opt) => norm(opt.textContent) === norm(nom));
        if (match) {
            target.value = match.value;
            target.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
}

function viandesEnvoyees(ligne) {
    return ligne.item_variations.filter((v) => /viande/i.test(v.variation_name || ''));
}

let courant = null;
afterEach(() => {
    if (courant) courant.unmount();
    courant = null;
    document.body.innerHTML = '';
});

describe('caisse — Tacos M : aucune viande choisie à la place du caissier (VG-2026-10-21 M3-001)', () => {
    it('une seule viande choisie → la ligne envoyée au panier porte UNE seule viande', async () => {
        const { wrapper, envois } = monter(tacosM());
        courant = wrapper;

        wrapper.vm.variationModalShow({ id: 26, name: 'Tacos M' });
        await flushPromises();

        const modal = document.getElementById('item-variation-modal');
        wizardEcritLesViandes(modal, ['Poulet mariné']);
        modal.dataset.wizardTotal = '6.9';
        modal.dispatchEvent(new CustomEvent('wizard:add-to-cart', { bubbles: false }));
        await flushPromises();

        const envoi = envois.find((e) => e.action === 'posCart/lists');
        expect(envoi, 'la ligne doit partir au panier').toBeTruthy();
        const viandes = viandesEnvoyees(envoi.payload[0]);
        expect(
            viandes.map((v) => `${v.variation_name}=${v.name}`),
            'aucune viande n\'a été choisie sous « Viande 3 » : rien ne doit y partir en cuisine ni être scellé'
        ).toEqual(['Viande 1=Poulet mariné']);
    });

    it('à la modification, une viande RETIRÉE dans le wizard ne repart pas avec la ligne', async () => {
        const { wrapper, envois } = monter(tacosM());
        courant = wrapper;

        const ligne = {
            item_id: 26,
            name: 'Tacos M',
            quantity: 1,
            convert_price: 6.9,
            currency_price: '6.90 €',
            instruction: '',
            item_variations: [
                { id: 43, item_attribute_id: 1, quantity: 1, variation_name: 'Viande 1', name: 'Poulet mariné' },
                { id: 1176, item_attribute_id: 2, quantity: 1, variation_name: 'Viande 2', name: 'Cordon Bleu' },
                { id: 317, item_attribute_id: 5, quantity: 1, variation_name: 'Sauce (1ère Gratuite)', name: 'Barbecue' },
            ],
            item_extras: [],
            pos_line_addons: [],
        };
        wrapper.vm.openEditFromCart(ligne, 0);
        await flushPromises();

        // Le caissier garde le poulet et retire le Cordon Bleu : le wizard n'écrit qu'un select.
        const modal = document.getElementById('item-variation-modal');
        wizardEcritLesViandes(modal, ['Poulet mariné']);
        modal.dataset.wizardTotal = '6.9';
        modal.dispatchEvent(new CustomEvent('wizard:add-to-cart', { bubbles: false }));
        await flushPromises();

        const envoi = envois.find((e) => e.action === 'posCart/replaceCartLine');
        expect(envoi, 'la ligne modifiée doit être remplacée').toBeTruthy();
        expect(viandesEnvoyees(envoi.payload.item).map((v) => `${v.variation_name}=${v.name}`))
            .toEqual(['Viande 1=Poulet mariné']);
        // La sauce, elle, n'est pas concernée et reste.
        expect(envoi.payload.item.item_variations.some((v) => v.id === 317)).toBe(true);
    });
});
