// VAGUE A — Commande (b): Tacos (8,50 €) via wizard popup caisse [FROZEN observe] → Multi-paiement tranche Ticket Restaurant
import { boot, login, gotoPos, quartet, jsClick, cartState } from './_d1-A-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();
const wizState = () => page.evaluate(() => {
  const root = document.querySelector('.wizard-modal, [class*="wizard"]');
  const header = document.querySelector('.wizard-step-header')?.innerText?.trim() || null;
  const badge = document.querySelector('.wizard-step-badge')?.innerText?.trim() || null;
  const opts = Array.from(document.querySelectorAll('.wizard-option')).slice(0, 24).map((o) => o.innerText.replace(/\s+/g, ' ').trim().slice(0, 80));
  const btns = ['cart', 'next', 'skip', 'back', 'cancel'].map((b) => {
    const el = document.querySelector('.wizard-btn-' + b);
    return el ? `${b}:"${el.innerText.replace(/\s+/g, ' ').trim().slice(0, 50)}"${el.disabled ? '[disabled]' : ''}` : null;
  }).filter(Boolean);
  const seeMore = Array.from(document.querySelectorAll('button, a')).filter((e) => /voir plus/i.test(e.innerText)).map((e) => e.innerText.trim());
  const prices = Array.from(document.querySelectorAll('[class*="wizard"] [class*="price"], [class*="wizard"] [class*="prix"]')).slice(0, 10).map((e) => e.innerText.trim());
  return { hasWizard: !!root, header, badge, optCount: document.querySelectorAll('.wizard-option').length, opts, btns, seeMore, prices };
});

try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1500);

  // Catégorie Tacos
  await page.locator('button.pos-v5-category[aria-label="Tacos"]').first().click().catch(async () => {
    const cats = await page.evaluate(() => Array.from(document.querySelectorAll('button.pos-v5-category')).map((c) => c.getAttribute('aria-label')));
    console.log('CATEGORIES:', JSON.stringify(cats));
  });
  await page.waitForTimeout(1500);
  await quartet(page, consoleLog, netLog, 'b01-cat-tacos');

  await page.locator('.pos-v5-tile:has-text("Tacos")').first().click();
  await page.waitForTimeout(2000);
  let st = await wizState();
  console.log('WIZ-STEP-1:', JSON.stringify(st, null, 1));
  await quartet(page, consoleLog, netLog, 'b02-wizard-step1');

  // Avancer dans les étapes en sélectionnant la 1re option de chaque étape, max 8 étapes
  for (let step = 1; step <= 8; step++) {
    st = await wizState();
    if (!st.hasWizard) break;
    // tenter "Voir plus" si présent (étape sauces)
    if (st.seeMore.length) {
      await page.evaluate(() => { const b = Array.from(document.querySelectorAll('button, a')).find((e) => /voir plus/i.test(e.innerText)); b && b.click(); });
      await page.waitForTimeout(700);
      const st2 = await wizState();
      console.log(`WIZ-STEP-${step} après Voir plus: optCount ${st.optCount} -> ${st2.optCount}`);
      await quartet(page, consoleLog, netLog, `b03-wizard-step${step}-voirplus`);
    }
    // sélectionner 1re option si rien n'est requis-sélectionné
    await page.evaluate(() => { const o = document.querySelector('.wizard-option'); o && o.click(); });
    await page.waitForTimeout(500);
    if (step <= 3) await quartet(page, consoleLog, netLog, `b04-wizard-step${step}-selected`);
    // next ou cart
    const advanced = await page.evaluate(() => {
      const next = document.querySelector('.wizard-btn-next');
      if (next && !next.disabled && getComputedStyle(next).display !== 'none') { next.click(); return 'next'; }
      const skip = document.querySelector('.wizard-btn-skip');
      if (skip && getComputedStyle(skip).display !== 'none') { skip.click(); return 'skip'; }
      const cart = document.querySelector('.wizard-btn-cart');
      if (cart && !cart.disabled) { cart.click(); return 'cart'; }
      return 'none';
    });
    console.log(`WIZ-STEP-${step} header="${st.header}" badge="${st.badge}" advance=${advanced}`);
    await page.waitForTimeout(900);
    if (advanced === 'cart' || advanced === 'none') break;
  }
  await page.waitForTimeout(1200);
  const cart = await cartState(page);
  console.log('CART-B:', JSON.stringify(cart, null, 1));
  await quartet(page, consoleLog, netLog, 'b05-cart-tacos');

  // --- Commander -> Multi-paiement, tranche unique Ticket Restaurant ---
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2500);
  const payTotal = await page.locator('.pos-v5-payment-total-value').first().textContent().catch(() => 'ABSENT');
  console.log('PAYMENT-MODAL-TOTAL:', JSON.stringify(payTotal?.trim()));
  await page.locator('[data-testid="pos-payment-mode-multi"]').click();
  await page.waitForTimeout(1000);
  await quartet(page, consoleLog, netLog, 'b06-payment-multi');

  // tranche: passer la méthode à Ticket Restaurant
  const trancheInfo = await page.evaluate(() => {
    const sels = Array.from(document.querySelectorAll('#multi select, [data-testid="pos-payment-split-block"] select'));
    return sels.map((s) => Array.from(s.options).map((o) => `${o.value}:${o.text.trim()}`));
  });
  console.log('TRANCHE-SELECT-OPTIONS:', JSON.stringify(trancheInfo));
  await page.evaluate(() => {
    const sel = document.querySelector('#multi select, [data-testid="pos-payment-split-block"] select');
    if (sel) {
      const opt = Array.from(sel.options).find((o) => /ticket/i.test(o.text));
      if (opt) { sel.value = opt.value; sel.dispatchEvent(new Event('change', { bubbles: true })); }
    }
  });
  await page.waitForTimeout(800);
  // montant tranche = total
  const covered = await page.locator('[data-testid="pos-payment-total-covered"]').textContent().catch(() => 'ABSENT');
  const remaining = await page.locator('[data-testid="pos-payment-remaining-due"]').textContent().catch(() => 'ABSENT');
  console.log('MULTI covered:', covered?.trim(), '| remaining:', remaining?.trim());
  await quartet(page, consoleLog, netLog, 'b07-payment-multi-tr');

  // confirm
  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && /api\/admin\/pos\b/.test(r.url()), { timeout: 20000 }).catch(() => null);
  await page.locator('[data-testid="pos-payment-confirm"]').click();
  const resp = await respP;
  if (resp) {
    console.log('ORDER-POST:', resp.status(), resp.url());
    try { const j = await resp.json(); console.log('ORDER-JSON id/serial/total:', j?.data?.id, j?.data?.order_serial_no, j?.data?.total); } catch { try { console.log('ORDER-BODY:', (await resp.text()).slice(0, 400)); } catch {} }
  }
  await page.waitForTimeout(3500);
  await quartet(page, consoleLog, netLog, 'b08-after-confirm');
  const receiptText = await page.evaluate(() => document.querySelector('#print-receipt-client')?.innerText || 'ABSENT');
  console.log('=== RECEIPT B ===\n' + receiptText.slice(0, 2500));
  await quartet(page, consoleLog, netLog, 'b09-receipt-tr');
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 15)));
  await browser.close();
}
