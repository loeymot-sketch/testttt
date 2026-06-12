// VAGUE A — Commande (a): Coca-Cola 33cl ×3 (4,50 €) → Commander → Espèces 10 € (rendu 5,50 €) → receipt
import { boot, login, gotoPos, quartet, jsClick, addSimple, cartState, BASE } from './_d1-A-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();
const ordersCreated = [];
page.on('response', async (r) => {
  if (r.request().method() === 'POST' && /admin\/pos\b/.test(r.url()) && r.status() < 300) {
    try {
      const j = await r.json();
      if (j?.data?.id && j?.data?.order_serial_no) ordersCreated.push({ id: j.data.id, serial: j.data.order_serial_no, total: j.data.total, url: r.url() });
    } catch {}
  }
});

try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1500);
  await quartet(page, consoleLog, netLog, 'a01-pos-initial');

  // --- Construire: Coca-Cola 33cl x3 ---
  await addSimple(page, 'Boissons', 'Coca-Cola 33cl', 1);
  // qty -> 3 via stepper +
  const plus = page.locator('.pos-v5-cart-item__qty button').nth(1);
  await plus.click().catch(() => {});
  await page.waitForTimeout(400);
  await plus.click().catch(() => {});
  await page.waitForTimeout(800);
  const cart = await cartState(page);
  console.log('CART-A:', JSON.stringify(cart, null, 1));
  await quartet(page, consoleLog, netLog, 'a02-cart-coca-x3');

  // --- Commander -> PaymentComponent (FROZEN observe) ---
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(3000);
  const modalVisible = await page.evaluate(() => {
    const m = document.querySelector('#orderpayment');
    return m ? getComputedStyle(m).display : 'ABSENT';
  });
  console.log('payment modal display:', modalVisible, '| url:', page.url());
  const payTotal = await page.locator('.pos-v5-payment-total-value').first().textContent().catch(() => 'ABSENT');
  console.log('PAYMENT-MODAL-TOTAL:', JSON.stringify(payTotal?.trim()));
  await quartet(page, consoleLog, netLog, 'a03-payment-modal-cash');

  // --- Espèces: saisir 10 ---
  await page.fill('#cashInput', '10').catch(async () => {
    await page.locator('.pos-v5-payment-numpad-wrap button:has-text("1")').first().click();
    await page.locator('.pos-v5-payment-numpad-wrap button:has-text("0")').first().click();
  });
  await page.waitForTimeout(800);
  const change = await page.locator('.pos-v5-payment-change-value').textContent().catch(() => 'ABSENT');
  console.log('RENDU-MONNAIE (10€ reçus):', JSON.stringify(change?.trim()));
  await quartet(page, consoleLog, netLog, 'a04-cash-10-rendu');

  // --- Confirmer ---
  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && /pos/.test(r.url()) && !/quote/.test(r.url()), { timeout: 20000 }).catch(() => null);
  await page.locator('[data-testid="pos-payment-confirm"]').click();
  const resp = await respP;
  if (resp) {
    console.log('ORDER-POST:', resp.status(), resp.url());
    try { const j = await resp.json(); console.log('ORDER-JSON id/serial/total:', j?.data?.id, j?.data?.order_serial_no, j?.data?.total); } catch {}
  }
  await page.waitForTimeout(3500);
  await quartet(page, consoleLog, netLog, 'a05-after-confirm');

  // --- Receipt modal ---
  const receiptVisible = await page.evaluate(() => {
    const m = document.querySelector('#receiptModal');
    return m ? getComputedStyle(m).display : 'ABSENT';
  });
  console.log('receipt modal display:', receiptVisible);
  const receiptText = await page.evaluate(() => {
    const r = document.querySelector('#print-receipt-client');
    return r ? r.innerText : 'ABSENT';
  });
  console.log('=== RECEIPT CLIENT ===\n' + receiptText.slice(0, 2500));
  await quartet(page, consoleLog, netLog, 'a06-receipt-cash');
  console.log('ORDERS-CREATED:', JSON.stringify(ordersCreated));
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 15)));
  await browser.close();
}
