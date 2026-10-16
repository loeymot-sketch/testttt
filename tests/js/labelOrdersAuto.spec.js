/**
 * [ETIQUETTES 2026-10-16] Le moteur d'impression AUTOMATIQUE de l'écran « Commandes », monté pour de vrai avec un
 * serveur et une imprimante simulés. Chaque cas vient de la revue adversariale : une commande ne doit ni s'imprimer
 * deux fois, ni jamais, ni quand l'owner vient seulement d'activer le mode.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import orders from './__fixtures__/orderLabelOrders.json';
import { parseStatus, STATUS_LENGTH } from '../../resources/js/helpers/brotherQl.js';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn() }, printBlocks: vi.fn(), printer: null }));

vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock('../../resources/js/helpers/labelPrintJob', () => ({
    printBlocks: (...a) => h.printBlocks(...a),
    previewUrls: (blocksList) => ({ urls: blocksList.map(() => 'data:image/png;base64,AA=='), overflow: false }),
}));
vi.mock('../../resources/js/helpers/labelPrinter.js', async (orig) => {
    const real = await orig();
    return { ...real, labelPrinter: () => h.printer };
});

import LabelOrdersComponent from '../../resources/js/components/admin/labels/LabelOrdersComponent.vue';

function readyStatus() {
    const f = new Uint8Array(STATUS_LENGTH);
    f.set([0x80, 0x20, 0x42, 0x34, 0x41, 0x30, 0x30, 0x00, 0, 0, 62, 0x4a, 0, 0, 0x3f, 0, 0, 0, 0, 0]);
    return parseStatus(f);
}

function fakePrinter(ready = true) {
    return {
        isSupported: () => true,
        getState: () => (ready ? { status: 'connected', printer: readyStatus(), error: null } : { status: 'disconnected', printer: null, error: null }),
        onChange: () => () => {},
        startKeepAlive() {}, stopKeepAlive() {},
        autoConnect: async () => ready, connect: async () => ready, disconnect: async () => true, print: async () => ({ ok: true }),
    };
}

let seq = 9000;
function order(overrides = {}) {
    const base = JSON.parse(JSON.stringify(orders.find((o) => String(o.queue_number) === 'A0114'))); // 1 étiquette
    return { ...base, id: ++seq, queue_number: `T${seq}`, created_at_iso: new Date().toISOString(), scheduled_at: null, ...overrides };
}

let claims;
let claimImpl;
let list;
let states;

function setup({ settings = {}, ready = true } = {}) {
    claims = [];
    list = [];
    states = {};
    claimImpl = () => ({ data: { claimed: true, log_id: 1, attempt: 1 } });
    h.printer = fakePrinter(ready);
    h.printBlocks.mockReset();
    h.printBlocks.mockResolvedValue({ ok: true });
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.get.mockImplementation(async () => ({ data: { data: list, meta: { states, server_now: new Date().toISOString(), overflow: false } } }));
    h.axios.post.mockImplementation(async (url, body) => {
        if (/\/claim$/.test(url)) {
            claims.push(url);
            const r = claimImpl(url, body);
            if (r instanceof Error) throw r;
            return r;
        }
        return { data: { status: 'ok' } };
    });
    window.localStorage.clear();
    window.localStorage.setItem('label_printer_settings', JSON.stringify({ autoPrint: true, autoPrintSince: Date.now() - 1000, autoPrintBaseline: [], ...settings }));
}

async function mountScreen() {
    const w = mount(LabelOrdersComponent, {
        global: {
            mocks: { $t: (k) => k },
            stubs: { 'router-link': { template: '<a><slot /></a>' } },
        },
    });
    await flushPromises();
    return w;
}

const polls = async (w, n = 1) => { for (let i = 0; i < n; i++) { await w.vm.poll(); await flushPromises(); } };

beforeEach(() => { vi.useRealTimers(); });
afterEach(() => { window.localStorage.clear(); });

describe('impression automatique — moteur', () => {
    it('une commande arrivée après l’activation s’imprime UNE fois, même après plusieurs sondages', async () => {
        setup();
        const w = await mountScreen();
        list = [order()];
        await polls(w, 4);
        expect(h.printBlocks).toHaveBeenCalledTimes(1);
        expect(claims).toHaveLength(1);
        w.unmount();
    });

    it('les commandes DÉJÀ sur le tableau à l’activation ne s’impriment jamais seules', async () => {
        const old = order();
        setup({ settings: { autoPrintBaseline: [old.id] } });
        const w = await mountScreen();
        list = [old];
        await polls(w, 3);
        expect(h.printBlocks).not.toHaveBeenCalled();
        expect(claims).toHaveLength(0);
        w.unmount();
    });

    it('une commande programmée créée AVANT l’activation s’imprime quand elle entre en cuisine', async () => {
        setup({ settings: { autoPrintBaseline: [] } });
        const w = await mountScreen();
        // créée il y a 5 h, mais n'apparaît sur le tableau qu'à présent (fenêtre de libération de la cuisine)
        list = [order({ created_at_iso: new Date(Date.now() - 5 * 3600 * 1000).toISOString(), scheduled_at: new Date().toISOString() })];
        await polls(w, 2);
        expect(h.printBlocks).toHaveBeenCalledTimes(1);
        w.unmount();
    });

    it('une commande ordinaire de plus de 90 minutes n’est pas imprimée seule (déjà servie)', async () => {
        setup();
        const w = await mountScreen();
        list = [order({ created_at_iso: new Date(Date.now() - 3 * 3600 * 1000).toISOString() })];
        await polls(w, 2);
        expect(h.printBlocks).not.toHaveBeenCalled();
        w.unmount();
    });

    it('erreur réseau sur la réclamation : le sondage suivant RÉESSAIE (la commande n’est pas perdue)', async () => {
        setup();
        const w = await mountScreen();
        list = [order()];
        let first = true;
        claimImpl = () => {
            if (first) { first = false; return Object.assign(new Error('Network Error'), { response: undefined }); }
            return { data: { claimed: true, log_id: 7, attempt: 1 } };
        };
        await polls(w, 3);
        expect(claims.length).toBeGreaterThanOrEqual(2);
        expect(h.printBlocks).toHaveBeenCalledTimes(1);
        w.unmount();
    });

    it('verrou refusé (une autre tablette l’a pris) : pas de redemande à chaque sondage', async () => {
        setup();
        const w = await mountScreen();
        list = [order()];
        claimImpl = () => ({ data: { claimed: false } });
        await polls(w, 5);
        expect(claims).toHaveLength(1);
        expect(h.printBlocks).not.toHaveBeenCalled();
        w.unmount();
    });

    it('imprimante non prête : rien n’est réclamé ni imprimé', async () => {
        setup({ ready: false });
        const w = await mountScreen();
        list = [order()];
        await polls(w, 3);
        expect(claims).toHaveLength(0);
        expect(h.printBlocks).not.toHaveBeenCalled();
        w.unmount();
    });

    it('mode désactivé : aucune impression automatique', async () => {
        setup({ settings: { autoPrint: false } });
        const w = await mountScreen();
        list = [order()];
        await polls(w, 3);
        expect(h.printBlocks).not.toHaveBeenCalled();
        w.unmount();
    });

    it('quitter l’écran pendant un sondage en cours n’imprime plus rien', async () => {
        setup();
        const w = await mountScreen();
        list = [order()];
        let release;
        h.axios.get.mockImplementationOnce(() => new Promise((r) => { release = () => r({ data: { data: list, meta: { states, server_now: new Date().toISOString() } } }); }));
        const pending = w.vm.poll();
        w.unmount();
        release();
        await pending;
        await flushPromises();
        expect(h.printBlocks).not.toHaveBeenCalled();
    });

    it('le verdict envoyé porte le numéro de tentative reçu à la réclamation', async () => {
        setup();
        claimImpl = () => ({ data: { claimed: true, log_id: 42, attempt: 3 } });
        const w = await mountScreen();
        list = [order()];
        await polls(w, 1);
        const resultCall = h.axios.post.mock.calls.find(([u]) => /prints\/42\/result$/.test(u));
        expect(resultCall).toBeTruthy();
        expect(resultCall[1]).toMatchObject({ status: 'printed', attempt: 3, printed_pages: 1 });
        w.unmount();
    });

    it('échec d’impression : verdict « failed » avec le message et zéro étiquette sortie', async () => {
        setup();
        h.printBlocks.mockResolvedValue({ ok: false, message: 'Capot ouvert : fermez le capot de l’imprimante.', pages: 0 });
        const w = await mountScreen();
        list = [order()];
        await polls(w, 1);
        const call = h.axios.post.mock.calls.find(([u]) => /\/result$/.test(u));
        expect(call[1]).toMatchObject({ status: 'failed', printed_pages: 0 });
        expect(call[1].message).toMatch(/Capot ouvert/);
        w.unmount();
    });

    it('activer le mode retient les commandes déjà affichées (baseline) et marque l’heure du serveur', async () => {
        setup({ settings: { autoPrint: false } });
        list = [order(), order()];
        const w = await mountScreen();
        await polls(w, 1);
        await w.get('[data-testid="label-auto-toggle"]').trigger('click');
        const saved = JSON.parse(window.localStorage.getItem('label_printer_settings'));
        expect(saved.autoPrint).toBe(true);
        expect(saved.autoPrintBaseline).toEqual(list.map((o) => o.id));
        expect(saved.autoPrintSince).toBeGreaterThan(0);
        await polls(w, 2);
        expect(h.printBlocks).not.toHaveBeenCalled(); // les deux étaient déjà là
        w.unmount();
    });

    it('échec sans étiquette sortie : retenté seulement après 35 s ; échec après étiquettes sorties : jamais seul', async () => {
        setup();
        const o = order();
        list = [o];
        const w = await mountScreen();
        h.printBlocks.mockResolvedValue({ ok: false, message: 'Capot ouvert', pages: 0 });
        states = { [o.id]: { printed: false, in_progress: false, failed: true, printed_pages: 0, last_message: 'Capot ouvert' } };
        await polls(w, 1);
        expect(h.printBlocks).toHaveBeenCalledTimes(1);

        await polls(w, 3); // trop tôt : le verrou serveur impose 30 s, le client attend 35 s
        expect(h.printBlocks).toHaveBeenCalledTimes(1);

        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(Date.now() + 40000);
        await polls(w, 1);
        expect(h.printBlocks).toHaveBeenCalledTimes(2);

        // des étiquettes sont sorties puis la panne : jamais de réimpression automatique (doublon)
        states = { [o.id]: { printed: false, in_progress: false, failed: true, printed_pages: 2, last_message: 'Fin de rouleau' } };
        vi.setSystemTime(Date.now() + 120000);
        await polls(w, 2);
        expect(h.printBlocks).toHaveBeenCalledTimes(2);
        vi.useRealTimers();
        w.unmount();
    });

    it('automatique actif + commande en attente + imprimante indisponible : bandeau rouge « bloquée »', async () => {
        setup({ ready: false });
        const w = await mountScreen();
        list = [order()];
        await polls(w, 1);
        const banner = w.find('[data-testid="label-blocked"]');
        expect(banner.exists()).toBe(true);
        expect(banner.text()).toMatch(/1 commande en attente — Imprimante non connectée/);
        expect(w.get('[data-testid="label-auto"]').text()).toMatch(/en pause/);
        expect(w.find('[data-testid="label-retry"]').exists()).toBe(true);
        w.unmount();
    });

    it('imprimante prête ou rien en attente : pas de bandeau', async () => {
        setup({ ready: true });
        const w = await mountScreen();
        list = [order()];
        await polls(w, 1);
        expect(w.find('[data-testid="label-blocked"]').exists()).toBe(false);
        w.unmount();
        setup({ ready: false });
        const w2 = await mountScreen();
        list = [];
        await polls(w2, 1);
        expect(w2.find('[data-testid="label-blocked"]').exists()).toBe(false);
        w2.unmount();
    });

    it('les commandes à imprimer passent avant les imprimées, les plus anciennes en tête', async () => {
        setup({ settings: { autoPrint: false } });
        const t = Date.now();
        const oldest = order({ created_at_iso: new Date(t - 9 * 60000).toISOString() });
        const newest = order({ created_at_iso: new Date(t - 1 * 60000).toISOString() });
        const done = order({ created_at_iso: new Date(t - 5 * 60000).toISOString() });
        list = [newest, done, oldest];
        states = { [done.id]: { printed: true, in_progress: false, failed: false, print_count: 1 } };
        const w = await mountScreen();
        await polls(w, 1);
        const pending = w.findAll('[data-testid="label-orders"] > li').map((li) => li.attributes('data-testid'));
        expect(pending).toEqual([`label-order-${oldest.id}`, `label-order-${newest.id}`]);
        expect(w.findAll('[data-testid="label-orders-done"] > li').map((li) => li.attributes('data-testid'))).toEqual([`label-order-${done.id}`]);
        // l'âge est affiché, avec une urgence à 8 min
        const ageText = w.get(`[data-testid="label-order-${oldest.id}"] [data-testid="label-order-age"]`);
        expect(ageText.text()).toMatch(/il y a 9 min/);
        expect(ageText.classes()).toContain('is-warn');
        w.unmount();
    });

    it('« Réimprimer celle-ci » n’imprime QU’UNE étiquette et le journal le sait', async () => {
        setup({ settings: { autoPrint: false } });
        const o = order({ id: 9500 });
        // 2 étiquettes : deux unités du même produit
        o.order_items[0].quantity = 2;
        list = [o];
        const w = await mountScreen();
        await polls(w, 1);
        await w.get('[data-testid="label-preview-9500"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="label-print-one-9500-2"]').trigger('click');
        await flushPromises();
        expect(h.printBlocks).toHaveBeenCalledTimes(1);
        expect(h.printBlocks.mock.calls[0][1]).toHaveLength(1);
        const claim = h.axios.post.mock.calls.find(([u]) => /\/claim$/.test(u));
        expect(claim[1]).toMatchObject({ mode: 'manual', labels_count: 1, partial: true });
        w.unmount();
    });
});
