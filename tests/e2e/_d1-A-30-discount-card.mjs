// VAGUE A — Commande (c): Tiramisu + Grande Frites, remise 10% avec motif → Carte (TPE) réf 4242
import { boot, login, gotoPos, quartet, addSimple, cartState } from './_d1-A-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();
try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1500);

  // Desserts: Tiramisu (3,80) + Frites: Grande Frites (4,00) => 7,80
  await addSimple(page, 'Desserts', 'Tiramisu', 1);
  await addSimple(page, 'Frites', 'Grande Frites', 1);
  let cart = await cartState(page);
  console.log('CART-C avant remise:', JSON.stringify(cart, null, 1));
  await quartet(page, consoleLog, netLog, 'c01-cart-2items');

  // --- Remise 10% sans motif -> APPLIQUER (gate motif ?) ---
  await page.fill('[data-testid="pos-discount-input"]', '10');
  await page.waitForTimeout(400);
  await page.locator('[data-testid="pos-discount-apply"]').click();
  await page.waitForTimeout(800);
  const gate = await page.evaluate(() => ({
    reasonFlag: !!document.querySelector('[data-testid="pos-discount-reason-required-flag"]'),
    invalid: document.querySelector('[data-testid="pos-discount-reason-invalid"]')?.innerText?.trim() || null,
    totals: Array.from(document.querySelectorAll('.pos-v5-total-row')).map((e) => e.innerText.replace(/\s+/g, ' ').trim()),
  }));
  console.log('REMISE-SANS-MOTIF:', JSON.stringify(gate));
  await quartet(page, consoleLog, netLog, 'c02-remise-sans-motif');

  // --- Motif puis APPLIQUER ---
  await page.fill('[data-testid="pos-discount-reason"]', 'Geste commercial dispute R1');
  await page.waitForTimeout(300);
  await page.locator('[data-testid="pos-discount-apply"]').click();
  await page.waitForTimeout(1000);
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

  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && /api\/admin\/pos$/.test(new URL(r.url()).pathname.replace(/\/$/, '')), { timeout: 20000 }).catch(() => null);
  await page.locator('[data-testid="pos-payment-confirm"]').click();
  const resp = await respP;
  if (resp) {
    console.log('ORDER-POST:', resp.status());
    try { const j = await resp.json(); console.log('ORDER-JSON id/serial/total/discount:', j?.data?.id, j?.data?.order_serial_no, j?.data?.total, j?.data?.discount); } catch { console.log('ORDER-BODY:', (await resp.text().catch(() => '')).slice(0, 400)); }
  } else console.log('NO ORDER POST CAPTURED');
  await page.waitForTimeout(3500);
  await quartet(page, consoleLog, netLog, 'c05-after-confirm');
  const receiptText = await page.evaluate(() => document.querySelector('#print-receipt-client')?.innerText || 'ABSENT');
  console.log('=== RECEIPT C ===\n' + receiptText.slice(0, 2500));
  await quartet(page, consoleLog, netLog, 'c06-receipt-card-remise');
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 15)));
  await browser.close();
}
