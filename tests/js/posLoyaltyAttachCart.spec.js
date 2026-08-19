/**
 * [FIDÉLITÉ PANIER 2026-08-19] Sentinelle : rattacher le client PENDANT la vente.
 *
 * CE QUE CETTE SENTINELLE PROTÈGE — et pourquoi elle existe.
 *
 * Le programme de fidélité était complet côté serveur (identifier, inscrire, rattacher,
 * créditer, débiter, grand-livre) mais ne tournait pas : mesuré le 2026-08-19 sur la base
 * réelle, 1817 ventes de caisse et **12** portant un code fidélité, dont 5 lignes « earn »
 * de surface caisse dans TOUTE la base.
 *
 * La cause n'était pas un moteur manquant, c'était une porte fermée : le modal
 * d'identification était gaté sur `orderId`, qui ne désigne qu'une commande DÉJÀ VALIDÉE.
 * Au moment naturel du comptoir — « vous avez la carte ? » pendant qu'on compose le panier —
 * il n'y a pas encore de commande : le bouton « Cumuler sur cette vente » restait inactif et
 * le champ `loyalty_customer_code` (accepté par PosOrderRequest:215, persisté par
 * OrderService:1204, lu par AwardLoyaltyPointsOnDelivery) n'était JAMAIS écrit par personne.
 *
 * Les deux moments doivent rester DISTINCTS, et c'est le cœur de ce fichier :
 *   - panier en cours  → rien en base, on annonce le client au formulaire de la vente ;
 *   - commande validée → appel serveur de rattachement rétroactif (comportement historique).
 * Les confondre re-créerait le défaut inverse : créditer la vente SUIVANTE au client
 * précédent.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('axios', () => ({
    default: { post: vi.fn(), get: vi.fn() },
}));
import axios from 'axios';
import PosLoyaltyIdentifyModal from '../../resources/js/components/admin/pos/PosLoyaltyIdentifyModal.vue';

const CLIENT = {
    id: 573,
    name: 'Client Comptoir',
    loyalty_code: 'E2ECAIS1',
    balance: 900,
    balance_eur: 9,
    usable_points: 0,
    usable_eur: 0,
    can_use: false,
    missing_points: 100,
    effective_floor: 1000,
};

function monter(props = {}) {
    return mount(PosLoyaltyIdentifyModal, {
        props: { open: true, orderId: null, cartHasItems: false, ...props },
        global: { stubs: { transition: false } },
    });
}

describe('PosLoyaltyIdentifyModal — rattachement au panier (avant la vente)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('active « Cumuler sur cette vente » quand le panier a des articles, SANS commande', async () => {
        const w = monter({ orderId: null, cartHasItems: true });
        await w.setData({ client: { ...CLIENT } });

        const bouton = w.find('[data-testid="loy-id-attach"]');
        expect(bouton.exists()).toBe(true);
        // Le défaut d'origine : ce bouton était pâle exactement à ce moment-là.
        expect(bouton.attributes('disabled')).toBeUndefined();
    });

    it('reste inactif et explique pourquoi quand il n’y a NI commande NI panier', async () => {
        const w = monter({ orderId: null, cartHasItems: false });
        await w.setData({ client: { ...CLIENT } });

        expect(w.find('[data-testid="loy-id-attach"]').attributes('disabled')).toBeDefined();
        // Un bouton pâle doit dire pourquoi — sinon le caissier appuie trois fois.
        expect(w.find('[data-testid="loy-id-no-order"]').exists()).toBe(true);
    });

    it('sans commande : émet attach-to-cart et n’écrit RIEN en base', async () => {
        const w = monter({ orderId: null, cartHasItems: true });
        await w.setData({ client: { ...CLIENT } });

        await w.find('[data-testid="loy-id-attach"]').trigger('click');

        // Aucune vente n'existe encore : toucher le serveur ici en créerait une, ou
        // échouerait en 404. Le rattachement du panier est purement local.
        expect(axios.post).not.toHaveBeenCalled();

        const emis = w.emitted('attach-to-cart');
        expect(emis).toBeTruthy();
        expect(emis[0][0].customer.loyalty_code).toBe('E2ECAIS1');
        // On ne prétend pas que les points sont déjà acquis : ils le seront à l'encaissement.
        expect(w.vm.succes).toMatch(/encaissement/i);
    });

    it('avec une commande : conserve le rattachement SERVEUR rétroactif (non régressé)', async () => {
        axios.post.mockResolvedValue({
            data: { status: true, data: { customer: { ...CLIENT, balance: 925 }, points_awarded: 25 } },
        });

        const w = monter({ orderId: 6597, cartHasItems: false });
        await w.setData({ client: { ...CLIENT } });

        await w.find('[data-testid="loy-id-attach"]').trigger('click');
        await new Promise((r) => setTimeout(r, 0));

        expect(axios.post).toHaveBeenCalledTimes(1);
        const [url, corps] = axios.post.mock.calls[0];
        expect(url).toBe('/admin/pos-order/6597/attach-loyalty');
        expect(corps).toEqual({ loyalty_code: 'E2ECAIS1' });
        expect(w.emitted('attached')).toBeTruthy();
        // Le chemin panier ne doit PAS se déclencher en même temps : deux rattachements
        // pour un seul geste, c'est le motif « jumeau » que ce fichier existe pour bloquer.
        expect(w.emitted('attach-to-cart')).toBeFalsy();
    });
});
