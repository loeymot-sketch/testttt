/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia « Repas témoin : Moment de la journée obligatoire »] Le service (matin / midi / soir) d'un repas témoin :
 * obligatoire sur la fiche, jamais pré-choisi, sur l'étiquette, envoyé en ligne comme hors ligne ; un appui direct ne l'imprime jamais à l'aveugle.
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
import DlcPrintSheet from '../../resources/js/components/admin/labels/DlcPrintSheet.vue';
import { dlcLabelBlocks } from '../../resources/js/helpers/dlcLabelBuilder';

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
const WITNESS = (over) => P({ id: 5, name: 'Poulet rôti', actions: [{ key: 'witness', value: 5, unit: 'days' }, { key: 'prepared', value: 3, unit: 'days' }], ...over });
const settings = { mediaId: 'c62', autoCut: true, mirror: true, turn180: false };

beforeEach(() => { vi.useRealTimers(); });

describe('repas témoin — fiche d’impression', () => {
    const mountSheet = (product) => mount(DlcPrintSheet, { props: { product, settings } });

    it('le service n’est jamais pré-choisi, et l’impression reste grisée tant qu’il manque', async () => {
        const w = mountSheet(WITNESS());
        expect(q(w, 'dlc-sheet-slot').exists()).toBe(true);
        expect(w.findAll('[data-testid^="dlc-sheet-slot-"][aria-checked="true"]')).toHaveLength(0);
        expect(q(w, 'dlc-sheet-slot-required').exists()).toBe(true);
        expect(q(w, 'dlc-sheet-print').attributes('disabled')).toBeDefined();
        await q(w, 'dlc-sheet-print').trigger('click');
        expect(w.emitted('print')).toBeUndefined();
    });

    it('choisir un service débloque l’impression et le transmet', async () => {
        const w = mountSheet(WITNESS());
        await q(w, 'dlc-sheet-slot-noon').trigger('click');
        expect(q(w, 'dlc-sheet-slot-noon').attributes('aria-checked')).toBe('true');
        expect(q(w, 'dlc-sheet-slot-required').exists()).toBe(false);
        expect(q(w, 'dlc-sheet-print').attributes('disabled')).toBeUndefined();
        await q(w, 'dlc-sheet-print').trigger('click');
        expect(w.emitted('print')[0][0]).toMatchObject({ action: 'witness', meal_slot: 'noon' });
    });

    it('les trois services existent : Matin, Midi, Soir', () => {
        const w = mountSheet(WITNESS());
        expect(['morning', 'noon', 'evening'].map((k) => q(w, `dlc-sheet-slot-${k}`).text())).toEqual(['Matin', 'Midi', 'Soir']);
    });

    it('un autre événement n’affiche aucun service et n’en envoie pas, même si un service avait été touché avant', async () => {
        const w = mountSheet(WITNESS());
        await q(w, 'dlc-sheet-slot-evening').trigger('click');
        await q(w, 'dlc-sheet-action-prepared').trigger('click');
        expect(q(w, 'dlc-sheet-slot').exists()).toBe(false);
        expect(q(w, 'dlc-sheet-print').attributes('disabled')).toBeUndefined();
        await q(w, 'dlc-sheet-print').trigger('click');
        const sent = w.emitted('print')[0][0];
        expect(sent.action).toBe('prepared');
        expect(sent.meal_slot).toBeUndefined();
    });
});

describe('repas témoin — étiquette', () => {
    const blocks = (mealSlot) => dlcLabelBlocks({ name: 'Poulet', action: 'witness', openedAt: '2026-10-20T10:00:00Z', expiresAt: '2026-10-25T10:00:00Z', storage: '0 à +3 °C', mealSlot });
    it('la bande dit le service', () => {
        expect(blocks('noon')).toContainEqual({ t: 'band', left: 'REPAS TÉMOIN · MIDI', size: 'lg' });
        expect(blocks('evening')).toContainEqual({ t: 'band', left: 'REPAS TÉMOIN · SOIR', size: 'lg' });
    });
    it('sans service connu, la bande reste « REPAS TÉMOIN » (ancienne étiquette)', () => {
        expect(blocks(undefined)).toContainEqual({ t: 'band', left: 'REPAS TÉMOIN', size: 'lg' });
        expect(blocks('night')).toContainEqual({ t: 'band', left: 'REPAS TÉMOIN', size: 'lg' });
    });
    it('un autre événement ne montre jamais de service', () => {
        const b = dlcLabelBlocks({ name: 'Poulet', action: 'prepared', openedAt: '2026-10-20T10:00:00Z', expiresAt: '2026-10-23T10:00:00Z', storage: '0 à +4 °C', mealSlot: 'noon' });
        expect(JSON.stringify(b)).not.toMatch(/MIDI|TÉMOIN/);
    });
});

describe('repas témoin — écran', () => {
    it('un appui sur un produit dont l’événement par défaut est « repas témoin » ouvre la fiche : rien n’est imprimé à l’aveugle', async () => {
        setup([WITNESS()]);
        const w = await mountScreen();
        await q(w, 'dlc-product-5').trigger('click');
        await flushPromises();
        expect(q(w, 'dlc-sheet').exists()).toBe(true);
        expect(printPosts()).toHaveLength(0);
    });

    it('depuis la fiche : le service part avec l’impression', async () => {
        setup([WITNESS()]);
        const w = await mountScreen();
        await q(w, 'dlc-product-5').trigger('click');
        await flushPromises();
        await q(w, 'dlc-sheet-slot-morning').trigger('click');
        await q(w, 'dlc-sheet-print').trigger('click');
        await flushPromises();
        expect(printPosts()).toHaveLength(1);
        expect(printPosts()[0]).toMatchObject({ product_id: 5, action: 'witness', meal_slot: 'morning' });
    });

    it('réseau coupé : le service est écrit sur l’étiquette ET dans la ligne gardée pour le journal', async () => {
        setup([WITNESS()], { printMode: 'network' });
        const w = await mountScreen();
        await q(w, 'dlc-product-5').trigger('click');
        await flushPromises();
        await q(w, 'dlc-sheet-slot-evening').trigger('click');
        await q(w, 'dlc-sheet-print').trigger('click');
        await flushPromises();
        const off = posts.filter(([u]) => u === 'admin/labels/dlc/offline').map(([, b]) => b);
        expect(off).toHaveLength(1);
        expect(off[0]).toMatchObject({ action: 'witness', meal_slot: 'evening' });
        const printed = h.printBlocks.mock.calls[0][1][0];
        expect(printed).toContainEqual({ t: 'band', left: 'REPAS TÉMOIN · SOIR', size: 'lg' });
    });

    it('un produit sans repas témoin s’imprime toujours en un appui', async () => {
        setup([P({ id: 1 })]);
        const w = await mountScreen();
        await q(w, 'dlc-product-1').trigger('click');
        await flushPromises();
        expect(printPosts()).toHaveLength(1);
        expect(printPosts()[0].meal_slot).toBeUndefined();
    });
});
