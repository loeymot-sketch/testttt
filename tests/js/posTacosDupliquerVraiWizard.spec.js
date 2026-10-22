/**
 * [VG-2026-10-21 M3-001 · tour 2] « Dupliquer » une ligne Tacos M à la caisse.
 *
 * Banc de bout en bout côté navigateur : le VRAI composant ItemComponent (monté), le VRAI
 * panier Vuex (`posCart`) et le VRAI wizard gelé `public/js/pos-wizard.js` (exécuté tel quel,
 * seulement instrumenté pour l'ouvrir de façon déterministe — même méthode que
 * posWizardHarness.js). Rien n'est imité : le caissier clique dans le wizard.
 *
 * Scénario du tour 2 (spec Playwright M3-07) : Tacos M, 1 viande + Barbecue → 1 ligne ;
 * « Dupliquer » → le wizard se rouvre → « Ajouter au panier ».
 *
 * Mesure (2026-10-22) : la duplication est ACCEPTÉE, sans aucun message d'erreur, et reconduit
 * la viande d'origine. Comme la copie est identique à l'original, le panier (posCart.js,
 * samePosLineMergeSignature — comportement préexistant, identique pour un Cayenne) la FUSIONNE
 * dans la ligne d'origine : 1 ligne × 2. Avant le correctif M3-001 la copie portait une viande
 * fantôme de plus, donc une signature différente, donc une 2ᵉ ligne : c'est ce que le test
 * Playwright M3-07 attendait.
 *
 * [VG-2026-10-21 C4] Correctif : « Dupliquer » passe { newLine: true } (PosComponent.duplicateCartLine →
 * openEditFromCart → addToCart → posCart/addLine). La copie est une SECONDE ligne (on duplique pour
 * l'ajuster) ; l'ajout NORMAL du même article deux fois, lui, fusionne toujours.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import { readFileSync } from 'fs';
import { resolve } from 'path';

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        requestHandler: () => '',
        currencyFormat: (v) => String(v),
        textShortener: (text) => text,
        onlyNumber: () => true,
        modalHide: () => {},
    },
}));
const alertes = { success: [], error: [], info: [], warning: [] };
vi.mock('../../resources/js/services/alertService', () => ({
    default: {
        success: (m) => alertes.success.push(m),
        error: (m) => alertes.error.push(m),
        info: (m) => alertes.info.push(m),
        warning: (m) => alertes.warning.push(m),
    },
}));

import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';
import { posCart } from '../../resources/js/store/modules/posCart';

const tick = (ms = 30) => new Promise((r) => setTimeout(r, ms));

const RAW = readFileSync(resolve(__dirname, '../../public/js/pos-wizard.js'), 'utf8');
const SOURCE = RAW.replace(/\}\)\(\);\s*$/, 'window.__wizTest = { open: openWizard, close: closeWizard };\n})();\n');

const VIANDES = ['Poulet mariné', 'Cordon Bleu', 'Kefta'];
function viandesSous(attributeId, baseId) {
    return VIANDES.map((name, i) => ({
        id: baseId + i, item_attribute_id: attributeId, name,
        convert_price: 0, currency_price: '0.00 €', price: 0, status: 5, is_available: true, thumb: '',
    }));
}

/** Tacos M tel que servi par `item/details?surface=pos` (base locale 2026-10-22). */
function tacosM() {
    return {
        id: 26,
        name: 'Tacos M',
        category_name: 'Tacos',
        description: 'Galette de blé, 1 viande au choix, frites maison et sauce.',
        caution: '', thumb: '', offer: [],
        convert_price: 6.9, currency_price: '6.90 €', is_available: true,
        itemAttributes: [
            { id: 1, name: 'Viande 1', min_select: 1, max_select: 1, allow_repeat: false },
            { id: 2, name: 'Viande 2', min_select: 0, max_select: 1, allow_repeat: false },
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
        // Profil publié (base locale, profil 93 v9) : viande obligatoire, viande_2/3 facultatives.
        composer_profile: {
            steps: [
                { step_key: 'viande', label: 'Viande', source_type: 'item_attribute', source_ref: '1', min_select: 1, max_select: 4, visible_on: ['pos', 'kiosk'], choices: viandesSous(1, 43) },
                { step_key: 'viande_2', label: 'Viande 2', source_type: 'item_attribute', source_ref: '2', min_select: 0, max_select: 4, visible_on: ['pos', 'kiosk'], choices: viandesSous(2, 1175) },
                { step_key: 'viande_3', label: 'Viande 3', source_type: 'item_attribute', source_ref: '3', min_select: 0, max_select: 4, visible_on: ['pos', 'kiosk'], choices: viandesSous(3, 1185) },
                { step_key: 'sauce', label: 'Sauce', source_type: 'item_attribute', source_ref: '5', min_select: 1, max_select: 2, visible_on: ['pos', 'kiosk'], choices: [] },
            ],
        },
    };
}

function creerStore() {
    return createStore({
        modules: {
            posCart,
            item: {
                namespaced: true,
                actions: {
                    details: () => Promise.resolve({ data: { data: JSON.parse(JSON.stringify(tacosM())) } }),
                    invalidateDetails: () => {},
                },
            },
        },
        getters: {
            'frontendSetting/lists': () => ({}),
        },
    });
}

let booted = false;
async function ouvrirWizard(modal) {
    if (!booted) {
        // eslint-disable-next-line no-new-func
        new Function(SOURCE)();
        booted = true;
        document.dispatchEvent(new Event('DOMContentLoaded'));
        await tick(10);
    }
    await tick(80); // l'observateur du wizard peut l'ouvrir lui-même
    if (!document.getElementById('pos-wizard-root')) window.__wizTest.open(modal);
    await tick(30);
    expect(document.querySelectorAll('#pos-wizard-root').length, 'un seul wizard ouvert').toBe(1);
    return document.getElementById('pos-wizard-root');
}

async function fermerWizard() {
    if (window.__wizTest) window.__wizTest.close();
    await tick(10);
}

function lignesPanier(store) {
    return store.state.posCart.lists.map((l) => ({
        quantity: l.quantity,
        viandes: (l.item_variations || [])
            .filter((v) => /viande/i.test(v.variation_name || ''))
            .map((v) => `${v.variation_name}=${v.name}`),
    }));
}

let wrapper = null;
afterEach(async () => {
    await fermerWizard();
    if (wrapper) wrapper.unmount();
    wrapper = null;
});

// La base locale ET la production servent FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true ; le mode
// historique est gardé pour ne dépendre d'aucun des deux chemins de construction du wizard.
describe.each([
    ['wizard piloté par le profil publié', true],
    ['wizard historique', false],
])('caisse — « Dupliquer » un Tacos M (vrai wizard, vrai panier) — %s', (_mode, composerAware) => {
    it('la duplication est acceptée sans erreur et reconduit la viande choisie, sans fantôme', async () => {
        window.foodkingConfig = { posWizardComposerAware: { enabled: composerAware } };
        alertes.error.length = 0;
        try { localStorage.clear(); } catch (e) { /* ignore */ }
        const store = creerStore();
        await store.dispatch('posCart/resetCart'); // l'état du module est partagé entre magasins
        wrapper = mount(ItemComponent, {
            attachTo: document.body,
            props: { items: {} },
            global: { mocks: { $t: (k) => k, $store: store }, stubs: { Swiper: true, SwiperSlide: true } },
        });

        // 1) Ajout normal : 1 viande + Barbecue.
        wrapper.vm.variationModalShow({ id: 26, name: 'Tacos M' });
        await flushPromises();
        const modal = document.getElementById('item-variation-modal');
        let wiz = await ouvrirWizard(modal);
        expect(wiz, 'wizard rendu').toBeTruthy();
        wiz.querySelector('button.viande-tile-add').click(); await tick();
        const sauce = Array.from(wiz.querySelectorAll('button.sauce-chip')).find((b) => /Barbecue/.test(b.textContent));
        sauce.click(); await tick();
        wiz.querySelector('button.wizard-btn-cart').click();
        await tick(450); await flushPromises(); // le wizard soumet après 180 ms
        await fermerWizard();

        expect(lignesPanier(store), 'ajout initial').toEqual([{ quantity: 1, viandes: ['Viande 1=Poulet mariné'] }]);

        // 2) « Dupliquer » = openEditFromCart(ligne, null, { newLine: true }) — l'appel exact de
        // PosComponent.duplicateCartLine — puis « Ajouter au panier » sans rien changer.
        wrapper.vm.openEditFromCart(JSON.parse(JSON.stringify(store.state.posCart.lists[0])), null, { newLine: true });
        await flushPromises();
        wiz = await ouvrirWizard(modal);
        expect(wiz.querySelector('.quota-badge').textContent.trim()).toMatch(/^1\/1/);
        wiz.querySelector('button.wizard-btn-cart').click();
        await tick(450); await flushPromises(); // le wizard soumet après 180 ms

        const refus = document.querySelector('#pos-wizard-root .wizard-validation-error');
        expect(refus && refus.style.display !== 'none' ? refus.textContent : null, 'aucun refus du wizard').toBeNull();
        expect(alertes.error, 'aucune erreur caisse').toEqual([]);
        // [VG-2026-10-21 C4] La copie est une SECONDE ligne (on duplique pour l'ajuster), pas une fusion.
        expect(lignesPanier(store), 'deux lignes, chacune avec la seule viande choisie').toEqual([
            { quantity: 1, viandes: ['Viande 1=Poulet mariné'] },
            { quantity: 1, viandes: ['Viande 1=Poulet mariné'] },
        ]);
        expect(store.state.posCart.subtotal, 'total = deux Tacos M').toBeCloseTo(13.8, 2);
    });

    it("[VG-2026-10-21 C4] l'ajout NORMAL du même Tacos M deux fois fusionne toujours (1 ligne × 2)", async () => {
        window.foodkingConfig = { posWizardComposerAware: { enabled: composerAware } };
        alertes.error.length = 0;
        try { localStorage.clear(); } catch (e) { /* ignore */ }
        const store = creerStore();
        await store.dispatch('posCart/resetCart');
        wrapper = mount(ItemComponent, {
            attachTo: document.body,
            props: { items: {} },
            global: { mocks: { $t: (k) => k, $store: store }, stubs: { Swiper: true, SwiperSlide: true } },
        });
        const modal = document.getElementById('item-variation-modal');
        for (let i = 0; i < 2; i++) {
            wrapper.vm.variationModalShow({ id: 26, name: 'Tacos M' });
            await flushPromises();
            const wiz = await ouvrirWizard(modal);
            wiz.querySelector('button.viande-tile-add').click(); await tick();
            const sauce = Array.from(wiz.querySelectorAll('button.sauce-chip')).find((b) => /Barbecue/.test(b.textContent));
            sauce.click(); await tick();
            wiz.querySelector('button.wizard-btn-cart').click();
            await tick(450); await flushPromises();
            await fermerWizard();
        }
        expect(alertes.error, 'aucune erreur caisse').toEqual([]);
        expect(lignesPanier(store)).toEqual([{ quantity: 2, viandes: ['Viande 1=Poulet mariné'] }]);
    });
});

describe('caisse — un ajout refusé par le panier ne reste pas muet', () => {
    it("[VG-2026-10-21 C4] si le panier rejette l'ajout, le caissier voit une alerte", async () => {
        alertes.error.length = 0;
        try { localStorage.clear(); } catch (e) { /* ignore */ }
        const store = createStore({
            modules: {
                posCart: {
                    ...posCart,
                    actions: {
                        ...posCart.actions,
                        lists: () => Promise.reject(new Error('panier indisponible')),
                    },
                },
                item: {
                    namespaced: true,
                    actions: {
                        details: () => Promise.resolve({ data: { data: JSON.parse(JSON.stringify(tacosM())) } }),
                        invalidateDetails: () => {},
                    },
                },
            },
            getters: { 'frontendSetting/lists': () => ({}) },
        });
        await store.dispatch('posCart/resetCart');
        wrapper = mount(ItemComponent, {
            attachTo: document.body,
            props: { items: {} },
            global: { mocks: { $t: (k) => k, $store: store }, stubs: { Swiper: true, SwiperSlide: true } },
        });
        wrapper.vm.variationModalShow({ id: 26, name: 'Tacos M' });
        await flushPromises();
        wrapper.vm.addToCart(true);
        await flushPromises(); await tick();
        expect(store.state.posCart.lists, "la ligne optimiste est retirée").toEqual([]);
        expect(alertes.error.length, 'une alerte visible').toBe(1);
    });
});
