import { describe, it, expect } from 'vitest';
import PosOrderShowComponent from '../../resources/js/components/admin/posOrders/PosOrderShowComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

/**
 * [UIUX-W2 F3 2026-06-11] Badge + libellé statut VIDES sur le show d'une
 * commande PENDING / CANCELED / REJECTED.
 *
 * Le mapping `orderStatusEnumArray` du show ne couvrait que
 * ACCEPT/PREPARING/PREPARED/OUT_FOR_DELIVERY/DELIVERED/RETURNED alors que
 * la LISTE (fix FP-25, PosOrderListComponent) mappe l'enum complet.
 * On aligne le show sur le même mapping complet.
 */
describe('PosOrderShowComponent.orderStatusEnumArray (F3 UIUX-W2)', () => {
    const callComputed = () => PosOrderShowComponent.computed.orderStatusEnumArray.call({
        $t: (k) => k,
    });

    it('maps EVERY orderStatusEnum value (no blank badge possible)', () => {
        const map = callComputed();
        for (const [name, value] of Object.entries(orderStatusEnum)) {
            expect(map[value], `status ${name} (${value}) must have a label`).toBeTruthy();
        }
    });

    it('uses the same FP-25 label keys as the list for the previously missing statuses', () => {
        const map = callComputed();
        expect(map[orderStatusEnum.PENDING]).toBe('label.pending');
        expect(map[orderStatusEnum.CANCELED]).toBe('label.canceled');
        expect(map[orderStatusEnum.REJECTED]).toBe('label.rejected');
    });
});
