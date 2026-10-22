/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Traçabilité montée pour de vrai : lot (ou explication) obligatoire, DLC dépassée = commentaire
 * obligatoire, produit du catalogue relié, envoi complet, fiche de rappel d'un lot.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

import HygieneTraceabilityComponent from '../../resources/js/components/admin/hygiene/HygieneTraceabilityComponent.vue';

const META = {
    summary: { count: 0, alerts: 0 }, products: [{ id: 7, name: 'Cheddar tranché', category: 'Fromages' }, { id: 8, name: 'Steak haché', category: 'Viandes' }],
    suppliers: [{ id: 3, name: 'Metro' }], receptions: [{ id: 11, supplier_id: 3, supplier_name: 'Metro', delivery_note_no: 'BL-9', received_at: '2026-10-20T08:00:00+02:00' }],
    server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris',
};
let posts;
let recallData;

function setup(rows = []) {
    posts = [];
    recallData = { lot: 'L1', entries: [], receptions: [], labels: [] };
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.get.mockImplementation(async (url, cfg) => {
        if (url === 'admin/hygiene/trace/today') return { data: { data: rows, meta: META } };
        if (url === 'admin/hygiene/operators') return { data: { data: [{ id: 5, name: 'Karim Benali', short: 'Karim B.', is_me: true }], meta: { me: 5, server_now: '2026-10-20T10:00:00+02:00' } } };
        if (url === 'admin/hygiene/trace/history') return { data: { data: [], meta: { server_now: '2026-10-20T10:00:00+02:00' } } };
        if (url === 'admin/hygiene/trace/recall') return { data: { data: { ...recallData, lot: cfg.params.lot }, meta: { server_now: '2026-10-20T10:00:00+02:00' } } };
        return { data: { data: [], meta: { server_now: '2026-10-20T10:00:00+02:00' } } };
    });
    h.axios.post.mockImplementation(async (url, body) => { posts.push([url, body]); return { data: { data: { id: 1 } } }; });
    window.localStorage.clear();
}
async function mountScreen() {
    const w = mount(HygieneTraceabilityComponent, { global: { mocks: { $t: (k) => k } } });
    await flushPromises();
    return w;
}
const submit = (w) => w.get('[data-testid="trc-submit"]');

beforeEach(() => setup());

describe('traçabilité — formulaire', () => {
    it('le produit est obligatoire ; le lot aussi, sauf explication', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="trc-new"]').trigger('click');
        expect(submit(w).attributes('disabled')).toBeDefined();
        await w.get('[data-testid="trc-product"]').setValue('Pain burger');
        expect(submit(w).attributes('disabled')).toBeDefined(); // pas de lot, pas d'explication
        expect(w.get('[data-testid="trc-nolot"]').exists()).toBe(true);
        await w.get('[data-testid="trc-lot"]').setValue('P-12');
        expect(submit(w).attributes('disabled')).toBeUndefined();
        await w.get('[data-testid="trc-lot"]').setValue('');
        await w.get('[data-testid="trc-note"]').setValue('Aucun lot sur le sachet');
        expect(submit(w).attributes('disabled')).toBeUndefined();
    });

    it('une DLC déjà dépassée exige un commentaire et le dit', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="trc-new"]').trigger('click');
        await w.get('[data-testid="trc-product"]').setValue('Yaourt');
        await w.get('[data-testid="trc-lot"]').setValue('Y1');
        await w.get('[data-testid="trc-dlc"]').setValue('2026-10-19');
        expect(w.get('[data-testid="trc-expired"]').exists()).toBe(true);
        expect(submit(w).attributes('disabled')).toBeDefined();
        await w.get('[data-testid="trc-note"]').setValue('Refusé, rendu au livreur');
        expect(submit(w).attributes('disabled')).toBeUndefined();
        await w.get('[data-testid="trc-dlc"]').setValue('2026-10-20'); // le jour même reste valable
        expect(w.find('[data-testid="trc-expired"]').exists()).toBe(false);
    });

    it('un produit du catalogue se choisit par suggestion et part par son identifiant', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="trc-new"]').trigger('click');
        await w.get('[data-testid="trc-product"]').setValue('ched');
        await w.get('[data-testid="trc-suggest-7"]').trigger('click');
        expect(w.get('[data-testid="trc-product-linked"]').exists()).toBe(true);
        await w.get('[data-testid="trc-lot"]').setValue('C-5');
        await w.get('[data-testid="trc-supplier-3"]').trigger('click');
        await w.get('[data-testid="trc-reception-11"]').trigger('click');
        await w.get('[data-testid="trc-dlc"]').setValue('2026-12-01');
        await submit(w).trigger('click');
        await flushPromises();
        const [, body] = posts.find(([u]) => u === 'admin/hygiene/trace');
        expect(body).toMatchObject({ dlc_product_id: 7, supplier_id: 3, reception_id: 11, lot: 'C-5', dlc_date: '2026-12-01', dlc_kind: 'dlc' });
        expect(body.product_name).toBeUndefined();
        expect(body.client_ref).toMatch(/^t/);
    });

    it('modifier le texte après une suggestion coupe le lien avec le catalogue', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="trc-new"]').trigger('click');
        await w.get('[data-testid="trc-product"]').setValue('ched');
        await w.get('[data-testid="trc-suggest-7"]').trigger('click');
        await w.get('[data-testid="trc-product"]').setValue('Cheddar fumé maison');
        expect(w.find('[data-testid="trc-product-linked"]').exists()).toBe(false);
        await w.get('[data-testid="trc-lot"]').setValue('X');
        await submit(w).trigger('click');
        await flushPromises();
        const [, body] = posts.find(([u]) => u === 'admin/hygiene/trace');
        expect(body.dlc_product_id).toBeUndefined();
        expect(body.product_name).toBe('Cheddar fumé maison');
    });

    it('refus du serveur : message dans le formulaire, saisie conservée', async () => {
        const w = await mountScreen();
        h.axios.post.mockImplementationOnce(async () => { const e = new Error('x'); e.response = { data: { message: 'Réception introuvable ou annulée.' } }; throw e; });
        await w.get('[data-testid="trc-new"]').trigger('click');
        await w.get('[data-testid="trc-product"]').setValue('Steak');
        await w.get('[data-testid="trc-lot"]').setValue('S1');
        await submit(w).trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="trc-form-error"]').text()).toContain('Réception introuvable');
        expect(w.get('[data-testid="trc-lot"]').element.value).toBe('S1');
    });
});

describe('traçabilité — rappel de lot', () => {
    it('la fiche liste entrées, livraisons et préparations avec leur profondeur', async () => {
        const w = await mountScreen();
        recallData = {
            entries: [{ id: 1, traced_at: '2026-10-20T08:10:00+02:00', product_name: 'Steak haché', supplier_name: 'Metro', dlc_date: '2026-12-01' }],
            receptions: [{ id: 2, supplier_name: 'Metro', delivery_note_no: 'BL-500', received_at: '2026-10-20T08:00:00+02:00', status: 'ok', decision_label: 'Accepté' }],
            labels: [
                { id: 5, depth: 0, product_name: 'Steak décongelé', lot: 'L1', opened_at: '2026-10-20T09:00:00+02:00', expires_at: '2026-10-21T09:00:00+02:00', discarded_at: null, discard_reason: null },
                { id: 6, depth: 1, product_name: 'Burger cuit', lot: 'L2', opened_at: '2026-10-20T10:00:00+02:00', expires_at: '2026-10-21T10:00:00+02:00', discarded_at: '2026-10-20T20:00:00+02:00', discard_reason: 'discarded' },
            ],
        };
        await w.get('[data-testid="trc-tab-recall"]').trigger('click');
        expect(w.get('[data-testid="trc-recall-go"]').attributes('disabled')).toBeDefined();
        await w.get('[data-testid="trc-recall-lot"]').setValue('LOT-77');
        await w.get('[data-testid="trc-recall-go"]').trigger('click');
        await flushPromises();
        expect(h.axios.get).toHaveBeenCalledWith('admin/hygiene/trace/recall', { params: { lot: 'LOT-77' } });
        expect(w.get('[data-testid="trc-recall-sum"]').text()).toContain('1 entrée · 1 livraison · 2 étiquettes');
        const items = w.findAll('[data-testid="trc-recall-labels"] li');
        expect(items).toHaveLength(2);
        expect(items[0].text()).toContain('encore en cours');
        expect(items[1].text()).toContain('jetée');
        expect(items[1].attributes('style')).toContain('margin-left: 24px');
    });

    it('lot inconnu : message clair', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="trc-tab-recall"]').trigger('click');
        await w.get('[data-testid="trc-recall-lot"]').setValue('NOPE');
        await w.get('[data-testid="trc-recall-go"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="trc-recall-none"]').text()).toContain('Aucune trace');
    });
});

// [VG-2026-10-21 M8-101 M8-103] réponse 200 sans la forme d'une réponse du serveur (portail captif, proxy)
describe('traçabilité — réponse 200 sans la forme attendue', () => {
    const HTML = { status: 200, data: '<!doctype html><title>Portail wifi</title>' };
    it('tracer : jamais « Produit tracé. », la saisie reste et le message dit « Envoi non confirmé »', async () => {
        const w = await mountScreen();
        const alertService = (await import('../../resources/js/services/alertService')).default;
        alertService.success.mockClear();
        await w.get('[data-testid="trc-new"]').trigger('click');
        await w.get('[data-testid="trc-product"]').setValue('Steak');
        await w.get('[data-testid="trc-lot"]').setValue('S1');
        h.axios.post.mockImplementationOnce(async () => HTML);
        await submit(w).trigger('click');
        await flushPromises();
        expect(alertService.success).not.toHaveBeenCalled();
        expect(w.get('[data-testid="trc-form-error"]').text()).toContain('Envoi non confirmé');
        expect(w.get('[data-testid="trc-lot"]').element.value).toBe('S1');
    });
    it('premier chargement : une erreur, jamais une journée vide', async () => {
        h.axios.get.mockImplementation(async () => HTML);
        const w = await mountScreen();
        expect(w.get('[data-testid="trc-error"]').text()).toContain('Traçabilité indisponible');
    });
});
