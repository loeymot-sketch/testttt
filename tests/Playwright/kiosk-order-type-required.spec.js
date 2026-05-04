const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.describe('Kiosk explicit order type contract', () => {
    test('idle, catalogue and store require an explicit dine-in or takeaway choice', async () => {
        const root = process.cwd();
        const idleSource = fs.readFileSync(
            path.join(root, 'resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue'),
            'utf8',
        );
        const categoriesSource = fs.readFileSync(
            path.join(root, 'resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue'),
            'utf8',
        );
        const storeSource = fs.readFileSync(
            path.join(root, 'resources/js/store/modules/kioskCart.js'),
            'utf8',
        );

        expect(idleSource).toContain('data-testid="kiosk-order-type-chooser"');
        expect(idleSource).toContain('data-testid="kiosk-order-type-dine-in"');
        expect(idleSource).toContain('data-testid="kiosk-order-type-takeaway"');
        expect(idleSource).toContain("this.$emit('start-order', orderType)");
        expect(idleSource).toContain('KioskAppComponent.startOrder');
        expect(idleSource).not.toContain('@click="handleIdleClick"');

        expect(categoriesSource).toContain('ensureOrderTypeSelected()');
        expect(categoriesSource).toContain("this.$router.replace({ name: 'kiosk.idle' })");
        expect(categoriesSource).toContain('if (!this.ensureOrderTypeSelected()) return;');

        expect(storeSource).toContain('KIOSK_ORDER_TYPE_REQUIRED');
        expect(storeSource).toContain('orderType: null');
        expect(storeSource).toContain('hasExplicitOrderType');
        expect(storeSource).toContain('requireExplicitOrderType: true');
        expect(storeSource).toContain('assertExplicitKioskOrderType(orderType ?? state.orderType)');
    });
});
