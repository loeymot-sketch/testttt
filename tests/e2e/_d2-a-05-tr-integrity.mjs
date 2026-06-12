// R2 VAGUE A — Commande simple qty3 Coca → Ticket Restaurant (multi-paiement) + INTÉGRITÉ chiffre par chiffre
import { boot, login, gotoPos, quartet, addItem, cartState, receiptText, jsClick, BASE } from './_d2-a-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();

try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1200);

  // Coca-Cola 33cl ×3
  await addItem(page, 'Boissons', 'Coca-Cola 33cl');
  await page.waitForTimeout(700);
  // stepper +2 via le bouton incrément (PosV5QtyStepper, dernier bouton = +)
  for (let i = 0; i < 2; i++) {
    const inc = await page.evaluate(() => {
      const item = document.querySelector('.pos-v5-cart-item');
      if (!item) return false;
      const btns = Array.from(item.querySelectorAll('button'));
      const plus = btns.find((b) => /augment|increase|\+/i.test((b.getAttribute('aria-label') || '') + b.innerText)) || btns[btns.length - 1];
      if (plus) { plus.click(); return true; }
      return false;
    });
    await page.waitForTimeout(600);
  }
  await page.waitForTimeout(600);
  let cart = await cartState(page);
  console.log('CART coca x3:', JSON.stringify(cart));
  await quartet(page, consoleLog, netLog, 'tr01-cart-coca-x3');

  // Paiement → Multi → tranche TR
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2000);
  await page.locator('[data-testid="pos-payment-mode-multi"]').click();
  await page.waitForTimeout(800);
  await page.locator('[data-testid="pos-payment-tranche-add"]').click();
  await page.waitForTimeout(1000);
  // sélectionne Ticket Restaurant dans le select de la tranche
  const trSet = await page.evaluate(() => {
    const sels = Array.from(document.querySelectorAll('select'));
    for (const s of sels) {
      const opt = Array.from(s.options).find((o) => /ticket restaurant/i.test(o.text));
      if (opt) { s.value = opt.value; s.dispatchEvent(new Event('change', { bubbles: true })); return { ok: true, options: Array.from(s.options).map((o) => o.text.trim()) }; }
    }
    return { ok: false };
  });
  console.log('TR-TRANCHE-SET:', JSON.stringify(trSet));
  await page.waitForTimeout(1000);
  const splitState = await page.evaluate(() => ({
    covered: document.querySelector('[data-testid="pos-payment-total-covered"]')?.innerText?.trim(),
    remaining: document.querySelector('[data-testid="pos-payment-remaining-due"]')?.innerText?.trim(),
    confirmDisabled: document.querySelector('[data-testid="pos-payment-confirm"]')?.disabled,
  }));
  console.log('SPLIT-STATE:', JSON.stringify(splitState));
  await quartet(page, consoleLog, netLog, 'tr02-multi-tr');

  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/api/admin/pos'), { timeout: 25000 }).catch(() => null);
  await page.locator('[data-testid="pos-payment-confirm"]').click();
  const resp = await respP;
  let json = null; try { json = await resp.json(); } catch {}
  const orderId = json?.data?.id;
  console.log('TR ORDER-POST status=' + (resp ? resp.status() : 'NONE') + ' id=' + orderId + ' serial=' + json?.data?.order_serial_no + ' total=' + json?.data?.total);
  await page.waitForTimeout(3000);
  await quartet(page, consoleLog, netLog, 'tr03-after-confirm');
  const rcpt = await receiptText(page);
  console.log('=== RECEIPT TR ===\n' + rcpt.slice(0, 2200));
  await quartet(page, consoleLog, netLog, 'tr04-receipt');

  // ===== INTÉGRITÉ: pos-orders show + historique via API (même session axios) =====
  if (orderId) {
    const integrity = await page.evaluate(async (id) => {
      const out = {};
      try { const r = await window.axios.get('admin/pos-orders/' + id); out.show = { total: r.data?.data?.total, payment: r.data?.data?.pos_payment_method ?? r.data?.data?.payment_method, items: (r.data?.data?.order_items || r.data?.data?.items || []).map((it) => ({ name: it.item_name || it.name, qty: it.quantity, total: it.total })) }; } catch (e) { out.show = 'ERR:' + (e?.response?.status || e.message); }
      return out;
    }, orderId);
    console.log('INTEGRITY-SHOW order ' + orderId + ':', JSON.stringify(integrity));
  }
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 20)));
  await browser.close();
}
