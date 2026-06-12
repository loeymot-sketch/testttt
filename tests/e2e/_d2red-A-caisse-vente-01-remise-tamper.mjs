// ADVERSARIAL R2 vague A — vérif live indépendante :
// (1) repro EXACTE du P0 R1 (Tiramisu+Grande Frites, remise 10%, espèces) -> attendu 201 + receipt, AUCUN logout
// (2) tamper quote_signature forgée -> attendu 4xx PROPRE (pas 401), toast visible, PAS de logout, panier conservé
import { boot, login, gotoPos, addItem, cartState, receiptText, confirmAndCapture, quartet, OUT } from './_d2-a-lib.mjs';
import path from 'path';

const { browser, page, consoleLog, netLog } = await boot();

const probeAlerts = async (ms = 6000) => {
  const seen = new Set();
  for (let i = 0; i < ms / 200; i++) {
    const msgs = await page.evaluate(() =>
      Array.from(document.querySelectorAll('[role=alert], .toast, .toastify, [class*="toast"], .swal2-popup, .alert'))
        .map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean)
    ).catch(() => []);
    msgs.forEach((m) => seen.add(m.slice(0, 220)));
    await page.waitForTimeout(200);
  }
  return [...seen];
};

try {
  await login(page);
  await gotoPos(page);

  // ===== (1) Repro EXACTE A-RED-1 R1 =====
  await addItem(page, 'Desserts', 'Tiramisu');
  await addItem(page, 'Frites', 'Grande Frites');
  console.log('CART:', JSON.stringify(await cartState(page)));
  await page.fill('[data-testid="pos-discount-input"]', '10');
  await page.fill('[data-testid="pos-discount-reason"]', 'dispute adversarial R2');
  await page.waitForTimeout(400);
  await page.locator('[data-testid="pos-discount-apply"]').click();
  await page.waitForTimeout(1200);
  console.log('CART après remise:', JSON.stringify((await cartState(page)).totals));
  await quartet(page, consoleLog, netLog, '_red2-01-cart-remise');

  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2500);
  await page.fill('#cashInput', '10');
  await page.waitForTimeout(400);
  const alertsP = probeAlerts(6000);
  const r1 = await confirmAndCapture(page);
  console.log('ORDER-POST (remise):', r1.status, '| body:', JSON.stringify(r1.json)?.slice(0, 300));
  const alerts1 = await alertsP;
  console.log('ALERTES (remise):', JSON.stringify(alerts1));
  console.log('URL après confirm:', page.url());
  const rcpt = await receiptText(page);
  console.log('RECEIPT (extrait):', rcpt === 'ABSENT' ? 'ABSENT' : rcpt.replace(/\n/g, ' | ').slice(0, 700));
  await quartet(page, consoleLog, netLog, '_red2-02-after-confirm-remise');

  // fermer le receipt, retour POS
  await page.evaluate(() => document.querySelector('.pos-v5-receipt-close, [class*="receipt"] button')?.click()).catch(() => {});
  await page.waitForTimeout(1500);
  await gotoPos(page);
  console.log('LOGGED-IN après vente remisée:', !page.url().includes('/login'), '|', page.url());

  // ===== (2) TAMPER quote_signature forgée =====
  await addItem(page, 'Boissons', 'Coca-Cola 33cl');
  console.log('CART tamper:', JSON.stringify((await cartState(page)).items));
  let tampered = false;
  await page.route('**/api/admin/pos', async (route) => {
    const req = route.request();
    if (req.method() === 'POST') {
      try {
        const body = JSON.parse(req.postData() || '{}');
        if (body.quote_signature) {
          body.quote_signature = 'deadbeef'.repeat(8); // signature FORGÉE
          tampered = true;
          return route.continue({ postData: JSON.stringify(body) });
        }
      } catch {}
    }
    return route.continue();
  });
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2500);
  await page.fill('#cashInput', '5');
  await page.waitForTimeout(400);
  const alertsP2 = probeAlerts(7000);
  const r2 = await confirmAndCapture(page);
  console.log('TAMPERED:', tampered, '| ORDER-POST (sig forgée):', r2.status, '| body:', JSON.stringify(r2.json)?.slice(0, 300));
  const alerts2 = await alertsP2;
  console.log('ALERTES (tamper):', JSON.stringify(alerts2));
  console.log('URL après tamper:', page.url(), '| sur login ?', page.url().includes('/login'));
  await quartet(page, consoleLog, netLog, '_red2-03-tamper-sig-forged');
  await page.unroute('**/api/admin/pos');

  // panier conservé ? fermer modal paiement d'abord
  await page.evaluate(() => document.querySelector('.pos-v5-payment-modal [aria-label*="erm"], .pos-v5-payment-modal button[class*="close"]')?.click()).catch(() => {});
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(1000);
  const after = await cartState(page);
  console.log('CART après tamper (conservé ?):', JSON.stringify(after.items));
  await quartet(page, consoleLog, netLog, '_red2-04-cart-after-tamper');

  console.log('NET>=400 (run complet):', JSON.stringify(netLog));
} finally {
  await browser.close();
}
