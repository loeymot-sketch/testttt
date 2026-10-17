/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Réception montée pour de vrai (serveur simulé) : limites par famille, aperçu du verdict,
 * contrôles visuels tous répondus, écart ⇒ décision + commentaire obligatoires, envoi complet et rejouable.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

import HygieneReceptionComponent from '../../resources/js/components/admin/hygiene/HygieneReceptionComponent.vue';

const META = {
    summary: { count: 0, alerts: 0, refused: 0 },
    categories: [{ key: 'chilled', label: 'Frais', max: 4, negative: false }, { key: 'frozen', label: 'Surgelé', max: -18, negative: true }, { key: 'ambient', label: 'Ambiant', max: null, negative: false }],
    checks: [{ key: 'packaging', label: 'Emballages intacts et propres' }, { key: 'dlc', label: 'DLC suffisantes' }],
    decisions: [{ key: 'accepted', label: 'Accepté' }, { key: 'reserve', label: 'Accepté avec réserve' }, { key: 'refused', label: 'Refusé' }],
    suppliers: [{ id: 3, name: 'Metro' }, { id: 4, name: 'Pomona' }], server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris',
};
let posts;
let rows;

function setup(initialRows = []) {
    posts = [];
    rows = initialRows;
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/hygiene/receptions/today') return { data: { data: rows, meta: META } };
        if (url === 'admin/hygiene/operators') return { data: { data: [{ id: 5, name: 'Karim Benali', short: 'Karim B.', is_me: true }], meta: { me: 5 } } };
        if (url === 'admin/hygiene/receptions/history') return { data: { data: [], meta: {} } };
        return { data: { data: [] } };
    });
    h.axios.post.mockImplementation(async (url, body) => { posts.push([url, body]); return { data: { data: { id: 1 } } }; });
    window.localStorage.clear();
}

async function mountScreen() {
    const w = mount(HygieneReceptionComponent, { global: { mocks: { $t: (k) => k } } });
    await flushPromises();
    return w;
}

async function type(w, id, value, neg) {
    if (neg !== undefined) {
        const sign = w.get(`[data-testid="rcp-t-${id}-sign"]`);
        if ((sign.attributes('aria-pressed') === 'true') !== neg) await sign.trigger('click');
    }
    await w.get(`[data-testid="rcp-t-${id}-input"]`).setValue(value);
}

beforeEach(() => setup());

describe('réception — formulaire', () => {
    it('le surgelé est négatif par défaut et le verdict suit la limite de SA famille', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="rcp-new"]').trigger('click');
        expect(w.get('[data-testid="rcp-t-frozen-sign"]').attributes('aria-pressed')).toBe('true');
        expect(w.get('[data-testid="rcp-t-chilled-sign"]').attributes('aria-pressed')).toBe('false');
        await type(w, 'chilled', '4');
        expect(w.get('[data-testid="rcp-verdict-chilled"]').text()).toContain('Conforme');
        await type(w, 'chilled', '4,1');
        expect(w.get('[data-testid="rcp-verdict-chilled"]').text()).toContain('Au-dessus de la limite');
        await type(w, 'frozen', '18');
        expect(w.get('[data-testid="rcp-verdict-frozen"]').text()).toContain('Conforme');
        await type(w, 'frozen', '10');
        expect(w.get('[data-testid="rcp-verdict-frozen"]').text()).toContain('Au-dessus de la limite');
        await type(w, 'ambient', '40');
        expect(w.get('[data-testid="rcp-verdict-ambient"]').text()).toContain('Relevé noté'); // aucune limite : jamais d'écart
    });

    it('rien ne part tant que fournisseur, température et contrôles ne sont pas renseignés — la raison est dite', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="rcp-new"]').trigger('click');
        const submit = () => w.get('[data-testid="rcp-submit"]');
        expect(submit().attributes('disabled')).toBeDefined();
        expect(w.get('[data-testid="rcp-block"]').text()).toContain('fournisseur');
        await w.get('[data-testid="rcp-supplier-3"]').trigger('click');
        expect(w.get('[data-testid="rcp-block"]').text()).toContain('au moins une température');
        await type(w, 'chilled', '3');
        expect(w.get('[data-testid="rcp-block"]').text()).toContain('contrôles visuels');
        await w.get('[data-testid="rcp-all-ok"]').trigger('click');
        expect(submit().attributes('disabled')).toBeUndefined();
    });

    it('livraison conforme : envoi complet, sans décision, avec opérateur par défaut et référence d’envoi', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="rcp-new"]').trigger('click');
        await w.get('[data-testid="rcp-supplier-3"]').trigger('click');
        await w.get('[data-testid="rcp-bl"]').setValue('BL-22');
        await type(w, 'chilled', '3,5');
        await type(w, 'frozen', '19');
        await w.get('[data-testid="rcp-all-ok"]').trigger('click');
        await w.get('[data-testid="rcp-submit"]').trigger('click');
        await flushPromises();
        const [url, body] = posts.find(([u]) => u === 'admin/hygiene/receptions');
        expect(url).toBe('admin/hygiene/receptions');
        expect(body).toMatchObject({ supplier_id: 3, delivery_note_no: 'BL-22', temperatures: { chilled: 3.5, frozen: -19 }, checks: { packaging: true, dlc: true } });
        expect(body.decision).toBeUndefined();
        expect(body.operator_user_id).toBeUndefined();
        expect(body.client_ref).toMatch(/^r/);
        expect(w.find('[data-testid="rcp-form"]').exists()).toBe(false);
    });

    it('un écart impose décision ET commentaire avant de pouvoir enregistrer', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="rcp-new"]').trigger('click');
        await w.get('[data-testid="rcp-supplier-4"]').trigger('click');
        await type(w, 'chilled', '9');
        await w.get('[data-testid="rcp-all-ok"]').trigger('click');
        expect(w.get('[data-testid="rcp-deviation"]').exists()).toBe(true);
        expect(w.get('[data-testid="rcp-submit"]').attributes('disabled')).toBeDefined();
        expect(w.get('[data-testid="rcp-block"]').text()).toContain('Accepté avec réserve');
        await w.get('[data-testid="rcp-decision-refused"]').trigger('click');
        expect(w.get('[data-testid="rcp-block"]').text()).toContain('dites ce qui ne va pas');
        await w.get('[data-testid="rcp-note"]').setValue('Viande à 9 °C, renvoyée');
        expect(w.get('[data-testid="rcp-submit"]').attributes('disabled')).toBeUndefined();
        await w.get('[data-testid="rcp-submit"]').trigger('click');
        await flushPromises();
        expect(posts[0][1]).toMatchObject({ supplier_id: 4, decision: 'refused', note: 'Viande à 9 °C, renvoyée', temperatures: { chilled: 9 } });
    });

    it('un contrôle visuel « non conforme » déclenche aussi l’écart', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="rcp-new"]').trigger('click');
        await w.get('[data-testid="rcp-all-ok"]').trigger('click');
        expect(w.find('[data-testid="rcp-deviation"]').exists()).toBe(false);
        await w.get('[data-testid="rcp-check-dlc-ko"]').trigger('click');
        expect(w.find('[data-testid="rcp-deviation"]').exists()).toBe(true);
    });

    it('nouveau fournisseur saisi à la volée : envoyé par son nom', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="rcp-new"]').trigger('click');
        await w.get('[data-testid="rcp-supplier-new"]').trigger('click');
        await w.get('[data-testid="rcp-supplier-name"]').setValue('Boucherie Dupont');
        await type(w, 'chilled', '2');
        await w.get('[data-testid="rcp-all-ok"]').trigger('click');
        await w.get('[data-testid="rcp-submit"]').trigger('click');
        await flushPromises();
        expect(posts[0][1].supplier_name).toBe('Boucherie Dupont');
        expect(posts[0][1].supplier_id).toBeUndefined();
    });

    it('le refus du serveur reste dans le formulaire, saisie conservée', async () => {
        const w = await mountScreen();
        h.axios.post.mockImplementationOnce(async () => { const e = new Error('x'); e.response = { data: { message: 'Fournisseur introuvable.' } }; throw e; });
        await w.get('[data-testid="rcp-new"]').trigger('click');
        await w.get('[data-testid="rcp-supplier-3"]').trigger('click');
        await type(w, 'chilled', '2');
        await w.get('[data-testid="rcp-all-ok"]').trigger('click');
        await w.get('[data-testid="rcp-submit"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="rcp-form-error"]').text()).toContain('Fournisseur introuvable');
        expect(w.get('[data-testid="rcp-t-chilled-input"]').element.value).toBe('2');
    });
});

describe('réception — liste du jour', () => {
    const R = (o) => ({ id: 1, supplier_name: 'Metro', delivery_note_no: 'BL-9', received_at: '2026-10-20T08:30:00+02:00', operator_name: 'Karim Benali', delivered_by: null,
        temperatures: [{ category: 'chilled', label: 'Frais', value_c: 3, max_c: 4, status: 'ok' }], checks: [{ key: 'dlc', label: 'DLC suffisantes', ok: true }],
        status: 'ok', decision: 'accepted', decision_label: 'Accepté', note: null, attachment_id: null, voided: false, ...o });

    it('affiche les livraisons, le compteur et exige un motif pour annuler', async () => {
        setup([R({ id: 1 }), R({ id: 2, supplier_name: 'Pomona', status: 'alert', decision: 'refused', decision_label: 'Refusé', note: 'Trop chaud', temperatures: [{ category: 'chilled', label: 'Frais', value_c: 9, max_c: 4, status: 'alert' }] })]);
        const w = await mountScreen();
        expect(w.get('[data-testid="rcp-row-2"]').text()).toContain('Refusé');
        expect(w.get('[data-testid="rcp-row-2"]').text()).toContain('9,0 °C');
        await w.get('[data-testid="rcp-void-1"]').trigger('click');
        expect(w.get('[data-testid="rcp-void-confirm-1"]').attributes('disabled')).toBeDefined();
        await w.get('[data-testid="rcp-void-reason-1"]').setValue('Mauvais BL saisi');
        await w.get('[data-testid="rcp-void-confirm-1"]').trigger('click');
        await flushPromises();
        expect(posts.find(([u]) => u === 'admin/hygiene/receptions/1/void')[1]).toEqual({ reason: 'Mauvais BL saisi' });
    });

    it('aucune livraison : message clair', async () => {
        const w = await mountScreen();
        expect(w.get('[data-testid="rcp-empty"]').text()).toContain('Aucune livraison');
    });
});
