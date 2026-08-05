// [GOAL-8AXES V5 2026-08-05] Capture visuelle KDS 6 cartes + flux horizontal.
// Preuves : 6 cartes lisibles par écran, barre de défilement visible, boutons ◀ ▶,
// pastille +N — aux deux résolutions (1920×1080 + 1366×768, G-4 défaut documenté).
const { test, expect } = require('@playwright/test');

const BASE = 'http://127.0.0.1:8000';
const SHOTS = 'reports/goal-8axes-2026-08-05/wave5';

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.fill('#formEmail', 'pos@lecayenne.fr');
  await page.fill('#formPassword', '123456');
  await page.click('button:has-text("Connexion")');
  await page.waitForTimeout(3500);
}

for (const vp of [{ w: 1920, h: 1080 }, { w: 1366, h: 768 }]) {
  test(`KDS 6 cartes @ ${vp.w}x${vp.h}`, async ({ page }) => {
    await page.setViewportSize({ width: vp.w, height: vp.h });
    await login(page);
    await page.goto(`${BASE}/kds`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000); // 1er poll board

    const cards = page.locator('.kds-v2__grid > *');
    const count = await cards.count();
    console.log(`[${vp.w}] cartes rendues: ${count}`);

    await page.screenshot({ path: `${SHOTS}/kds-6cards-${vp.w}.png`, fullPage: false });

    if (count > 6) {
      // Barre + boutons de scroll présents dès qu'il y a débordement.
      await expect(page.locator('[data-testid="kds-scroll-right"]')).toBeVisible();
      await page.locator('[data-testid="kds-scroll-right"]').click();
      await page.waitForTimeout(800);
      await page.screenshot({ path: `${SHOTS}/kds-6cards-${vp.w}-scrolled.png`, fullPage: false });
    }
  });
}
