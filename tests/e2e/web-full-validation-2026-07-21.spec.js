// VALIDATION owner « tous les sites Web validés, pages juridiques conformes ».
// Chaque page légale : rendu OK + AUCUN [À COMPLÉTER] visible. Surfaces cœur : chargent sans
// erreur console rouge. Captures lues.
const { test, expect } = require('@playwright/test');
const path = require('path'); const fs = require('fs');
const SHOT = path.join(__dirname, '__screenshots__', 'full-validation-2026-07-21');
fs.mkdirSync(SHOT, { recursive: true });
test.setTimeout(150_000);

const LEGAL = [
  { file: 'legal/mentions.html', name: 'mentions' },
  { file: 'legal/cgv.html', name: 'cgv' },
  { file: 'legal/privacy.html', name: 'privacy' },
  { file: 'legal/cookies.html', name: 'cookies' },
  { file: 'legal/allergens.html', name: 'allergens' },
];

for (const p of LEGAL) {
  test(`Légal — ${p.name} : rendu + 0 placeholder`, async ({ page }) => {
    const errs = [];
    page.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
    await page.goto('/' + p.file, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(500);
    const body = await page.locator('body').innerText();
    await page.screenshot({ path: path.join(SHOT, `legal-${p.name}.png`), fullPage: true });
    const leftovers = (body.match(/À COMPLÉTER|COMPLETER|\[.*PROPRIÉTAIRE/gi) || []);
    console.log(`[${p.name}] len=${body.length} placeholders=${leftovers.length} erreurs=${errs.length}`);
    expect(body.length, 'page non vide').toBeGreaterThan(400);
    expect(leftovers.length, 'aucun [À COMPLÉTER] visible').toBe(0);
  });
}

test('Cœur — home + menu chargent sans erreur console', async ({ page }) => {
  const errs = [];
  page.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
  await page.goto('/?dev', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45000 });
  await page.waitForTimeout(1000);
  await page.screenshot({ path: path.join(SHOT, 'home.png') });
  // menu
  const d = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await d.isVisible({ timeout: 3000 }).catch(()=>0)) await d.click();
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 15000 });
  await page.waitForTimeout(500);
  const bodyErrs = errs.filter(e => !/favicon|manifest|third-party|net::ERR_/i.test(e));
  console.log(`[cœur] erreurs console (hors favicon)=${bodyErrs.length}` + (bodyErrs.length? ' :: '+bodyErrs.slice(0,3).join(' | '):''));
  expect(bodyErrs.length, 'pas d\'erreur console bloquante').toBeLessThanOrEqual(0);
});
