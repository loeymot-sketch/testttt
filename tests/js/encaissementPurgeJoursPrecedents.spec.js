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
import alertService from '../../resources/js/services/alertService';
import EncaissementComponent from '../../resources/js/components/admin/encaissement/EncaissementComponent.vue';

// [GOAL CAISSE/CUISINE #3 2026-10-02] File « en attente d'encaissement » : jour courant par défaut,
// filtre « Jours précédents », purge avec CONFIRMATION (une par une ou toutes).
const fr = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../resources/js/languages/fr.json'), 'utf8'));
const $t = (key, params) => {
    let v = key.split('.').reduce((o, k) => (o ? o[k] : undefined), fr) ?? key;
    if (params) Object.entries(params).forEach(([k, val]) => { v = String(v).replace(`{${k}}`, val); });
    return v;
};

const order = (id, total = 10) => ({ id, order_serial_no: `S${id}`, queue_number: `A${id}`, source_surface: 'kiosk', total, order_items: [] });

const mountEnc = () => mount(EncaissementComponent, {
    global: {
        mocks: { $t, $store: { getters: { 'frontendSetting/lists': {} } } },
        stubs: { LoadingComponent: true, BreadcrumbComponent: true, CaisseSecondaryNav: true, PosCounterCollectModal: true },
    },
});

const pending = (today, previous, previousCount = previous.length) => (url, cfg) => Promise.resolve({
    data: { data: cfg?.params?.scope === 'previous' ? previous : today, meta: { previous_count: previousCount } },
});

describe('file en attente : jour courant par défaut', () => {
    beforeEach(() => { vi.clearAllMocks(); });

    it('demande scope=today au chargement et affiche le badge des jours précédents', async () => {
        axios.get.mockImplementation(pending([order(1)], [order(8), order(9)]));
        const w = mountEnc();
        await flushPromises();

        expect(axios.get.mock.calls[0][1].params.scope).toBe('today');
        expect(w.find('[data-testid="enc-previous-count"]').text()).toBe('2');
        expect(w.find('[data-testid="enc-purge-all"]').exists()).toBe(false);
        expect(w.find('[data-testid="enc-purge-8"]').exists()).toBe(false);
    });

    it('nouveau jour = liste vide ✅ (et non les commandes de la veille)', async () => {
        axios.get.mockImplementation(pending([], [order(8)]));
        const w = mountEnc();
        await flushPromises();
        expect(w.find('[data-test="enc-empty-real"]').exists()).toBe(true);
        expect(w.text()).toContain('Aucune commande à encaisser');
    });
});

describe('jours précédents + purge', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axios.get.mockImplementation(pending([order(1)], [order(8, 12.5), order(9, 7)]));
    });

    const openPrevious = async () => {
        const w = mountEnc();
        await flushPromises();
        await w.find('[data-testid="enc-scope-previous"]').trigger('click');
        await flushPromises();
        return w;
    };

    it('le filtre charge scope=previous et propose Purger / Tout purger', async () => {
        const w = await openPrevious();
        expect(axios.get.mock.calls.at(-1)[1].params.scope).toBe('previous');
        expect(w.find('[data-testid="enc-purge-8"]').exists()).toBe(true);
        expect(w.find('[data-testid="enc-purge-9"]').exists()).toBe(true);
        expect(w.find('[data-testid="enc-purge-all"]').text()).toContain('Tout purger (2)');
        expect(w.find('[data-testid="enc-previous-hint"]').exists()).toBe(true);
    });

    it('AUCUNE purge sans confirmation : le clic ouvre seulement la confirmation', async () => {
        const w = await openPrevious();
        await w.find('[data-testid="enc-purge-all"]').trigger('click');

        expect(w.find('[data-testid="enc-purge-confirm"]').exists()).toBe(true);
        expect(w.find('[data-testid="enc-purge-summary"]').text()).toContain('2 commande(s)');
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('annuler la confirmation ne purge rien', async () => {
        const w = await openPrevious();
        await w.find('[data-testid="enc-purge-all"]').trigger('click');
        await w.find('[data-testid="enc-purge-cancel"]').trigger('click');

        expect(w.find('[data-testid="enc-purge-confirm"]').exists()).toBe(false);
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('le motif est obligatoire pour confirmer', async () => {
        const w = await openPrevious();
        await w.find('[data-testid="enc-purge-all"]').trigger('click');
        await w.find('[data-testid="enc-purge-reason"]').setValue('  ');
        expect(w.find('[data-testid="enc-purge-ok"]').attributes('disabled')).toBeDefined();
    });

    it('confirmer « tout purger » envoie all + confirm + motif, puis recharge', async () => {
        axios.post.mockResolvedValue({ data: { status: true, purged: 2, skipped: [] } });
        const w = await openPrevious();
        await w.find('[data-testid="enc-purge-all"]').trigger('click');
        await w.find('[data-testid="enc-purge-ok"]').trigger('click');
        await flushPromises();

        const [url, body, cfg] = axios.post.mock.calls[0];
        expect(url).toBe('admin/pos/counter-collect/purge-previous');
        expect(body).toMatchObject({ all: true, confirm: true });
        expect(body.reason.length).toBeGreaterThanOrEqual(3);
        expect(cfg.headers['X-Idempotency-Key']).toContain('pos-purge-previous-all-');
        expect(alertService.success).toHaveBeenCalled();
        expect(w.find('[data-testid="enc-purge-confirm"]').exists()).toBe(false);
    });

    it('purger UNE commande envoie ses ids seulement', async () => {
        axios.post.mockResolvedValue({ data: { status: true, purged: 1, skipped: [] } });
        const w = await openPrevious();
        await w.find('[data-testid="enc-purge-9"]').trigger('click');
        expect(w.find('[data-testid="enc-purge-summary"]').text()).toContain('S9');
        await w.find('[data-testid="enc-purge-ok"]').trigger('click');
        await flushPromises();

        expect(axios.post.mock.calls[0][1]).toMatchObject({ ids: [9], confirm: true });
        expect(axios.post.mock.calls[0][1]).not.toHaveProperty('all');
    });

    it('les commandes non purgeables sont signalées, pas masquées', async () => {
        axios.post.mockResolvedValue({ data: { status: true, purged: 1, skipped: [{ id: 8, reason: 'x' }] } });
        const w = await openPrevious();
        await w.find('[data-testid="enc-purge-all"]').trigger('click');
        await w.find('[data-testid="enc-purge-ok"]').trigger('click');
        await flushPromises();
        expect(alertService.warning).toHaveBeenCalled();
    });

    it('un échec serveur affiche une erreur et garde la liste', async () => {
        axios.post.mockRejectedValue({ response: { data: { message: 'Boom' } } });
        const w = await openPrevious();
        await w.find('[data-testid="enc-purge-all"]').trigger('click');
        await w.find('[data-testid="enc-purge-ok"]').trigger('click');
        await flushPromises();
        expect(alertService.error).toHaveBeenCalledWith('Boom');
    });
});
