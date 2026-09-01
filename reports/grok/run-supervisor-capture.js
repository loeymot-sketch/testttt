const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const OUT = path.join(__dirname, 'captures', 'supervisor-2026-08-28');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
const EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const PASS = process.env.E2E_ADMIN_PASS || '123456';

async function shot(page, name) {
    await page.screenshot({ path: path.join(OUT, name), fullPage: true });
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    page.on('pageerror', (err) => console.log('PAGEERROR', err.message));
    page.on('console', (msg) => {
        if (msg.type() === 'error') console.log('CONSOLE', msg.text());
    });

    await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
    await page.locator('#formEmail').fill(EMAIL);
    await page.locator('#formPassword').fill(PASS);
    await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
    await page.waitForURL((url) => !/\/login(?:$|\?)/.test(url.pathname), { timeout: 25_000 });
    await shot(page, '01-dashboard.png');

    await page.goto(BASE + '/admin/items/1/composer', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await shot(page, '02-item-composer.png');

    await page.goto(BASE + '/admin/categories/1/composer', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await shot(page, '03-category-composer.png');

    await page.goto(BASE + '/admin/setting/item-category', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await shot(page, '04-categories.png');

    await page.goto(BASE + '/admin/setting/role', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await shot(page, '05-roles.png');

    await page.goto(BASE + '/admin/setting/page', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await shot(page, '06-pages.png');

    const itemText = await page.locator('body').innerText().catch(() => '');
    console.log('DONE captures in', OUT);
    await browser.close();
})().catch((err) => {
    console.error(err);
    process.exit(1);
});
