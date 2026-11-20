import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import VueNextSelect from 'vue-next-select';

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({ default: { name: 'LoadingComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({ default: { name: 'ItemComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({ default: { name: 'PaymentComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({ default: { name: 'CreateCustomerAddressComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({ default: { name: 'CustomerAddressCreateComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({ default: { name: 'ConnectionStatusBanner', template: '<div />' } }));

import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

// [VG-2026-10-21 M4-104] « TypeError: Cannot read properties of undefined (reading 'id') » à l'ouverture du paiement
// d'une livraison (console de la capture M4/16). Pile : vue-next-select hasOption → Array.some → isSameOption →
// valueBy(undefined). Cause : la livraison pose `checkoutProps.form.customer_id` sur le client retrouvé ou CRÉÉ par
// téléphone (ensureDeliveryCustomerAndAddress / lookupDeliveryCustomer), absent de la liste `user/lists` chargée au
// démarrage ; le sélecteur client (PosComponent.vue, `<vue-select id="customer" value-by="id">`) cherche alors une
// option introuvable (undefined) et plante en la comparant.
function contexte({ liste, customerId, nom = '', saisi = '' }) {
    return {
        $store: { getters: { 'user/lists': liste } },
        checkoutProps: { form: { customer_id: customerId } },
        deliveryInline: { customerName: nom, name: saisi },
    };
}

function monterSelecteur(options, modelValue) {
    return mount(VueNextSelect, { props: { options, modelValue, valueBy: 'id', labelBy: 'name' } });
}

describe('sélecteur client de la caisse — client de livraison hors de la liste (M4-104)', () => {
    const LISTE = [{ id: 1, name: 'Client comptoir' }, { id: 2, name: 'Alice' }];

    it('le client créé par téléphone (absent de la liste) ne fait plus planter le sélecteur', () => {
        const options = PosComponent.computed.customers.call(contexte({ liste: LISTE, customerId: 8136, saisi: 'Bob Livraison' }));
        expect(() => monterSelecteur(options, 8136)).not.toThrow();
        expect(options.find((o) => o.id === 8136)).toEqual({ id: 8136, name: 'Bob Livraison' });
    });

    it('le client retrouvé par téléphone porte son vrai nom dans le sélecteur', () => {
        const options = PosComponent.computed.customers.call(contexte({ liste: LISTE, customerId: 77, nom: 'Chloé', saisi: 'chl' }));
        expect(() => monterSelecteur(options, 77)).not.toThrow();
        expect(options.find((o) => o.id === 77).name).toBe('Chloé');
    });

    it('rien d\'ajouté quand le client est déjà dans la liste, ni quand aucun client n\'est choisi', () => {
        expect(PosComponent.computed.customers.call(contexte({ liste: LISTE, customerId: 2 }))).toEqual(LISTE);
        expect(PosComponent.computed.customers.call(contexte({ liste: LISTE, customerId: null }))).toEqual(LISTE);
        // Liste encore vide : on n'invente rien (la caisse la recharge avant l'encaissement).
        expect(PosComponent.computed.customers.call(contexte({ liste: [], customerId: 9 }))).toEqual([]);
    });
});
