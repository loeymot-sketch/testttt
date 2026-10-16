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

function setup(list) {
    products = list;
    prints = [];
    h.printer = printer;
    h.printBlocks.mockReset();
    h.printBlocks.mockResolvedValue({ ok: true, pages: 1 });
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.put.mockReset();
    h.axios.get.mockImplementation(async (url) => (url === 'admin/labels/products'
        ? { data: { data: products, meta: { server_now: new Date().toISOString(), timezone: 'Europe/Paris' } } }
        : { data: { data: [] } }));
    h.axios.post.mockImplementation(async (url, body) => {
        if (url === 'admin/labels/dlc/print') {
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

describe('écran DLC — un par un', () => {
    it('un appui imprime aussitôt, avec le nombre et l’événement habituels du produit', async () => {
        setup([P({ default_copies: 2 })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-product-1"]').trigger('click');
        await flushPromises();
        expect(prints).toEqual([{ product_id: 1, copies: 2, action: undefined, original_dlc: undefined }]);
        expect(h.printBlocks).toHaveBeenCalledTimes(1);
        expect(h.printBlocks.mock.calls[0][1]).toHaveLength(2); // deux étiquettes
        expect(w.get('[data-testid="dlc-last"]').text()).toMatch(/Oignons émincés — 2 étiquettes · Préparation/);
        expect(w.get('[data-testid="dlc-last"]').text()).toMatch(/Lot L261016-0001/);
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
        expect(prints).toEqual([{ product_id: 1, copies: 2, action: 'opened', original_dlc: undefined }]);
        expect(w.find('[data-testid="dlc-sheet"]').exists()).toBe(false); // refermée après succès
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

    it('événement imposé pour la sélection : appliqué aux produits qui le connaissent, les autres gardent le leur', async () => {
        setup([
            P({ id: 1, actions: [{ key: 'opened', value: 2, unit: 'days' }, { key: 'thawed', value: 3, unit: 'days' }] }),
            P({ id: 2, name: 'Tomates', actions: [{ key: 'prepared', value: 24, unit: 'hours' }] }),
        ]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-mode-list"]').trigger('click');
        await w.get('[data-testid="dlc-select-all"]').trigger('click');
        await w.get('[data-testid="dlc-batch-action"]').setValue('thawed');
        await w.get('[data-testid="dlc-basket-print"]').trigger('click');
        await flushPromises();
        const byId = Object.fromEntries(prints.map((p) => [p.product_id, p.action]));
        expect(byId).toEqual({ 1: 'thawed', 2: undefined });
    });

    it('un produit exigeant la DLC du fabricant n’est jamais imprimé à l’aveugle en lot : il est signalé', async () => {
        setup([P({ id: 1 }), P({ id: 2, name: 'Crème', ask_original_dlc: true })]);
        const w = await mountScreen();
        await w.get('[data-testid="dlc-mode-list"]').trigger('click');
        await w.get('[data-testid="dlc-select-all"]').trigger('click');
        await w.get('[data-testid="dlc-basket-print"]').trigger('click');
        await flushPromises();
        expect(prints.map((p) => p.product_id)).toEqual([1]);
        expect(w.get('[data-testid="dlc-last-skipped"]').text()).toMatch(/Crème/);
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
        await w.get('[data-testid="dlc-sheet-print"]').trigger('click');
        expect(w.emitted('print')[0][0]).toEqual({ action: 'opened', copies: 1, original_dlc: iso });
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
        await w.get('[data-testid="dlc-ed-ask-original"]').setValue(true);
        await w.get('[data-testid="dlc-ed-save"]').trigger('click');
        const payload = w.emitted('save')[0][0];
        expect(payload).toMatchObject({
            name: 'Poulet mariné', category: 'Viandes & volailles', allergens: ['moutarde'], ask_original_dlc: true,
            print_operator: true, default_copies: 1,
            actions: [{ key: 'opened', value: 2, unit: 'days' }, { key: 'thawed', value: 3, unit: 'days' }],
        });
        expect(w.find('[data-testid="dlc-ed-preview"]').exists()).toBe(true);
    });

    it('à l’édition : reprend les événements existants, en tête, dans leur ordre', () => {
        const w = mount(DlcProductEditor, { props: { product: P({ actions: [{ key: 'thawed', value: 3, unit: 'days' }] }), settings } });
        expect(w.get('[data-testid="dlc-ed-action-thawed"]').element.checked).toBe(true);
        expect(w.get('[data-testid="dlc-ed-action-opened"]').element.checked).toBe(false);
        expect(w.findAll('[data-testid^="dlc-ed-action-"]')[0].attributes('data-testid')).toBe('dlc-ed-action-thawed');
    });
});
