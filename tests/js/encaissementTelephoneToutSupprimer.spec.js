import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import fs from 'node:fs';
import path from 'node:path';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}));
vi.mock('../../resources/js/services/appService', () => ({ default: { currencyFormat: vi.fn((a) => String(a)) } }));
vi.mock('../../resources/js/services/eventContract', () => ({ onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })) }));
vi.mock('../../resources/js/helpers/posLocalPrinter', () => ({ printEscPosViaCaisseBridge: vi.fn() }));

import axios from 'axios';
import EncaissementComponent from '../../resources/js/components/admin/encaissement/EncaissementComponent.vue';

// [GOAL REMARQUES 2026-10-03 · T-2.2 R-060] « Dans l'attente je veux tout supprimer, je supprime tout »
// (commandes TÉLÉPHONE). Bouton dans l'onglet « Aujourd'hui », compteur des seules commandes téléphone,
// confirmation obligatoire, motif pré-rempli ; la borne et le site ne sont jamais concernés.
const langue = (code) => JSON.parse(fs.readFileSync(path.resolve(__dirname, `../../resources/js/languages/${code}.json`), 'utf8'));
const fr = langue('fr');
const $t = (key, params) => {
    let v = key.split('.').reduce((o, k) => (o ? o[k] : undefined), fr) ?? key;
    if (params) Object.entries(params).forEach(([k, val]) => { v = String(v).replace(`{${k}}`, val); });
    return v;
};

const cmd = (id, surface, total = 10) => ({ id, order_serial_no: `S${id}`, queue_number: `A${id}`, source_surface: surface, total, order_items: [] });

const monter = () => mount(EncaissementComponent, {
    global: {
        mocks: { $t, $store: { getters: { 'frontendSetting/lists': {} } } },
        stubs: { LoadingComponent: true, BreadcrumbComponent: true, CaisseSecondaryNav: true, PosCounterCollectModal: true },
    },
});

const file = (today) => () => Promise.resolve({ data: { data: today, meta: { previous_count: 0 } } });

describe('Encaissement — supprimer d\'un geste les commandes téléphone du jour (R-060)', () => {
    beforeEach(() => { vi.clearAllMocks(); });

    it('le bouton compte UNIQUEMENT les commandes téléphone', async () => {
        axios.get.mockImplementation(file([cmd(1, 'phone'), cmd(2, 'phone'), cmd(3, 'kiosk'), cmd(4, 'web')]));
        const w = monter();
        await flushPromises();
        const bouton = w.find('[data-testid="enc-purge-phone"]');
        expect(bouton.exists()).toBe(true);
        expect(bouton.text()).toContain('2');
    });

    it('aucun bouton quand aucune commande téléphone n\'attend', async () => {
        axios.get.mockImplementation(file([cmd(3, 'kiosk')]));
        const w = monter();
        await flushPromises();
        expect(w.find('[data-testid="enc-purge-phone"]').exists()).toBe(false);
    });

    it('confirmation avec motif pré-rempli, puis appel de la route dédiée — jamais celle des jours précédents', async () => {
        axios.get.mockImplementation(file([cmd(1, 'phone', 12), cmd(2, 'phone', 8), cmd(3, 'kiosk')]));
        axios.post.mockResolvedValue({ data: { status: true, purged: 2, skipped: [] } });
        const w = monter();
        await flushPromises();

        await w.find('[data-testid="enc-purge-phone"]').trigger('click');
        expect(w.find('[data-testid="enc-purge-confirm"]').exists()).toBe(true);
        expect(w.find('[data-testid="enc-purge-summary"]').text()).toContain('2');
        expect(w.find('[data-testid="enc-purge-reason"]').element.value).toBe('Client non venu');

        await w.find('[data-testid="enc-purge-ok"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledTimes(1);
        const [url, body, cfg] = axios.post.mock.calls[0];
        expect(url).toBe('admin/pos/counter-collect/purge-phone-today');
        // [revue vague 2 · P2-1] Seules les commandes MONTRÉES partent.
        expect(body).toEqual({ confirm: true, reason: 'Client non venu', ids: [1, 2] });
        expect(cfg.headers['X-Idempotency-Key']).toMatch(/^pos-purge-phone-today-/);
    });

    it('[revue vague 2 · P1-2] une commande téléphone À L\'AVANCE n\'est ni comptée ni proposée à la suppression', async () => {
        const plusTard = new Date(Date.now() + 6 * 3600 * 1000).toISOString();
        axios.get.mockImplementation(file([cmd(1, 'phone'), { ...cmd(2, 'phone'), scheduled_at: plusTard }]));
        const w = monter();
        await flushPromises();
        expect(w.find('[data-testid="enc-purge-phone"]').text()).toContain('(1)');
    });

    it('[revue vague 2 · P3] la fenêtre de suppression téléphone ne réclame pas de justificatif', async () => {
        axios.get.mockImplementation(file([cmd(1, 'phone')]));
        const w = monter();
        await flushPromises();
        await w.find('[data-testid="enc-purge-phone"]').trigger('click');
        const fenetre = w.find('[data-testid="enc-purge-confirm"]').text();
        expect(fenetre).not.toContain('obligatoire');
        expect(fenetre).toContain('téléphone');
    });

    it('les libellés existent dans les trois langues', () => {
        for (const code of ['fr', 'en', 'ar']) {
            const l = langue(code).label;
            expect(l.enc_purge_phone, `${code}.enc_purge_phone`).toBeTruthy();
            expect(l.enc_purge_summary_phone, `${code}.enc_purge_summary_phone`).toBeTruthy();
            expect(l.enc_purge_reason_phone_default, `${code}.enc_purge_reason_phone_default`).toBeTruthy();
        }
    });
});
