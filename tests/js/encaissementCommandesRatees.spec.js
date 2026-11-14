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

// [GOAL REMARQUES 2026-10-03 · T-2.3 R-059] « ça va dans commande rater ça reste 24 heures ». Onglet en
// LECTURE SEULE : client, téléphone, produits, heure d'annulation. Aucun bouton d'action.
const langue = (code) => JSON.parse(fs.readFileSync(path.resolve(__dirname, `../../resources/js/languages/${code}.json`), 'utf8'));
const fr = langue('fr');
const $t = (key, params) => {
    let v = key.split('.').reduce((o, k) => (o ? o[k] : undefined), fr) ?? key;
    if (params) Object.entries(params).forEach(([k, val]) => { v = String(v).replace(`{${k}}`, val); });
    return v;
};

const monter = () => mount(EncaissementComponent, {
    global: {
        mocks: { $t, $store: { getters: { 'frontendSetting/lists': {} } } },
        stubs: { LoadingComponent: true, BreadcrumbComponent: true, CaisseSecondaryNav: true, PosCounterCollectModal: true },
    },
});

const RATEE = {
    id: 42, numero: 'T042', client: 'Karim', telephone: '0600000000', total: 17.8,
    annulee_a: '2026-10-03T13:05:00+02:00', motif: 'Client non venu', produits: ['2 × Cayenne', 'Menu (Frites + Boisson)'],
};

const routes = (url) => Promise.resolve(url === 'admin/pos/counter-collect/missed'
    ? { data: { data: [RATEE], meta: { fenetre_heures: 24 } } }
    : { data: { data: [], meta: { previous_count: 0 } } });

describe('Encaissement — onglet « Ratées (24 h) » (R-059)', () => {
    beforeEach(() => { vi.clearAllMocks(); axios.get.mockImplementation(routes); });

    const ouvrir = async () => {
        const w = monter();
        await flushPromises();
        await w.find('[data-testid="enc-scope-missed"]').trigger('click');
        await flushPromises();
        return w;
    };

    it('charge la route dédiée et affiche client, téléphone, produits', async () => {
        const w = await ouvrir();
        expect(axios.get.mock.calls.at(-1)[0]).toBe('admin/pos/counter-collect/missed');
        const liste = w.find('[data-testid="enc-missed-list"]');
        expect(liste.exists()).toBe(true);
        expect(liste.text()).toContain('T042');
        expect(liste.text()).toContain('Karim');
        expect(liste.text()).toContain('0600000000');
        expect(liste.text()).toContain('2 × Cayenne');
    });

    it('lecture seule : ni encaisser, ni annuler, ni purger', async () => {
        const w = await ouvrir();
        const liste = w.find('[data-testid="enc-missed-list"]');
        expect(liste.findAll('button')).toHaveLength(0);
        expect(w.find('[data-testid="enc-purge-phone"]').exists()).toBe(false);
    });

    it('le rafraîchissement automatique ne remplace pas la liste par la file en attente', async () => {
        const w = await ouvrir();
        axios.get.mockClear();
        await w.vm.fetchPending(true);
        expect(axios.get.mock.calls[0][0]).toBe('admin/pos/counter-collect/missed');
    });

    it('libellés présents dans les trois langues', () => {
        for (const code of ['fr', 'en', 'ar']) {
            const l = langue(code).label;
            for (const k of ['enc_scope_missed', 'enc_missed_empty', 'enc_missed_hint', 'enc_missed_cancelled_at']) {
                expect(l[k], `${code}.${k}`).toBeTruthy();
            }
        }
    });
});
