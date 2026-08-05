// ZZ-TEST AUDIT CAISSIER S7 2026-08-02 — cas limites : F5 en pleine commande, commande parkée,
// 2 onglets, split cash+carte, annulation ligne.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-logique-2026-08-01/shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});

test.describe.configure({ mode: 'serial' });
test.setTimeout(150_000);

// Ajoute un produit simple (Grande Frites) au panier via la grille + wizard sauce.
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
    if (m && getComputedStyle(m).display !== 'none') {
      const s = m.querySelector('.sauce-chip'); if (s) s.click();
    }
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

test('S7a — F5 en pleine commande : le panier survit-il ?', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S7a|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);
  await addSimpleItem(page, /^Frites/i, 'Grande Frites');
  const before = await page.evaluate(() => ({
    lines: document.querySelectorAll('.pos-v5-cart-item').length,
    total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null,
  }));
  log('cart_before_reload', before);
  await shot(page, 's7a-01-avant-f5.png');

  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(5000);
  await shot(page, 's7a-02-apres-f5.png');
  const after = await page.evaluate(() => ({
    lines: document.querySelectorAll('.pos-v5-cart-item').length,
    total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null,
    cartText: [...document.querySelectorAll('.pos-v5-cart-item')].map(e => (e.innerText || '').replace(/\s+/g, ' ').slice(0, 120)),
  }));
  log('cart_after_reload', after);
  fs.writeFileSync(path.join(OUT, 's7a-report.json'), JSON.stringify(R, null, 2));
});

test('S7b — 2 onglets caisse ouverts : le panier est-il partagé/divergent ?', async ({ browser }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S7b|' + k + '|' + JSON.stringify(v)); };
  const ctx = await browser.newContext();
  const p1 = await ctx.newPage();
  await loginAsPosOperator(p1);
  await p1.waitForTimeout(3000);
  const state1 = await p1.evaluate(() => ({ lines: document.querySelectorAll('.pos-v5-cart-item').length, total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null }));
  log('tab1_initial', state1);

  const p2 = await ctx.newPage();
  await p2.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await p2.waitForTimeout(5000);
  const state2 = await p2.evaluate(() => ({ url: location.pathname, lines: document.querySelectorAll('.pos-v5-cart-item').length, total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null }));
  log('tab2_initial', state2);
  await shot(p2, 's7b-01-onglet2.png');

  // ajouter un article dans l'onglet 2
  await addSimpleItem(p2, /^Frites/i, 'Petite Frites');
  const t2 = await p2.evaluate(() => ({ lines: document.querySelectorAll('.pos-v5-cart-item').length, total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null }));
  log('tab2_after_add', t2);
  await p1.waitForTimeout(3000);
  const t1 = await p1.evaluate(() => ({ lines: document.querySelectorAll('.pos-v5-cart-item').length, total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null }));
  log('tab1_after_tab2_add', t1);
  await shot(p1, 's7b-02-onglet1-apres.png');
  await ctx.close();
  fs.writeFileSync(path.join(OUT, 's7b-report.json'), JSON.stringify(R, null, 2));
});

test('S7c — commande parkée (mise en attente) puis reprise', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S7c|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);
  // panier de départ
  const start = await page.evaluate(() => document.querySelectorAll('.pos-v5-cart-item').length);
  if (start === 0) await addSimpleItem(page, /^Frites/i, 'Grande Frites');
  const before = await page.evaluate(() => ({ lines: document.querySelectorAll('.pos-v5-cart-item').length, total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null }));
  log('cart_before_park', before);

  const parkBtn = await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /mettre en attente/i.test(x.innerText || ''));
    if (b) { b.click(); return (b.innerText || '').trim(); }
    return null;
  });
  log('park_clicked', parkBtn);
  await page.waitForTimeout(3000);
  await shot(page, 's7c-01-apres-park.png');
  const afterPark = await page.evaluate(() => ({
    lines: document.querySelectorAll('.pos-v5-cart-item').length,
    parkedBadge: [...document.querySelectorAll('button')].find(x => /en attente/i.test(x.innerText || ''))?.innerText?.replace(/\s+/g, ' ') || null,
    toast: document.querySelector('.alert, [class*="alert"]')?.innerText?.slice(0, 200) || null,
  }));
  log('after_park', afterPark);

  // rouvrir la liste des commandes en attente
  const openParked = await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /en attente/i.test(x.innerText || ''));
    if (b) { b.click(); return true; } return false;
  });
  log('open_parked_list', openParked);
  await page.waitForTimeout(2500);
  await shot(page, 's7c-02-liste-attente.png');
  const parkedList = await page.evaluate(() => {
    const mods = [...document.querySelectorAll('.modal, [role="dialog"]')].filter(m => getComputedStyle(m).display !== 'none' && m.offsetHeight > 60);
    return mods.map(m => (m.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 800));
  });
  log('parked_list', parkedList);

  // reprendre
  const resume = await page.evaluate(() => {
    const mods = [...document.querySelectorAll('.modal, [role="dialog"]')].filter(m => getComputedStyle(m).display !== 'none' && m.offsetHeight > 60);
    for (const m of mods) {
      const b = [...m.querySelectorAll('button')].find(x => /reprendre|récupérer|charger|restaurer/i.test(x.innerText || ''));
      if (b) { b.click(); return (b.innerText || '').trim(); }
    }
    return null;
  });
  log('resume_clicked', resume);
  await page.waitForTimeout(3000);
  await shot(page, 's7c-03-apres-reprise.png');
  log('cart_after_resume', await page.evaluate(() => ({
    lines: document.querySelectorAll('.pos-v5-cart-item').length,
    total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null,
  })));
  fs.writeFileSync(path.join(OUT, 's7c-report.json'), JSON.stringify(R, null, 2));
});

test('S7d — split multi-paiement (espèces + carte)', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S7d|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);
  const start = await page.evaluate(() => document.querySelectorAll('.pos-v5-cart-item').length);
  if (start === 0) await addSimpleItem(page, /^Frites/i, 'Grande Frites');
  log('cart', await page.evaluate(() => ({ lines: document.querySelectorAll('.pos-v5-cart-item').length, total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null })));

  await page.evaluate(() => document.querySelector('[data-testid="pos-v5-pay"]')?.click());
  await page.waitForTimeout(2200);
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-mode-multi"]')?.click());
  await page.waitForTimeout(1500);
  await shot(page, 's7d-01-multi.png');
  const multi = await page.evaluate(() => {
    const m = document.querySelector('#orderpayment');
    return {
      text: m ? (m.innerText || '').replace(/\n{2,}/g, '\n').slice(0, 1200) : null,
      covered: document.querySelector('[data-testid="pos-payment-total-covered"]')?.innerText?.replace(/\s+/g, ' ') || null,
      remaining: document.querySelector('[data-testid="pos-payment-remaining-due"]')?.innerText?.replace(/\s+/g, ' ') || null,
      confirmDisabled: !!document.querySelector('[data-testid="pos-payment-confirm"]')?.disabled,
      trancheAdd: !!document.querySelector('[data-testid="pos-payment-tranche-add"]'),
      autoBalance: !!document.querySelector('[data-testid="pos-payment-auto-balance"]'),
      splitEqual: !!document.querySelector('[data-testid="pos-payment-split-equal"]'),
    };
  });
  log('multi_state', multi);

  // équilibrer automatiquement puis observer
  await page.evaluate(() => document.querySelector('[data-testid="pos-payment-auto-balance"]')?.click());
  await page.waitForTimeout(1500);
  await shot(page, 's7d-02-auto-balance.png');
  log('after_auto_balance', await page.evaluate(() => ({
    covered: document.querySelector('[data-testid="pos-payment-total-covered"]')?.innerText?.replace(/\s+/g, ' ') || null,
    remaining: document.querySelector('[data-testid="pos-payment-remaining-due"]')?.innerText?.replace(/\s+/g, ' ') || null,
    confirmDisabled: !!document.querySelector('[data-testid="pos-payment-confirm"]')?.disabled,
    tranches: [...document.querySelectorAll('#orderpayment [class*="tranche"]')].map(e => (e.innerText || '').replace(/\s+/g, ' ').slice(0, 140)).slice(0, 6),
  })));
  fs.writeFileSync(path.join(OUT, 's7d-report.json'), JSON.stringify(R, null, 2));
});
