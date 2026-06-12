// ADVERSARIAL R2 VAGUE A — re-vérification live indépendante des heals
// 1) remise 10% → espèces → attendu 201 (heal A-RED-1/2) + headers rate-limit quote (A-RED-3)
// 2) historique + show navigateur (heal A-RED-11: plus de 403 delivery-boy ; sonde 422 loyalty)
// 3) tamper qty 1→5 → 409 : le caissier voit-il un TOAST ? (poll 250ms ×24)
import { boot, login, gotoPos, quartet, addItem, cartState, receiptText, jsClick } from './_d2-a-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();

const quoteHeaders = [];
page.on('response', async (r) => {
  if (r.url().includes('/api/admin/pos/quote') && r.request().method() === 'POST') {
    const h = r.headers();
    quoteHeaders.push({ status: r.status(), limit: h['x-ratelimit-limit'], remaining: h['x-ratelimit-remaining'] });
  }
});

try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1200);

  // ---- 1. remise 10% espèces (re-run indépendant du heal) ----
  await addItem(page, 'Desserts', 'Tiramisu');
  await page.waitForTimeout(1000);
  console.log('CART:', JSON.stringify(await cartState(page)));
  await page.fill('[data-testid="pos-discount-input"]', '10');
  await page.fill('[data-testid="pos-discount-reason"]', 'red R2 contre-verif adversaire');
  await page.waitForTimeout(700);
  await page.locator('[data-testid="pos-discount-apply"]').click();
  await page.waitForTimeout(1800);
  console.log('CART post-remise:', JSON.stringify(await cartState(page)));

  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2200);
  await page.locator('[data-testid="pos-payment-mode-cash"]').click();
  await page.waitForTimeout(800);
  await page.fill('#cashInput', '10');
  await page.waitForTimeout(600);
  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/api/admin/pos'), { timeout: 25000 }).catch(() => null);
  await page.locator('[data-testid="pos-payment-confirm"]').click();
  const resp = await respP;
  let json = null; try { json = resp ? await resp.json() : null; } catch {}
  console.log('ORDER-POST status=' + (resp ? resp.status() : 'NONE') + ' id=' + json?.data?.id + ' total=' + json?.data?.total + ' discount=' + json?.data?.discount + ' msg=' + JSON.stringify(json?.message ?? null));
  await page.waitForTimeout(3000);
  console.log('POST-CONFIRM url=' + page.url() + ' loggedOut=' + page.url().includes('/login'));
  console.log('QUOTE-HEADERS:', JSON.stringify(quoteHeaders.slice(-4)));
  const rcpt = await receiptText(page);
  console.log('=== RECEIPT (extrait) ===\n' + (rcpt || '').slice(0, 1200));
  await quartet(page, consoleLog, netLog, '_red2-01-after-confirm');
  const orderId = json?.data?.id;

  // fermer le receipt
  await jsClick(page, '.pos-v5-payment-close');
  await page.evaluate(() => { document.querySelectorAll('button').forEach((b) => { if (/fermer|nouvelle commande|close/i.test(b.innerText)) b.click(); }); }).catch(() => {});
  await page.waitForTimeout(1500);

  // ---- 2. historique + show navigateur ----
  netLog.length = 0;
  await page.goto('http://127.0.0.1:8768/admin/pos-orders', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  const histRow = await page.evaluate((oid) => {
    const rows = Array.from(document.querySelectorAll('tr, [class*="order-row"], [class*="card"]'));
    const hit = rows.find((r) => r.innerText && r.innerText.includes(String(oid)));
    return hit ? hit.innerText.replace(/\s+/g, ' ').slice(0, 300) : '(introuvable: ' + oid + ')';
  }, orderId);
  console.log('HISTORIQUE row:', JSON.stringify(histRow));
  await quartet(page, consoleLog, netLog, '_red2-02-history');

  netLog.length = 0;
  await page.goto('http://127.0.0.1:8768/admin/pos-orders/show/' + orderId, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  console.log('SHOW net>=400:', JSON.stringify(netLog.slice(0, 10)));
  const showProbe = await page.evaluate(() => {
    const t = (document.querySelector('#app') || document.body).innerText;
    const grab = (re) => (t.match(re) || ['(absent)'])[0];
    return {
      total: grab(/Total[^\n]{0,40}/),
      sousTotal: grab(/Sous[- ]?[Tt]otal[^\n]{0,40}/),
      remise: grab(/Remise[^\n]{0,40}/i),
      instruction: grab(/Instruction[^\n]{0,140}/),
      paiement: grab(/(Type de paiement|Espèces|Statut de paiement)[^\n]{0,60}/),
    };
  });
  console.log('SHOW probe:', JSON.stringify(showProbe));
  await quartet(page, consoleLog, netLog, '_red2-03-show');

  // ---- 3. tamper → 409 → TOAST visible ? ----
  await gotoPos(page);
  await page.waitForTimeout(1200);
  await addItem(page, 'Boissons', 'Coca-Cola 33cl');
  await page.waitForTimeout(900);
  await page.route('**/api/admin/pos', async (route) => {
    const req = route.request();
    if (req.method() !== 'POST') return route.continue();
    let body; try { body = JSON.parse(req.postData() || '{}'); } catch { return route.continue(); }
    let items = body.items; const wasString = typeof items === 'string';
    if (wasString) { try { items = JSON.parse(items); } catch { items = null; } }
    if (Array.isArray(items) && items.length) { items[0].quantity = 5; body.items = wasString ? JSON.stringify(items) : items; }
    await route.continue({ postData: JSON.stringify(body) });
  });
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2200);
  await page.locator('[data-testid="pos-payment-mode-cash"]').click();
  await page.waitForTimeout(700);
  await page.fill('#cashInput', '20');
  await page.waitForTimeout(500);
  const resp2P = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/api/admin/pos'), { timeout: 25000 }).catch(() => null);
  const toastSamples = [];
  const poller = (async () => {
    for (let i = 0; i < 24; i++) {
      const ts = await page.evaluate(() => Array.from(document.querySelectorAll('.iziToast-message, .iziToast-title, [class*="toast"], [role="alert"], .swal2-popup')).map((t) => (t.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 200)).filter(Boolean)).catch(() => []);
      ts.forEach((x) => { if (!toastSamples.includes(x)) toastSamples.push(x); });
      await new Promise((r) => setTimeout(r, 250));
    }
  })();
  await page.locator('[data-testid="pos-payment-confirm"]').click();
  const resp2 = await resp2P;
  console.log('TAMPER status=' + (resp2 ? resp2.status() : 'NONE') + ' body=' + (resp2 ? (await resp2.text().catch(() => '')).slice(0, 200) : ''));
  await poller;
  console.log('TOASTS observés (poll 6s):', JSON.stringify(toastSamples.slice(0, 8)));
  console.log('post-tamper url=' + page.url() + ' loggedOut=' + page.url().includes('/login'));
  await quartet(page, consoleLog, netLog, '_red2-04-tamper-toast');
  await page.unroute('**/api/admin/pos');
} finally {
  console.log('NET>=400 final:', JSON.stringify(netLog.slice(0, 20)));
  await browser.close();
}
