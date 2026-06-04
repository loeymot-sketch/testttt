// Round 3 verification — verify 5 heals applied 2026-05-28 20:09
// Targeted, not full E2E.
const { test, expect } = require('@playwright/test');

const SCREEN_DIR = '/tmp/foodking-round3-verify';

async function adminLogin(page) {
    await page.goto('http://127.0.0.1:8000/login');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1500);
    const inputs = await page.locator('input:not([type=checkbox]):not([type=hidden])').all();
    if (inputs.length >= 2) {
        await inputs[0].fill('admin@lecayenne.fr');
        await inputs[1].fill('123456');
    }
    await page.click('button:has-text("Connexion"), button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(2500);
}

test('HEAL-1 LIVREUR axios cash-sessions list shows data', async ({ page }) => {
    test.setTimeout(90000);
    page.setDefaultTimeout(15000);

    const requests = [];
    page.on('request', r => { if (r.url().includes('cash-sessions')) requests.push(r.url()); });

    await adminLogin(page);
    await page.goto('http://127.0.0.1:8000/admin/delivery-boy-cash-sessions');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(3000);
    await page.screenshot({ path: `${SCREEN_DIR}/livreur/heal1-cash-sessions-list.png`, fullPage: true });

    const html = await page.content();
    console.log('### HEAL-1 axios requests ###');
    requests.forEach(u => console.log('  ', u));
    console.log('### HEAL-1 "Aucune donnée" present?', html.includes('Aucune donnée'));
    console.log('### HEAL-1 livreur 10 row present?', html.includes('Livreur E2E') || html.includes('livreur10') || /opening.*50/.test(html));
});

test('HEAL-2 LIVREUR PII row shows real phone', async ({ page }) => {
    test.setTimeout(90000);
    page.setDefaultTimeout(15000);

    await adminLogin(page);
    await page.goto('http://127.0.0.1:8000/admin/delivery-boys');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(3000);
    await page.screenshot({ path: `${SCREEN_DIR}/livreur/heal2-delivery-boys.png`, fullPage: true });

    const html = await page.content();
    console.log('### HEAL-2 "Livreur E2E" present?', html.includes('Livreur E2E'));
    console.log('### HEAL-2 "+33700000010" present?', html.includes('+33700000010'));
    console.log('### HEAL-2 "PENDING_CREATE_" present?', html.includes('PENDING_CREATE_'));
    console.log('### HEAL-2 "nullPENDING" present?', html.includes('nullPENDING'));
});

test('HEAL-4 OSS alias /order-status-screen renders same as /admin/...', async ({ page }) => {
    test.setTimeout(90000);
    page.setDefaultTimeout(15000);

    // Admin route (baseline)
    await adminLogin(page);
    await page.goto('http://127.0.0.1:8000/admin/order-status-screen');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(3500);
    await page.screenshot({ path: `${SCREEN_DIR}/oss/heal4-admin-prefix.png`, fullPage: true });
    const adminHtml = await page.content();
    const adminHasOss = /order-status-screen|OrderStatusScreen|preparing|ready|En préparation|Prêt/i.test(adminHtml);
    const adminHas404 = /404|NotFound|page not found/i.test(adminHtml);

    // Alias route (no /admin prefix)
    await page.goto('http://127.0.0.1:8000/order-status-screen');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(3500);
    await page.screenshot({ path: `${SCREEN_DIR}/oss/heal4-alias-no-prefix.png`, fullPage: true });
    const aliasHtml = await page.content();
    const aliasHasOss = /order-status-screen|OrderStatusScreen|preparing|ready|En préparation|Prêt/i.test(aliasHtml);
    const aliasHas404 = /404|NotFound|page not found/i.test(aliasHtml);

    console.log('### HEAL-4 admin route adminHasOss?', adminHasOss, 'adminHas404?', adminHas404);
    console.log('### HEAL-4 alias route aliasHasOss?', aliasHasOss, 'aliasHas404?', aliasHas404);
});

test('HEAL-3 POS card option enabled', async ({ page }) => {
    test.setTimeout(120000);
    page.setDefaultTimeout(15000);

    await adminLogin(page);
    await page.goto('http://127.0.0.1:8000/admin/pos');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(4000);
    await page.screenshot({ path: `${SCREEN_DIR}/pos/heal3-pos-loaded.png`, fullPage: true });

    const html = await page.content();
    console.log('### HEAL-3 POS page loaded — title contains "Caisse"?', /Caisse|POS|caisse/i.test(html));
    console.log('### HEAL-3 PaymentTerminal/Carte string present?', /Carte|terminal|TPE|payment_terminal/i.test(html));
});
