import { describe, expect, it, vi } from 'vitest';
import {
    suggestAddresses, quoteAddress, lookupCustomer, ensureCustomer, saveAddress,
    fetchDeliveryStatus, setDeliveryEnabled, describeQuote,
} from '../../resources/js/helpers/posLivraison.js';

// [LIVRAISON 2026-10-20] Appels serveur de la livraison depuis la caisse (aucune règle de prix côté écran).
const http = (over = {}) => ({ get: vi.fn(), post: vi.fn(), ...over });

describe('posLivraison — appels serveur', () => {
    it('suggestAddresses mappe la Base Adresse Nationale vers la liste de suggestions existante', async () => {
        const h = http({ get: vi.fn().mockResolvedValue({ data: { data: [{ label: '10 Rue Test 62110 Hénin-Beaumont', city: 'Hénin-Beaumont', postal_code: '62110' }] } }) });
        const list = await suggestAddresses(h, '10 rue test');
        expect(h.get).toHaveBeenCalledWith('/admin/pos-delivery/suggest', { params: { q: '10 rue test' } });
        expect(list).toEqual([{ place_id: '10 Rue Test 62110 Hénin-Beaumont', description: '10 Rue Test 62110 Hénin-Beaumont', city: 'Hénin-Beaumont', postal_code: '62110' }]);
    });

    it('quoteAddress envoie le TEXTE seulement : jamais de coordonnées ni de distance', async () => {
        const h = http({ post: vi.fn().mockResolvedValue({ data: { allowed: true, fee: 3.9 } }) });
        await quoteAddress(h, '10 rue test');
        expect(h.post).toHaveBeenCalledWith('/admin/pos-delivery/quote', { q: '10 rue test' });
    });

    it('lookupCustomer renvoie null quand personne ne correspond', async () => {
        expect(await lookupCustomer(http({ get: vi.fn().mockResolvedValue({ data: { found: false } }) }), '0612345678')).toBeNull();
        expect(await lookupCustomer(http({ get: vi.fn().mockResolvedValue({ data: { found: true, data: { id: 7 } } }) }), '0612345678')).toEqual({ id: 7 });
    });

    it('ensureCustomer / saveAddress remontent le message serveur lisible', async () => {
        const boom = (message) => http({ post: vi.fn().mockRejectedValue({ response: { data: { message } } }) });
        await expect(ensureCustomer(boom('Ce numéro est incomplet.'), { phone: '06' })).rejects.toThrow('Ce numéro est incomplet.');
        await expect(saveAddress(boom('Adresse introuvable.'), { customerId: 1, address: 'x' })).rejects.toThrow('Adresse introuvable.');
    });

    it("saveAddress n'envoie ni latitude ni longitude", async () => {
        const h = http({ post: vi.fn().mockResolvedValue({ data: { data: { id: 3 } } }) });
        await saveAddress(h, { customerId: 9, address: '10 rue test', apartment: '2B' });
        expect(h.post).toHaveBeenCalledWith('/admin/pos-delivery/address', { customer_id: 9, address: '10 rue test', apartment: '2B' });
    });

    it('suspendre / remettre : lit et écrit un booléen', async () => {
        expect(await fetchDeliveryStatus(http({ get: vi.fn().mockResolvedValue({ data: { enabled: false } }) }))).toBe(false);
        const h = http({ post: vi.fn().mockResolvedValue({ data: { enabled: true } }) });
        expect(await setDeliveryEnabled(h, true)).toBe(true);
        expect(h.post).toHaveBeenCalledWith('/admin/pos-delivery/toggle', { enabled: true });
    });

    it('describeQuote : phrase lisible pour le caissier', () => {
        expect(describeQuote({ enabled: true, allowed: true, fee: 3.9, distance_km: 1.2, travel_minutes: 6, address: { city: 'Hénin-Beaumont' } })).toBe('Hénin-Beaumont · 3,90 € · 1,2 km · ~6 min');
        expect(describeQuote({ enabled: true, allowed: false, suggest_uber_eats: true })).toContain('Uber Eats');
        expect(describeQuote({ enabled: false })).toBe('La livraison est suspendue.');
        expect(describeQuote(null)).toBe('');
    });
});
