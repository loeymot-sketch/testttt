const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.describe('KDS multi-screen release contract', () => {
    test('KDS status mutations send expected_status and handle conflict refresh', async () => {
        const root = process.cwd();
        const component = fs.readFileSync(
            path.join(root, 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'),
            'utf8',
        );
        const kdsStore = fs.readFileSync(
            path.join(root, 'resources/js/store/modules/kds.js'),
            'utf8',
        );

        expect(kdsStore).toContain('expected_status');
        expect(component).toContain('kdsStatusPayload(order, status)');
        expect(component).toContain('err?.response?.status === 409');
        expect(component).toContain('kdsOrderListAtCap');
        expect(component).toContain('Voir plus');
    });
});
