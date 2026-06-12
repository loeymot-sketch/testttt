// R2 VAGUE A — PRIORITÉ HEAL A-RED-1/A-RED-2 : remise manuelle 10% → Espèces → 201 + receipt, ×2 runs
// Attendu post-heal H1 Fix1 (9b4cb6af3): plus de 401 "Order quote intent mismatch" → logout → panier perdu.
import { boot, login, gotoPos, quartet, addItem, cartState, receiptText, confirmAndCapture, jsClick } from './_d2-a-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();

async function runDiscountCashOrder(runTag) {
  await gotoPos(page);
  await page.waitForTimeout(1200);

  await addItem(page, 'Desserts', 'Tiramisu');
  await page.waitForTimeout(1000);
  let cart = await cartState(page);
  console.log(`[${runTag}] CART avant remise:`, JSON.stringify(cart));

  // Remise 10% + motif
  await page.fill('[data-testid="pos-discount-input"]', '10');
  await page.fill('[data-testid="pos-discount-reason"]', `Geste commercial dispute R2 ${runTag}`);
  await page.waitForTimeout(600);
  await page.locator('[data-testid="pos-discount-apply"]').click();
  await page.waitForTimeout(1500);
  cart = await cartState(page);
  console.log(`[${runTag}] CART après remise 10%:`, JSON.stringify(cart));
  await quartet(page, consoleLog, netLog, `h${runTag}-01-cart-remise10`);

  // Paiement Espèces
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2200);
  const payTotal = await page.locator('.pos-v5-payment-total-value').first().textContent().catch(() => 'ABSENT');
  console.log(`[${runTag}] PAYMENT-MODAL-TOTAL:`, JSON.stringify(payTotal?.trim()));
  await page.locator('[data-testid="pos-payment-mode-cash"]').click();
  await page.waitForTimeout(800);
  await page.fill('#cashInput', '10');
  await page.waitForTimeout(600);
  const rendu = await page.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('.pos-v5-payment-modal *')).filter((el) => /rendre|monnaie/i.test(el.innerText || '') && el.children.length === 0);
    return rows.map((r) => r.parentElement?.innerText?.replace(/\s+/g, ' ').slice(0, 120));
  }).catch(() => []);
  console.log(`[${runTag}] RENDU-INFO:`, JSON.stringify(rendu.slice(0, 3)));
  await quartet(page, consoleLog, netLog, `h${runTag}-02-paymodal-cash`);

  const { status, json } = await confirmAndCapture(page);
  console.log(`[${runTag}] ORDER-POST status=${status} id=${json?.data?.id} serial=${json?.data?.order_serial_no} total=${json?.data?.total} discount=${json?.data?.discount} message=${JSON.stringify(json?.message || null)}`);
  await page.waitForTimeout(3500);
  const onLogin = page.url().includes('/login');
  console.log(`[${runTag}] POST-CONFIRM url=${page.url()} loggedOut=${onLogin}`);
  await quartet(page, consoleLog, netLog, `h${runTag}-03-after-confirm`);

  const rcpt = await receiptText(page);
  console.log(`[${runTag}] === RECEIPT ===\n` + rcpt.slice(0, 2600));
  await quartet(page, consoleLog, netLog, `h${runTag}-04-receipt`);

  // Fermer le modal receipt s'il existe pour enchaîner
  await jsClick(page, '.pos-v5-payment-close');
  await page.evaluate(() => {
    document.querySelectorAll('button').forEach((b) => { if (/fermer|nouvelle commande|close/i.test(b.innerText)) b.click(); });
  }).catch(() => {});
  await page.waitForTimeout(1500);
  return { status, id: json?.data?.id, serial: json?.data?.order_serial_no, total: json?.data?.total, discount: json?.data?.discount, loggedOut: onLogin };
}

try {
  await login(page);
  const r1 = await runDiscountCashOrder('A');
  await page.waitForTimeout(2000); // espacement bursts quote
  const r2 = await runDiscountCashOrder('B');
  console.log('SUMMARY-RUN-A:', JSON.stringify(r1));
  console.log('SUMMARY-RUN-B:', JSON.stringify(r2));
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 20)));
  await browser.close();
}
