import { describe, it, expect, vi } from 'vitest';

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({ default: { name: 'LoadingComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({ default: { name: 'ItemComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({ default: { name: 'PaymentComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({ default: { name: 'CreateCustomerAddressComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({ default: { name: 'CustomerAddressCreateComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({ default: { name: 'ConnectionStatusBanner', template: '<div />' } }));

import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

// [VG-2026-10-21 M3-005] Chaque carte de la file « À encaisser » affichait « Invalid Date » : le serveur envoie
// `order_datetime` PRÉFORMATÉ (AppLibrary::datetime = TIME_FORMAT + ', ' + DATE_FORMAT, ici « 03:39 PM, 21-10-2026 »,
// relevé par curl sur GET /api/admin/pos/counter-collect/pending) et la caisse le passait à `new Date`.
const heure = (v) => PosComponent.methods.formatKioskTime.call({}, v);

describe('file « À encaisser » — heure de commande (M3-005)', () => {
    it('lit la forme réellement envoyée par le serveur : « 03:39 PM, 21-10-2026 » → 15:39', () => {
        expect(heure('03:39 PM, 21-10-2026')).toBe('15:39');
        expect(heure('12:03 AM, 22-10-2026')).toBe('00:03');
        expect(heure('12:15 PM, 22-10-2026')).toBe('12:15');
    });

    it('lit aussi un format 24 h (TIME_FORMAT « H:i ») et l\'ISO de created_at', () => {
        expect(heure('17:10, 22-10-2026')).toBe('17:10');
        expect(heure('2026-10-22T00:00:14+02:00')).toMatch(/^\d{2}:\d{2}$/);
    });

    it('ne montre JAMAIS « Invalid Date » : repli sur la valeur brute lisible', () => {
        expect(heure('bientôt')).toBe('bientôt');
        expect(heure('')).toBe('');
        expect(heure(null)).toBe('');
        for (const v of ['03:39 PM, 21-10-2026', '17:10, 22-10-2026', 'n\'importe quoi', '2026-13-45T99:99']) {
            expect(heure(v)).not.toMatch(/Invalid/i);
        }
    });
});
