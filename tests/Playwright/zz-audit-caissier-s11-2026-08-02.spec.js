// ZZ-TEST AUDIT CAISSIER S11 2026-08-02 — preuve visuelle du rendu « undefined » dans le
// détail « À encaisser au comptoir » (PosComponent.vue:1605).
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-logique-2026-08-01/shots');
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});
test.setTimeout(120_000);

test('S11 — détail commande dans « À encaisser » : composition lisible ?', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S11|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);
  await page.evaluate(() => document.querySelector('[data-testid="kiosk-cash-open"]')?.click());
  await page.waitForTimeout(2500);

  // développer les 3 premiers Détail
  const expanded = await page.evaluate(() => {
    const btns = [...document.querySelectorAll('[data-testid^="kiosk-cash-expand-"]')].slice(0, 3);
    btns.forEach(b => b.click());
    return btns.map(b => b.getAttribute('data-testid'));
  });
  log('expanded', expanded);
  await page.waitForTimeout(2000);
  await shot(page, 's11-01-details-expanded.png');

  const details = await page.evaluate(() => {
    return [...document.querySelectorAll('[data-testid^="kiosk-cash-detail"]')]
      .map(d => (d.innerText || '').replace(/\s+/g, ' ').slice(0, 400)).slice(0, 6);
  });
  log('details', details);
  log('undefined_present', await page.evaluate(() => /undefined/.test(document.body.innerText || '')));
  log('undefined_occurrences', await page.evaluate(() => ((document.body.innerText || '').match(/undefined/g) || []).length));
  fs.writeFileSync(path.join(OUT, 's11-report.json'), JSON.stringify(R, null, 2));
});
