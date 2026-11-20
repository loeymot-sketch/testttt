/**
 * posWizardSaucesOffertesCruditesPayantes.spec.js
 * -----------------------------------------------------------------------------
 * [OWNER 2026-11-11 LOCK_POS_KIOSK_SAUCES_CRUDITES_2026-11-11] Banc FONCTIONNEL : exécute le VRAI
 * public/js/pos-wizard.js (zone gelée) dans happy-dom via posWizardHarness.
 *
 * A — « Deux sauces offertes » sur un sandwich. Le serveur publie `sauce_included` (2 pour la
 *     catégorie « Sandwichs », 1 ailleurs). Le wizard ne facture que max(0, N − offertes) sauces via
 *     l'extra générique « Sauce supplémentaire » (0,50 € en base) : total affiché, pastilles
 *     « offerte » / « +0,50 », quantité transmise (data-wizard-qty) — et la ligne cuisine
 *     « Sauce : A, B » liste TOUJOURS toutes les sauces.
 *     Les sauces FRITES d'une formule gardent 1 offerte puis payantes, ajoutées côté Vue
 *     (ItemComponent.withFritesSauceSupplements) : quantité finale = max(0,N−offertes) + max(0,M−1).
 *
 * B — Crudités PAYANTES (groupe 'crudite', Maïs/Olives/Poivrons cuits à 0,90 €) : opt-in (jamais
 *     « ✓ » par défaut), prix visible sur le bouton, comptées dans le total, transmises et imprimées
 *     quand elles sont cochées.
 */
import { describe, it, expect, vi } from 'vitest';
import { mountPosWizard, cayenneLikeItem, drinksCatalogFixture, tick } from './posWizardHarness.js';

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

const SAUCE_SUPPL_ID = 700;

function sandwich(overrides = {}) {
    return cayenneLikeItem({
        sauce_included: 2,
        variations: {
            301: [
                { id: 9001, name: 'Poulet mariné', thumb: '' },
                { id: 9002, name: 'Mexicanos', thumb: '' },
            ],
            311: [
                { id: 9101, name: 'Algérienne', thumb: null },
                { id: 9102, name: 'Samouraï', thumb: null },
                { id: 9103, name: 'Blanche', thumb: null },
            ],
            320: [{ id: 9201, name: 'Pain', thumb: null }],
        },
        extras: [
            { id: 51, name: 'Oignon', convert_price: 0, currency_price: '€0.00', group_label: 'crudite', thumb: null },
            { id: 52, name: 'Salade', convert_price: 0, currency_price: '€0.00', group_label: 'crudite', thumb: null },
            { id: 70, name: 'Maïs', convert_price: 0.9, currency_price: '€0.90', group_label: 'crudite', thumb: null },
            { id: 71, name: 'Olives', convert_price: 0.9, currency_price: '€0.90', group_label: 'crudite', thumb: null },
            { id: 80, name: 'Cheddar', convert_price: 0.9, currency_price: '€0.90', group_label: 'supplement', thumb: null },
            { id: SAUCE_SUPPL_ID, name: 'Sauce supplémentaire', convert_price: 0.5, currency_price: '€0.50', thumb: null },
        ],
        ...overrides,
    });
}

/** Pont Vue (checkboxes `.extra .custom-checkbox-field`) pour les extras de l'item. */
function bridgeHtml(item) {
    return item.extras.map((e) => '<div class="extra"><label>' + e.name + '</label>'
        + '<input type="checkbox" class="custom-checkbox-field" value="' + e.id + '"></div>').join('');
}

const totalText = (root) => (root.querySelector('.sticky-total .total-value') || {}).textContent || '';
const root = () => document.getElementById('pos-wizard-root');
const chip = (r, id) => r.querySelector('.sauce-section .sauce-chip[data-id="s_' + id + '"]');
const garn = (r, id) => r.querySelector('.garniture-toggle-btn[data-garniture="c_' + id + '"]');

async function clickSauces(ids) {
    for (const id of ids) {
        chip(root(), id).click();
        await tick(10);
    }
}

async function pickMeat() {
    root().querySelector('.viande-btn.plus[data-viande="v_9001"]').click();
    await tick(10);
}

async function addToCart() {
    root().querySelector('[data-action="add-to-cart"]').click();
    await tick(500); // syncAndSubmit + submit 180 ms + fermeture 200 ms
}

/** Quantité « Sauce supplémentaire » telle que la lit ItemComponent.onWizardBridgeExtra. */
function bridgedQty(modal, extraId) {
    const cb = modal.querySelector('.custom-checkbox-field[value="' + extraId + '"]');
    if (!cb || !cb.checked) return 0;
    const q = parseInt(cb.getAttribute('data-wizard-qty'), 10);
    return Number.isFinite(q) && q > 0 ? q : 1;
}

describe('caisse — deux sauces offertes sur un sandwich (sauce_included)', () => {
    it('sandwich, 2 sauces : aucune « Sauce supplémentaire », total sans +0,50, les 2 pastilles « offerte »', async () => {
        const item = sandwich();
        const { modal } = await mountPosWizard({ itemData: item, originalBodyHtml: bridgeHtml(item) });
        await pickMeat();
        await clickSauces([9101, 9102]);

        const r = root();
        expect(totalText(r), '7,40 sans supplément sauce').toMatch(/7[.,]40/);
        expect(r.querySelector('.sauce-section .sauce-badge.paid'), 'aucun badge payant à 2 sauces').toBeNull();
        expect(chip(r, 9102).querySelector('.chip-free').style.display).not.toBe('none');
        expect(chip(r, 9102).querySelector('.chip-paid').style.display).toBe('none');
        // La cuisine lit TOUTES les sauces dans l'instruction.
        expect(r.querySelector('.ticket-content').textContent).toMatch(/Sauce\s*:\s*Algérienne,\s*Samouraï/);

        await addToCart();
        expect(bridgedQty(modal, SAUCE_SUPPL_ID), '0 sauce facturée').toBe(0);
    });

    it('sandwich, 3 sauces : 1 « Sauce supplémentaire », total +0,50, seule la 3ᵉ est payante', async () => {
        const item = sandwich();
        const { modal } = await mountPosWizard({ itemData: item, originalBodyHtml: bridgeHtml(item) });
        await pickMeat();
        await clickSauces([9101, 9102, 9103]);

        const r = root();
        expect(totalText(r)).toMatch(/7[.,]90/);
        expect(r.querySelector('.sauce-section .sauce-badge.paid').textContent).toMatch(/0[.,]50/);
        expect(chip(r, 9103).querySelector('.chip-paid').style.display).not.toBe('none');
        expect(chip(r, 9102).querySelector('.chip-paid').style.display).toBe('none');
        expect(r.querySelector('.ticket-content').textContent).toMatch(/Sauce\s*:\s*Algérienne,\s*Samouraï,\s*Blanche/);

        await addToCart();
        expect(bridgedQty(modal, SAUCE_SUPPL_ID)).toBe(1);
    });

    it('tacos (sauce_included 1), 2 sauces : 1 « Sauce supplémentaire » (règle inchangée hors sandwichs)', async () => {
        const item = sandwich({ name: 'Tacos M', category_name: 'Tacos', sauce_included: 1 });
        const { modal } = await mountPosWizard({ itemData: item, originalBodyHtml: bridgeHtml(item) });
        await pickMeat();
        await clickSauces([9101, 9102]);

        expect(totalText(root())).toMatch(/7[.,]90/);
        await addToCart();
        expect(bridgedQty(modal, SAUCE_SUPPL_ID)).toBe(1);
    });

    it('sauce_included absent ou invalide → 1 offerte (repli sûr)', async () => {
        const item = sandwich({ sauce_included: 'n/a' });
        const { modal } = await mountPosWizard({ itemData: item, originalBodyHtml: bridgeHtml(item) });
        await pickMeat();
        await clickSauces([9101, 9102]);
        await addToCart();
        expect(bridgedQty(modal, SAUCE_SUPPL_ID)).toBe(1);
    });

    it('formule frites : 2 sauces sandwich (offertes) + 2 sauces frites → 1 « Sauce supplémentaire » facturée au total', async () => {
        const item = sandwich();
        const { modal } = await mountPosWizard({
            itemData: item,
            drinksCatalog: drinksCatalogFixture(),
            originalBodyHtml: bridgeHtml(item),
        });
        // L'attribut est retiré à la fermeture du wizard : on capte la valeur au moment où il est posé.
        let capturedLines = null;
        const nativeSet = modal.setAttribute.bind(modal);
        modal.setAttribute = (name, value) => {
            if (name === 'data-wizard-pos-line-addons') capturedLines = value;
            return nativeSet(name, value);
        };
        await pickMeat();
        await clickSauces([9101, 9102]);
        root().querySelector('.formule-card[data-value="addon_1"]').click();
        await tick(20);
        root().querySelector('.sauce-frites-inline .sauce-chip[data-id="sf_9101"]').click();
        await tick(10);
        root().querySelector('.sauce-frites-inline .sauce-chip[data-id="sf_9102"]').click();
        await tick(10);
        const boisson = root().querySelector('.boisson-opt[data-id="52"]');
        if (boisson) { boisson.click(); await tick(10); }

        await addToCart();
        const wizardQty = bridgedQty(modal, SAUCE_SUPPL_ID);
        expect(wizardQty, 'sauces sandwich : 0 (2 offertes)').toBe(0);

        // Le pont Vue réel : quantité produit (wizard) + sauces frites (M − 1) sur l'extra du parent.
        modal.setAttribute = nativeSet;
        const lines = JSON.parse(capturedLines || '[]');
        expect(lines.length, 'ligne formule transmise').toBe(1);
        expect(lines[0].menu_restore.sauceFritesOrder).toHaveLength(2);

        const vm = vmFor(item, lines, wizardQty);
        const payload = vm.buildPosCartMainPayload();
        const sauce = payload.item_extras.find((e) => e.id === SAUCE_SUPPL_ID);
        expect(sauce, 'la 2ᵉ sauce frites reste facturée').toBeTruthy();
        expect(sauce.quantity).toBe(1); // max(0, 2−2) + max(0, 2−1)
    });
});

describe('caisse — crudités payantes : opt-in, prix visible, transmises et imprimées', () => {
    it('Maïs (0,90 €) n\'est PAS coché par défaut, affiche son prix, et n\'est ni compté ni transmis', async () => {
        const item = sandwich();
        const { modal } = await mountPosWizard({ itemData: item, originalBodyHtml: bridgeHtml(item) });
        const r = root();
        const mais = garn(r, 70);
        expect(mais.textContent, 'pas de ✓ fantôme').not.toMatch(/✓/);
        expect(mais.textContent).toMatch(/\+0[.,]90/);
        expect(garn(r, 52).textContent, 'Salade gratuite reste incluse').toMatch(/✓ Salade/);
        expect(r.querySelector('.ticket-content').textContent).not.toMatch(/Maïs/);

        await pickMeat();
        await clickSauces([9101]);
        await addToCart();
        const cb = (v) => modal.querySelector('.custom-checkbox-field[value="' + v + '"]');
        expect(cb('70').checked, 'Maïs non transmis').toBe(false);
        expect(cb('52').checked, 'Salade transmise').toBe(true);
    });

    it('Maïs coché → « ✓ », +0,90 au total, ligne cuisine, et transmis au pont', async () => {
        const item = sandwich();
        const { modal } = await mountPosWizard({ itemData: item, originalBodyHtml: bridgeHtml(item) });
        await pickMeat();
        await clickSauces([9101]);
        garn(root(), 70).click();
        await tick(10);

        const r = root();
        expect(garn(r, 70).textContent).toMatch(/✓ Maïs/);
        expect(garn(r, 70).textContent).toMatch(/\+0[.,]90/);
        expect(totalText(r), '7,40 + 0,90').toMatch(/8[.,]30/);
        expect(r.querySelector('.ticket-content').textContent).toMatch(/Maïs/);

        await addToCart();
        expect(modal.querySelector('.custom-checkbox-field[value="70"]').checked).toBe(true);
    });

    it('le bloc « Suppléments » ne propose aucune crudité (Maïs/Olives), mais garde le vrai supplément', async () => {
        const item = sandwich();
        await mountPosWizard({ itemData: item });
        const keys = Array.from(root().querySelectorAll('.supplements-section [data-key]')).map((n) => n.getAttribute('data-key'));
        expect(keys).not.toContain('p_70');
        expect(keys).not.toContain('p_71');
        expect(keys).toContain('p_80');
    });
});

// ---------------------------------------------------------------------------
// C — « Modifier » depuis le panier : la quantité de viandes EN PLUS est ré-appliquée.
// ---------------------------------------------------------------------------
const VIANDE_SUPPL_ID = 398;

function tacos2Viandes() {
    return cayenneLikeItem({
        id: 40,
        name: 'Tacos L (2 viandes)',
        category_name: 'Tacos',
        convert_price: 9,
        sauce_included: 1,
        itemAttributes: [
            { id: 301, name: 'Viande 1', max_select: 1 },
            { id: 302, name: 'Viande 2', max_select: 1 },
            { id: 311, name: 'Sauce (1ère Gratuite)' },
        ],
        variations: {
            301: [{ id: 9001, name: 'Poulet mariné', thumb: '' }, { id: 9002, name: 'Mexicanos', thumb: '' }, { id: 9003, name: 'Kefta', thumb: '' }],
            302: [{ id: 9011, name: 'Poulet mariné', thumb: '' }, { id: 9012, name: 'Mexicanos', thumb: '' }, { id: 9013, name: 'Kefta', thumb: '' }],
            311: [{ id: 9101, name: 'Algérienne', thumb: null }],
        },
        extras: [
            { id: 52, name: 'Salade', convert_price: 0, currency_price: '€0.00', group_label: 'crudite', thumb: null },
            { id: 70, name: 'Maïs', convert_price: 0.9, currency_price: '€0.90', group_label: 'crudite', thumb: null },
            { id: 80, name: 'Cheddar', convert_price: 0.9, currency_price: '€0.90', group_label: 'supplement', thumb: null },
            { id: VIANDE_SUPPL_ID, name: 'Viande supplémentaire', convert_price: 2.5, currency_price: '€2.50', thumb: null },
        ],
        addons: [],
    });
}

/** Ligne de panier telle que l'enregistre la caisse : 2 viandes incluses + 1 Kefta en plus + Maïs. */
function ligneTacos() {
    return {
        item_id: 40,
        name: 'Tacos L (2 viandes)',
        quantity: 1,
        convert_price: 9,
        item_variations: [
            { attribute_name: 'Viande 1', variation_name: 'Poulet mariné', id: 9001, quantity: 1 },
            { attribute_name: 'Viande 2', variation_name: 'Mexicanos', id: 9012, quantity: 1 },
        ],
        item_extras: [
            { id: 52, name: 'Salade', quantity: 1 },
            { id: 70, name: 'Maïs', quantity: 1 },
            { id: VIANDE_SUPPL_ID, name: 'Viande supplémentaire', quantity: 1 },
        ],
        pos_line_addons: [],
        instruction: 'TACOS L (2 VIANDES)\nViandes : Poulet mariné, Mexicanos, +Kefta - Salade, Maïs Sauce : Algérienne\nViandes en plus : Kefta',
    };
}

describe('caisse — « Modifier » une ligne : viandes en plus et crudités payantes', () => {
    it('openEditFromCart ne recharge PAS l\'extra générique « Viande supplémentaire » (ni la sauce)', async () => {
        const item = tacos2Viandes();
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: item } }));
        const vm = vmFor(item, [], 0);
        vm.$store.dispatch = dispatch;
        vm.$nextTick = (cb) => (cb ? Promise.resolve().then(cb) : Promise.resolve());
        try { vm.openEditFromCart(ligneTacos(), 0); } catch (_) { /* ouverture DOM hors sujet */ }
        await new Promise((r) => setTimeout(r, 0));
        expect(dispatch).toHaveBeenCalled();
        expect(vm.getExtraQuantity(VIANDE_SUPPL_ID), 'case du pont décochée → le wizard la re-clique').toBe(0);
        expect(vm.getExtraQuantity(70), 'Maïs, extra ordinaire, reste rechargé').toBe(1);
    });

    it('buildWizardRestorePayload : viandes en plus nommées depuis l\'instruction, Maïs en crudité, rien en p_398', () => {
        const vm = vmFor(tacos2Viandes(), [], 0);
        const restore = vm.buildWizardRestorePayload(ligneTacos(), tacos2Viandes());
        expect(restore.viandeSupplItems).toEqual({ v_9003: 1 });
        expect(restore.supplements['p_' + VIANDE_SUPPL_ID]).toBeUndefined();
        expect(restore.supplements.p_70, 'Maïs n\'est pas un supplément').toBeUndefined();
        expect(restore.garnitures.c_70).toBe(true);
    });

    it('« 2× Kefta » dans l\'instruction → { v_9003: 2 } (id canonique du 1er attribut)', () => {
        const vm = vmFor(tacos2Viandes(), [], 0);
        const ligne = ligneTacos();
        ligne.instruction = 'TACOS L\nViandes : Poulet mariné, Mexicanos, +2\u00d7Kefta\nViandes en plus : 2\u00d7 Kefta';
        expect(vm.buildWizardRestorePayload(ligne, tacos2Viandes()).viandeSupplItems).toEqual({ v_9003: 2 });
    });

    it('vrai wizard : rouvrir, passer de 1 à 2 Kefta en plus → 2 « Viande supplémentaire » transmises', async () => {
        const item = tacos2Viandes();
        const vm = vmFor(item, [], 0);
        const restore = vm.buildWizardRestorePayload(ligneTacos(), item);
        // Pont tel que Vue le rend APRÈS openEditFromCart : case viande suppl décochée (quantité 0).
        const html = bridgeHtml(item);
        const existing = document.getElementById('item-variation-modal');
        if (existing) existing.setAttribute('data-wizard-restore-selections', JSON.stringify(restore));
        const { modal } = await mountPosWizard({ itemData: item, originalBodyHtml: html });
        expect(existing, 'modal réutilisé par le harnais').toBeTruthy();

        const r = root();
        expect(garn(r, 70).textContent, 'Maïs restauré coché').toMatch(/✓ Maïs/);
        r.querySelector('.viande-tile-add.plus[data-viande="v_9003"]').click();
        await tick(10);
        await addToCart();
        expect(bridgedQty(modal, VIANDE_SUPPL_ID), 'nouvelle quantité ré-appliquée').toBe(2);
        expect(modal.querySelector('.custom-checkbox-field[value="70"]').checked, 'Maïs re-transmis').toBe(true);
    });

    it('extra demandé mais devenu indisponible : retiré ET signalé (plus de retrait muet)', async () => {
        const alertService = (await import('../../resources/js/services/alertService')).default;
        const spy = vi.spyOn(alertService, 'error').mockImplementation(() => {});
        const item = tacos2Viandes();
        item.extras = item.extras.map((e) => (e.id === 80 ? { ...e, is_available: false, unavailable_reason: 'stock_rupture' } : e));
        const vm = vmFor(item, [], 0);
        vm.temp.item_extras = [{ id: 80, name: 'Cheddar', quantity: 1 }];
        const payload = vm.buildPosCartMainPayload();
        expect(payload.item_extras.find((e) => e.id === 80)).toBeUndefined();
        expect(spy).toHaveBeenCalledTimes(1);
        expect(String(spy.mock.calls[0][0])).toMatch(/Cheddar/);
        spy.mockRestore();
    });
});

// ---------------------------------------------------------------------------
// Pont Vue (ItemComponent) — même fabrique que posFormuleFritesFacturation.spec.js.
// ---------------------------------------------------------------------------
function vmFor(item, wizardLines, productSauceQty) {
    const data = ItemComponent.data.call({});
    const vm = {
        ...data,
        item: JSON.parse(JSON.stringify(item)),
        $t: (key) => key,
        $store: { dispatch: vi.fn(() => Promise.resolve()), getters: { 'frontendSetting/lists': {} } },
        $refs: {
            itemVariationModal: {
                dataset: { wizardTotal: '9.90' },
                getAttribute: (name) => (name === 'data-wizard-pos-line-addons' ? JSON.stringify(wizardLines) : null),
                setAttribute: vi.fn(),
                removeAttribute: vi.fn(),
                classList: { add: vi.fn(), remove: vi.fn(), contains: () => false },
                focus: vi.fn(),
            },
            itemInfoModal: { classList: { add: vi.fn(), remove: vi.fn(), contains: () => false } },
        },
    };
    Object.entries(ItemComponent.methods).forEach(([name, fn]) => { vm[name] = fn.bind(vm); });
    vm.resetTempState();
    vm.temp.name = item.name;
    vm.temp.item_id = item.id;
    vm.temp.quantity = 1;
    vm.temp.convert_price = item.convert_price;
    if (productSauceQty > 0) {
        vm.temp.item_extras = [{ id: SAUCE_SUPPL_ID, quantity: productSauceQty, name: 'Sauce supplémentaire' }];
    }
    return vm;
}
