/**
 * [CAYENNE HYGIÈNE 2026-10-18 · demande du propriétaire n°1] Favoris DLC : l'étoile, l'onglet « Favoris », « Préparer toute ma liste »,
 * l'impression de la liste d'un seul appui sur le panier. Écran monté pour de vrai, serveur et imprimante simulés.
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
let products;
let puts;
let prints;
let failFavorite;

function setup(list, { fail = false } = {}) {
    products = list; puts = []; prints = []; failFavorite = fail;
    h.printer = printer;
    h.printBlocks.mockReset(); h.printBlocks.mockResolvedValue({ ok: true, pages: 1 });
    h.axios.get.mockReset(); h.axios.post.mockReset(); h.axios.put.mockReset(); h.alert.error.mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/labels/products') return { data: { data: products, meta: { server_now: new Date().toISOString(), timezone: 'Europe/Paris' } } };
        if (url === 'admin/labels/watch') return { data: { data: [], meta: { server_now: new Date().toISOString() } } };
        return { data: { data: [] } };
    });
    h.axios.put.mockImplementation(async (url, body) => {
        puts.push([url, body]);
        if (failFavorite) { throw new Error('Network Error'); }
        const id = Number(url.match(/products\/(\d+)\/favorite/)[1]);
        return { data: { data: { ...products.find((p) => p.id === id), is_favorite: body.favorite } } };
    });
    h.axios.post.mockImplementation(async (url, body) => {
        if (url === 'admin/labels/dlc/print') {
            prints.push(body);
            const p = products.find((x) => x.id === body.product_id);
            return { data: { log_id: 100 + prints.length, attempt: 1, name: p.name, category: p.category, action: body.action || p.actions[0].key, opened_at: '2026-10-16T08:00:00Z',
                expires_at: '2026-10-17T08:00:00Z', copies: body.copies, lot: 'L261016-0001', allergens: p.allergens, storage: p.storage, by: 'Karim', capped: false } };
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
const shown = (w) => w.findAll('[data-testid^="dlc-product-"]').map((x) => Number(x.attributes('data-testid').replace('dlc-product-', '')));
const LIST = () => [P({ id: 1, name: 'Oignons émincés' }), P({ id: 2, name: 'Tomates tranchées' }), P({ id: 3, name: 'Cheddar tranché', category: 'Fromages & produits laitiers' }), P({ id: 4, name: 'Poulet mariné', category: 'Viandes & volailles' })];

beforeEach(() => { vi.useRealTimers(); });

describe('favoris DLC', () => {
    it('chaque tuile a son étoile (☆ vide), l’onglet Favoris dit « 0 » et explique quoi faire', async () => {
        setup(LIST());
        const w = await mountScreen();
        expect(q(w, 'dlc-fav-1').text()).toBe('☆');
        expect(q(w, 'dlc-fav-1').attributes('aria-pressed')).toBe('false');
        expect(q(w, 'dlc-fav-1').attributes('aria-label')).toContain('Ajouter aux favoris');
        expect(q(w, 'dlc-fav-count').text()).toBe('0');
        await q(w, 'dlc-cat-fav').trigger('click');
        expect(q(w, 'dlc-fav-empty').text()).toContain('Touchez ☆');
        expect(shown(w)).toEqual([]);
    });

    it('toucher l’étoile enregistre sur le serveur, l’onglet compte et ne montre que les favoris', async () => {
        setup(LIST());
        const w = await mountScreen();
        await q(w, 'dlc-fav-2').trigger('click');
        await q(w, 'dlc-fav-3').trigger('click');
        await flushPromises();
        expect(puts).toEqual([['admin/labels/products/2/favorite', { favorite: true }], ['admin/labels/products/3/favorite', { favorite: true }]]);
        expect(q(w, 'dlc-fav-2').text()).toBe('★');
        expect(q(w, 'dlc-fav-2').attributes('aria-pressed')).toBe('true');
        expect(q(w, 'dlc-fav-count').text()).toBe('2');
        await q(w, 'dlc-cat-fav').trigger('click');
        expect(shown(w).sort()).toEqual([2, 3]);
        expect(q(w, 'dlc-fav-empty').exists()).toBe(false);
    });

    it('retirer un favori le fait disparaître de l’onglet', async () => {
        setup([P({ id: 1, is_favorite: true }), P({ id: 2, name: 'Tomates', is_favorite: true })]);
        const w = await mountScreen();
        await q(w, 'dlc-cat-fav').trigger('click');
        expect(shown(w).sort()).toEqual([1, 2]);
        await q(w, 'dlc-fav-1').trigger('click');
        await flushPromises();
        expect(puts[0]).toEqual(['admin/labels/products/1/favorite', { favorite: false }]);
        expect(shown(w)).toEqual([2]);
    });

    it('un échec réseau remet l’étoile comme avant et le dit (jamais un favori fantôme)', async () => {
        setup(LIST(), { fail: true });
        const w = await mountScreen();
        await q(w, 'dlc-fav-1').trigger('click');
        await flushPromises();
        expect(q(w, 'dlc-fav-1').text()).toBe('☆');
        expect(q(w, 'dlc-fav-count').text()).toBe('0');
        expect(h.alert.error).toHaveBeenCalledWith(expect.stringContaining('Favori non enregistré'));
    });

    it('un double appui rapide n’envoie qu’une requête', async () => {
        setup(LIST());
        const w = await mountScreen();
        const star = q(w, 'dlc-fav-1');
        star.trigger('click');
        star.trigger('click');
        await flushPromises();
        expect(puts).toHaveLength(1);
    });

    it('« Préparer toute ma liste » sélectionne tous les favoris d’un appui ; un appui sur le panier les imprime tous', async () => {
        setup([P({ id: 1, is_favorite: true }), P({ id: 2, name: 'Tomates', is_favorite: true }), P({ id: 3, name: 'Cheddar', is_favorite: false })]);
        const w = await mountScreen();
        await q(w, 'dlc-cat-fav').trigger('click');
        await q(w, 'dlc-fav-prepare').trigger('click');
        expect(q(w, 'dlc-basket-total').text()).toContain('2 étiquettes');
        await q(w, 'dlc-basket-print').trigger('click');
        await flushPromises();
        expect(prints.map((p) => p.product_id).sort()).toEqual([1, 2]); // les 2 favoris, pas le 3e
    });

    it('la recherche reste dans l’onglet Favoris', async () => {
        setup([P({ id: 1, name: 'Oignons émincés', is_favorite: true }), P({ id: 2, name: 'Oignons rouges', is_favorite: false }), P({ id: 3, name: 'Tomates', is_favorite: true })]);
        const w = await mountScreen();
        await q(w, 'dlc-cat-fav').trigger('click');
        await q(w, 'dlc-search').setValue('oignon');
        expect(shown(w)).toEqual([1]);
    });

    it('un produit désactivé n’est pas compté dans les favoris', async () => {
        setup([P({ id: 1, is_favorite: true, is_active: false }), P({ id: 2, name: 'Tomates', is_favorite: true })]);
        const w = await mountScreen();
        expect(q(w, 'dlc-fav-count').text()).toBe('1');
    });
});
