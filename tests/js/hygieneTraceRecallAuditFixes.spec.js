/**
 * [CAYENNE HYGIÈNE · audit E2E 2026-11-18] Traçabilité : fiche de rappel honnête (réception annulée barrée et motivée, étiquette
 * non confirmée dite telle, fournisseur absent dit proprement, photos consultables), journal (photo, DLC/DLUO), erreur effacée dès que
 * la saisie change, suggestions tolérantes, avertissement « lot déjà tracé », impression de la fiche seule.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

import HygieneTraceabilityComponent from '../../resources/js/components/admin/hygiene/HygieneTraceabilityComponent.vue';

const NOW = '2026-10-20T10:00:00+02:00';
const META = {
    summary: { count: 0, alerts: 0 },
    products: [
        { id: 7, name: 'Oignons émincés', category: 'Légumes' }, { id: 8, name: 'Œufs durs', category: 'Œufs' },
        { id: 9, name: 'Ail haché', category: 'Légumes' }, { id: 10, name: 'Steak haché', category: 'Viandes' },
    ],
    suppliers: [{ id: 3, name: 'Metro' }], receptions: [], server_now: NOW, timezone: 'Europe/Paris',
};
let posts;
let rows;
let history;
let recallData;

function setup(opts = {}) {
    posts = [];
    rows = opts.rows || [];
    history = opts.history || [];
    recallData = opts.recall || { entries: [], receptions: [], labels: [] };
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.get.mockImplementation(async (url, cfg) => {
        if (url === 'admin/hygiene/trace/today') return { data: { data: rows, meta: META } };
        if (url === 'admin/hygiene/operators') return { data: { data: [{ id: 5, name: 'Karim Benali', short: 'Karim B.', is_me: true }], meta: { me: 5, server_now: NOW } } };
        if (url === 'admin/hygiene/trace/history') return { data: { data: history, meta: { server_now: NOW } } };
        if (url === 'admin/hygiene/trace/recall') return { data: { data: { ...recallData, lot: cfg.params.lot }, meta: { server_now: NOW } } };
        return { data: { data: [], meta: { server_now: NOW } } };
    });
    h.axios.post.mockImplementation(async (url, body) => { posts.push([url, body]); return { data: { data: { id: 99 } } }; });
    window.localStorage.clear();
}
const mounted = [];
async function mountScreen(store = { getters: {} }) {
    const w = mount(HygieneTraceabilityComponent, { global: { mocks: { $t: (k) => k, $store: store }, stubs: { 'router-link': true } } });
    mounted.push(w);
    await flushPromises();
    return w;
}
async function openRecall(w, lot = 'LOT-9') {
    await w.get('[data-testid="trc-tab-recall"]').trigger('click');
    await w.get('[data-testid="trc-recall-lot"]').setValue(lot);
    await w.get('[data-testid="trc-recall-go"]').trigger('click');
    await flushPromises();
}
const tracePosts = () => posts.filter(([u]) => u === 'admin/hygiene/trace');

beforeEach(() => setup());
afterEach(() => {
    while (mounted.length) { try { mounted.pop().unmount(); } catch (_) { /* déjà démonté */ } }
    document.body.classList.remove('trc-printing');
});

describe('fiche de rappel — audit 2026-11-18', () => {
    it('P0 : une réception annulée reste listée, barrée, avec « ANNULÉE : motif », quand et par qui', async () => {
        setup({ recall: {
            entries: [{ id: 1, traced_at: '2026-10-20T08:10:00+02:00', product_name: 'Steak haché', supplier_name: 'Metro', dlc_date: '2026-12-01', dlc_kind: 'dlc', attachment_id: 31 }],
            receptions: [
                { id: 2, supplier_name: 'Metro', delivery_note_no: 'BL-500', received_at: '2026-10-20T08:00:00+02:00', status: 'alert', decision: 'reserve', decision_label: 'Accepté avec réserve',
                    voided: true, voided_at: '2026-10-20T09:30:00+02:00', void_reason: 'Mauvais fournisseur saisi', voided_by_name: 'Karim Benali', attachment_id: 41, attachment_ids: [42] },
                { id: 3, supplier_name: 'Pomona', delivery_note_no: null, received_at: '2026-10-19T08:00:00+02:00', status: 'ok', decision: 'accepted', decision_label: 'Accepté', voided: false, attachment_id: null, attachment_ids: [] },
            ],
            labels: [],
        } });
        const w = await mountScreen();
        await openRecall(w);

        const voided = w.get('[data-testid="trc-recall-reception-2"]');
        expect(voided.classes()).toContain('trc-voided');
        expect(voided.get('.trc-struck').text()).toContain('Accepté avec réserve');
        expect(w.get('[data-testid="trc-recall-reception-void-2"]').text()).toBe('ANNULÉE : Mauvais fournisseur saisi (le 20/10 09:30, par Karim Benali)');
        expect(w.find('[data-testid="trc-recall-reception-void-3"]').exists()).toBe(false);
        expect(w.get('[data-testid="trc-recall-reception-3"]').find('.trc-struck').exists()).toBe(false);
        expect(w.get('[data-testid="trc-recall-sum"]').text()).toContain('2 livraisons (dont 1 annulée)');

        // P1 : les photos (BL, photo complémentaire, étiquette fournisseur) se consultent depuis la fiche
        expect(voided.find('[data-testid="hy-photo-view-41"]').text()).toBe('📷 Voir le BL');
        expect(voided.find('[data-testid="hy-photo-view-42"]').text()).toBe('📷 Photo 2');
        expect(w.get('[data-testid="trc-recall-entry-1"]').find('[data-testid="hy-photo-view-31"]').text()).toBe("📷 Voir l'étiquette");
        // la DLC de l'entrée est nommée (DLC / DLUO)
        expect(w.get('[data-testid="trc-recall-entry-1"]').text()).toContain('DLC 01/12/2026');
    });

    it('P1 : une étiquette « non confirmée » est dite telle ; P2-e : un fournisseur absent ne donne pas « · · »', async () => {
        setup({ recall: {
            entries: [{ id: 1, traced_at: '2026-10-20T08:10:00+02:00', product_name: 'Ail haché', supplier_name: null, dlc_date: '2026-12-01', dlc_kind: 'dluo', attachment_id: null }],
            receptions: [],
            labels: [
                { id: 5, depth: 0, product_name: 'Ail confit', lot: 'L1', status: 'unconfirmed', printed_pages: 1, opened_at: '2026-10-20T09:00:00+02:00', expires_at: '2026-10-21T09:00:00+02:00', discarded_at: null, discard_reason: null },
                { id: 6, depth: 0, product_name: 'Ail émincé', lot: 'L2', status: 'printed', printed_pages: 1, opened_at: '2026-10-20T09:05:00+02:00', expires_at: '2026-10-21T09:05:00+02:00', discarded_at: null, discard_reason: null },
            ],
        } });
        const w = await mountScreen();
        await openRecall(w);
        const entry = w.get('[data-testid="trc-recall-entry-1"]').text();
        expect(entry).not.toMatch(/·\s*·/);
        expect(entry).toContain('fournisseur non renseigné');
        expect(entry).toContain('DLUO 01/12/2026');
        const items = w.findAll('[data-testid="trc-recall-labels"] li');
        expect(items[0].text()).toContain('impression non confirmée');
        expect(items[1].text()).not.toContain('non confirmée');
    });

    it('P2-g : « Imprimer la fiche » n’imprime que la fiche, avec le restaurant et l’heure d’édition, puis rend la page', async () => {
        setup({ recall: { entries: [{ id: 1, traced_at: '2026-10-20T08:10:00+02:00', product_name: 'Steak haché', supplier_name: 'Metro' }], receptions: [], labels: [] } });
        const prevTitle = document.title;
        document.title = '(2) Le Cayenne';
        const print = vi.fn(() => {
            // pendant l'impression : la classe qui active les règles @media print est posée, l'en-tête est à jour
            expect(document.body.classList.contains('trc-printing')).toBe(true);
        });
        const prevPrint = window.print;
        window.print = print;
        try {
            const w = await mountScreen();
            await openRecall(w);
            await w.get('[data-testid="trc-recall-print"]').trigger('click');
            await flushPromises();
            expect(print).toHaveBeenCalledTimes(1);
            const head = w.get('[data-testid="trc-print-head"]').text();
            expect(head).toMatch(/^Le Cayenne · Fiche éditée le \d{2}\/\d{2}\/\d{4} à \d{2}:\d{2}$/);
            window.dispatchEvent(new Event('afterprint'));
            expect(document.body.classList.contains('trc-printing')).toBe(false);
            // l'impression du navigateur (Ctrl+P) hors de la fiche ne pose pas la classe
            await w.get('[data-testid="trc-tab-today"]').trigger('click');
            window.dispatchEvent(new Event('beforeprint'));
            expect(document.body.classList.contains('trc-printing')).toBe(false);
        } finally {
            window.print = prevPrint;
            document.title = prevTitle;
        }
    });

    it('P2-g : le nom du restaurant vient des réglages quand ils sont chargés ; démonter l’écran retire la classe d’impression', async () => {
        setup({ recall: { entries: [{ id: 1, traced_at: '2026-10-20T08:10:00+02:00', product_name: 'Steak haché', supplier_name: 'Metro' }], receptions: [], labels: [] } });
        const w = await mountScreen({ getters: { 'company/lists': { company_name: 'Le Cayenne Montpellier' } } });
        await openRecall(w);
        window.dispatchEvent(new Event('beforeprint')); // Ctrl+P sur la fiche : même mise en page
        await flushPromises();
        expect(document.body.classList.contains('trc-printing')).toBe(true);
        expect(w.get('[data-testid="trc-print-head"]').text()).toMatch(/^Le Cayenne Montpellier · Fiche éditée le /);
        w.unmount();
        expect(document.body.classList.contains('trc-printing')).toBe(false);
    });
});

describe('journal — audit 2026-11-18', () => {
    it('P1 + P2-f : la photo d’étiquette se consulte au journal et la date dit DLC ou DLUO', async () => {
        setup({ history: [
            { id: 4, traced_at: '2026-10-18T08:00:00+02:00', product_name: 'Steak haché', lot: 'S-1', dlc_date: '2026-12-01', dlc_kind: 'dlc', supplier_name: 'Metro', operator_name: 'Karim', attachment_id: 51, voided: false },
            { id: 5, traced_at: '2026-10-18T08:05:00+02:00', product_name: 'Farine', lot: 'F-1', dlc_date: '2027-03-01', dlc_kind: 'dluo', supplier_name: null, operator_name: 'Karim', attachment_id: null, voided: false },
        ] });
        const w = await mountScreen();
        await w.get('[data-testid="trc-tab-journal"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="trc-history-meta-4"]').text()).toContain('DLC 01/12/2026');
        expect(w.get('[data-testid="trc-history-meta-5"]').text()).toContain('DLUO 01/03/2027');
        expect(w.find('[data-testid="hy-photo-view-51"]').exists()).toBe(true);
    });
});

describe('formulaire — audit 2026-11-18', () => {
    it('P1 : l’erreur du serveur s’efface dès que la saisie change', async () => {
        const w = await mountScreen();
        h.axios.post.mockImplementationOnce(async () => { const e = new Error('x'); e.response = { data: { message: 'Réception introuvable ou annulée.' } }; throw e; });
        await w.get('[data-testid="trc-new"]').trigger('click');
        await w.get('[data-testid="trc-product"]').setValue('Steak');
        await w.get('[data-testid="trc-lot"]').setValue('S1');
        await w.get('[data-testid="trc-submit"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="trc-form-error"]').text()).toContain('Réception introuvable');
        await w.get('[data-testid="trc-lot"]').setValue('S12');
        expect(w.find('[data-testid="trc-form-error"]').exists()).toBe(false);
    });

    it('P2-c : suggestions tolérantes (« emince », « oeuf », « l\'ail »)', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="trc-new"]').trigger('click');
        const names = async (q) => { await w.get('[data-testid="trc-product"]').setValue(q); return w.findAll('[data-testid^="trc-suggest-"]').map((b) => b.text()); };
        expect(await names('emince')).toContain('Oignons émincés');
        expect(await names('oeuf')).toContain('Œufs durs');
        expect(await names("l'ail")).toContain('Ail haché');
        expect(await names('steaks')).toContain('Steak haché');
    });

    it('P2-d : même produit + même lot (casse près) déjà tracé aujourd’hui → avertissement, rien n’est envoyé sans confirmation', async () => {
        setup({ rows: [{ id: 12, product_name: 'Steak haché', dlc_product_id: 10, lot: 'lot-77', traced_at: '2026-10-20T08:15:00+02:00', voided: false, status: 'ok' }] });
        const w = await mountScreen();
        await w.get('[data-testid="trc-new"]').trigger('click');
        await w.get('[data-testid="trc-product"]').setValue('steak');
        await w.get('[data-testid="trc-suggest-10"]').trigger('click');
        await w.get('[data-testid="trc-lot"]').setValue(' LOT-77 ');
        await w.get('[data-testid="trc-submit"]').trigger('click');
        await flushPromises();
        expect(tracePosts()).toHaveLength(0);
        expect(w.get('[data-testid="trc-dup"]').text()).toContain('déjà tracé aujourd\'hui à 08:15');
        await w.get('[data-testid="trc-dup-confirm"]').trigger('click');
        await flushPromises();
        expect(tracePosts()).toHaveLength(1);
        expect(tracePosts()[0][1]).toMatchObject({ dlc_product_id: 10, lot: 'LOT-77' });
    });

    it('P2-d : déjà tracé un autre jour (journal) → « le … » ; corriger la saisie retire l’avertissement ; une ligne annulée ne compte pas', async () => {
        setup({
            rows: [{ id: 13, product_name: 'Cheddar', lot: 'C-1', traced_at: '2026-10-20T08:00:00+02:00', voided: true }],
            history: [{ id: 3, product_name: 'cheddar', dlc_product_id: null, lot: 'c-1', traced_at: '2026-10-18T07:40:00+02:00', voided: false }],
        });
        const w = await mountScreen();
        await w.get('[data-testid="trc-new"]').trigger('click');
        await w.get('[data-testid="trc-product"]').setValue('Cheddar');
        await w.get('[data-testid="trc-lot"]').setValue('C-1');
        await w.get('[data-testid="trc-submit"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="trc-dup"]').text()).toContain('déjà tracé le 18/10 07:40');
        await w.get('[data-testid="trc-lot"]').setValue('C-2');
        expect(w.find('[data-testid="trc-dup"]').exists()).toBe(false);
        history = [];
        await w.get('[data-testid="trc-submit"]').trigger('click');
        await flushPromises();
        expect(tracePosts()).toHaveLength(1);
    });
});
