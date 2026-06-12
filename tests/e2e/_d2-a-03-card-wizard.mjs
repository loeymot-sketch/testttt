// R2 VAGUE A — Vente CARTE (terminal manuel + réf) sur Tacos COMPOSÉ via wizard FROZEN.
// Couvre: gap R1 (carte jusqu'au receipt) + A-RED-7 (séparateur options « Poulet mariné, Sauce » sans espace-avant-virgule).
import { boot, login, gotoPos, quartet, addItem, cartState, receiptText, confirmAndCapture, jsClick } from './_d2-a-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();

try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1200);

  // Tacos composé : Poulet mariné (viande) + Algérienne (sauce) via wizard
  await addItem(page, 'Tacos', 'Tacos', ['Poulet mariné', 'Algérienne', 'Menu']);
  await page.waitForTimeout(900);
  const cart = await cartState(page);
  console.log('CART tacos composé:', JSON.stringify(cart));
  await quartet(page, consoleLog, netLog, 'card01-cart-tacos');

  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2200);
  const payTotal = await page.locator('.pos-v5-payment-total-value').first().textContent().catch(() => 'ABSENT');
  console.log('PAYMENT-MODAL-TOTAL:', JSON.stringify(payTotal?.trim()));

  await page.locator('[data-testid="pos-payment-mode-card"]').click();
  await page.waitForTimeout(1000);
  const terminalState = await page.evaluate(() => {
    const sel = document.querySelector('[data-testid="pos-payment-terminal-select"]');
    return sel ? { value: sel.value, options: Array.from(sel.options).map((o) => o.text.trim()) } : 'ABSENT';
  });
  console.log('TERMINAL-SELECT:', JSON.stringify(terminalState));
  // sélectionne un terminal réel si dispo
  await page.evaluate(() => {
    const sel = document.querySelector('[data-testid="pos-payment-terminal-select"]');
    if (sel) { const real = Array.from(sel.options).find((o) => o.value && o.value !== 'null' && !o.disabled); if (real) { sel.value = real.value; sel.dispatchEvent(new Event('change', { bubbles: true })); } }
  });
  await page.fill('#cardInput', '4242');
  await page.waitForTimeout(600);
  await quartet(page, consoleLog, netLog, 'card02-paymodal-card');

  const { status, json } = await confirmAndCapture(page);
  console.log(`CARD ORDER-POST status=${status} id=${json?.data?.id} serial=${json?.data?.order_serial_no} total=${json?.data?.total} message=${JSON.stringify(json?.message || null)}`);
  await page.waitForTimeout(3500);
  console.log('POST-CONFIRM url=' + page.url() + ' loggedOut=' + page.url().includes('/login'));
  await quartet(page, consoleLog, netLog, 'card03-after-confirm');

  const rcpt = await receiptText(page);
  console.log('=== RECEIPT CARD ===\n' + rcpt.slice(0, 2800));
  // Check A-RED-7 séparateur: pas d'espace AVANT la virgule sur la ligne options
  const sepCheck = await page.evaluate(() => {
    const txt = document.querySelector('#print-receipt-client')?.innerText || '';
    const spaceBeforeComma = / ,/.test(txt);
    const optLines = txt.split('\n').filter((l) => /viande|sauce/i.test(l)).map((l) => l.trim());
    return { spaceBeforeComma, optLines };
  });
  console.log('A-RED-7 SEP-CHECK:', JSON.stringify(sepCheck));
  await quartet(page, consoleLog, netLog, 'card04-receipt');
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 20)));
  await browser.close();
}
