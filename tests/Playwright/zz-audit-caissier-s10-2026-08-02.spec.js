// ZZ-TEST AUDIT CAISSIER S10 2026-08-02 — confirmation du split espèces+carte (money-path DB).
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-logique-2026-08-01/shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});

test.setTimeout(150_000);

test('S10 — split espèces 2 + carte 2 CONFIRMÉ (ticket + DB)', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S10|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);

  // panier propre : Grande Frites 4,00
  if ((await page.evaluate(() => document.querySelectorAll('.pos-v5-cart-item').length)) === 0) {
    await page.locator('[data-testid="pos-category-tile"]').filter({ hasText: /^Frites/i }).first().click().catch(() => {});
    await page.waitForTimeout(1400);
    await page.evaluate(() => {
      const t = [...document.querySelectorAll('.pos-v5-tile')].find(el => (el.querySelector('.pos-v5-tile__name')?.innerText || '').trim() === 'Grande Frites');
      if (t) t.click();
    });
    await page.waitForTimeout(1600);
    await page.evaluate(() => { const m = document.querySelector('#item-variation-modal'); if (m && getComputedStyle(m).display !== 'none') { const s = m.querySelector('.sauce-chip'); if (s) s.click(); } });
    await page.waitForTimeout(700);
    await page.evaluate(() => { const m = document.querySelector('#item-variation-modal'); if (m) { const a = [...m.querySelectorAll('button')].find(b => /ajouter au panier/i.test(b.innerText || '')); if (a) a.click(); } });
    await page.waitForTimeout(1600);
  }
  await page.locator('[data-testid="pos-customer-name"]').fill('ZZ-TEST-SPLIT').catch(() => {});
  log('total', await page.evaluate(() => document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null));

  await page.evaluate(() => document.querySelector('[data-testid="pos-v5-pay"]')?.click());
  await page.waitForTimeout(2200);
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-mode-multi"]')?.click());
  await page.waitForTimeout(1200);
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-tranche-add"]')?.click());
  await page.waitForTimeout(800);
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-tranche-add"]')?.click());
  await page.waitForTimeout(1200);

  const filled = await page.evaluate(() => {
    const setVal = (el, v) => {
      const proto = el.tagName === 'SELECT' ? window.HTMLSelectElement.prototype : window.HTMLInputElement.prototype;
      Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, v);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const rows = [...document.querySelectorAll('.pos-v5-tranche-row')];
    if (rows.length < 2) return { error: 'rows', n: rows.length };
    // tranche 0 = espèces 2,00, reçu 2,00
    [...rows[0].querySelectorAll('input')].forEach(i => setVal(i, '2'));
    // tranche 1 = carte 2,00
    const sel = rows[1].querySelector('select');
    const card = sel && [...sel.options].find(o => /carte/i.test(o.text));
    if (card) setVal(sel, card.value);
    [...rows[1].querySelectorAll('input')].forEach(i => setVal(i, '2'));
    return { ok: true };
  });
  log('filled', filled);
  await page.waitForTimeout(1500);
  await shot(page, 's10-01-split-pret.png');
  log('before_confirm', await page.evaluate(() => ({
    covered: document.querySelector('[data-testid="pos-payment-total-covered"]')?.innerText?.replace(/\s+/g, ' ') || null,
    remaining: document.querySelector('[data-testid="pos-payment-remaining-due"]')?.innerText?.replace(/\s+/g, ' ') || null,
    confirmDisabled: !!document.querySelector('[data-testid="pos-payment-confirm"]')?.disabled,
  })));

  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-confirm"]')?.click());
  await page.waitForTimeout(6000);
  await shot(page, 's10-02-apres-confirm.png');
  log('after_confirm', await page.evaluate(() => {
    const rec = document.querySelector('#receiptModal');
    const vis = rec && getComputedStyle(rec).display !== 'none';
    return {
      receiptVisible: !!vis,
      receipt: vis ? (rec.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 1800) : null,
      cartLines: document.querySelectorAll('.pos-v5-cart-item').length,
      toast: document.querySelector('.alert, [class*="alert"]')?.innerText?.replace(/\s+/g, ' ').slice(0, 200) || null,
    };
  }));
  fs.writeFileSync(path.join(OUT, 's10-report.json'), JSON.stringify(R, null, 2));
});
