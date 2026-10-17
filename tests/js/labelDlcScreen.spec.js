/**
 * [ETIQUETTES 2026-10-16 · owner « comme les concurrents : très configuré, un par un ou tout ensemble »] L'écran DLC monté
 * pour de vrai (serveur et imprimante simulés) : un appui imprime aussitôt, « ⋯ » ouvre la fiche (événement, nombre, DLC du
 * fabricant), la sélection multiple imprime tout, l'éditeur envoie la configuration complète.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, printBlocks: vi.fn(), printer: null }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock('../../resources/js/helpers/labelPrintJob', () => ({
    printBlocks: (...a) => h.printBlocks(...a),
    previewUrls: (list) => ({ urls: list.map(() => 'data:image/png;base64,AA=='), overflow: false }),
}));
vi.mock('../../resources/js/helpers/labelPrinter.js', async (orig) => ({ ...(await orig()), labelPrinter: () => h.printer }));

import LabelDlcComponent from '../../resources/js/components/admin/labels/LabelDlcComponent.vue';
import DlcPrintSheet from '../../resources/js/components/admin/labels/DlcPrintSheet.vue';
import DlcProductEditor from '../../resources/js/components/admin/labels/DlcProductEditor.vue';

const printer = { isSupported: () => true, getState: () => ({ status: 'connected', printer: null }), onChange: () => () => {}, startKeepAlive() {}, stopKeepAlive() {}, isConnected: () => true };

const P = (over) => ({
    id: 1, name: 'Oignons émincés', category: 'Légumes & crudités', shelf_life_value: 24, shelf_life_unit: 'hours',
    actions: [{ key: 'prepared', value: 24, unit: 'hours' }], allergens: [], storage: '0 à +4 °C', note: null,
    ask_original_dlc: false, print_operator: true, default_copies: 1, is_active: true, uses_30d: 0, ...over,
});
let products;
let prints;
let posts;
let watchRows;

function setup(list, opts = {}) {
    products = list;
    prints = [];
    posts = [];
    watchRows = opts.watch || [];
    h.printer = printer;
    h.printBlocks.mockReset();
    h.printBlocks.mockResolvedValue({ ok: true, pages: 1 });
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.put.mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/labels/products') return { data: { data: products, meta: { server_now: new Date().toISOString(), timezone: 'Europe/Paris' } } };
        if (url === 'admin/labels/watch') return { data: { data: watchRows, meta: { server_now: new Date().toISOString() } } };
        return { data: { data: [] } };
    });
    h.axios.post.mockImplementation(async (url, body) => {
        posts.push([url, body]);
        if (url === 'admin/labels/dlc/print') {
            if (opts.offline) { const e = new Error('Network Error'); throw e; }
            prints.push(body);
            const p = products.find((x) => x.id === body.product_id);
            return { data: {
                log_id: 100 + prints.length, attempt: 1, name: p.name, category: p.category, action: body.action || p.actions[0].key,
                opened_at: '2026-10-16T08:00:00Z', expires_at: '2026-10-17T08:00:00Z', copies: body.copies, lot: 'L261016-0001',
                allergens: p.allergens, storage: p.storage, by: 'Karim', capped: false,
            } };
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

beforeEach(() => { vi.useRealTimers(); });

describe('écran DLC — recherche tolérante (2026-10-17)', () => {
    const LIST = () => [
        P({ id: 1, name: 'Oignons rouges émincés' }), P({ id: 2, name: 'Champignons frais émincés' }),
        P({ id: 3, name: 'Poulet mariné', category: 'Viandes & volailles' }), P({ id: 4, name: 'Poivrons coupés' }),
    ];
    const shown = (w) => w.findAll('[data-testid^="dlc-product-"]').map((x) => x.attributes('data-testid'));

    it('« ognion » retrouve les oignons, signale des résultats proches, et un appui imprime', async () => {
        setup(LIST());
        const w = await mountScreen();
        await w.get('[data-testid="dlc-search"]').setValue('ognion');
        expect(shown(w)).toEqual(['dlc-product-1']);
        expect(w.get('[data-testid="dlc-fuzzy-note"]').text()).toMatch(/proches de « ognion »/);
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        expect(prints.map((p) => p.product_id)).toEqual([1]);
    });

    it('« chompignion » et le pluriel « poivrons » ; une saisie juste n’affiche aucun avertissement', async () => {
        setup(LIST());
        const w = await mountScreen();
        await w.get('[data-testid="dlc-search"]').setValue('chompignion');
        expect(shown(w)).toEqual(['dlc-product-2']);
        await w.get('[data-testid="dlc-search"]').setValue('poivrons');
        expect(shown(w)).toEqual(['dlc-product-4']);
        expect(w.find('[data-testid="dlc-fuzzy-note"]').exists()).toBe(false);
    });

    it('rien de proche : « Créer » est proposé ; et le catalogue manquant aussi s’il y en a', async () => {
        setup(LIST());
        const w = await mountScreen();
        await w.get('[data-testid="dlc-search"]').setValue('zzzzzz');
        expect(shown(w)).toEqual([]);
        expect(w.find('[data-testid="dlc-fuzzy-note"]').exists()).toBe(false);
        expect(w.get('[data-testid="dlc-add-from-query"]').text()).toMatch(/Créer « zzzzzz »/);
    });

    it('sélection multiple : on cherche, on coche plusieurs produits, la sélection survit à une autre recherche', async () => {
        setup(LIST());
        const w = await mountScreen();
        await w.get('[data-testid="dlc-mode-list"]').trigger('click');
        await w.get('[data-testid="dlc-search"]').setValue('oignon');
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await w.get('[data-testid="dlc-search"]').setValue('poulet');
        await w.get('[data-testid="dlc-product-3"]').trigger('click');
        await w.get('[data-testid="dlc-search"]').setValue('');
        expect(w.get('[data-testid="dlc-basket-total"]').text()).toMatch(/2 étiquettes/);
        await w.get('[data-testid="dlc-basket-print"]').trigger('click');
        await flushPromises();
        expect(prints.map((p) => p.product_id).sort()).toEqual([1, 3]);
    });
});

describe('écran DLC — un par un', () => {
    it('un appui imprime aussitôt, avec le nombre et l’événement habituels du produit', async () => {
        setup([P({ default_copies: 2 })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        expect(prints).toEqual([{ product_id: 1, copies: 2, action: undefined, original_dlc: undefined, supplier_lot: undefined, parent_log_id: undefined }]);
        expect(h.printBlocks).toHaveBeenCalledTimes(1);
        expect(h.printBlocks.mock.calls[0][1]).toHaveLength(2); // deux étiquettes
        expect(w.get('[data-testid="dlc-last"]').text()).toMatch(/Oignons émincés — 2 étiquettes · Préparation/);
        expect(w.get('[data-testid="dlc-last"]').text()).toMatch(/N° L261016-0001/);
    });

    it('appuis rapides sur plusieurs produits : aucun ne se perd, ils attendent leur tour (dans l’ordre)', async () => {
        setup([P({ id: 1 }), P({ id: 2, name: 'Tomates' }), P({ id: 3, name: 'Olives' })]);
        let release;
        h.printBlocks.mockImplementation(() => new Promise((r) => { release = r; }));
        const w = await mountScreen();
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="dlc-product-2"]').trigger('click');
        await w.get('[data-testid="dlc-product-3"]').trigger('click');
        await flushPromises();
        expect(w.findAll('[data-testid="dlc-tile-queued"]').map((x) => x.text())).toEqual(['En attente ×1', 'En attente ×1']);
        for (let i = 0; i < 3; i++) { release({ ok: true, pages: 1 }); await flushPromises(); }
        expect(prints.map((p) => p.product_id)).toEqual([1, 2, 3]);
        expect(w.findAll('[data-testid="dlc-tile-queued"]')).toHaveLength(0);
    });

    it('file : une panne d’imprimante rend les appuis en attente (et le dit) ; un refus du serveur ne bloque que son produit', async () => {
        setup([P({ id: 1 }), P({ id: 2, name: 'Tomates' }), P({ id: 3, name: 'Olives' })]);
        let release;
        h.printBlocks.mockImplementation(() => new Promise((r) => { release = r; }));
        const w = await mountScreen();
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="dlc-product-2"]').trigger('click');
        await w.get('[data-testid="dlc-product-3"]').trigger('click');
        release({ ok: false, message: 'Capot ouvert' });
        await flushPromises();
        expect(prints.map((p) => p.product_id), 'les appuis en attente ne partent pas sur une imprimante en panne').toEqual([1]);
        expect(w.findAll('[data-testid="dlc-tile-queued"]')).toHaveLength(0);

        // refus du serveur pour le 1er (422) : les suivants partent quand même
        h.printBlocks.mockReset();
        h.printBlocks.mockResolvedValue({ ok: true, pages: 1 });
        const base = h.axios.post.getMockImplementation();
        h.axios.post.mockImplementation(async (url, body) => {
            if (url === 'admin/labels/dlc/print' && body.product_id === 1) {
                const e = new Error('422'); e.response = { status: 422, data: { message: 'Refusé' } }; throw e;
            }
            return base(url, body);
        });
        await w.get('[data-testid="dlc-mode-list"]').trigger('click');
        await w.get('[data-testid="dlc-select-all"]').trigger('click');
        await w.get('[data-testid="dlc-basket-print"]').trigger('click');
        await flushPromises();
        expect(h.printBlocks).toHaveBeenCalledTimes(2);
    });

    it('un rebond du doigt (deux appuis en moins d’une demi-seconde) ne fait qu’une étiquette', async () => {
        setup([P({ id: 1 })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        expect(prints).toHaveLength(1);
    });

    it('réseau coupé : l’étiquette sort quand même (« Imprimée hors ligne »), et sa ligne de journal part en file', async () => {
        setup([P({ id: 1, storage: '≤ −18 °C', thawed_storage: '0 à +4 °C', actions: [{ key: 'thawed', value: 3, unit: 'days' }] })], { offline: true });
        const w = await mountScreen();
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        expect(h.printBlocks).toHaveBeenCalledTimes(1);
        const blocks = JSON.stringify(h.printBlocks.mock.calls[0][1][0]);
        expect(blocks).toMatch(/Hors ligne · réf\. [A-Z0-9]{6}/);
        expect(blocks).toContain('NE PAS RECONGELER');
        expect(blocks).toContain('À conserver entre 0 et +4 °C'); // jamais « −18 » sur une étiquette de décongélation
        const journal = posts.find(([url]) => url === 'admin/labels/dlc/offline');
        expect(journal).toBeTruthy();
        expect(journal[1]).toMatchObject({ product_id: 1, product_name: 'Oignons émincés', action: 'thawed', copies: 1, status: 'printed', storage: '0 à +4 °C' });
        // la référence imprimée est celle de la ligne de journal
        expect(blocks).toContain(`réf. ${journal[1].client_ref.slice(-6).toUpperCase()}`);
        expect(journal[1].client_ref).toMatch(/^t[a-z0-9]+-[a-z0-9]+$/);
        expect(w.get('[data-testid="dlc-last"]').text()).toMatch(/inscrite au journal dès le retour du réseau/);
    });

    it('hors ligne et journal en attente : le dit, et compte déjà l’étiquette dans « À surveiller »', async () => {
        setup([P({ id: 1, actions: [{ key: 'prepared', value: 2, unit: 'hours' }] })], { offline: true });
        h.axios.post.mockImplementation(async (url, body) => {
            posts.push([url, body]);
            const e = new Error('Network Error'); throw e; // tout le réseau est coupé, journal compris
        });
        const w = await mountScreen();
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="dlc-pending"]').text()).toMatch(/1 étiquette imprimée hors ligne en attente du journal/);
        expect(w.get('[data-testid="dlc-watch"]').text()).toMatch(/Oignons émincés/);
        expect(w.find('[data-testid="dlc-watch-pending"]').exists()).toBe(true);
        expect(w.find('[data-testid^="dlc-discard-q-"]').exists()).toBe(false); // rien à « jeter » côté serveur tant qu'il n'y est pas
    });

    it('fiche « décongélation » sans conservation après décongélation : l’appui ouvre l’éditeur, rien ne s’imprime', async () => {
        setup([P({ id: 1, storage: '≤ −18 °C', actions: [{ key: 'thawed', value: 24, unit: 'hours' }] })]);
        const w = await mountScreen();
        expect(w.get('[data-testid="dlc-product-1"]').text()).toMatch(/Conservation après décongélation à renseigner/);
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        expect(prints).toEqual([]);
        expect(w.get('[data-testid="dlc-ed-error"]').text()).toMatch(/Conservation après décongélation à renseigner/);
    });

    it('« Annuler cette impression » : la ligne reste au journal, marquée annulée', async () => {
        setup([P({ id: 1 })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        // la notice vient d'apparaître sous le doigt : un appui immédiat est ignoré (il visait ce qui était dessous)
        await w.get('[data-testid="dlc-last-cancel"]').trigger('click');
        await flushPromises();
        expect(posts.find(([url]) => url === 'admin/labels/prints/discard')).toBeUndefined();
        w.vm.toastShownAt -= 1000;
        await w.get('[data-testid="dlc-last-cancel"]').trigger('click');
        await flushPromises();
        expect(posts.find(([url]) => url === 'admin/labels/prints/discard')[1]).toEqual({ ids: [101], reason: 'cancelled' });
        expect(w.get('[data-testid="dlc-undo"]').text()).toMatch(/annulée/);
    });

    it('produit qui exige la DLC du fabricant : l’appui ouvre la fiche, rien ne part tout seul', async () => {
        setup([P({ ask_original_dlc: true })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        expect(prints).toEqual([]);
        expect(w.find('[data-testid="dlc-sheet"]').exists()).toBe(true);
    });

    it('« ⋯ » : choisir l’événement, le nombre, puis imprimer', async () => {
        setup([P({ actions: [{ key: 'thawed', value: 3, unit: 'days' }, { key: 'opened', value: 48, unit: 'hours' }] })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-more-1"]').trigger('click');
        await w.get('[data-testid="dlc-sheet-action-opened"]').trigger('click');
        await w.get('[data-testid="dlc-sheet-plus"]').trigger('click');
        await w.get('[data-testid="dlc-sheet-print"]').trigger('click');
        await flushPromises();
        expect(prints).toEqual([{ product_id: 1, copies: 2, action: 'opened', original_dlc: undefined, supplier_lot: undefined, parent_log_id: undefined }]);
        expect(w.find('[data-testid="dlc-sheet"]').exists()).toBe(false); // refermée après succès
    });

    it('« Modifier la fiche » depuis la fiche d’impression ouvre l’éditeur du produit', async () => {
        setup([P({ id: 1 })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-more-1"]').trigger('click');
        await w.get('[data-testid="dlc-sheet-edit"]').trigger('click');
        expect(w.find('[data-testid="dlc-sheet"]').exists()).toBe(false);
        expect(w.get('[data-testid="dlc-ed-name"]').element.value).toBe('Oignons émincés');
    });

    it('familles en onglets : filtre la grille', async () => {
        setup([P({ id: 1 }), P({ id: 2, name: 'Poulet', category: 'Viandes & volailles' })]);
        const w = await mountScreen();
        expect(w.findAll('[data-testid^="dlc-product-"]')).toHaveLength(2);
        await w.get('[data-testid="dlc-cat-viandes-volailles"]').trigger('click');
        expect(w.findAll('[data-testid^="dlc-product-"]').map((b) => b.text())).toEqual([expect.stringContaining('Poulet')]);
        await w.get('[data-testid="dlc-cat-all"]').trigger('click');
        expect(w.findAll('[data-testid^="dlc-product-"]')).toHaveLength(2);
    });
});

describe('écran DLC — tout ensemble', () => {
    it('« Tout sélectionner » puis « Imprimer la sélection » : un travail par produit, un seul récapitulatif', async () => {
        setup([P({ id: 1, default_copies: 2 }), P({ id: 2, name: 'Tomates', uses_30d: 5 })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-mode-list"]').trigger('click');
        await w.get('[data-testid="dlc-select-all"]').trigger('click');
        expect(w.get('[data-testid="dlc-basket-total"]').text()).toBe('3 étiquettes');
        await w.get('[data-testid="dlc-basket-print"]').trigger('click');
        await flushPromises();
        // Tomates (plus utilisées) d'abord, puis Oignons
        expect(prints.map((p) => [p.product_id, p.copies])).toEqual([[2, 1], [1, 2]]);
        expect(w.get('[data-testid="dlc-last-batch"]').text()).toMatch(/3 étiquettes imprimées — Tomates ×1, Oignons émincés ×2/);
    });

    it('événement imposé pour la sélection : seuls les produits qui le prévoient restent, jamais d’événement remplacé en silence', async () => {
        setup([
            P({ id: 1, thawed_storage: '0 à +4 °C', actions: [{ key: 'opened', value: 2, unit: 'days' }, { key: 'thawed', value: 3, unit: 'days' }] }),
            P({ id: 2, name: 'Tomates', actions: [{ key: 'prepared', value: 24, unit: 'hours' }] }),
        ]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-mode-list"]').trigger('click');
        await w.get('[data-testid="dlc-select-all"]').trigger('click');
        expect(w.get('[data-testid="dlc-basket-total"]').text()).toBe('2 étiquettes');
        await w.get('[data-testid="dlc-batch-action"]').setValue('thawed');
        // Tomates ne se décongèlent pas : sorties de la sélection, et la tuile le dit
        expect(w.get('[data-testid="dlc-basket-total"]').text()).toBe('1 étiquette');
        expect(w.get('[data-testid="dlc-product-2"]').text()).toMatch(/Sans décongélation/);
        expect(w.get('[data-testid="dlc-select-all"]').text()).toBe('Tout sélectionner (1)');
        await w.get('[data-testid="dlc-basket-print"]').trigger('click');
        await flushPromises();
        expect(prints.map((p) => [p.product_id, p.action])).toEqual([[1, 'thawed']]);
    });

    it('« DLC du fabricant » dans la sélection : une seule étape demande les dates, puis TOUT part ensemble', async () => {
        const tomorrow = new Intl.DateTimeFormat('fr-CA', { timeZone: 'Europe/Paris' }).format(new Date(Date.now() + 86400000));
        setup([P({ id: 1 }), P({ id: 2, name: 'Crème', ask_original_dlc: true })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-mode-list"]').trigger('click');
        expect(w.get('[data-testid="dlc-select-all"]').text()).toBe('Tout sélectionner (2)');
        await w.get('[data-testid="dlc-select-all"]').trigger('click');
        expect(w.get('[data-testid="dlc-basket-total"]').text()).toBe('2 étiquettes');
        await w.get('[data-testid="dlc-basket-print"]').trigger('click');
        await flushPromises();
        // rien ne part avant les dates
        expect(prints).toEqual([]);
        expect(w.get('[data-testid="dlc-originals"]').text()).toMatch(/Crème/);
        expect(w.get('[data-testid="dlc-originals-print"]').attributes('disabled')).toBeDefined();
        // jamais pré-remplie ; « Même emballage » n'apparaît que si une DLC antérieure est connue
        expect(w.get('[data-testid="dlc-originals-date-2"]').element.value).toBe('');
        await w.get('[data-testid="dlc-originals-date-2"]').setValue(tomorrow);
        await w.get('[data-testid="dlc-originals-lot-2"]').setValue('2345A');
        await w.get('[data-testid="dlc-originals-print"]').trigger('click');
        await flushPromises();
        const byId = Object.fromEntries(prints.map((p) => [p.product_id, [p.original_dlc, p.supplier_lot]]));
        expect(byId).toEqual({ 1: [undefined, undefined], 2: [tomorrow, '2345A'] });
        expect(w.find('[data-testid="dlc-originals"]').exists()).toBe(false);
    });

    it('« fait à partir d’un produit étiqueté » : jamais dans un lot, un appui ouvre sa fiche ; l’origine (ou « produit neuf ») est obligatoire', async () => {
        setup([P({ id: 1 }), P({ id: 2, name: 'Poulet mariné', needs_parent: true })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-mode-list"]').trigger('click');
        expect(w.get('[data-testid="dlc-select-all"]').text()).toBe('Tout sélectionner (1)');
        expect(w.get('[data-testid="dlc-product-2"]').text()).toMatch(/À part \(produit d'origine\)/);
        await w.get('[data-testid="dlc-mode-single"]').trigger('click');
        await w.get('[data-testid="dlc-product-2"]').trigger('click');
        await flushPromises();
        expect(prints).toEqual([]);
        expect(w.get('[data-testid="dlc-sheet-parent-required"]').exists()).toBe(true);
        expect(w.get('[data-testid="dlc-sheet-print"]').attributes('disabled')).toBeDefined();
        await w.get('[data-testid="dlc-sheet-parent-none"]').trigger('click');
        await w.get('[data-testid="dlc-sheet-print"]').trigger('click');
        await flushPromises();
        expect(prints).toEqual([expect.objectContaining({ product_id: 2, parent_none: true })]);
    });

    it('fiche sans conservation : jamais imprimée à l’aveugle, l’appui ouvre l’éditeur avec la raison', async () => {
        setup([P({ id: 1, storage: null })]);
        const w = await mountScreen();
        expect(w.get('[data-testid="dlc-product-1"]').text()).toMatch(/Conservation à renseigner/);
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        expect(prints).toEqual([]);
        expect(w.get('[data-testid="dlc-ed-error"]').text()).toMatch(/Conservation à renseigner : elle est imprimée sur l’étiquette/);
    });

    it('− retire une étiquette ; à zéro le produit sort de la sélection', async () => {
        setup([P({ id: 1 })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-mode-list"]').trigger('click');
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        expect(w.get('[data-testid="dlc-tile-count"]').text()).toBe('× 2');
        await w.get('[data-testid="dlc-minus-1"]').trigger('click');
        await w.get('[data-testid="dlc-minus-1"]').trigger('click');
        expect(w.find('[data-testid="dlc-basket"]').exists()).toBe(false);
    });
});

describe('écran laissé ouvert', () => {
    it('l’heure avance : un lot qui atteint sa DLC passe « expiré » sans recharger, et la surveillance se relit', async () => {
        vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
        const lot = { id: 9, dlc_product_id: 1, status: 'printed', product_name: 'Oignons émincés', copies: 1, expires_at: new Date(Date.now() + 10 * 60000).toISOString() };
        setup([P({ id: 1 })], { watch: [lot] });
        const w = await mountScreen();
        expect(w.get('[data-testid="dlc-watch"]').text()).toMatch(/expire dans 10 min/);
        expect(w.find('[data-testid="dlc-watch-chip"]').exists()).toBe(false);
        const watchCalls = () => h.axios.get.mock.calls.filter(([u]) => u === 'admin/labels/watch').length;
        const before = watchCalls();
        vi.advanceTimersByTime(11 * 60000);
        await flushPromises();
        expect(w.get('[data-testid="dlc-watch"]').text()).toMatch(/EXPIRÉE depuis/);
        expect(w.get('[data-testid="dlc-watch-chip"]').text()).toMatch(/1 lot expiré/);
        expect(w.get('[data-testid="dlc-product-1"]').text()).toMatch(/Lot expiré/);
        expect(watchCalls(), 'la surveillance est relue (lots imprimés sur une autre tablette)').toBeGreaterThan(before);
        vi.useRealTimers();
    });
});

describe('à surveiller', () => {
    const lot = (over) => ({ id: 7, dlc_product_id: 1, status: 'printed', product_name: 'Olives', copies: 3, lot: 'L261010-0007', expires_at: new Date(Date.now() - 35 * 3600000).toISOString(), ...over });

    it('un lot échu depuis 35 h, jamais sorti, reste signalé (liste du serveur) ; « Utilisé » le sort avec sa raison', async () => {
        setup([P({ id: 1, name: 'Olives' })], { watch: [lot()] });
        const w = await mountScreen();
        expect(w.get('[data-testid="dlc-watch"]').text()).toMatch(/Olives/);
        expect(w.get('[data-testid="dlc-watch"]').text()).toMatch(/N° L261010-0007/);
        expect(w.get('[data-testid="dlc-watch-chip"]').text()).toMatch(/1 lot expiré/);
        expect(w.get('[data-testid="dlc-product-1"]').text()).toMatch(/Lot expiré/);
        await w.get('[data-testid="dlc-used-7"]').trigger('click');
        await flushPromises();
        expect(posts.find(([url]) => url === 'admin/labels/prints/discard')[1]).toEqual({ ids: [7], reason: 'used' });
        expect(w.get('[data-testid="dlc-undo"]').text()).toMatch(/utilisé/);
    });

    it('une étiquette réservée sans verdict (réseau coupé pendant l’impression) est À L’ÉCRAN : « non confirmée »', async () => {
        setup([P({ id: 1, name: 'Olives' })], { watch: [lot({ id: 12, status: 'claimed', expires_at: new Date(Date.now() + 5 * 3600000).toISOString() })] });
        const w = await mountScreen();
        expect(w.get('[data-testid="dlc-watch"]').text()).toMatch(/Olives/);
        expect(w.get('[data-testid="dlc-watch-unconfirmed"]').text()).toMatch(/Impression non confirmée/);
    });

    it('au-delà de 30 lots, le reste est annoncé (« + N autres »), jamais tu', async () => {
        const rows = Array.from({ length: 33 }, (_, i) => lot({ id: i + 1, product_name: `P${i}`, expires_at: new Date(Date.now() + (i + 1) * 3600000).toISOString() }));
        setup([P({ id: 1 })], { watch: rows });
        const w = await mountScreen();
        expect(w.findAll('[data-testid="dlc-watch-item"]')).toHaveLength(30);
        expect(w.get('[data-testid="dlc-watch-more"]').text()).toMatch(/\+ 3 autres lots/);
    });
});

describe('fiche d’impression', () => {
    const settings = { mediaId: 'c62', autoCut: true, mirror: true, turn180: false };
    const mountSheet = (product) => mount(DlcPrintSheet, { props: { product: P(product), settings } });

    it('DLC du fabricant exigée : impossible d’imprimer sans elle, ni avec une date dépassée', async () => {
        const w = mountSheet({ ask_original_dlc: true });
        expect(w.get('[data-testid="dlc-sheet-print"]').attributes('disabled')).toBeDefined();
        await w.get('[data-testid="dlc-sheet-original"]').setValue('2020-01-01');
        expect(w.get('[data-testid="dlc-sheet-cap-note"]').text()).toMatch(/dépassée/);
        expect(w.get('[data-testid="dlc-sheet-print"]').attributes('disabled')).toBeDefined();
    });

    it('DLC du fabricant proche : la fiche annonce la limitation, et l’envoie', async () => {
        const w = mountSheet({ ask_original_dlc: true, actions: [{ key: 'opened', value: 30, unit: 'days' }] });
        const d = new Date(Date.now() + 2 * 86400000);
        const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        await w.get('[data-testid="dlc-sheet-original"]').setValue(iso);
        expect(w.get('[data-testid="dlc-sheet-cap-note"]').text()).toMatch(/limitée à celle du fabricant/);
        await w.get('[data-testid="dlc-sheet-supplier-lot"]').setValue(' 2345A ');
        // la carte de l'événement annonce la même DLC (limitée) que l'aperçu
        expect(w.get('[data-testid="dlc-sheet-action-opened"]').text()).toMatch(/\(limitée\)/);
        await w.get('[data-testid="dlc-sheet-print"]').trigger('click');
        expect(w.emitted('print')[0][0]).toEqual({ action: 'opened', copies: 1, original_dlc: iso, supplier_lot: '2345A', parent_log_id: null });
    });

    it('fait à partir d’un produit déjà étiqueté : la DLC suit la sienne (sauf cuisson), et son N° part au serveur', async () => {
        const parentExp = new Date(Date.now() + 20 * 3600000).toISOString();
        const lots = [
            { id: 55, dlc_product_id: 9, status: 'printed', product_name: 'Poulet', action: 'thawed', lot: 'L261016-0055', expires_at: parentExp },
            { id: 56, dlc_product_id: 9, status: 'failed', product_name: 'Raté', expires_at: parentExp },                 // jamais sortie
            { id: 57, dlc_product_id: 9, status: 'printed', product_name: 'Vieux', expires_at: new Date(Date.now() - 1000).toISOString() }, // périmé
        ];
        const w = mount(DlcPrintSheet, { props: {
            product: P({ actions: [{ key: 'prepared', value: 3, unit: 'days' }, { key: 'cooked', value: 3, unit: 'days' }] }), settings, lots,
        } });
        await w.get('[data-testid="dlc-sheet-parent-toggle"]').trigger('click');
        expect(w.findAll('[data-testid^="dlc-sheet-parent-5"]').map((b) => b.attributes('data-testid'))).toEqual(['dlc-sheet-parent-55']);
        await w.get('[data-testid="dlc-sheet-parent-55"]').trigger('click');
        expect(w.get('[data-testid="dlc-sheet-parent-note"]').text()).toMatch(/suivra celle du produit d'origine/);
        await w.get('[data-testid="dlc-sheet-action-cooked"]').trigger('click');
        expect(w.get('[data-testid="dlc-sheet-parent-note"]').text()).toMatch(/Cuisson : nouvelle durée/);
        await w.get('[data-testid="dlc-sheet-print"]').trigger('click');
        expect(w.emitted('print')[0][0]).toMatchObject({ action: 'cooked', parent_log_id: 55 });
    });

    it('la DLC d’un nouvel emballage n’est JAMAIS pré-remplie ; « Même emballage » la reprend sur un choix explicite', async () => {
        const w = mount(DlcPrintSheet, { props: { product: P({ ask_original_dlc: true }), settings, lastOriginal: { original_dlc: '2099-12-31', supplier_lot: 'LOT9' } } });
        expect(w.get('[data-testid="dlc-sheet-original"]').element.value).toBe('');
        expect(w.get('[data-testid="dlc-sheet-print"]').attributes('disabled')).toBeDefined();
        expect(w.get('[data-testid="dlc-sheet-same-pack"]').text()).toMatch(/31\/12\/2099 · lot LOT9/);
        await w.get('[data-testid="dlc-sheet-same-pack"]').trigger('click');
        expect(w.get('[data-testid="dlc-sheet-original"]').element.value).toBe('2099-12-31');
        expect(w.get('[data-testid="dlc-sheet-supplier-lot"]').element.value).toBe('LOT9');
        expect(w.get('[data-testid="dlc-sheet-print"]').attributes('disabled')).toBeUndefined();
    });

    it('déjà cuit PLUS HAUT dans la chaîne (lot sous vide d’un rôti) : la fiche applique la même règle que le serveur', async () => {
        const lots = [{ id: 71, dlc_product_id: 9, status: 'printed', product_name: 'Poulet sous vide', action: 'vacuum', chain_cooked: true,
            lot: 'L261016-0071', expires_at: new Date(Date.now() + 10 * 3600000).toISOString() }];
        const w = mount(DlcPrintSheet, { props: { product: P({ actions: [{ key: 'cooked', value: 3, unit: 'days' }] }), settings, lots } });
        await w.get('[data-testid="dlc-sheet-parent-toggle"]').trigger('click');
        await w.get('[data-testid="dlc-sheet-parent-71"]').trigger('click');
        expect(w.get('[data-testid="dlc-sheet-parent-note"]').text()).toMatch(/une seule remise en température/);
        expect(w.get('[data-testid="dlc-sheet-action-cooked"]').text()).toMatch(/\(limitée\)/);
    });

    it('recuire un produit DÉJÀ cuit : une seule remise en température, la DLC suit le lot d’origine ; un lot « à vérifier » est signalé', async () => {
        const lots = [{ id: 70, dlc_product_id: 9, status: 'printed', product_name: 'Poulet rôti', action: 'cooked', lot: 'L261016-0070', anomaly: true,
            expires_at: new Date(Date.now() + 10 * 3600000).toISOString() }];
        const w = mount(DlcPrintSheet, { props: { product: P({ actions: [{ key: 'cooked', value: 3, unit: 'days' }] }), settings, lots } });
        await w.get('[data-testid="dlc-sheet-parent-toggle"]').trigger('click');
        expect(w.get('[data-testid="dlc-sheet-parent-anomaly"]').text()).toMatch(/À vérifier/);
        await w.get('[data-testid="dlc-sheet-parent-70"]').trigger('click');
        expect(w.get('[data-testid="dlc-sheet-parent-note"]').text()).toMatch(/une seule remise en température/);
        expect(w.get('[data-testid="dlc-sheet-action-cooked"]').text()).toMatch(/\(limitée\)/);
    });

    it('décongélation : la conservation APRÈS décongélation de la fiche est celle annoncée', () => {
        const w = mountSheet({ storage: '≤ −18 °C', thawed_storage: '0 à +2 °C', actions: [{ key: 'thawed', value: 24, unit: 'hours' }] });
        expect(w.get('[data-testid="dlc-sheet-thawed-note"]').text()).toMatch(/À conserver entre 0 et \+2 °C/);
    });

    it('décongélation sans conservation après décongélation sur la fiche : impression bloquée, avec la marche à suivre', () => {
        const w = mountSheet({ storage: '≤ −18 °C', actions: [{ key: 'thawed', value: 3, unit: 'days' }] });
        expect(w.get('[data-testid="dlc-sheet-thawed-missing"]').text()).toMatch(/Modifier la fiche/);
        expect(w.get('[data-testid="dlc-sheet-print"]').attributes('disabled')).toBeDefined();
    });
});

describe('éditeur de produit', () => {
    const settings = { mediaId: 'c62', autoCut: true, mirror: true, turn180: false };

    it('envoie la configuration complète : famille, événements dans l’ordre, allergènes, options', async () => {
        const w = mount(DlcProductEditor, { props: { product: {}, settings } });
        await w.get('[data-testid="dlc-ed-name"]').setValue('Poulet mariné');
        await w.get('[data-testid="dlc-ed-category"]').setValue('Viandes & volailles');
        await w.get('[data-testid="dlc-ed-action-thawed"]').setValue(true);
        await w.get('[data-testid="dlc-ed-value-thawed"]').setValue(3);
        await w.get('[data-testid="dlc-ed-allergen-moutarde"]').trigger('click');
        // fiche neuve : un appui suffit, la DLC du fabricant n'est demandée que si on coche l'option
        expect(w.get('[data-testid="dlc-ed-ask-original"]').element.checked).toBe(false);
        // la conservation est obligatoire (imprimée sur l'étiquette)
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.emitted('save')).toBeUndefined();
        expect(w.get('[data-testid="dlc-ed-error"]').text()).toMatch(/température de conservation/);
        await w.get('[data-testid="dlc-ed-storage-1"]').trigger('click');
        // décongélation cochée : la conservation après décongélation est obligatoire
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.get('[data-testid="dlc-ed-error"]').text()).toMatch(/conservation après décongélation/);
        await w.get('[data-testid="dlc-ed-thawed-0"]').trigger('click'); // 0 à +2 °C (viande)
        // viande décongelée 3 jours : au-delà de l'usage courant (24 h), à confirmer
        expect(w.get('[data-testid="dlc-ed-warn-thawed"]').text()).toMatch(/24 h est l'usage courant/);
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.emitted('save')).toBeUndefined();
        await w.get('[data-testid="dlc-ed-durations-ok"]').setValue(true);
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        const payload = w.emitted('save')[0][0];
        expect(payload).toMatchObject({
            name: 'Poulet mariné', category: 'Viandes & volailles', allergens: ['moutarde'], ask_original_dlc: false,
            print_operator: true, default_copies: 1, storage: '0 à +4 °C', thawed_storage: '0 à +2 °C', durations_checked: true,
            actions: [{ key: 'opened', value: 2, unit: 'days' }, { key: 'thawed', value: 3, unit: 'days' }],
        });
        expect(w.find('[data-testid="dlc-ed-preview"]').exists()).toBe(true);
    });

    it('« Par défaut » : l’événement choisi passe en tête (c’est lui qu’imprime un appui)', async () => {
        const w = mount(DlcProductEditor, { props: { product: P({ thawed_storage: '0 à +4 °C', actions: [{ key: 'opened', value: 2, unit: 'days' }, { key: 'thawed', value: 3, unit: 'days' }] }), settings } });
        await w.get('[data-testid="dlc-ed-default-thawed"]').setValue(true);
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.emitted('save')[0][0].actions.map((a) => a.key)).toEqual(['thawed', 'opened']);
    });

    it('durée inhabituelle (décongelé 5 jours, « 96 heures ») : signalée, et à confirmer avant d’enregistrer', async () => {
        const w = mount(DlcProductEditor, { props: { product: P({ thawed_storage: '0 à +4 °C', actions: [{ key: 'thawed', value: 5, unit: 'days' }] }), settings } });
        expect(w.get('[data-testid="dlc-ed-warn-thawed"]').text()).toMatch(/3 jours au plus/);
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.emitted('save')).toBeUndefined();
        expect(w.get('[data-testid="dlc-ed-error"]').text()).toMatch(/Durées vérifiées/);
        await w.get('[data-testid="dlc-ed-durations-ok"]').setValue(true);
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.emitted('save')).toHaveLength(1);

        const h96 = mount(DlcProductEditor, { props: { product: P({ actions: [{ key: 'prepared', value: 96, unit: 'hours' }] }), settings } });
        expect(h96.get('[data-testid="dlc-ed-warn-prepared"]').text()).toMatch(/96 heures = 4 jours : vérifiez l'unité/);
    });

    it('décongelé à température ambiante : signalé pour tout sauf le pain (liste blanche, pas liste noire des viandes)', () => {
        const warn = (name, category = '') => mount(DlcProductEditor, { props: { product: P({ name, category, storage: '≤ −18 °C', thawed_storage: 'Ambiante', actions: [{ key: 'thawed', value: 24, unit: 'hours' }] }), settings } })
            .find('[data-testid="dlc-ed-warn-thawed"]');
        ['Cordon bleu', 'Tenders', 'Mexicanos', 'Cabillaud', 'Poulet', 'Fricadelle', 'Capitaine'].forEach((n) => expect(warn(n).exists(), n).toBe(true));
        expect(warn('Cordon bleu').text()).toMatch(/réservé au pain et aux viennoiseries/);
        // un mot de pain n'annule JAMAIS un mot de viande ou de poisson (régression relevée au tour G)
        ['Wrap poulet', 'Galette kebab', 'Pain de viande', 'Pain de poisson'].forEach((n) => expect(warn(n).exists(), n).toBe(true));
        // desserts et pâtisseries à la crème : au froid
        ['Tiramisu', 'Cheesecake', 'Pâtisserie à la crème'].forEach((n) => expect(warn(n, 'Desserts').exists(), n).toBe(true));
        ['Pains burger', 'Brioches', 'Wraps', 'Pita'].forEach((n) => expect(warn(n).exists(), n).toBe(false));
    });

    it('sélection « Décongélation » : une fiche dont l’après-décongélation est invalide (ancienne valeur congélateur) reste à part', async () => {
        setup([P({ id: 1, thawed_storage: '≤ −18 °C', actions: [{ key: 'opened', value: 2, unit: 'days' }, { key: 'thawed', value: 3, unit: 'days' }] })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-mode-list"]').trigger('click');
        await w.get('[data-testid="dlc-batch-action"]').setValue('thawed');
        expect(w.get('[data-testid="dlc-select-all"]').text()).toBe('Tout sélectionner (0)');
        expect(w.get('[data-testid="dlc-product-1"]').text()).toMatch(/À part \(fiche à compléter\)/);
    });

    it('viande décongelée au-delà de 24 h : liste fast-food comprise (cordon bleu, nuggets…)', () => {
        const w = mount(DlcProductEditor, { props: { product: P({ name: 'Cordon bleu', storage: '≤ −18 °C', thawed_storage: '0 à +4 °C', actions: [{ key: 'thawed', value: 3, unit: 'days' }] }), settings } });
        expect(w.get('[data-testid="dlc-ed-warn-thawed"]').text()).toMatch(/24 h est l'usage courant/);
    });

    it('liste des lots en cours indisponible : la fiche le dit (liste partielle), jamais en silence', async () => {
        const lots = [{ id: 80, dlc_product_id: 9, status: 'printed', product_name: 'Poulet', action: 'thawed', expires_at: new Date(Date.now() + 3600000).toISOString() }];
        const w = mount(DlcPrintSheet, { props: { product: P({}), settings, lots, lotsPartial: true } });
        await w.get('[data-testid="dlc-sheet-parent-toggle"]').trigger('click');
        expect(w.get('[data-testid="dlc-sheet-lots-partial"]').text()).toMatch(/indisponible/);
    });

    it('viande hachée décongelée au-dessus de +2 °C : signalée (arrêté du 21/12/2009), à confirmer', async () => {
        const w = mount(DlcProductEditor, { props: { product: P({ name: 'Steak haché', storage: '≤ −18 °C', thawed_storage: '0 à +4 °C', actions: [{ key: 'thawed', value: 24, unit: 'hours' }] }), settings } });
        expect(w.get('[data-testid="dlc-ed-warn-thawed"]').text()).toMatch(/\+2 °C au plus/);
        await w.get('[data-testid="dlc-ed-thawed-0"]').trigger('click'); // 0 à +2 °C
        expect(w.find('[data-testid="dlc-ed-warn-thawed"]').exists()).toBe(false);
    });

    it('décongélation d’un produit surgelé : la conservation APRÈS décongélation est obligatoire, et annoncée', async () => {
        const w = mount(DlcProductEditor, { props: { product: P({ name: 'Pains burger', storage: '≤ −18 °C', actions: [{ key: 'thawed', value: 48, unit: 'hours' }] }), settings } });
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.emitted('save')).toBeUndefined();
        expect(w.get('[data-testid="dlc-ed-error"]').text()).toMatch(/conservation après décongélation/);
        await w.get('[data-testid="dlc-ed-thawed-3"]').trigger('click'); // Ambiante (un pain)
        expect(w.get('[data-testid="dlc-ed-thawed-note"]').text()).toMatch(/À conserver à température ambiante.*NE PAS RECONGELER/);
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.emitted('save')[0][0]).toMatchObject({ storage: '≤ −18 °C', thawed_storage: 'Ambiante' });
    });

    it('« aucun allergène » et « fait à partir d’un produit étiqueté » partent au serveur ; un allergène coché annule « aucun »', async () => {
        const w = mount(DlcProductEditor, { props: { product: P({}), settings } });
        await w.get('[data-testid="dlc-ed-no-allergens"]').setValue(true);
        await w.get('[data-testid="dlc-ed-needs-parent"]').setValue(true);
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        expect(w.emitted('save')[0][0]).toMatchObject({ no_allergens: true, needs_parent: true, allergens: [] });
        await w.get('[data-testid="dlc-ed-allergen-lait"]').trigger('click');
        expect(w.get('[data-testid="dlc-ed-no-allergens"]').element.checked).toBe(false);
    });

    it('à l’édition : reprend les événements existants, en tête, dans leur ordre', () => {
        const w = mount(DlcProductEditor, { props: { product: P({ actions: [{ key: 'thawed', value: 3, unit: 'days' }] }), settings } });
        expect(w.get('[data-testid="dlc-ed-action-thawed"]').element.checked).toBe(true);
        expect(w.get('[data-testid="dlc-ed-action-opened"]').element.checked).toBe(false);
        expect(w.findAll('[data-testid^="dlc-ed-action-"]')[0].attributes('data-testid')).toBe('dlc-ed-action-thawed');
    });
});

describe('écran DLC — catalogue recommandé (2026-10-17)', () => {
    function withRecommended(meta, startWith = []) {
        setup(startWith);
        let current = startWith;
        h.axios.get.mockImplementation(async (url) => {
            if (url === 'admin/labels/products') return { data: { data: current, meta: { server_now: new Date().toISOString(), timezone: 'Europe/Paris', recommended: meta(current) } } };
            return { data: { data: [] } };
        });
        h.axios.post.mockImplementation(async (url, body) => {
            posts.push([url, body]);
            if (url === 'admin/labels/products/install-recommended') {
                current = [P({ id: 1, name: 'Tomates tranchées' }), P({ id: 2, name: 'Poulet mariné', allergens: [], allergens_unreviewed: true })];
                return { data: { data: { added: 2, total: 2, missing: 0, never_configured: false } } };
            }
            return { data: {} };
        });
    }

    it('premier lancement : le catalogue s’installe seul, une seule fois, et la liste apparaît prête à imprimer', async () => {
        withRecommended((list) => ({ total: 2, missing: list.length ? 0 : 2, never_configured: list.length === 0 }));
        const w = await mountScreen();
        const installs = posts.filter(([u]) => u === 'admin/labels/products/install-recommended');
        expect(installs).toEqual([['admin/labels/products/install-recommended', { only_if_empty: true }]]);
        expect(w.find('[data-testid="dlc-empty"]').exists()).toBe(false);
        expect(w.find('[data-testid="dlc-product-1"]').exists()).toBe(true);
        expect(w.get('[data-testid="dlc-installed-note"]').text()).toMatch(/2 produits préconfigurés/);
        await w.get('[data-testid="dlc-installed-dismiss"]').trigger('click');
        expect(w.find('[data-testid="dlc-installed-note"]').exists()).toBe(false);
        // un produit à recette variable dit que ses allergènes sont à renseigner (rien d'affirmé)
        expect(w.get('[data-testid="dlc-product-2"] [data-testid="dlc-tile-review"]').text()).toBe('Allergènes à renseigner');
        expect(w.find('[data-testid="dlc-product-1"] [data-testid="dlc-tile-review"]').exists()).toBe(false);
    });

    it('un restaurant qui a déjà configuré (ou supprimé) un produit ne reçoit rien tout seul', async () => {
        withRecommended(() => ({ total: 2, missing: 2, never_configured: false }), [P({ id: 9, name: 'Mon produit' })]);
        const w = await mountScreen();
        expect(posts.some(([u]) => u === 'admin/labels/products/install-recommended')).toBe(false);
        // …le bouton de gestion permet d'ajouter les manquants, sans only_if_empty
        await w.get('[data-testid="dlc-manage-toggle"]').trigger('click');
        await w.get('[data-testid="dlc-install-more"]').trigger('click');
        await flushPromises();
        expect(posts.filter(([u]) => u === 'admin/labels/products/install-recommended')).toEqual([['admin/labels/products/install-recommended', {}]]);
    });
});
