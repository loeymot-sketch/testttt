import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import KdsCanceledBanner, { readAcks, writeAcks, ACK_STORAGE_KEY } from '../../../resources/js/components/admin/kitchenDisplaySystem/KdsCanceledBanner.vue';

/**
 * [SIGNAL ANNULATION CUISINE 2026-08-19] Bandeau « RETIRER DU PASSE ».
 *
 * Contrat : GET admin/kds-order expose `meta.recently_canceled` =
 *   [{ id, order_serial_no, queue_number, canceled_at, from_status, to_status, reason, items }]
 * bornée côté serveur (fenêtre kds.canceled_notice_minutes, max 20).
 *
 * Ce que ces specs protègent réellement : le plat sur le passe. Toute régression qui
 * masquerait le bandeau à tort renvoie la cuisine à l'aveugle — c'est exactement l'état
 * d'AVANT ce correctif.
 */

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../resources/js/services/appService', () => ({
    default: { requestHandler: vi.fn(() => '') },
}));

import axios from 'axios';
import { kitchenDisplaySystemOrder } from '../../../resources/js/store/modules/kitchenDisplaySystemOrder';

const ENTRY_A = {
    id: 6598, order_serial_no: '1908266598', queue_number: 'A0032',
    canceled_at: '2026-08-19T10:32:57+02:00', from_status: 8, to_status: 16,
    reason: 'Client injoignable', items: 'Cayenne · Frites',
};
const ENTRY_B = {
    id: 6600, order_serial_no: '1908266600', queue_number: 'A0033',
    canceled_at: '2026-08-19T10:40:00+02:00', from_status: 7, to_status: 16,
    reason: '', items: 'Tacos',
};

function freshStorage() {
    const store = {};
    return {
        getItem: (k) => (Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null),
        setItem: (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
    };
}

beforeEach(() => {
    global.localStorage = freshStorage();
    window.localStorage = global.localStorage;
});

describe('KdsCanceledBanner — le signal que la cuisine n’avait pas', () => {
    it('liste vide → aucun bandeau (le board reste net quand rien n’est annulé)', () => {
        const w = mount(KdsCanceledBanner, { props: { entries: [] } });
        expect(w.find('[data-testid="kds-canceled-banner"]').exists()).toBe(false);
    });

    it('affiche le numéro de FILE, les plats et le motif — ce qu’il faut pour retrouver l’assiette', () => {
        const w = mount(KdsCanceledBanner, { props: { entries: [ENTRY_A] } });
        const banner = w.find('[data-testid="kds-canceled-banner"]');
        expect(banner.exists()).toBe(true);
        expect(banner.text()).toContain('N°A0032');
        expect(banner.text()).toContain('Cayenne · Frites');
        expect(banner.text()).toContain('Client injoignable');
        // role=alert + aria-live=assertive : une annulation n'est pas une information polie.
        expect(banner.attributes('role')).toBe('alert');
        expect(banner.attributes('aria-live')).toBe('assertive');
    });

    it('« Vu » retire l’entrée de CE poste, et seulement celle-là', async () => {
        const w = mount(KdsCanceledBanner, { props: { entries: [ENTRY_A, ENTRY_B] } });
        expect(w.findAll('.kds-canceled-banner__entry')).toHaveLength(2);

        await w.find('[data-testid="kds-canceled-ack-6598"]').trigger('click');

        const rows = w.findAll('.kds-canceled-banner__entry');
        expect(rows).toHaveLength(1);
        expect(w.text()).toContain('N°A0033');
        expect(w.text()).not.toContain('N°A0032');
    });

    it('une entrée acquittée ne revient pas au sondage suivant', async () => {
        const w = mount(KdsCanceledBanner, { props: { entries: [ENTRY_A] } });
        await w.find('[data-testid="kds-canceled-ack-6598"]').trigger('click');
        await w.setProps({ entries: [ENTRY_A] });
        expect(w.find('[data-testid="kds-canceled-banner"]').exists()).toBe(false);
    });

    it('MAIS une NOUVELLE annulation de la même commande ré-alerte (l’accusé porte sur le retrait, pas sur le numéro)', async () => {
        const w = mount(KdsCanceledBanner, { props: { entries: [ENTRY_A] } });
        await w.find('[data-testid="kds-canceled-ack-6598"]').trigger('click');
        expect(w.find('[data-testid="kds-canceled-banner"]').exists()).toBe(false);

        await w.setProps({ entries: [{ ...ENTRY_A, canceled_at: '2026-08-19T11:05:00+02:00' }] });
        expect(w.find('[data-testid="kds-canceled-banner"]').exists()).toBe(true);
    });

    it('formes inattendues (null, non-objets, id manquant) → neutralisées, jamais d’erreur', () => {
        const w = mount(KdsCanceledBanner, { props: { entries: [null, 'x', { reason: 'sans id' }, ENTRY_A] } });
        expect(w.findAll('.kds-canceled-banner__entry')).toHaveLength(1);
    });

    it('sans numéro de file, retombe sur le serial — jamais sur du vide', () => {
        const w = mount(KdsCanceledBanner, { props: { entries: [{ ...ENTRY_A, queue_number: null }] } });
        expect(w.text()).toContain('#1908266598');
    });
});

describe('Accusés « Vu » — repli SÛR quand le stockage est cassé', () => {
    it('JSON corrompu → {} (le bandeau réapparaît plutôt que de se taire)', () => {
        const s = freshStorage();
        s.setItem(ACK_STORAGE_KEY, '{pas du json');
        expect(readAcks(s)).toEqual({});
    });

    it('stockage indisponible en lecture → {} sans lever', () => {
        const s = { getItem: () => { throw new Error('SecurityError'); }, setItem: () => {} };
        expect(readAcks(s)).toEqual({});
    });

    it('stockage indisponible en écriture → le clic répond quand même', () => {
        const s = { getItem: () => null, setItem: () => { throw new Error('QuotaExceeded'); } };
        expect(() => writeAcks({ 1: 'x' }, [1], s)).not.toThrow();
    });

    it('purge les accusés des commandes que le serveur ne sert plus (l’écran cuisine ne ferme jamais)', () => {
        const s = freshStorage();
        const kept = writeAcks({ 1: 'a', 2: 'b', 3: 'c' }, [2], s);
        expect(kept).toEqual({ 2: 'b' });
        expect(JSON.parse(s.getItem(ACK_STORAGE_KEY))).toEqual({ 2: 'b' });
    });
});

describe('Store kitchenDisplaySystemOrder — commit défensif de meta.recently_canceled', () => {
    it('état initial = tableau vide', () => {
        expect(kitchenDisplaySystemOrder.state.recentlyCanceled).toEqual([]);
    });

    it('meta absente (serveur plus ancien) → [] et non undefined', async () => {
        const state = { lists: [], scheduledUpcoming: [], recentlyCanceled: [] };
        const commit = vi.fn((type, payload) => { state[type] = payload; });
        axios.get.mockResolvedValueOnce({ data: { data: [] } });
        await kitchenDisplaySystemOrder.actions.lists({ commit }, {});
        expect(commit).toHaveBeenCalledWith('recentlyCanceled', []);
    });

    it('meta présente → committée telle quelle', async () => {
        const commit = vi.fn();
        axios.get.mockResolvedValueOnce({ data: { data: [], meta: { recently_canceled: [ENTRY_A] } } });
        await kitchenDisplaySystemOrder.actions.lists({ commit }, {});
        expect(commit).toHaveBeenCalledWith('recentlyCanceled', [ENTRY_A]);
    });

    it('meta de forme inattendue (objet au lieu de tableau) → []', async () => {
        const commit = vi.fn();
        axios.get.mockResolvedValueOnce({ data: { data: [], meta: { recently_canceled: { nope: 1 } } } });
        await kitchenDisplaySystemOrder.actions.lists({ commit }, {});
        expect(commit).toHaveBeenCalledWith('recentlyCanceled', []);
    });

    it('la mutation neutralise tout ce qui n’est pas un tableau', () => {
        const state = { recentlyCanceled: [ENTRY_A] };
        kitchenDisplaySystemOrder.mutations.recentlyCanceled(state, null);
        expect(state.recentlyCanceled).toEqual([]);
    });
});
