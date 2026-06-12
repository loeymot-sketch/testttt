// R2 VAGUE A — Anti-tamper post-heal : POST /api/admin/pos modifié en vol (qty 1→5 + discount gonflée)
// Attendu (H1 Fix1): rejet PROPRE (409 intent mismatch), AUCUN order créé, PAS de logout, panier conservé.
import { boot, login, gotoPos, quartet, addItem, cartState } from './_d2-a-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();

try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1200);

  await addItem(page, 'Boissons', 'Coca-Cola 33cl');
  await page.waitForTimeout(800);
  const cartBefore = await cartState(page);
  console.log('CART avant tamper:', JSON.stringify(cartBefore));

  // Interception: modifie le body du POST order (champ liant: quantity + discount explicite)
  let tampered = null;
  await page.route('**/api/admin/pos', async (route) => {
    const req = route.request();
    if (req.method() !== 'POST') return route.continue();
    let body;
    try { body = JSON.parse(req.postData() || '{}'); } catch { return route.continue(); }
    // items peut être un JSON string — parse/retamper/re-stringify
    let items = body.items;
    const wasString = typeof items === 'string';
    if (wasString) { try { items = JSON.parse(items); } catch { items = null; } }
    if (Array.isArray(items) && items.length) {
      items[0].quantity = 5;
      body.items = wasString ? JSON.stringify(items) : items;
    }
    // tamper ITEMS SEUL (champ liant du quote) — pas de discount pour ne pas déclencher les gardes amont
    tampered = { itemsType: wasString ? 'string' : typeof body.items, items0qty: Array.isArray(items) ? items[0]?.quantity : 'N/A' };
    console.log('TAMPER-APPLIED:', JSON.stringify(tampered));
    await route.continue({ postData: JSON.stringify(body) });
  });

  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2200);
  await page.locator('[data-testid="pos-payment-mode-cash"]').click();
  await page.waitForTimeout(700);
  await page.fill('#cashInput', '20');
  await page.waitForTimeout(500);

  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/api/admin/pos'), { timeout: 25000 }).catch(() => null);
  await page.locator('[data-testid="pos-payment-confirm"]').click();
  const resp = await respP;
  let bodyTxt = '';
  if (resp) { bodyTxt = await resp.text().catch(() => ''); }
  console.log('TAMPER-RESPONSE status=' + (resp ? resp.status() : 'NONE') + ' body=' + bodyTxt.slice(0, 300));
  await page.waitForTimeout(3000);

  const onLogin = page.url().includes('/login');
  const toasts = await page.evaluate(() => Array.from(document.querySelectorAll('.iziToast-message, [class*="toast"], [role="alert"]')).map((t) => t.innerText.replace(/\s+/g, ' ').trim().slice(0, 200)).filter(Boolean));
  console.log('POST-TAMPER url=' + page.url() + ' loggedOut=' + onLogin + ' toasts=' + JSON.stringify(toasts.slice(0, 5)));
  await quartet(page, consoleLog, netLog, 't01-tamper-rejected');

  // panier conservé ?
  await page.evaluate(() => document.querySelector('.pos-v5-payment-close')?.click());
  await page.waitForTimeout(1200);
  const cartAfter = await cartState(page);
  console.log('CART après tamper (conservé ?):', JSON.stringify(cartAfter));
  await quartet(page, consoleLog, netLog, 't02-cart-after-tamper');
  await page.unroute('**/api/admin/pos');
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 20)));
  await browser.close();
}
