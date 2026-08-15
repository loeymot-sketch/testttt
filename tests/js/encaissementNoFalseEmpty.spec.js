import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * [T-4.1 FAUX-VIDE 2026-08-15 · GOAL_CONFORT_MAX] `fetchPending()` avalait
 * silencieusement toute erreur réseau/serveur (catch vide), laissant
 * `orders` à `[]` — le template affichait alors le MÊME ✅ vert "Aucune
 * commande à encaisser" qu'une file réellement vide. Un caissier ne
 * pouvait donc jamais distinguer une panne d'une vraie file vide : des
 * commandes en attente réelles pouvaient rester invisibles à vie.
 *
 * On teste la méthode directement (convention déjà établie par
 * encaissementClientReceiptGate.spec.js pour ce même composant).
 */
vi.mock('axios', () => ({ default: { get: vi.fn() } }));

import axios from 'axios';
import EncaissementComponent from '../../resources/js/components/admin/encaissement/EncaissementComponent.vue';

const fetchPending = EncaissementComponent.methods.fetchPending;

function ctx(overrides = {}) {
    return {
        loading: { isActive: false },
        orders: [],
        fetchError: false,
        ...overrides,
    };
}

describe('EncaissementComponent.fetchPending — pas de faux-vide sur panne', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('succès : orders rempli, fetchError repassé à false', async () => {
        axios.get.mockResolvedValue({ data: { data: [{ id: 1 }] } });
        const c = ctx({ fetchError: true }); // état d'erreur précédent
        await fetchPending.call(c);
        expect(c.orders).toEqual([{ id: 1 }]);
        expect(c.fetchError).toBe(false);
        expect(c.loading.isActive).toBe(false);
    });

    it('échec réseau AVEC orders=[] : fetchError=true — PAS un faux ✅ vide', async () => {
        axios.get.mockRejectedValue(new Error('Network Error'));
        const c = ctx({ orders: [] });
        await fetchPending.call(c);
        expect(c.fetchError, 'une panne doit être TRACÉE, jamais confondue avec une file vide').toBe(true);
        expect(c.orders).toEqual([]);
        expect(c.loading.isActive).toBe(false);
    });

    it('échec réseau alors qu\'une VRAIE liste est déjà affichée : orders conservés (pas effacés par un poll transitoire)', async () => {
        axios.get.mockRejectedValue(new Error('Network Error'));
        const existing = [{ id: 42 }];
        const c = ctx({ orders: existing });
        await fetchPending.call(c, true); // poll silencieux
        expect(c.fetchError).toBe(true);
        // La liste réelle déjà chargée n'est PAS effacée par le catch — c'est le
        // template (fetchError && orders.length===0) qui décide de ne PAS masquer
        // une liste non vide derrière la bannière d'erreur.
        expect(c.orders).toBe(existing);
    });

    it('succès en mode silencieux (poll) : loading jamais activé', async () => {
        axios.get.mockResolvedValue({ data: { data: [] } });
        const c = ctx();
        await fetchPending.call(c, true);
        expect(c.loading.isActive).toBe(false);
        expect(c.fetchError).toBe(false);
    });
});
