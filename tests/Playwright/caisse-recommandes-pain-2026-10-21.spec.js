// [OWNER 2026-10-21] Caisse : suppléments « (recommandé) » en tête et pain/galette seuls. Capture ; lire les PNG.
// PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test <ce fichier> --workers=1
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURE_DIR || 'reports/test-e2e/caisse-recommandes-2026-10-21';

async function tuile(page, nom) {
  await page.getByText(nom, { exact: true }).first().click({ timeout: 8_000 });
  await page.waitForTimeout(2500);
}

test('Caisse — Cayenne : pain seul puis suppléments recommandés', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1600, height: 1000 });
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('response', async (r) => {
    if (/admin\/item\/details\/22/.test(r.url())) {
      const j = await r.json().catch(() => null);
      console.log('details/22 extras[0] =', JSON.stringify((j && j.data && j.data.extras || [])[0]));
    }
  });
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);
  await page.screenshot({ path: `${OUT}/00-caisse.png` });
  await tuile(page, 'Sandwichs');
  await page.screenshot({ path: `${OUT}/00b-sandwichs.png` });
  await tuile(page, 'Cayenne');
  await page.screenshot({ path: `${OUT}/01-wizard.png` });
  await page.locator('[data-action="toggle-suppl"]').first().click({ timeout: 5_000 });
  await page.waitForTimeout(500);
  await page.locator('.supplements-section').first().scrollIntoViewIfNeeded();
  await page.waitForTimeout(800);
  console.log('supplement-opt visibles =', await page.locator('.supplement-opt:visible').count(), '/ total =', await page.locator('.supplement-opt').count());
  await page.screenshot({ path: `${OUT}/02-supplements.png` });
  const noms = await page.locator('.supplement-opt .option-name').allInnerTexts();
  console.log('suppléments caisse =', JSON.stringify(noms));
  expect(noms[0]).toMatch(/Cheddar[\s\S]*\(recommandé\)/);
  expect(noms.filter((n) => /recommandé/.test(n))).toHaveLength(2);
  const pains = await page.locator('.wizard-option img').evaluateAll((e) => e.map((i) => i.getAttribute('src')));
  console.log('vignettes wizard =', JSON.stringify(pains));
  console.log('erreurs JS =', errors.length);
});
