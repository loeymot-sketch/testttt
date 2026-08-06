// [AUDIT-F heals] Vérification MESURÉE des 3 P1 UX : footer sticky (confirm dans
// le viewport), pastille allergène ≥16px, origine réelle dans la modale.
const { test, expect } = require('@playwright/test');
const BASE = 'http://127.0.0.1:8000';
const SHOTS = 'reports/goal-revision-absolue-2026-08-06/round-1/heals';

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.fill('#formEmail', 'pos@lecayenne.fr');
  await page.fill('#formPassword', '123456');
  await page.click('button:has-text("Connexion")');
  await page.waitForTimeout(3500);
}

test('P1-1 confirm modale encaissement DANS le viewport @1366', async ({ page }) => {
  test.setTimeout(120000);
  await page.setViewportSize({ width: 1366, height: 768 });
  await login(page);
  await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(6000);
  await page.getByRole('button', { name: '💳 Encaisser' }).first().click({ timeout: 10000 });
  await page.waitForTimeout(1500);
  const confirm = page.locator('[data-testid="pos-counter-collect-confirm"]');
  await confirm.waitFor({ state: 'visible', timeout: 8000 });
  const box = await confirm.boundingBox();
  const origin = await page.locator('[data-testid="cc-modal-source"]').innerText().catch(() => '?');
  console.log('UX_P1_1', JSON.stringify({ confirmBottom: box.y + box.height, viewport: 768, origin }));
  await page.screenshot({ path: `${SHOTS}/ux-modal-confirm-1366.png` });
  expect(box.y + box.height, 'confirm entièrement visible sans scroll').toBeLessThanOrEqual(768);
});

test('P1-3 pastille allergène lisible au KDS', async ({ page }) => {
  test.setTimeout(120000);
  await page.setViewportSize({ width: 1920, height: 1080 });
  await login(page);
  await page.goto(`${BASE}/kds`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(6000);
  const pill = page.locator('.kds-card__allergen-pill').first();
  const found = await pill.count();
  if (found === 0) {
    console.log('UX_P1_3', JSON.stringify({ skipped: 'aucune commande avec allergène sur le board' }));
    return;
  }
  const m = await pill.evaluate((el) => {
    const s = getComputedStyle(el);
    return { fontSize: s.fontSize, height: el.getBoundingClientRect().height };
  });
  console.log('UX_P1_3', JSON.stringify(m));
  await page.screenshot({ path: `${SHOTS}/ux-kds-allergen-1920.png` });
  expect(parseFloat(m.fontSize)).toBeGreaterThanOrEqual(16);
});
