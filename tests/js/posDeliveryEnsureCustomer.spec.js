import { describe, expect, it, vi, beforeEach } from 'vitest';

// [LIVRAISON 2026-10-20] La caisse enregistre le client par TÉLÉPHONE et l'adresse UNE fois (géocodée par le
// serveur), sans faux e-mail @pos.local, sans mot de passe fixe, sans coordonnées venues de l'écran.
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { error: vi.fn(), success: vi.fn(), info: vi.fn() } }));

import axios from 'axios';
import alertService from '../../resources/js/services/alertService';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

const ensure = PosComponent.methods.ensureDeliveryCustomerAndAddress;

function ctx(over = {}) {
    return {
        deliveryCustomerId: PosComponent.methods.deliveryCustomerId,
        _walkInCustomerId: 5,
        loading: { isActive: false },
        checkoutProps: { form: { customer_id: null, address_id: null, delivery_charge: 0, delivery_distance_km: null } },
        deliveryInline: { name: 'Karim', phone: '06 12 34 56 78', address: '10 Rue Test 62110 Hénin-Beaumont', confirmed: true, apartment: '2B', quote: null },
        ...over,
    };
}

describe('caisse — ensureDeliveryCustomerAndAddress (serveur-autoritatif)', () => {
    beforeEach(() => { vi.clearAllMocks(); });

    it('crée/retrouve le client par téléphone puis enregistre l\'adresse, et prend le prix du serveur', async () => {
        axios.post
            .mockResolvedValueOnce({ data: { data: { id: 41 } } })
            .mockResolvedValueOnce({ data: { data: { id: 9, allowed: true, fee: 3.9, distance_km: 1.2 } } });
        const c = ctx();

        expect(await ensure.call(c)).toBe(true);

        expect(axios.post).toHaveBeenNthCalledWith(1, '/admin/pos-delivery/customer', { phone: '06 12 34 56 78', name: 'Karim', email: null });
        expect(axios.post).toHaveBeenNthCalledWith(2, '/admin/pos-delivery/address', { customer_id: 41, address: '10 Rue Test 62110 Hénin-Beaumont', apartment: '2B' });
        expect(c.checkoutProps.form).toMatchObject({ customer_id: 41, address_id: 9, delivery_charge: 3.9, delivery_distance_km: 1.2 });
    });

    it("n'envoie jamais de faux e-mail, de mot de passe ni de coordonnées", async () => {
        axios.post.mockResolvedValueOnce({ data: { data: { id: 1 } } }).mockResolvedValueOnce({ data: { data: { id: 2, allowed: true, fee: 4.9, distance_km: 3 } } });
        await ensure.call(ctx());
        const sent = JSON.stringify(axios.post.mock.calls);
        expect(sent).not.toMatch(/pos\.local|delivery123|password|latitude|longitude/);
    });

    it('un client déjà identifié (adresse enregistrée choisie) ne recrée rien', async () => {
        const c = ctx();
        c.checkoutProps.form.address_id = 5;
        expect(await ensure.call(c)).toBe(true);
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('adresse hors rayon : refusée, aucune adresse retenue, message Uber Eats', async () => {
        axios.post.mockResolvedValueOnce({ data: { data: { id: 41 } } }).mockResolvedValueOnce({ data: { data: { id: 9, allowed: false, suggest_uber_eats: true, fee: 0 } } });
        const c = ctx();
        expect(await ensure.call(c)).toBe(false);
        expect(c.checkoutProps.form.address_id).toBeNull();
        expect(alertService.error).toHaveBeenCalledWith(expect.stringContaining('Uber Eats'));
    });

    it('sans téléphone ni client connu : refuse sans appeler le serveur', async () => {
        const c = ctx();
        c.deliveryInline.phone = '';
        expect(await ensure.call(c)).toBe(false);
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('adresse non choisie dans la liste : refusée', async () => {
        const c = ctx();
        c.deliveryInline.confirmed = false;
        expect(await ensure.call(c)).toBe(false);
        expect(axios.post).not.toHaveBeenCalled();
    });

    it("l'erreur serveur lisible (adresse introuvable) est affichée", async () => {
        axios.post.mockResolvedValueOnce({ data: { data: { id: 41 } } }).mockRejectedValueOnce({ response: { data: { message: 'Adresse introuvable.' } } });
        expect(await ensure.call(ctx())).toBe(false);
        expect(alertService.error).toHaveBeenCalledWith('Adresse introuvable.');
    });

    // [relecture adverse P1] Le client « de passage » (compte partagé) est déjà dans form.customer_id au démarrage :
    // il ne doit JAMAIS recevoir l'adresse ni la commande d'un vrai client de livraison.
    it('le client de passage n\'est pas le client livré : on crée / retrouve le vrai client par téléphone', async () => {
        axios.post.mockResolvedValueOnce({ data: { data: { id: 41 } } }).mockResolvedValueOnce({ data: { data: { id: 9, allowed: true, fee: 3.9, distance_km: 1 } } });
        const c = ctx();
        c.checkoutProps.form.customer_id = 5; // = _walkInCustomerId

        expect(await ensure.call(c)).toBe(true);

        expect(axios.post).toHaveBeenNthCalledWith(1, '/admin/pos-delivery/customer', expect.objectContaining({ phone: '06 12 34 56 78' }));
        expect(axios.post).toHaveBeenNthCalledWith(2, '/admin/pos-delivery/address', expect.objectContaining({ customer_id: 41 }));
        expect(c.checkoutProps.form.customer_id).toBe(41);
    });

    it('un client choisi volontairement dans la liste (≠ passage) est conservé sans recréation', async () => {
        axios.post.mockResolvedValueOnce({ data: { data: { id: 9, allowed: true, fee: 4.9, distance_km: 3 } } });
        const c = ctx();
        c.checkoutProps.form.customer_id = 77;

        expect(await ensure.call(c)).toBe(true);

        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.post).toHaveBeenCalledWith('/admin/pos-delivery/address', expect.objectContaining({ customer_id: 77 }));
    });

    it('changer le numéro détache le client précédent, son adresse et son prix', () => {
        const clear = PosComponent.methods.clearDeliveryKnownCustomer;
        const c = ctx();
        c.deliveryInline.customerId = 41;
        c.deliveryInline.confirmed = true;
        c.checkoutProps.form = { customer_id: 41, address_id: 9, delivery_charge: 3.9, delivery_distance_km: 1 };

        clear.call(c);

        expect(c.checkoutProps.form).toMatchObject({ customer_id: 5, address_id: null, delivery_charge: 0, delivery_distance_km: null });
        expect(c.deliveryInline.confirmed).toBe(false);
        expect(c.deliveryInline.customerId).toBeNull();
    });
});
