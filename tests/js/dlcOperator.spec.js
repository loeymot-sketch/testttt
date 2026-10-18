/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia « Qui ? obligatoire partout »] L'écran des étiquettes DLC : « Qui ? » en tête, la personne (et son jeton si elle a un code)
 * part avec chaque impression ; un refus de « Qui ? » arrête le lot (inutile de refuser 8 fois) ; hors ligne, la personne est écrite en toutes lettres.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, printBlocks: vi.fn(), printer: null, alert: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: h.alert }));
vi.mock('../../resources/js/helpers/labelPrintJob', () => ({
    printBlocks: (...a) => h.printBlocks(...a),
    previewUrls: (list) => ({ urls: list.map(() => 'data:image/png;base64,AA=='), overflow: false }),
}));
vi.mock('../../resources/js/helpers/labelPrinter.js', async (orig) => ({ ...(await orig()), labelPrinter: () => h.printer }));

import LabelDlcComponent from '../../resources/js/components/admin/labels/LabelDlcComponent.vue';

const printer = { isSupported: () => true, getState: () => ({ status: 'connected', printer: null }), onChange: () => () => {}, startKeepAlive() {}, stopKeepAlive() {}, isConnected: () => true };
const P = (over) => ({
    id: 1, name: 'Oignons émincés', category: 'Légumes & crudités', shelf_life_value: 24, shelf_life_unit: 'hours',
    actions: [{ key: 'prepared', value: 24, unit: 'hours' }], allergens: [], no_allergens: true, storage: '0 à +4 °C', note: null,
    ask_original_dlc: false, print_operator: true, default_copies: 1, is_active: true, is_favorite: false, uses_30d: 0, ...over,
});
const PEOPLE = [
    { id: 1, name: 'Compte Tablette', short: 'Compte T.', is_me: true, has_pin: false },
    { id: 7, name: 'Sarah Lopez', short: 'Sarah L.', is_me: false, has_pin: true },
    { id: 8, name: 'Bob Martin', short: 'Bob M.', is_me: false, has_pin: false },
];
let products;
let posts;
let printMode;

function setup(list, { printMode: pm = 'ok' } = {}) {
    products = list; posts = []; printMode = pm;
    h.printer = printer;
    h.printBlocks.mockReset(); h.printBlocks.mockResolvedValue({ ok: true, pages: 1 });
    h.axios.get.mockReset(); h.axios.post.mockReset(); h.alert.error.mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/labels/products') return { data: { data: products, meta: { server_now: new Date().toISOString(), timezone: 'Europe/Paris' } } };
        if (url === 'admin/labels/watch') return { data: { data: [], meta: { server_now: new Date().toISOString() } } };
        if (url === 'admin/hygiene/operators') return { data: { data: PEOPLE, meta: { me: 1 } } };
        return { data: { data: [] } };
    });
    h.axios.post.mockImplementation(async (url, body) => {
        posts.push([url, body]);
        if (url === 'admin/hygiene/operators/verify') return { data: { data: { token: 'JETON-SARAH', expires_at: new Date(Date.now() + 600000).toISOString() } } };
        if (url === 'admin/labels/dlc/print') {
            if (printMode === 'operator-refused') { const e = new Error('422'); e.response = { status: 422, data: { message: 'Le code de Sarah L. est requis pour signer à son nom.', errors: { operator_pin: ['Le code de Sarah L. est requis pour signer à son nom.'] } } }; throw e; }
            if (printMode === 'network') throw new Error('Network Error');
            const p = products.find((x) => x.id === body.product_id);
            return { data: { log_id: 100 + posts.length, attempt: 1, name: p.name, category: p.category, action: body.action || p.actions[0].key, opened_at: '2026-10-16T08:00:00Z', expires_at: '2026-10-17T08:00:00Z',
                copies: body.copies, lot: 'L261016-0001', allergens: p.allergens, storage: p.storage, by: 'Karim', capped: false } };
        }
        return { data: { status: 'ok' } };
    });
    window.localStorage.clear();
}
async function mountScreen() {
    const w = mount(LabelDlcComponent, { global: { mocks: { $t: (k) => k }, stubs: { 'router-link': { template: '<a><slot /></a>' } } } });
    await flushPromises();
    return w;
}
const q = (w, id) => w.find(`[data-testid="${id}"]`);
const printPosts = () => posts.filter(([u]) => u === 'admin/labels/dlc/print').map(([, b]) => b);
const LIST = () => [P({ id: 1 }), P({ id: 2, name: 'Tomates tranchées' }), P({ id: 3, name: 'Cheddar tranché' })];

beforeEach(() => { vi.useRealTimers(); });

describe('étiquettes DLC — « Qui ? »', () => {
    it('« Qui ? » est en tête de l’écran, avant de toucher un produit', async () => {
        setup(LIST());
        const w = await mountScreen();
        expect(q(w, 'dlc-who').exists()).toBe(true);
        expect(q(w, 'dlc-who').find('[data-testid="hy-operator-btn"]').text()).toContain('Compte T.');
    });

    it('sans choix, l’impression part sans personne désignée : le serveur signe du compte connecté', async () => {
        setup(LIST());
        const w = await mountScreen();
        await q(w, 'dlc-product-1').trigger('click');
        await flushPromises();
        expect(printPosts()).toHaveLength(1);
        expect(printPosts()[0].operator_user_id).toBeUndefined();
        expect(printPosts()[0].operator_token).toBeUndefined();
    });

    it('une personne sans code, choisie, signe l’étiquette (operator_user_id part, pas de jeton)', async () => {
        setup(LIST());
        const w = await mountScreen();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-8').trigger('click');
        await q(w, 'dlc-product-1').trigger('click');
        await flushPromises();
        expect(printPosts()[0]).toMatchObject({ product_id: 1, operator_user_id: 8 });
        expect(printPosts()[0].operator_token).toBeUndefined();
    });

    it('une personne avec un code : son code est demandé UNE fois, puis le jeton accompagne chaque impression (jamais le code)', async () => {
        setup(LIST());
        const w = await mountScreen();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        await q(w, 'hy-pin-input').setValue('4821');
        await q(w, 'hy-pin-ok').trigger('click');
        await flushPromises();
        await q(w, 'dlc-product-1').trigger('click');
        await flushPromises();
        await q(w, 'dlc-product-2').trigger('click');
        await flushPromises();
        const prints = printPosts();
        expect(prints).toHaveLength(2);
        prints.forEach((b) => expect(b).toMatchObject({ operator_user_id: 7, operator_token: 'JETON-SARAH' }));
        expect(JSON.stringify(posts)).not.toContain('"pin":"4821"'.replace('"pin"', '"operator_pin"')); // le code ne part qu'à la vérification
        expect(prints.every((b) => b.operator_pin === undefined)).toBe(true);
    });

    it('un refus de « Qui ? » arrête le lot après le premier produit : un seul message, pas huit', async () => {
        setup(LIST(), { printMode: 'operator-refused' });
        const w = await mountScreen();
        await q(w, 'dlc-mode-list').trigger('click');
        await q(w, 'dlc-select-all').trigger('click');
        await q(w, 'dlc-basket-print').trigger('click');
        await flushPromises();
        expect(printPosts()).toHaveLength(1);                                   // le 2e et le 3e ne sont même pas tentés
        expect(h.alert.error).toHaveBeenCalledTimes(1);
        expect(h.alert.error).toHaveBeenCalledWith(expect.stringContaining('Le code de Sarah L. est requis'));
        expect(q(w, 'dlc-basket-total').text()).toContain('3 étiquettes');       // la sélection reste pour réessayer après avoir saisi le code
    });

    it('hors ligne, l’étiquette gardée porte la personne en toutes lettres (« moi » = le compte qui a imprimé)', async () => {
        setup(LIST(), { printMode: 'network' });
        const w = await mountScreen();
        await q(w, 'dlc-product-1').trigger('click');
        await flushPromises();
        const sent = posts.find(([u]) => u === 'admin/labels/dlc/offline');   // journalisée dès que possible (ou gardée en file si le réseau manque encore)
        expect(sent).toBeTruthy();
        expect(sent[1].operator_user_id).toBe(1);
    });
});
