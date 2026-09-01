const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const OUT = path.join(__dirname, 'captures', 'supervisor-2026-08-28');
const EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const PASS = process.env.E2E_ADMIN_PASS || '123456';

test.use({ viewport: { width: 1440, height: 900 } });

async function login(page) {
    await page.goto('/login');
    await expect(page.locator('#formEmail')).toBeVisible({ timeout: 20_000 });
    await page.locator('#formEmail').fill(EMAIL);
    await page.locator('#formPassword').fill(PASS);
    await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
    await page.waitForURL((url) => !/\/login(?:$|\?)/.test(url.pathname), { timeout: 25_000 });
}

test('supervisor read-only composer and settings surfaces', async ({ page }) => {
    fs.mkdirSync(OUT, { recursive: true });
    page.on('pageerror', (err) => console.log('PAGEERROR', err.message));
    page.on('console', (msg) => {
        if (msg.type() === 'error') console.log('CONSOLE', msg.text());
    });

    await login(page);
    await page.screenshot({ path: path.join(OUT, '01-dashboard.png'), fullPage: true });

    await page.goto('/admin/items/1/composer');
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(OUT, '02-item-composer.png'), fullPage: true });
    const itemRoot = page.locator('[data-testid="admin-composer-root"]');
    await expect(itemRoot).toBeVisible({ timeout: 15_000 });

    await page.goto('/admin/categories/1/composer');
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(OUT, '03-category-composer.png'), fullPage: true });
    await expect(page.locator('[data-testid="admin-composer-root"]')).toBeVisible({ timeout: 15_000 });

    await page.goto('/admin/setting/item-category');
    await page.waitForTimeout(1200);
    await page.screenshot({ path: path.join(OUT, '04-categories.png'), fullPage: true });

    await page.goto('/admin/setting/role');
    await page.waitForTimeout(1200);
    await page.screenshot({ path: path.join(OUT, '05-roles.png'), fullPage: true });

    await page.goto('/admin/setting/page');
    await page.waitForTimeout(1200);
    await page.screenshot({ path: path.join(OUT, '06-pages.png'), fullPage: true });
});
