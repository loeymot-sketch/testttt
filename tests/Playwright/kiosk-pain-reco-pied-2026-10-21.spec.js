// [OWNER 2026-10-21] Borne : étape pain/galette (vignette = le pain seul, plus grande), pied du wizard remonté,
// suppléments « Recommandé » en tête. Capture à la résolution réelle de la borne (1080×1920) ; lire les PNG.
// PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test <ce fichier> --workers=1
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURE_DIR || 'reports/test-e2e/kiosk-pain-reco-2026-10-21';

test.describe('Borne — pain seul, pied remonté, suppléments recommandés', () => {
  test.setTimeout(120_000);

  test('Cayenne : étape pain puis suppléments', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
    if (await takeaway.isVisible({ timeout: 4_000 }).catch(() => false)) {
      await takeaway.click({ force: true });
      await page.waitForTimeout(1_500);
    }
    await page.goto('/kiosk/wizard/22', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.kiosk-wizard', { timeout: 20_000 });
    await page.waitForTimeout(2_000);

    const wizard = page.locator('.kiosk-wizard').first();
    await page.screenshot({ path: `${OUT}/01-etape-pain.png` });

    // La vignette ne doit plus être le sandwich complet.
    const thumbs = await page.locator('.kiosk-pain-thumb').evaluateAll((els) => els.map((e) => e.getAttribute('src')));
    expect(thumbs.length).toBeGreaterThan(0);
    for (const src of thumbs) expect(src).toMatch(/pain-seul|galette-seule/);

    // Le pied de page est au-dessus du bord bas (réserve de 110 px).
    const nav = await page.locator('.kiosk-nav-actions').boundingBox();
    const vh = page.viewportSize().height;
    console.log('nav-actions bottom gap =', Math.round(vh - (nav.y + nav.height)), 'px');
    expect(vh - (nav.y + nav.height)).toBeGreaterThanOrEqual(110);

    // Choisir « Pain » puis avancer jusqu'aux suppléments.
    await page.locator('.kiosk-option-card').first().click();
    for (let i = 0; i < 8; i++) {
      if (await page.locator('[data-testid="kiosk-supplement-recommended"]').first().isVisible().catch(() => false)) break;
      const next = page.locator('.kiosk-btn-next').first();
      // Étape à choix obligatoire (viande…) : on prend les premiers « Choisir » jusqu'à ce que « Suivant » s'ouvre.
      for (let k = 0; k < 3 && (await next.isDisabled()); k++) {
        await page.locator('.kiosk-viande-card, .kiosk-sauce-card, .kiosk-option-card').nth(k).click({ timeout: 2_000 }).catch(() => {});
        await page.waitForTimeout(400);
      }
      if (await next.isDisabled()) break;
      await next.click({ timeout: 3_000 });
      await page.waitForTimeout(900);
    }
    await page.screenshot({ path: `${OUT}/02-supplements.png` });
    const badges = await page.locator('[data-testid="kiosk-supplement-recommended"]').count();
    console.log('badges recommandés =', badges, '| erreurs JS =', errors.length);
    expect(errors.filter((m) => /TypeError|ReferenceError/.test(m))).toHaveLength(0);
    expect(badges).toBe(2);
    await expect(wizard).toBeVisible();
  });
});
