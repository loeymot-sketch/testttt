// [CAISSE-WEB-INTEL 2026-08-06] Capture visuelle du tracker après la vague
// intelligence commandes web (badges CB/🕐/🛵, pill 🌐, bandeau ⚠️, select prépa).
const { test } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

// URL relative — les cookies de session vivent sur le baseURL (localhost:8000) ;
// un host en dur 127.0.0.1 casserait la session (domaine ≠).
const TRACKER_URL = '/admin/pos-orders-tracker';
const OUT = 'tests/captures/webintel-2026-08-06';

test('capture tracker web-intel', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(TRACKER_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);
    await page.screenshot({ path: `${OUT}/tracker-board.png`, fullPage: true });
});
