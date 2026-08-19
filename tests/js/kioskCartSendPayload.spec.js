import { describe, it, expect } from 'vitest';
import { buildKioskOrderPayload } from '../../resources/js/store/modules/kioskCart.js';

describe('kioskCart submit payload', () => {
    it('builds an outgoing payload without client money fields', () => {
        const payload = buildKioskOrderPayload({
            orderType: 10,
            promoCode: 'PROMO10',
            loyaltyCustomer: { loyalty_code: 'LOYAL-42' },
            items: [
                {
                    item_id: 12,
                    quantity: 2,
                    convert_price: 9.9,
                    item_variation_total: 1.5,
                    item_extra_total: 2,
                    total: 26.8,
                    discount: 5,
                    branch_id: 3,
                    instruction: 'sans oignons',
                    item_variations: [{ id: 100, variation_name: 'taille', name: 'XL', price: 1.5, quantity: 2 }],
                    item_extras: [{ id: 200, name: 'cheddar', price: 2 }],
                    item_addons: [{ id: 300, name: 'Coca', role: 'drink', price: 2.5, quantity: 2 }],
                },
            ],
        }, {
            orderType: 10,
            paymentMethod: 'card',
        });

        expect(payload).toEqual({
            order_type: 10,
            loyalty_code: 'LOYAL-42',
            // [FIDÉLITÉ BORNE 2026-08-19] Ce que le client dépense, en POINTS — une QUANTITÉ, pas
            // de l'argent. C'est précisément pourquoi ce champ ne contredit pas l'invariant que
            // ce test protège : `discount` reste interdit dans la liste ci-dessous, et le serveur
            // convertit lui-même les points au taux de la maison.
            loyalty_redeem_points: 0,
            kiosk_promo_code: 'PROMO10',
            is_advance_order: 10,
            source: 5,
            payment_method: 4,
            items: expect.any(String),
        });

        const forbiddenRootKeys = ['branch_id', 'subtotal', 'discount', 'delivery_charge', 'total', 'tax', 'tva'];
        forbiddenRootKeys.forEach((key) => {
            expect(payload).not.toHaveProperty(key);
        });

        const orderItems = JSON.parse(payload.items);
        expect(orderItems).toEqual([
            {
                item_id: 12,
                instruction: 'sans oignons',
                quantity: 2,
                item_variations: [{ id: 100, variation_name: 'taille', name: 'XL', quantity: 2 }],
                item_extras: [{ id: 200, name: 'cheddar' }],
                item_addons: [{ id: 300, name: 'Coca', role: 'drink', quantity: 2 }],
            },
        ]);

        const forbiddenLineKeys = [
            'branch_id',
            'item_price',
            'price',
            'discount',
            'total_price',
            'item_variation_total',
            'item_extra_total',
            'subtotal',
            'total',
            'tax',
            'tva',
        ];

        orderItems.forEach((item) => {
            forbiddenLineKeys.forEach((key) => {
                expect(item).not.toHaveProperty(key);
            });
        });
    });
});
