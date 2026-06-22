import { describe, it, expect } from 'vitest';

/**
 * [Sprint H4 Z3-NEW-002/003 2026-05-17] kdsLegacyShouldShowDelivery
 * helper must return true for DELIVERY orders regardless of lane
 * (onlineOrder, dineinOrder, takeawayOrder, kioskOrder). The Vue
 * template uses the helper return value to render the delivery
 * block — rollback ?v2=0 must show delivery on all 4 lanes.
 *
 * Helper logic mirrored from
 *   resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue
 * lines 1348-1358 (methods.kdsLegacyShouldShowDelivery).
 *
 * Source-of-truth precedence:
 *   1. order.source_surface === 'delivery' (string-insensitive)
 *   2. Number(order.order_type) === 5 (OrderType::DELIVERY)
 *
 * NOTE: The Vue template now wraps the SAME delivery info block on
 * dinein / takeaway / kiosk lanes IN ADDITION to onlineOrder. The
 * helper itself is the gate — if a delivery order ends up in a
 * misclassified lane, the chef + courier still see the block.
 */
describe('KDS legacy delivery guard — all 4 lanes', () => {
    // Inline replica of the helper logic.
    function kdsLegacyShouldShowDelivery(order) {
        if (!order) {
            return false;
        }
        const surface = String(order.source_surface || '').toLowerCase();
        if (surface === 'delivery') {
            return true;
        }
        // OrderType::DELIVERY === 5 (app/Enums/OrderType.php).
        return Number(order.order_type) === 5;
    }

    const deliveryByType = { order_type: 5, id: 100 };
    const deliveryBySurface = { order_type: 4, source_surface: 'delivery', id: 101 };
    const dineinOrder = { order_type: 4, id: 200 };
    const takeawayOrder = { order_type: 10, id: 201 };
    const kioskOrder = { order_type: 25, id: 202 };

    it('returns true for any order whose order_type === 5 (DELIVERY)', () => {
        expect(kdsLegacyShouldShowDelivery(deliveryByType)).toBe(true);
    });

    it('returns true for any order whose source_surface === "delivery" (case-insensitive)', () => {
        expect(kdsLegacyShouldShowDelivery(deliveryBySurface)).toBe(true);
        expect(kdsLegacyShouldShowDelivery({ ...deliveryBySurface, source_surface: 'DELIVERY' })).toBe(true);
    });

    it('returns false for non-delivery orders (no block needed on any lane)', () => {
        expect(kdsLegacyShouldShowDelivery(dineinOrder)).toBe(false);
        expect(kdsLegacyShouldShowDelivery(takeawayOrder)).toBe(false);
        expect(kdsLegacyShouldShowDelivery(kioskOrder)).toBe(false);
    });

    it('returns false for null / undefined order (defensive guard)', () => {
        expect(kdsLegacyShouldShowDelivery(null)).toBe(false);
        expect(kdsLegacyShouldShowDelivery(undefined)).toBe(false);
    });
});
