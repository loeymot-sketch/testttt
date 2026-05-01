const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

async function expectNoStaffEscape(page) {
    const controls = await page.locator('a, button, [role="button"]').evaluateAll((elements) => {
        return elements.map((element) => ({
            text: (element.textContent || '').trim(),
            href: element.getAttribute('href') || '',
            testid: element.getAttribute('data-testid') || '',
        }));
    }).catch(() => []);

    const joined = controls
        .map((control) => `${control.text} ${control.href} ${control.testid}`)
        .join('\n');

    expect(joined).not.toMatch(/\/admin\b|\/admin\/|\/pos\b|\/pos\/|kiosk\.admin/i);
    expect(joined).not.toMatch(/acces administrateur|accès administrateur|aller a la caisse|aller à la caisse|go to pos/i);
}

test.describe('kiosk lockdown release', () => {
    test('/kiosk/admin is blocked to a customer-safe kiosk screen', async ({ page }) => {
        await page.goto('/kiosk/admin');
        await expect(page).toHaveURL(/\/kiosk\/(?:idle|login)(?:$|[?#])/, { timeout: 15_000 });
        await expectNoStaffEscape(page);
    });

    test('admin bundles are not publicly served', async ({ request }) => {
        await expect((await request.get('/js/kiosk-admin.js')).status()).toBe(404);
        await expect((await request.get('/js/kiosk-admin.js.LICENSE.txt')).status()).toBe(404);
        await expect((await request.get('/js/kiosk.js')).status()).toBe(404);
        await expect((await request.get('/js/kiosk.js.LICENSE.txt')).status()).toBe(404);
    });

    test('customer kiosk screens expose no staff escape controls', async ({ page }) => {
        for (const route of ['/kiosk/idle', '/kiosk/cart', '/kiosk/payment', '/kiosk/cash-instruction']) {
            await page.goto(route);
            await page.waitForLoadState('domcontentloaded');
            await expectNoStaffEscape(page);
        }
    });

    test('payment back button is active before submit and bound to submitting during submit', async ({ page }) => {
        await page.goto('/kiosk/payment');
        const back = page.getByTestId('kiosk-payment-back');
        if (await back.count()) {
            await expect(back).toBeEnabled();
        }

        const componentPath = path.resolve(__dirname, '../../resources/js/components/frontend/kiosk/KioskPaymentComponent.vue');
        const source = fs.readFileSync(componentPath, 'utf8');
        expect(source).toContain('data-testid="kiosk-payment-back"');
        expect(source).toContain(':disabled="submitting"');
    });
});
