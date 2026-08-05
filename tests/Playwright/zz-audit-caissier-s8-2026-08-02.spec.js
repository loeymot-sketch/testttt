// ZZ-TEST AUDIT CAISSIER S8 2026-08-02 — parking (window.prompt) + split multi-paiement complet.
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
  await page.evaluate(() => {
    const m = document.querySelector('#item-variation-modal');
    if (m && getComputedStyle(m).display !== 'none') { const s = m.querySelector('.sauce-chip'); if (s) s.click(); }
  });
  await page.waitForTimeout(700);
  await page.evaluate(() => {
    const m = document.querySelector('#item-variation-modal');
    if (m && getComputedStyle(m).display !== 'none') {
      const add = [...m.querySelectorAll('button')].find(b => /ajouter au panier/i.test(b.innerText || '') && !b.disabled);
      if (add) add.click();
    }
  });
  await page.waitForTimeout(1600);
}

test('S8a — mise en attente via window.prompt (acceptée) + reprise', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S8a|' + k + '|' + JSON.stringify(v)); };
  const dialogs = [];
  page.on('dialog', async (d) => { dialogs.push({ type: d.type(), msg: d.message() }); await d.accept('ZZ-TEST-PARK-1'); });

  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);
  if ((await page.evaluate(() => document.querySelectorAll('.pos-v5-cart-item').length)) === 0) {
    await addSimpleItem(page, /^Frites/i, 'Grande Frites');
  }
  log('cart_before', await page.evaluate(() => ({ lines: document.querySelectorAll('.pos-v5-cart-item').length, total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null })));

  await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /mettre en attente/i.test(x.innerText || '')); if (b) b.click(); });
  await page.waitForTimeout(3500);
  log('dialogs_seen', dialogs);
  await shot(page, 's8a-01-apres-park.png');
  log('after_park', await page.evaluate(() => ({
    lines: document.querySelectorAll('.pos-v5-cart-item').length,
    parkedBtn: [...document.querySelectorAll('button')].find(x => /en attente/i.test(x.innerText || ''))?.innerText?.replace(/\s+/g, ' ') || null,
    toast: document.querySelector('.alert, [class*="alert"]')?.innerText?.replace(/\s+/g, ' ').slice(0, 200) || null,
  })));

  // rouvrir la liste et reprendre
  await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /en attente/i.test(x.innerText || '')); if (b) b.click(); });
  await page.waitForTimeout(2500);
  await shot(page, 's8a-02-liste.png');
  const list = await page.evaluate(() => {
    const mods = [...document.querySelectorAll('.modal, [role="dialog"], [class*="overlay"]')].filter(m => getComputedStyle(m).display !== 'none' && m.offsetHeight > 60);
    return mods.map(m => (m.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 700)).filter(Boolean);
  });
  log('parked_list', list);
  const resumed = await page.evaluate(() => {
    const mods = [...document.querySelectorAll('.modal, [role="dialog"], [class*="overlay"]')].filter(m => getComputedStyle(m).display !== 'none' && m.offsetHeight > 60);
    for (const m of mods) {
      const b = [...m.querySelectorAll('button')].find(x => /reprendre|charger|restaurer|récupérer/i.test(x.innerText || ''));
      if (b) { b.click(); return (b.innerText || '').trim(); }
    }
    return null;
  });
  log('resume_clicked', resumed);
  await page.waitForTimeout(3000);
  await shot(page, 's8a-03-apres-reprise.png');
  log('cart_after_resume', await page.evaluate(() => ({ lines: document.querySelectorAll('.pos-v5-cart-item').length, total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null })));
  fs.writeFileSync(path.join(OUT, 's8a-report.json'), JSON.stringify(R, null, 2));
});

test('S8b — split espèces + carte : tranches, reste dû, confirmation', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S8b|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);
  if ((await page.evaluate(() => document.querySelectorAll('.pos-v5-cart-item').length)) === 0) {
    await addSimpleItem(page, /^Frites/i, 'Grande Frites');
  }
  log('cart', await page.evaluate(() => ({ lines: document.querySelectorAll('.pos-v5-cart-item').length, total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null })));

  await page.evaluate(() => document.querySelector('[data-testid="pos-v5-pay"]')?.click());
  await page.waitForTimeout(2200);
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-mode-multi"]')?.click());
  await page.waitForTimeout(1200);

  // ajouter 2 tranches
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-tranche-add"]')?.click());
  await page.waitForTimeout(1000);
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-tranche-add"]')?.click());
  await page.waitForTimeout(1200);
  await shot(page, 's8b-01-deux-tranches.png');
  const t = await page.evaluate(() => {
    const m = document.querySelector('#orderpayment');
    return {
      text: m ? (m.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 1400) : null,
      rows: [...document.querySelectorAll('.pos-v5-tranche-row, [class*="tranche"]')].map(e => (e.innerText || '').replace(/\s+/g, ' ').slice(0, 150)).slice(0, 8),
      inputs: [...(m?.querySelectorAll('input, select') || [])].map(i => ({ tag: i.tagName, type: i.type, val: i.value, ph: i.placeholder })).slice(0, 12),
      covered: document.querySelector('[data-testid="pos-payment-total-covered"]')?.innerText?.replace(/\s+/g, ' ') || null,
      remaining: document.querySelector('[data-testid="pos-payment-remaining-due"]')?.innerText?.replace(/\s+/g, ' ') || null,
      confirmDisabled: !!document.querySelector('[data-testid="pos-payment-confirm"]')?.disabled,
      autoBalance: !!document.querySelector('[data-testid="pos-payment-auto-balance"]'),
    };
  });
  log('two_tranches', t);

  // équilibrer auto
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-auto-balance"]')?.click());
  await page.waitForTimeout(1500);
  await shot(page, 's8b-02-equilibre.png');
  log('after_balance', await page.evaluate(() => ({
    covered: document.querySelector('[data-testid="pos-payment-total-covered"]')?.innerText?.replace(/\s+/g, ' ') || null,
    remaining: document.querySelector('[data-testid="pos-payment-remaining-due"]')?.innerText?.replace(/\s+/g, ' ') || null,
    confirmDisabled: !!document.querySelector('[data-testid="pos-payment-confirm"]')?.disabled,
    modalText: document.querySelector('#orderpayment')?.innerText?.replace(/\n{2,}/g, '\n').slice(0, 1200) || null,
  })));
  fs.writeFileSync(path.join(OUT, 's8b-report.json'), JSON.stringify(R, null, 2));
});
