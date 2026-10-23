import { describe, it, expect, vi } from 'vitest';

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({ default: { name: 'LoadingComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({ default: { name: 'ItemComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({ default: { name: 'PaymentComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({ default: { name: 'CreateCustomerAddressComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({ default: { name: 'CustomerAddressCreateComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({ default: { name: 'ConnectionStatusBanner', template: '<div />' } }));

import axios from 'axios';
import * as livraison from '../../resources/js/helpers/posLivraison.js';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

// [VG-2026-10-21 M4-002] Une livraison prise en caisse « tout de suite » part à la PREMIÈRE tournée non pleine
// (DeliveryWaveService::pickAsapWave), parfois des heures plus tard (17:10 → 23:00, ou le lendemain) ; la caisse
// n'affichait que « Hénin-Beaumont · 3,90 € · 0 km · ~4 min ». Le devis serveur (POST /admin/pos-delivery/quote,
// forme relevée par curl sur :8766) renvoie `waves` : [{ wave_at: 'Y-m-d H:i:s', time, label, stops, full }]
// avec les mêmes règles (fenêtre de service, tournée partie, plafond d'arrêts) que l'affectation « au plus tôt ».
const MAINTENANT = new Date(2026, 9, 22, 17, 10); // jeudi 22/10/2026 17:10
const vague = (wave_at, full = false) => ({ wave_at, time: wave_at.slice(11, 16), label: wave_at.slice(11, 16), stops: 0, full });
const devis = (waves) => ({ status: true, enabled: true, allowed: true, fee: 3.9, address: { city: 'Hénin-Beaumont' }, distance_km: 3.47, travel_minutes: 13, waves });

describe('caisse — heure de départ d\'une livraison « tout de suite » (M4-002)', () => {
    it('annonce la première tournée NON pleine, pas la première de la liste', () => {
        const q = devis([vague('2026-10-22 18:00:00', true), vague('2026-10-22 18:30:00', true), vague('2026-10-22 23:00:00')]);
        expect(livraison.describeDeparture(q, MAINTENANT)).toBe('Départ prévu aujourd\'hui à 23:00');
    });

    it('un départ le lendemain se lit AVEC la date', () => {
        const q = devis([vague('2026-10-22 23:30:00', true), vague('2026-10-23 18:00:00')]);
        expect(livraison.describeDeparture(q, MAINTENANT)).toBe('Départ prévu demain (ven. 23/10) à 18:00');
    });

    it('au-delà du lendemain : jour et date', () => {
        expect(livraison.describeDeparture(devis([vague('2026-10-24 19:30:00')]), MAINTENANT)).toBe('Départ prévu sam. 24/10 à 19:30');
    });

    it('toutes les tournées listées pleines : le dit, sans inventer d\'heure', () => {
        const q = devis([vague('2026-10-22 18:00:00', true), vague('2026-10-23 18:30:00', true)]);
        expect(livraison.describeDeparture(q, MAINTENANT)).toBe('Tournées complètes jusqu\'à demain (ven. 23/10) 18:30 : départ plus tard');
    });

    it('rien quand le devis ne porte pas de tournées ou refuse l\'adresse', () => {
        expect(livraison.describeDeparture(null, MAINTENANT)).toBe('');
        expect(livraison.describeDeparture({ ...devis([]), allowed: false }, MAINTENANT)).toBe('');
        expect(livraison.describeDeparture(devis(undefined), MAINTENANT)).toBe('');
    });

    it('l\'écran caisse expose l\'heure de départ du devis courant', () => {
        const ctx = { deliveryInline: { quote: devis([vague('2026-10-22 18:00:00', true), vague('2026-10-22 23:00:00')]) } };
        expect(PosComponent.computed.deliveryDepartureText.call(ctx)).toMatch(/^Départ prévu .*23:00$/);
    });

    it('adresse déjà enregistrée : la caisse redemande les tournées au serveur pour afficher le départ', async () => {
        axios.post.mockResolvedValueOnce({ data: devis([vague('2026-10-23 18:00:00')]) });
        const ctx = {
            deliveryInline: { quote: null, address: '', addressText: '', suggestions: [], confirmed: false, customerId: 5 },
            checkoutProps: { form: { customer_id: 5, address_id: null, delivery_charge: 0, delivery_distance_km: null } },
            clearDeliveryGeocodeError: vi.fn(),
            // [E2E 2026-10-23 C2-001] L'option du client est mémorisée avant d'être choisie (sélecteur client).
            deliveryCustomerOptions: [],
            rememberCustomerOption: PosComponent.methods.rememberCustomerOption,
        };
        ctx._rafraichirTourneesAdresse = PosComponent.methods._rafraichirTourneesAdresse.bind(ctx);
        PosComponent.methods.pickSavedDeliveryAddress.call(ctx, {
            id: 3, address: '10 Rue de la Gare 62110 Hénin-Beaumont', allowed: true, fee: 3.9, tier: 'home', distance_km: 3.47, city: 'Hénin-Beaumont', postal_code: '62110',
        });
        await new Promise((r) => setTimeout(r, 0));
        expect(axios.post).toHaveBeenCalledWith('/admin/pos-delivery/quote', { q: '10 Rue de la Gare 62110 Hénin-Beaumont' });
        expect(ctx.deliveryInline.quote.waves).toHaveLength(1);
        expect(ctx.deliveryInline.quote.fee).toBe(3.9); // le prix de l'adresse enregistrée n'est pas remplacé
    });
});
