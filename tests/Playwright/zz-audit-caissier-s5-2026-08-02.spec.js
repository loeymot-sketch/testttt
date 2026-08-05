// ZZ-TEST AUDIT CAISSIER S5 2026-08-02 — rupture 86 (marquer + propager + réactiver) et
// produit 86 déjà présent dans un panier en cours.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-logique-2026-08-01/shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: false }).catch(() => {});

// Produit cobaye : Fish Burger (id 100, cat Burgers) — simple, non impliqué ailleurs.
const ITEM_ID = Number(process.env.ZZ_86_ITEM || 98);
const ITEM_NAME = process.env.ZZ_86_NAME || 'Cheese Burger';

test.describe.configure({ mode: 'serial' });
test.setTimeout(180_000);

test('S5a — marquer 86 depuis la caisse et vérifier la tuile', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S5a|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);

  await page.evaluate(() => document.querySelector('[data-testid="pos-availability-panel-open"]')?.click());
  await page.waitForTimeout(2000);
  await page.locator('[data-testid="availability-panel-search"]').fill(ITEM_NAME).catch((e) => log('search_fail', e.message.slice(0, 80)));
  await page.waitForTimeout(1500);
  await shot(page, 's5a-01-panel-recherche.png');

  const before = await page.evaluate((id) => ({
    disableBtn: !!document.querySelector(`[data-testid="availability-disable-${id}"]`),
    enableBtn: !!document.querySelector(`[data-testid="availability-enable-${id}"]`),
    optionsBtn: !!document.querySelector(`[data-testid="availability-options-${id}"]`),
  }), ITEM_ID);
  log('buttons_before', before);

  const clicked = await page.evaluate((id) => {
    const b = document.querySelector(`[data-testid="availability-disable-${id}"]`);
    if (!b) return null; b.click(); return (b.innerText || '').trim();
  }, ITEM_ID);
  log('disable_clicked', clicked);
  await page.waitForTimeout(3000);
  await shot(page, 's5a-02-apres-86.png');
  const after = await page.evaluate((id) => ({
    disableBtn: !!document.querySelector(`[data-testid="availability-disable-${id}"]`),
    enableBtn: !!document.querySelector(`[data-testid="availability-enable-${id}"]`),
    panelText: document.querySelector('[data-testid="availability-panel-search"]')?.closest('div')?.parentElement?.innerText?.replace(/\s+/g, ' ').slice(0, 500) || null,
  }), ITEM_ID);
  log('buttons_after', after);

  // fermer le panel, vérifier la tuile catalogue (badge 86 + clic bloqué)
  await page.keyboard.press('Escape').catch(() => {});
  await page.evaluate(() => document.querySelector('.atp-close')?.click());
  await page.waitForTimeout(1500);
  await page.locator('[data-testid="pos-category-tile"]').filter({ hasText: /burgers/i }).first().click().catch(() => {});
  await page.waitForTimeout(2000);
  await shot(page, 's5a-03-tuile-86.png');
  const tile = await page.evaluate((name) => {
    const t = [...document.querySelectorAll('.pos-v5-tile')].find(el => (el.querySelector('.pos-v5-tile__name')?.innerText || '').trim() === name);
    if (!t) return null;
    return {
      text: (t.innerText || '').replace(/\s+/g, ' ').slice(0, 200),
      hasBadge86: !!t.querySelector('.pos-item-86-badge'),
      classes: String(t.className).slice(0, 120),
      ariaDisabled: t.getAttribute('aria-disabled'),
    };
  }, ITEM_NAME);
  log('tile_state', tile);

  // clic sur la tuile 86 → doit refuser
  const clickResult = await page.evaluate((name) => {
    const t = [...document.querySelectorAll('.pos-v5-tile')].find(el => (el.querySelector('.pos-v5-tile__name')?.innerText || '').trim() === name);
    if (!t) return 'tile-not-found';
    t.click();
    return 'clicked';
  }, ITEM_NAME);
  await page.waitForTimeout(1800);
  await shot(page, 's5a-04-clic-tuile-86.png');
  log('click_86_tile', { clickResult, cartLines: await page.evaluate(() => document.querySelectorAll('.pos-v5-cart-item').length), toast: await page.evaluate(() => document.querySelector('.alert, .toast, [class*="alert"]')?.innerText?.slice(0, 200) || null) });

  fs.writeFileSync(path.join(OUT, 's5a-report.json'), JSON.stringify(R, null, 2));
});

test('S5b — produit 86 DÉJÀ dans un panier en cours : que se passe-t-il à l’encaissement ?', async ({ page }) => {
  const R = [];
  const log = (k, v) => { R.push({ [k]: v }); console.log('S5b|' + k + '|' + JSON.stringify(v)); };
  await loginAsPosOperator(page);
  await page.waitForTimeout(3000);

  // 1) réactiver le produit pour pouvoir l'ajouter
  await page.evaluate(() => document.querySelector('[data-testid="pos-availability-panel-open"]')?.click());
  await page.waitForTimeout(1800);
  await page.locator('[data-testid="availability-panel-search"]').fill(ITEM_NAME).catch(() => {});
  await page.waitForTimeout(1500);
  const reEnabled = await page.evaluate((id) => { const b = document.querySelector(`[data-testid="availability-enable-${id}"]`); if (b) { b.click(); return true; } return false; }, ITEM_ID);
  log('reenabled', reEnabled);
  await page.waitForTimeout(2500);
  await page.evaluate(() => document.querySelector('.atp-close')?.click());
  await page.waitForTimeout(1200);

  // 2) ajouter au panier
  await page.locator('[data-testid="pos-category-tile"]').filter({ hasText: /burgers/i }).first().click().catch(() => {});
  await page.waitForTimeout(1800);
  await page.evaluate((name) => {
    const t = [...document.querySelectorAll('.pos-v5-tile')].find(el => (el.querySelector('.pos-v5-tile__name')?.innerText || '').trim() === name);
    if (t) t.click();
  }, ITEM_NAME);
  await page.waitForTimeout(2000);
  // wizard éventuel
  await page.evaluate(() => {
    const m = document.querySelector('#item-variation-modal');
    if (m && getComputedStyle(m).display !== 'none') {
      const sauce = [...m.querySelectorAll('.sauce-chip')][0]; if (sauce) sauce.click();
      setTimeout(() => { const add = [...m.querySelectorAll('button')].find(b => /ajouter au panier/i.test(b.innerText || '')); if (add) add.click(); }, 300);
    }
  });
  await page.waitForTimeout(2500);
  const cart1 = await page.evaluate(() => ({ lines: document.querySelectorAll('.pos-v5-cart-item').length, total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null }));
  log('cart_with_item', cart1);
  await shot(page, 's5b-01-panier-avec-item.png');

  // 3) le passer en 86 pendant que le panier est ouvert
  await page.evaluate(() => document.querySelector('[data-testid="pos-availability-panel-open"]')?.click());
  await page.waitForTimeout(1800);
  await page.locator('[data-testid="availability-panel-search"]').fill(ITEM_NAME).catch(() => {});
  await page.waitForTimeout(1500);
  const disabled = await page.evaluate((id) => { const b = document.querySelector(`[data-testid="availability-disable-${id}"]`); if (b) { b.click(); return true; } return false; }, ITEM_ID);
  log('disabled_while_in_cart', disabled);
  await page.waitForTimeout(3000);
  await page.evaluate(() => document.querySelector('.atp-close')?.click());
  await page.waitForTimeout(1500);
  await shot(page, 's5b-02-panier-apres-86.png');
  const cart2 = await page.evaluate(() => ({
    lines: document.querySelectorAll('.pos-v5-cart-item').length,
    total: document.querySelector('[data-testid="pos-grand-total"]')?.innerText?.replace(/\s+/g, ' ') || null,
    cartText: [...document.querySelectorAll('.pos-v5-cart-item')].map(e => (e.innerText || '').replace(/\s+/g, ' ').slice(0, 150)),
    banner: document.querySelector('[data-testid="pos-availability-banner"]')?.innerText?.replace(/\s+/g, ' ') || null,
  }));
  log('cart_after_86', cart2);

  // 4) tenter l'encaissement
  const payClicked = await page.evaluate(() => { const b = document.querySelector('[data-testid="pos-v5-pay"]'); if (b) { b.click(); return true; } return false; });
  log('pay_clicked', payClicked);
  await page.waitForTimeout(2500);
  await shot(page, 's5b-03-tentative-paiement.png');
  const payState = await page.evaluate(() => {
    const m = document.querySelector('#orderpayment');
    return { modalOpen: m ? getComputedStyle(m).display !== 'none' : false, toast: document.querySelector('.alert, [class*="alert"]')?.innerText?.slice(0, 250) || null };
  });
  log('pay_state', payState);

  if (payState.modalOpen) {
    await page.locator('[data-testid="pos-payment-mode-cash"]').click().catch(() => {});
    await page.waitForTimeout(500);
    await page.locator('#cashInput').fill('50').catch(() => {});
    await page.waitForTimeout(600);
    await page.locator('[data-testid="pos-payment-confirm"]').click().catch(() => {});
    await page.waitForTimeout(5000);
    await shot(page, 's5b-04-apres-confirm-86.png');
    log('after_confirm', await page.evaluate(() => ({
      payOpen: document.querySelector('#orderpayment') ? getComputedStyle(document.querySelector('#orderpayment')).display !== 'none' : null,
      receipt: document.querySelector('#receiptModal') && getComputedStyle(document.querySelector('#receiptModal')).display !== 'none',
      body: (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 400),
    })));
  }

  // 5) remettre disponible (propreté)
  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  await page.evaluate(() => document.querySelector('[data-testid="pos-availability-panel-open"]')?.click());
  await page.waitForTimeout(1800);
  await page.locator('[data-testid="availability-panel-search"]').fill(ITEM_NAME).catch(() => {});
  await page.waitForTimeout(1500);
  log('cleanup_reenabled', await page.evaluate((id) => { const b = document.querySelector(`[data-testid="availability-enable-${id}"]`); if (b) { b.click(); return true; } return false; }, ITEM_ID));
  await page.waitForTimeout(2000);

  fs.writeFileSync(path.join(OUT, 's5b-report.json'), JSON.stringify(R, null, 2));
});
