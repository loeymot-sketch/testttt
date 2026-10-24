import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const checkout = readFileSync(
    resolve(process.cwd(), 'resources/js/components/frontend/checkout/CheckoutComponent.vue'),
    'utf8',
);
const french = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf8'));

describe('web checkout fulfillment copy', () => {
    it('uses an explicit takeaway confirmation and does not falsely expose disabled delivery', () => {
        expect(checkout).toContain("$t('message.order_takeaway')");
        expect(checkout).toContain("$t('message.confirm_takeaway')");
        expect(checkout).toContain('checkout-delivery-coming-soon');
        expect(checkout).toContain("setting.order_setup_delivery !== activityEnum.ENABLE");
    });

    it('states the owner-approved French information clearly', () => {
        expect(french.message.order_takeaway).toBe('Commander à emporter');
        expect(french.message.confirm_takeaway).toBe('Confirmer à emporter');
        expect(french.message.delivery_coming_soon).toBe('Livraison par nos livreurs bientôt.');
    });
});
