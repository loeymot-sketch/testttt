/**
 * [CAYENNE HYGIÈNE · audit E2E 2026-11-18] Réception : le journal montre le contrôle visuel non conforme et les photos (consultables
 * après le jour même), l'erreur du serveur s'efface dès que la saisie change, « 4,04 » reçoit un message exact (pas « illisible »).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

import HygieneReceptionComponent from '../../resources/js/components/admin/hygiene/HygieneReceptionComponent.vue';

const NOW = '2026-10-20T10:00:00+02:00';
const META = {
    summary: { count: 0, alerts: 0, refused: 0 },
    categories: [{ key: 'chilled', label: 'Frais', max: 4, negative: false }, { key: 'frozen', label: 'Surgelé', max: -18, negative: true }],
    checks: [{ key: 'packaging', label: 'Emballages intacts et propres' }, { key: 'dlc', label: 'DLC / DLUO suffisantes' }],
    decisions: [{ key: 'accepted', label: 'Accepté' }, { key: 'reserve', label: 'Accepté avec réserve' }, { key: 'refused', label: 'Refusé' }],
    suppliers: [{ id: 3, name: 'Metro' }], server_now: NOW, timezone: 'Europe/Paris',
};
let history;

function setup(opts = {}) {
    history = opts.history || [];
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/hygiene/receptions/today') return { data: { data: [], meta: META } };
        if (url === 'admin/hygiene/operators') return { data: { data: [{ id: 5, name: 'Karim Benali', short: 'Karim B.', is_me: true }], meta: { me: 5, server_now: NOW } } };
        if (url === 'admin/hygiene/receptions/history') return { data: { data: history, meta: { server_now: NOW } } };
        return { data: { data: [], meta: { server_now: NOW } } };
    });
    h.axios.post.mockImplementation(async () => ({ data: { data: { id: 1 } } }));
    window.localStorage.clear();
}
async function mountScreen() {
    const w = mount(HygieneReceptionComponent, { global: { mocks: { $t: (k) => k }, stubs: { 'router-link': true } } });
    await flushPromises();
    return w;
}

beforeEach(() => setup());

describe('réception — journal (audit 2026-11-18)', () => {
    it('P1 : le contrôle visuel non conforme et les photos figurent au journal', async () => {
        setup({ history: [{
            id: 8, received_at: '2026-10-18T07:30:00+02:00', supplier_name: 'Metro', delivery_note_no: '2210', status: 'alert', decision: 'reserve',
            decision_label: 'Accepté avec réserve', operator_name: 'Karim', note: 'DLC courte',
            temperatures: [{ category: 'chilled', label: 'Frais', value_c: 3, status: 'ok' }],
            checks: [{ key: 'packaging', label: 'Emballages intacts et propres', ok: true }, { key: 'dlc', label: 'DLC / DLUO suffisantes', ok: false }],
            attachment_id: 61, attachment_ids: [61, 62], voided: false,
        }, {
            id: 9, received_at: '2026-10-18T09:00:00+02:00', supplier_name: 'Pomona', delivery_note_no: null, status: 'ok', decision: 'accepted', decision_label: 'Accepté',
            temperatures: [], checks: [{ key: 'dlc', label: 'DLC / DLUO suffisantes', ok: true }], attachment_id: null, attachment_ids: [], voided: false,
        }] });
        const w = await mountScreen();
        await w.get('[data-testid="rcp-tab-journal"]').trigger('click');
        await flushPromises();
        const meta = w.get('[data-testid="rcp-history-meta-8"]').text();
        expect(meta).toContain('✗ DLC / DLUO suffisantes');
        expect(meta).not.toContain('Emballages');
        expect(w.get('[data-testid="rcp-history-meta-9"]').text()).not.toContain('✗');
        expect(w.get('[data-testid="hy-photo-view-61"]').text()).toBe('📷 Voir le BL');
        expect(w.get('[data-testid="hy-photo-view-62"]').text()).toBe('📷 Photo 2');
        expect(w.findAll('[data-testid="hy-photo-view-61"]')).toHaveLength(1); // jamais deux fois la même photo
    });
});

describe('réception — formulaire (audit 2026-11-18)', () => {
    it('P1 : l’erreur du serveur s’efface dès que la saisie change', async () => {
        h.axios.post.mockImplementationOnce(async () => { const e = new Error('x'); e.response = { data: { message: 'Fournisseur introuvable.' } }; throw e; });
        const w = await mountScreen();
        await w.get('[data-testid="rcp-new"]').trigger('click');
        await w.get('[data-testid="rcp-supplier-3"]').trigger('click');
        await w.get('[data-testid="rcp-t-chilled-input"]').setValue('3');
        await w.get('[data-testid="rcp-all-ok"]').trigger('click');
        await w.get('[data-testid="rcp-submit"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="rcp-form-error"]').text()).toContain('Fournisseur introuvable');
        await w.get('[data-testid="rcp-bl"]').setValue('BL-9');
        expect(w.find('[data-testid="rcp-form-error"]').exists()).toBe(false);
    });

    it('P2-h : « 4,04 » n’est pas « illisible » : message exact avec la valeur au dixième ; une vraie faute reste « illisible »', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="rcp-new"]').trigger('click');
        await w.get('[data-testid="rcp-t-chilled-input"]').setValue('4,04');
        expect(w.get('[data-testid="rcp-raw-error-chilled"]').text()).toBe('Une seule décimale (le thermomètre se lit au dixième) : tapez 4,0.');
        await w.get('[data-testid="rcp-t-frozen-input"]').setValue('18.27');
        expect(w.get('[data-testid="rcp-raw-error-frozen"]').text()).toContain('tapez 18,3');
        await w.get('[data-testid="rcp-t-chilled-input"]').setValue('3-5');
        expect(w.get('[data-testid="rcp-raw-error-chilled"]').text()).toContain('Valeur illisible');
        await w.get('[data-testid="rcp-t-chilled-input"]').setValue('4,0');
        expect(w.find('[data-testid="rcp-raw-error-chilled"]').exists()).toBe(false);
    });
});
