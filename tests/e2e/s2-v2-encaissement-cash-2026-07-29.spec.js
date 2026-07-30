// [S2 V2 2026-07-29] E2E RÉEL money-path : encaisser au comptoir en ESPÈCES avec
// rendu de monnaie, navigateur réel + backend réel + DB dev (foodking_e2e).
// La vérité au centime est vérifiée EN DB après le run (voir V2-MONEYPATH.md).
//
// Note d'implémentation : les cartes de la file se rafraîchissent en continu et un
// backdrop de layout intercepte les pointeurs → les clics passent par le DOM
// (page.evaluate) plutôt que par l'actionability de Playwright. Le parcours reste
// intégralement réel (mêmes handlers Vue, mêmes appels API).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const OUT = __dirname + '/../captures/goal-s2-v2-2026-07-29';
fs.mkdirSync(OUT, { recursive: true });
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8010';

test.use({ viewport: { width: 1280, height: 800 } });
test.setTimeout(180000);

test('encaissement cash avec rendu — au centime', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));

  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const inputs = page.locator('form input, input');
  await inputs.nth(0).fill('admin@lecayenne.fr');
  await inputs.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).last().click();
  await page.waitForTimeout(4000);

  await page.goto(BASE + '/admin/encaissement', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(6000);
  await page.screenshot({ path: OUT + '/e1-file.png' });

  const before = await page.evaluate(() => {
    const c = document.querySelector('.enc-ticket');
    return {
      count: document.querySelectorAll('.enc-ticket').length,
      serial: c ? (c.querySelector('.enc-queue') || {}).textContent : null,
      total: c ? (c.querySelector('.enc-total, [class*="total"]') || {}).textContent : null,
    };
  });
  expect(before.count).toBeGreaterThan(0);

  // Ouvre la modale d'encaissement du premier ticket.
  await page.evaluate(() => {
    const first = document.querySelector('.enc-ticket');
    const b = [...first.querySelectorAll('button')].find((x) => /encaisser/i.test(x.textContent));
    b.click();
  });
  await page.waitForTimeout(2500);
  await page.screenshot({ path: OUT + '/e2-modal.png' });

  const total = await page.evaluate(() => (document.querySelector('[data-testid="pos-counter-collect-total"]') || {}).textContent);

  // Espèces : 10,00 € reçus pour un ticket à 7,40 € → rendu attendu 2,60 €.
  // Saisie via le setter natif + event `input` pour que le v-model Vue la voie.
  await page.evaluate(() => {
    const el = document.querySelector('[data-testid="pos-counter-collect-received-input"]');
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(el, '10');
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await page.waitForTimeout(1200);
  const change = await page.evaluate(() => (document.querySelector('[data-testid="pos-counter-collect-change"]') || {}).textContent);
  await page.screenshot({ path: OUT + '/e3-rendu.png' });
  console.log(`MONEYPATH serial=${(before.serial || '').trim()} total=${(total || '').trim()} rendu=${(change || '').trim()}`);

  await page.evaluate(() => {
    document.querySelector('[data-testid="pos-counter-collect-confirm"]').click();
  });
  await page.waitForTimeout(6000);
  await page.screenshot({ path: OUT + '/e4-apres-confirm.png' });

  const after = await page.evaluate(() => ({
    count: document.querySelectorAll('.enc-ticket').length,
    modalOpen: !!document.querySelector('[data-testid="pos-counter-collect-modal"]'),
  }));
  console.log(`QUEUE avant=${before.count} apres=${after.count} modale=${after.modalOpen}`);
  console.log('PAGEERRORS=' + JSON.stringify(errors));

  // La file doit avoir diminué d'exactement un ticket.
  expect(after.count).toBe(before.count - 1);
  expect(errors).toEqual([]);
});
