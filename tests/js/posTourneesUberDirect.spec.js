import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// [UBER-DIRECT 2026-10-22] Planche « Tournées » : chaque arrêt dit QUI livre (nos livreurs / Uber), et la caisse bascule
// d'un appui — en voyant le prix d'Uber AVANT de confirmer. Forme du serveur : PosDeliveryController::board + uberView.
const stop = (over) => ({
    id: 1, order_id: 8001, order_number: '8001', customer: 'Alice', address: '1 rue A', collect_method: 'paid_online', amount_due: 0,
    left_at: null, arrived_at: null, delivered_at: null, has_photo: false, dispatch: 'own', uber: null, uber_blocked: null, ...over,
});
let board;
const mk = (stops, uberEnabled = true) => ({ status: true, enabled: true, uber_enabled: uberEnabled, couriers: [], data: [{ wave_at: '2026-10-22 20:30:00', time: '20:30', left: false, courier: 'Kiki', courier_id: 3, stops }] });

const get = vi.fn(() => Promise.resolve({ data: board }));
const post = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a) => get(...a), post: (...a) => post(...a) } }));

import PosDeliveryBoard from '../../resources/js/components/admin/pos/PosDeliveryBoard.vue';

const open = async () => {
    const w = mount(PosDeliveryBoard);
    await flushPromises();
    return w;
};

describe('caisse — tournées : bascule Uber / nos livreurs', () => {
    beforeEach(() => {
        get.mockClear();
        post.mockReset();
        board = mk([stop({})]);
    });

    it('un arrêt à nous propose « Confier à Uber » ; le prix est montré AVANT toute course', async () => {
        post.mockResolvedValueOnce({ data: { ok: true, quote_id: 'dqt_1', fee: 7.42, eta_minutes: 25 } });
        const w = await open();

        expect(w.find('[data-testid="pos-delivery-use-uber"]').attributes('disabled')).toBeUndefined();
        await w.find('[data-testid="pos-delivery-use-uber"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith('/admin/pos-delivery/uber/quote', { stop_id: 1 });
        expect(w.find('[data-testid="pos-delivery-uber-price"]').text()).toContain('7,42 €');
        expect(w.find('[data-testid="pos-delivery-uber-price"]').text()).toContain('25 min');
        w.unmount();
    });

    it('« Confirmer Uber » envoie le devis vu, puis recharge la planche', async () => {
        post.mockResolvedValueOnce({ data: { ok: true, quote_id: 'dqt_1', fee: 7.42, eta_minutes: 25 } }).mockResolvedValueOnce({ data: { ok: true } });
        const w = await open();
        await w.find('[data-testid="pos-delivery-use-uber"]').trigger('click');
        await flushPromises();
        get.mockClear();

        await w.find('[data-testid="pos-delivery-uber-confirm"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenLastCalledWith('/admin/pos-delivery/uber/dispatch', { stop_id: 1, quote_id: 'dqt_1' });
        expect(get).toHaveBeenCalled();
        expect(w.find('[data-testid="pos-delivery-uber-price"]').exists()).toBe(false);
        w.unmount();
    });

    it('prix changé entre-temps : on ne dépêche rien, le nouveau prix est à reconfirmer', async () => {
        post.mockResolvedValueOnce({ data: { ok: true, quote_id: 'dqt_1', fee: 7.42, eta_minutes: 25 } })
            .mockRejectedValueOnce({ response: { data: { code: 'quote_changed', message: 'Le devis a expiré.', quote: { quote_id: 'dqt_2', fee: 9.1, eta_minutes: 30 } } } });
        const w = await open();
        await w.find('[data-testid="pos-delivery-use-uber"]').trigger('click');
        await flushPromises();

        await w.find('[data-testid="pos-delivery-uber-confirm"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-testid="pos-delivery-uber-price"]').text()).toContain('9,10 €');
        expect(w.text()).toContain('Le prix d\'Uber a changé');
        expect(post).toHaveBeenCalledTimes(2);
        w.unmount();
    });

    it('un arrêt à encaisser à la porte : bouton grisé ET la vraie raison est affichée', async () => {
        const raison = 'À encaisser à la porte : Uber ne peut pas encaisser. Faites payer la commande avant de la confier à Uber.';
        board = mk([stop({ collect_method: 'cash', amount_due: 12, uber_blocked: raison })]);
        const w = await open();

        expect(w.find('[data-testid="pos-delivery-use-uber"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-testid="pos-delivery-uber-reason"]').text()).toBe(raison);
        w.unmount();
    });

    it('[round 2 · U1-005] course Uber revenue après le sac (left_at posé par le coursier Uber) : la raison affichée est celle du serveur, pas « Le livreur est déjà parti »', async () => {
        const raison = 'Le coursier Uber avait le sac et la course a été annulée ou ramenée : la commande n\'est pas livrée. À régler avec Uber et le client, puis clôturer ou annuler la commande.';
        board = mk([stop({ left_at: '2026-10-24 16:20:00', dispatch: 'own', uber_attention: 'Uber a ramené la commande au restaurant alors que le coursier avait le sac : la commande n\'est pas livrée. À traiter avec le client.', uber_blocked: raison })]);
        const w = await open();

        expect(w.find('[data-testid="pos-delivery-use-uber"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-testid="pos-delivery-uber-reason"]').text()).toBe(raison);
        expect(w.find('[data-testid="pos-delivery-uber-reason"]').text()).not.toContain('déjà parti');
        expect(w.find('[data-testid="pos-delivery-use-uber"]').attributes('title')).toBe(raison);
        w.unmount();
    });

    it('notre livreur est parti (sans raison serveur) : « Le livreur est déjà parti » reste dit', async () => {
        board = mk([stop({ left_at: '2026-10-24 16:20:00', uber_blocked: null })]);
        const w = await open();

        expect(w.find('[data-testid="pos-delivery-use-uber"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-testid="pos-delivery-uber-reason"]').text()).toBe('Le livreur est déjà parti.');
        w.unmount();
    });

    it('Uber non activé : bouton grisé, raison dite, aucun appel', async () => {
        board = mk([stop({})], false);
        const w = await open();

        expect(w.find('[data-testid="pos-delivery-use-uber"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-testid="pos-delivery-uber-reason"]').text()).toContain('pas activé');
        expect(post).not.toHaveBeenCalled();
        w.unmount();
    });

    it('un arrêt confié à Uber montre le coursier, le suivi et « Nos livreurs » pour le reprendre', async () => {
        board = mk([stop({ dispatch: 'uber', uber: { status: 'pickup', tracking_url: 'https://uber.test/t/1', fee: 7.42, courier_name: 'Marc', bag_taken: false } })]);
        post.mockResolvedValueOnce({ data: { ok: true } });
        const w = await open();

        expect(w.find('[data-testid="pos-delivery-uber-badge"]').text()).toContain('Coursier en route vers le restaurant');
        expect(w.text()).toContain('Marc');
        expect(w.find('a[href="https://uber.test/t/1"]').exists()).toBe(true);
        expect(w.find('[data-testid="pos-delivery-use-uber"]').exists()).toBe(false);

        await w.find('[data-testid="pos-delivery-use-own"]').trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/admin/pos-delivery/uber/release', { stop_id: 1 });
        w.unmount();
    });

    it('le coursier Uber a le sac : on ne peut plus reprendre la commande', async () => {
        board = mk([stop({ dispatch: 'uber', uber: { status: 'dropoff', tracking_url: null, fee: 7.42, courier_name: null, bag_taken: true, eta: '16:47', kitchen_pending: true } })]);
        const w = await open();

        expect(w.find('[data-testid="pos-delivery-use-own"]').attributes('disabled')).toBeDefined();
        // [E2E UBER 2026-10-24 · U1-007/U1-014/U1-004] La raison est VISIBLE (pas seulement en `title`), l'arrivée estimée d'Uber
        // est dite, et la cuisine est appelée quand Uber a le sac d'une commande qu'elle n'a pas marquée prête.
        expect(w.find('[data-testid="pos-delivery-uber-bag-taken"]').text()).toContain('a déjà la commande');
        expect(w.find('[data-testid="pos-delivery-uber-badge"]').text()).toContain('arrivée ~16:47');
        expect(w.find('[data-testid="pos-delivery-uber-kitchen-pending"]').text()).toContain('marquez la commande prête');
        w.unmount();
    });

    it('un échec du serveur s\'affiche en clair, la commande reste à nous', async () => {
        post.mockRejectedValueOnce({ response: { data: { message: 'Uber n\'a pas donné de prix.' } } });
        const w = await open();

        await w.find('[data-testid="pos-delivery-use-uber"]').trigger('click');
        await flushPromises();

        expect(w.find('[role="alert"]').text()).toContain('Uber n\'a pas donné de prix.');
        expect(w.find('[data-testid="pos-delivery-uber-price"]').exists()).toBe(false);
        w.unmount();
    });
});

// [E2E 2026-10-22 · B-001/B-002] Une course qui a mal fini est DITE à la caisse, en alerte, jamais silencieuse.
describe('caisse — tournées : course Uber en échec', () => {
    it('affiche l\'alerte du serveur sur l\'arrêt concerné', async () => {
        board = mk([stop({ uber_attention: 'Uber a ramené la commande au restaurant alors que le coursier avait le sac : la commande n\'est pas livrée. À traiter avec le client.' })]);
        const w = await open();

        const alerte = w.find('[data-testid="pos-delivery-uber-attention"]');
        expect(alerte.exists()).toBe(true);
        expect(alerte.attributes('role')).toBe('alert');
        expect(alerte.text()).toContain('la commande n\'est pas livrée');
        w.unmount();
    });

    it('aucune alerte quand tout va bien', async () => {
        board = mk([stop({})]);
        const w = await open();

        expect(w.find('[data-testid="pos-delivery-uber-attention"]').exists()).toBe(false);
        w.unmount();
    });
});
