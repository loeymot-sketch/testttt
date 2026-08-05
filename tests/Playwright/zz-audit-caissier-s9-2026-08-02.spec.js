// ZZ-TEST AUDIT CAISSIER S9 2026-08-02 — reprise commande parkée (drawer réel) + split cash/carte complet.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-logique-2026-08-01/shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});

test.describe.configure({ mode: 'serial' });
test.setTimeout(150_000);

async function addSimpleItem(page, categoryRe, itemName) {
  await page.locator('[data-testid="pos-category-tile"]').filter({ hasText: categoryRe }).first().click().catch(() => {});
  await page.waitForTimeout(1400);
  await page.evaluate((name) => {
    const t = [...document.querySelectorAll('.pos-v5-tile')].find(el => (el.querySelector('.pos-v5-tile__name')?.innerText || '').trim() === name);
    if (t) t.click();
  }, itemName);
  await page.waitForTimeout(1600);
  await page.evaluate(() => { const m = document.querySelector('#item-variation-modal'); if (m && getComputedStyle(m).display !== 'none') { const s = m.querySelector('.sauce-chip'); if (s) s.click(); } });
  await page.waitForTimeout(700);
  await page.evaluate(() => {
    const m = document.querySelector('#item-variation-modal');
    if (m && getComputedStyle(m).display !== 'none') { const add = [...m.querySelectorAll('button')].find(b => /ajouter au panier/i.test(b.innerText || '') && !b.disabled); if (add) add.click(); }
  });
  await page.waitForTimeout(1600);
}

test('S9a — reprise de la commande parkée (drawer .parked-orders-overlay)', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S9a|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);

  // vider le panier si besoin (la reprise exige un panier vide)
  const lines0 = await page.evaluate(() => document.querySelectorAll('.pos-v5-cart-item').length);
  log('cart_at_start', lines0);
  if (lines0 > 0) {
    await page.evaluate(() => document.querySelector('[data-testid="pos-cart-reset"]')?.click());
    await page.waitForTimeout(600);
    await page.evaluate(() => document.querySelector('[data-testid="pos-cart-reset"]')?.click());
    await page.waitForTimeout(1500);
  }

  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /commandes en attente|en attente/i.test((x.getAttribute('aria-label') || '') + ' ' + (x.getAttribute('title') || '')));
    if (b) b.click();
  });
  await page.waitForTimeout(2500);
  await shot(page, 's9a-01-drawer.png');
  const drawer = await page.evaluate(() => {
    const d = document.querySelector('.parked-orders-overlay');
    return d ? (d.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 900) : null;
  });
  log('drawer', drawer);

  const restored = await page.evaluate(() => {
    const b = document.querySelector('.parked-orders-action-primary');
    if (!b) return null;
    b.click(); return (b.innerText || '').trim();
  });
  log('restore_clicked', restored);
  await page.waitForTimeout(3500);
  await shot(page, 's9a-02-apres-restore.png');
  log('cart_after_restore', await page.evaluate(() => ({
    lines: document.querySelectorAll('.pos-v5-cart-item').length,
    total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null,
    cartText: [...document.querySelectorAll('.pos-v5-cart-item')].map(e => (e.innerText || '').replace(/\s+/g, ' ').slice(0, 130)),
    toast: document.querySelector('.alert, [class*="alert"]')?.innerText?.replace(/\s+/g, ' ').slice(0, 200) || null,
  })));
  fs.writeFileSync(path.join(OUT, 's9a-report.json'), JSON.stringify(R, null, 2));
});

test('S9b — split espèces 2,00 + carte 2,00 sur un total 4,00', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S9b|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);
  if ((await page.evaluate(() => document.querySelectorAll('.pos-v5-cart-item').length)) === 0) {
    await addSimpleItem(page, /^Frites/i, 'Grande Frites');
  }
  const total = await page.evaluate(() => document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null);
  log('total', total);

  await page.evaluate(() => document.querySelector('[data-testid="pos-v5-pay"]')?.click());
  await page.waitForTimeout(2200);
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-mode-multi"]')?.click());
  await page.waitForTimeout(1200);
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-tranche-add"]')?.click());
  await page.waitForTimeout(900);
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-tranche-add"]')?.click());
  await page.waitForTimeout(1200);

  // structure des tranches
  const struct = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('.pos-v5-tranche-row')];
    return rows.map((r, i) => ({
      i,
      selects: [...r.querySelectorAll('select')].map(s => ({ value: s.value, opts: [...s.options].map(o => o.value + '=' + o.text.trim()) })),
      inputs: [...r.querySelectorAll('input')].map(inp => ({ type: inp.type, val: inp.value, ph: inp.placeholder, id: inp.id, cls: String(inp.className).slice(0, 40) })),
      buttons: [...r.querySelectorAll('button')].map(b => (b.innerText || '').trim().slice(0, 25)),
    }));
  });
  log('tranche_structure', struct);

  // remplir : tranche 0 = cash 2,00 reçu 2,00 ; tranche 1 = carte 2,00
  const filled = await page.evaluate(() => {
    const setVal = (el, v) => {
      const proto = el.tagName === 'SELECT' ? window.HTMLSelectElement.prototype : window.HTMLInputElement.prototype;
      Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, v);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const rows = [...document.querySelectorAll('.pos-v5-tranche-row')];
    if (rows.length < 2) return { error: 'less-than-2-rows', rows: rows.length };
    const out = [];
    // tranche 0 : cash
    const r0inputs = [...rows[0].querySelectorAll('input')];
    r0inputs.forEach((inp, idx) => setVal(inp, '2'));
    out.push({ row: 0, inputs: r0inputs.length });
    // tranche 1 : sélectionner carte puis montant
    const sel1 = rows[1].querySelector('select');
    if (sel1) {
      const cardOpt = [...sel1.options].find(o => /carte|card/i.test(o.text));
      if (cardOpt) setVal(sel1, cardOpt.value);
      out.push({ row: 1, mode: sel1.value });
    }
    const r1inputs = [...rows[1].querySelectorAll('input')];
    r1inputs.forEach(inp => setVal(inp, '2'));
    out.push({ row: 1, inputs: r1inputs.length });
    return out;
  });
  log('filled', filled);
  await page.waitForTimeout(1500);
  await shot(page, 's9b-01-tranches-remplies.png');
  log('state', await page.evaluate(() => ({
    covered: document.querySelector('[data-testid="pos-payment-total-covered"]')?.innerText?.replace(/\s+/g, ' ') || null,
    remaining: document.querySelector('[data-testid="pos-payment-remaining-due"]')?.innerText?.replace(/\s+/g, ' ') || null,
    change: document.querySelector('[data-testid="pos-payment-total-change"]')?.innerText?.replace(/\s+/g, ' ') || null,
    confirmDisabled: !!document.querySelector('[data-testid="pos-payment-confirm"]')?.disabled,
    modalText: document.querySelector('#orderpayment')?.innerText?.replace(/\n{2,}/g, '\n').slice(0, 1100) || null,
  })));
  fs.writeFileSync(path.join(OUT, 's9b-report.json'), JSON.stringify(R, null, 2));
});
