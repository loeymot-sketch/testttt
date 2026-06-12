// VAGUE A — Commande (c) v2: Tiramisu + Grande Frites, remise 10% motif → Carte (TPE)
import { boot, login, gotoPos, quartet, cartState, freshAuth } from './_d1-A-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();

async function completeWizardIfAny(tag) {
  for (let round = 0; round < 3; round++) {
    await page.waitForTimeout(900);
    const wiz = await page.evaluate(() => !!document.querySelector('.pos-wizard'));
    if (!wiz) return;
    const sections = await page.evaluate(() => Array.from(document.querySelectorAll('.wizard-section')).map((s, i) => ({ i, head: s.innerText.split('\n')[0].slice(0, 50), mandatory: /obligatoire/i.test(s.innerText.slice(0, 200)), selected: s.querySelectorAll('.wizard-option.selected, .wizard-option[class*="active"]').length })));
    console.log(`WIZ[${tag}] round${round}:`, JSON.stringify(sections));
    // sélectionne 1re option de chaque section obligatoire non remplie
    await page.evaluate(() => {
      document.querySelectorAll('.wizard-section').forEach((s) => {
        if (/obligatoire/i.test(s.innerText.slice(0, 200)) && !s.querySelector('.wizard-option.selected, .wizard-option[class*="active"]')) s.querySelector('.wizard-option')?.click();
      });
    });
    await page.waitForTimeout(500);
    await page.evaluate(() => document.querySelector('.wizard-btn-cart')?.click());
  }
}

async function addItem(cat, tile) {
  await page.locator(`button.pos-v5-category[aria-label="${cat}"]`).first().click().catch(() => {});
  await page.waitForTimeout(1200);
  const before = await page.locator('.pos-v5-cart-item').count();
  await page.locator(`.pos-v5-tile:has-text("${tile}")`).first().click();
  await completeWizardIfAny(tile);
  await page.waitForTimeout(800);
  const after = await page.locator('.pos-v5-cart-item').count();
  console.log(`ADD ${tile}: cart ${before} -> ${after}`);
}

try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1500);

  await addItem('Desserts', 'Tiramisu');
  await addItem('Frites', 'Grande Frites');
  let cart = await cartState(page);
  console.log('CART-C avant remise:', JSON.stringify(cart, null, 1));
  await quartet(page, consoleLog, netLog, 'c01-cart-2items');

  // --- Remise 10% : motif d'abord vide -> bouton APPLIQUER désactivé (gate) ---
  await page.fill('[data-testid="pos-discount-input"]', '10');
  await page.waitForTimeout(500);
  const applyDisabled = await page.locator('[data-testid="pos-discount-apply"]').isDisabled();
  const flag = await page.evaluate(() => ({
    reasonFlag: !!document.querySelector('[data-testid="pos-discount-reason-required-flag"]'),
    flagText: document.querySelector('[data-testid="pos-discount-reason-required-flag"]')?.innerText?.trim() || null,
  }));
  console.log('REMISE-SANS-MOTIF: applyDisabled=', applyDisabled, JSON.stringify(flag));
  await quartet(page, consoleLog, netLog, 'c02-remise-sans-motif-gate');

  await page.fill('[data-testid="pos-discount-reason"]', 'Geste commercial dispute R1');
  await page.waitForTimeout(400);
  await page.locator('[data-testid="pos-discount-apply"]').click();
  await page.waitForTimeout(1200);
  cart = await cartState(page);
  console.log('CART-C après remise 10%:', JSON.stringify(cart, null, 1));
  await quartet(page, consoleLog, netLog, 'c03-remise-10pct-appliquee');

  // --- Commander -> Carte (TPE) ---
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2500);
  const payTotal = await page.locator('.pos-v5-payment-total-value').first().textContent().catch(() => 'ABSENT');
  console.log('PAYMENT-MODAL-TOTAL:', JSON.stringify(payTotal?.trim()));
  await page.locator('[data-testid="pos-payment-mode-card"]').click();
  await page.waitForTimeout(1000);
  const terminalState = await page.evaluate(() => {
    const sel = document.querySelector('[data-testid="pos-payment-terminal-select"]');
    return sel ? { value: sel.value, options: Array.from(sel.options).map((o) => o.text.trim()) } : 'ABSENT';
  });
  console.log('TERMINAL-SELECT:', JSON.stringify(terminalState));
  await page.fill('#cardInput', '4242');
  await page.waitForTimeout(500);
  await quartet(page, consoleLog, netLog, 'c04-payment-card');

  let confirmed = false;
  for (let attempt = 1; attempt <= 3 && !confirmed; attempt++) {
    const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/api/admin/pos'), { timeout: 20000 }).catch(() => null);
    await page.locator('[data-testid="pos-payment-confirm"]').click().catch(() => {});
    const resp = await respP;
    if (!resp) { console.log('NO ORDER POST CAPTURED attempt', attempt); break; }
    console.log('ORDER-POST attempt', attempt, ':', resp.status());
    if (resp.status() === 401) {
      // token révoqué par relogin parallèle — re-login même contexte, cart localStorage intact ?
      await login(page);
      await gotoPos(page);
      await page.waitForTimeout(1500);
      const cartAfter = await cartState(page);
      console.log('CART après re-login (intact ?):', JSON.stringify(cartAfter.items), cartAfter.payLabel);
      await quartet(page, consoleLog, netLog, 'c05b-cart-after-relogin');
      if (!cartAfter.payLabel) {
        // panier perdu -> reconstruire la commande complète
        await addItem('Desserts', 'Tiramisu');
        await addItem('Frites', 'Grande Frites');
        await page.fill('[data-testid="pos-discount-input"]', '10');
        await page.fill('[data-testid="pos-discount-reason"]', 'Geste commercial dispute R1');
        await page.waitForTimeout(400);
        await page.locator('[data-testid="pos-discount-apply"]').click();
        await page.waitForTimeout(1000);
      }
      await page.locator('[data-testid="pos-v5-pay"]').click();
      await page.waitForTimeout(2500);
      await page.locator('[data-testid="pos-payment-mode-card"]').click();
      await page.waitForTimeout(800);
      await page.fill('#cardInput', '4242');
      await page.waitForTimeout(400);
      continue;
    }
    try { const j = await resp.json(); console.log('ORDER-JSON id/serial/total/discount:', j?.data?.id, j?.data?.order_serial_no, j?.data?.total, j?.data?.discount); } catch { console.log('ORDER-BODY:', (await resp.text().catch(() => '')).slice(0, 400)); }
    confirmed = resp.status() < 300;
  }
  await page.waitForTimeout(3500);
  await quartet(page, consoleLog, netLog, 'c05-after-confirm');
  const receiptText = await page.evaluate(() => document.querySelector('#print-receipt-client')?.innerText || 'ABSENT');
  console.log('=== RECEIPT C ===\n' + receiptText.slice(0, 2500));
  await quartet(page, consoleLog, netLog, 'c06-receipt-card-remise');
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 15)));
  await browser.close();
}
