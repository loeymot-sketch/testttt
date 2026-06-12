// VAGUE A — isolation du 401 sur POST /api/admin/pos : matrice cash/card × remise/sans
import { boot, login, gotoPos, quartet, addSimple, cartState } from './_d1-A-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();
const bodies = [];
page.on('response', async (r) => {
  if (r.url().endsWith('/api/admin/pos') && r.request().method() === 'POST') {
    let body = '';
    try { body = (await r.text()).slice(0, 300); } catch {}
    bodies.push({ status: r.status(), body, reqAuth: (r.request().headers()['authorization'] || '').slice(-12) });
    console.log('POS-POST:', r.status(), '| auth…' + (r.request().headers()['authorization'] || '').slice(-10), '| body:', body);
  }
});

async function payCash() {
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2200);
  await page.fill('#cashInput', '20').catch(() => {});
  await page.waitForTimeout(500);
  await page.locator('[data-testid="pos-payment-confirm"]').click().catch(() => {});
  await page.waitForTimeout(3000);
  // fermer receipt si présent
  await page.evaluate(() => document.querySelector('#receiptModal button.modal-close, #receiptModal [aria-label]')?.click());
  await page.locator('#receiptModal button:has-text("Fermer")').click().catch(() => {});
  await page.waitForTimeout(1200);
}
async function payCard() {
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2200);
  await page.locator('[data-testid="pos-payment-mode-card"]').click();
  await page.waitForTimeout(800);
  await page.fill('#cardInput', '4242');
  await page.waitForTimeout(400);
  await page.locator('[data-testid="pos-payment-confirm"]').click().catch(() => {});
  await page.waitForTimeout(3000);
  await page.locator('#receiptModal button:has-text("Fermer")').click().catch(() => {});
  await page.waitForTimeout(1200);
}
async function applyRemise() {
  await page.fill('[data-testid="pos-discount-input"]', '10');
  await page.fill('[data-testid="pos-discount-reason"]', 'Iso 401 R1');
  await page.waitForTimeout(300);
  await page.locator('[data-testid="pos-discount-apply"]').click();
  await page.waitForTimeout(900);
}

try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1200);

  console.log('--- V1 cash sans remise');
  await addSimple(page, 'Boissons', 'Coca-Cola 33cl', 1);
  await payCash();

  console.log('--- V2 cash + remise');
  await addSimple(page, 'Boissons', 'Coca-Cola 33cl', 1);
  await applyRemise();
  await payCash();

  console.log('--- V3 card sans remise');
  await addSimple(page, 'Boissons', 'Coca-Cola 33cl', 1);
  await payCard();

  console.log('--- V4 card + remise');
  await addSimple(page, 'Boissons', 'Coca-Cola 33cl', 1);
  await applyRemise();
  await payCard();

  await quartet(page, consoleLog, netLog, 'iso01-final');
  console.log('RESULTS:', JSON.stringify(bodies, null, 1));
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 20)));
  await browser.close();
}
